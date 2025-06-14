<?php
// 1. Register Custom Post Type for Imcomplete Order PANNA
add_action('init', function () {
    register_post_type('partial_checkout', [
        'labels' => [
            'name'          => __('Imcomplete Order PANNA', 'text-domain'),
            'singular_name' => __('Partial Checkout', 'text-domain'),
            'edit_item'     => __('View Details', 'text-domain'),
        ],
        'public'        => false,
        'show_ui'       => true,
        'menu_icon'     => 'dashicons-cart',
        'supports'      => ['title', 'editor'],
        'show_in_menu'  => true,
        'capability_type' => 'post',
        'capabilities'  => ['create_posts' => 'do_not_allow'],
        'map_meta_cap'  => true,
    ]);
});

// 2. AJAX handler to capture partial checkout data
add_action('wp_ajax_capture_checkout_data', 'save_partial_checkout_data');
add_action('wp_ajax_nopriv_capture_checkout_data', 'save_partial_checkout_data');

function save_partial_checkout_data() {
    // Verify nonce for security
    if (!check_ajax_referer('partial_checkout_nonce', 'security', false)) {
        wp_send_json_error(__('Invalid nonce', 'text-domain'));
        return;
    }

    // Sanitize and validate input
    $name    = sanitize_text_field($_POST['name'] ?? '');
    $phone   = sanitize_text_field($_POST['phone'] ?? '');
    $address = sanitize_text_field($_POST['address'] ?? '');
    $products = $_POST['products'] ?? [];

    $phone_clean = preg_replace('/\D+/', '', $phone);

    // Validate required fields
    if (empty($name) || empty($phone_clean) || strlen($phone_clean) < 7 || empty($address)) {
        wp_send_json_error(__('Missing or invalid required fields', 'text-domain'));
        return;
    }

    // Check for existing partial checkout (cached to reduce DB queries)
    $cache_key = 'partial_checkout_' . md5($phone_clean . $address);
    $existing = wp_cache_get($cache_key, 'partial_checkout');

    if (false === $existing) {
        $existing = get_posts([
            'post_type'      => 'partial_checkout',
            'post_status'    => 'publish',
            'meta_query'     => [
                ['key' => '_phone', 'value' => $phone_clean, 'compare' => '='],
                ['key' => '_address', 'value' => $address, 'compare' => '='],
            ],
            'fields'         => 'ids',
            'posts_per_page' => 1,
        ]);
        wp_cache_set($cache_key, $existing, 'partial_checkout', 3600); // Cache for 1 hour
    }

    if (!empty($existing)) {
        wp_send_json_success(__('Already saved', 'text-domain'));
        return;
    }

    // Check for completed orders to avoid capturing successful purchases
    $order_cache_key = 'order_check_' . md5($phone_clean);
    $order_exists = wp_cache_get($order_cache_key, 'partial_checkout');

    if (false === $order_exists) {
        $order_exists = wc_get_orders([
            'limit'       => 1,
            'status'      => ['wc-completed', 'wc-processing', 'wc-on-hold'],
            'meta_query'  => [
                [
                    'key'     => '_billing_phone',
                    'value'   => $phone_clean,
                    'compare' => '=',
                ],
            ],
        ]);
        wp_cache_set($order_cache_key, $order_exists, 'partial_checkout', 3600); // Cache for 1 hour
    }

    if (!empty($order_exists)) {
        wp_send_json_success(__('Order already completed', 'text-domain'));
        return;
    }

    // Prepare product details
    $product_lines = '';
    if (is_array($products) && !empty($products)) {
        foreach ($products as $p) {
            $prod_name = esc_html($p['name'] ?? '');
            $qty       = intval($p['qty'] ?? 0);
            $price     = floatval(preg_replace('/[^\d.]/', '', $p['price'] ?? '0'));
            $url       = esc_url($p['url'] ?? '#');
            $product_lines .= "🛒 <a href='{$url}' target='_blank' rel='noopener noreferrer'>{$prod_name}</a> × {$qty} (" . wc_price($price) . ")\n";
        }
    }

    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $note = "📞 Phone: $phone\n📍 Address: $address\n💻 IP: $ip\n\n$product_lines";

    // Insert partial checkout post
    $post_id = wp_insert_post([
        'post_type'   => 'partial_checkout',
        'post_title'  => $name,
        'post_content' => $note,
        'post_status' => 'publish',
    ]);

    if (is_wp_error($post_id) || !$post_id) {
        wp_send_json_error(__('Failed to save data', 'text-domain'));
        return;
    }

    // Save meta data
    update_post_meta($post_id, '_phone', $phone_clean);
    update_post_meta($post_id, '_address', $address);
    update_post_meta($post_id, '_ip', $ip);
    update_post_meta($post_id, '_products', $products);

    // Store post_id in transient instead of session for better compatibility
    set_transient('partial_checkout_' . $phone_clean, $post_id, 24 * HOUR_IN_SECONDS);

    wp_send_json_success(__('Saved', 'text-domain'));
}

// 3. Enqueue and print JS on checkout page
add_action('wp_enqueue_scripts', function () {
    if (!function_exists('is_checkout') || !is_checkout() || is_order_received_page()) {
        return;
    }
    wp_enqueue_script('jquery');
});

add_action('wp_footer', function () {
    if (!function_exists('is_checkout') || !is_checkout() || is_order_received_page()) {
        return;
    }

    // Get cart items
    $cart_items = [];
    if (WC()->cart && !WC()->cart->is_empty()) {
        foreach (WC()->cart->get_cart() as $item) {
            $product = $item['data'];
            $cart_items[] = [
                'name' => $product->get_name(),
                'qty'  => $item['quantity'],
                'price' => strip_tags(wc_price($product->get_price())),
                'url'  => get_permalink($product->get_id()),
            ];
        }
    }

    $nonce = wp_create_nonce('partial_checkout_nonce');
    ?>
    <script type="text/javascript">
    (function ($) {
        const partialCart = <?php echo wp_json_encode($cart_items, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        const ajaxUrl = "<?php echo esc_url(admin_url('admin-ajax.php')); ?>";
        const nonce = "<?php echo esc_js($nonce); ?>";

        let debounceTimeout = null;
        let lastSentData = '';

        function sendPartialData() {
            clearTimeout(debounceTimeout);
            debounceTimeout = setTimeout(function () {
                const $form = $('form.checkout');
                if (!$form.length) return;

                const name = $form.find('input[name="billing_first_name"]').val()?.trim() || '';
                const phone = $form.find('input[name="billing_phone"]').val()?.trim() || '';
                const address = $form.find('input[name="billing_address_1"]').val()?.trim() || '';

                if (!name || !phone || !address) return;

                const currentData = `${name}|${phone}|${address}`;
                if (currentData === lastSentData) return;

                $.ajax({
                    url: ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'capture_checkout_data',
                        security: nonce,
                        name: name,
                        phone: phone,
                        address: address,
                        products: partialCart
                    },
                    success: function (response) {
                        if (response.success) {
                            lastSentData = currentData;
                            console.log('Partial checkout saved.');
                        } else {
                            console.warn('Partial checkout error:', response.data);
                        }
                    },
                    error: function () {
                        console.warn('Partial checkout AJAX request failed.');
                    }
                });
            }, 2000); // Reduced debounce to 2 seconds for better UX
        }

        // Single event listener with delegation
        $(document).on('input change blur', 'form.checkout input[name="billing_first_name"], form.checkout input[name="billing_phone"], form.checkout input[name="billing_address_1"]', sendPartialData);
    })(jQuery);
    </script>
    <?php
});

// 4. Handle Thank You page: Delete partial checkout and add order note
add_action('woocommerce_thankyou', function ($order_id) {
    if (!$order_id) return;

    $order = wc_get_order($order_id);
    if (!$order) return;

    $phone_clean = preg_replace('/\D+/', '', $order->get_billing_phone());
    $post_id = get_transient('partial_checkout_' . $phone_clean);

    // Delete partial checkout post
    if ($post_id && get_post_type($post_id) === 'partial_checkout') {
        wp_delete_post($post_id, true);
        delete_transient('partial_checkout_' . $phone_clean);
    }

    // Add order note with checkout details
    $note = '';
    $products = [];
    foreach ($order->get_items() as $item) {
        $product = $item->get_product();
        $products[] = "🛒 <a href='" . esc_url(get_permalink($product->get_id())) . "' target='_blank' rel='noopener noreferrer'>" . esc_html($product->get_name()) . "</a> × " . $item->get_quantity() . " (" . wc_price($product->get_price()) . ")";
    }
    $note .= "📞 Phone: " . esc_html($order->get_billing_phone()) . "\n";
    $note .= "📍 Address: " . esc_html($order->get_billing_address_1()) . "\n";
    $note .= "💻 IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . "\n\n";
    $note .= implode("\n", $products);

    if ($note) {
        echo '<div class="woocommerce-message" style="white-space: pre-wrap; font-size: 16px; margin-top: 20px;">' . esc_html($note) . '</div>';
        $order->add_order_note($note);
    }
});
?>
