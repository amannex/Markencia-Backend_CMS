<?php

// add theme support
add_theme_support( 'post-thumbnails' );

add_action('rest_api_init', function () {
    remove_filter('rest_pre_serve_request', 'rest_send_cors_headers');
    add_filter('rest_pre_serve_request', function ($value) {
        header('Access-Control-Allow-Origin: *'); // For staging; replace '*' with your specific React URL in production
        header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
        header('Access-Control-Allow-Headers: Origin, Content-Type, Authorization');
        return $value;
    });
}, 15);

/**
 * Override Contact Form 7 REST API pattern to support alphanumeric IDs
 */
add_action('rest_api_init', function () {
    // Re-register the feedback route path to accept characters and words (\w+) instead of only digits (\d+)
    register_rest_route('contact-form-7/v1', '/contact-forms/(?P<id>[a-zA-w0-9]+)/feedback', array(
        'methods' => 'POST',
        'callback' => function($request) {
            // Forward processing directly to the native plugin handler instance
            if (class_exists('WPCF7_RESTRoutes')) {
                return wpcf7_rest_service_feedback($request);
            }
            return new WP_Error('plugin_missing', 'Contact Form 7 controller missing', array('status' => 500));
        },
        'permission_callback' => '__return_true'
    ));
}, 10);


/**
 * Register a custom Headless API Endpoint for Markencia Payments
 */
add_action('rest_api_init', function () {
    register_rest_route('markencia/v1', '/create-checkout', array(
        'methods'             => 'POST',
        'callback'            => 'handle_markencia_stripe_checkout',
        'permission_callback' => '__return_true', // Open to public checkout requests
    ));
});

function handle_markencia_stripe_checkout($request) {
    // 1. Get the payload sent from the React app
    $params   = $request->get_json_params();
    $price_id = sanitize_text_field($params['priceId']);

    if (empty($price_id)) {
        return new WP_Error('missing_data', 'Stripe Price ID is required.', array('status' => 400));
    }

    // 2. Set your Stripe API configuration values
    $stripe_secret_key = 'sk_test_YOUR_STRIPE_SECRET_KEY_HERE'; // ⚠️ REPLACE THIS WITH YOUR STRIPE SECRET KEY
    $stripe_api_url    = 'https://api.stripe.com/v1/checkout/sessions';

    // 3. Prepare the checkout transaction details
    $checkout_data = array(
        'payment_method_types' => array('card'),
        'line_items' => array(
            array(
                'price'    => $price_id,
                'quantity' => 1,
            )
        ),
        'mode'        => 'subscription', // Because these are monthly recurring plans
        'success_url' => 'http://localhost:5173/?session_id={CHECKOUT_SESSION_ID}',
        'cancel_url'  => 'http://localhost:5173',
    );

    // 4. Fire the secure server-to-server API request directly to Stripe
    $response = wp_remote_post($stripe_api_url, array(
        'headers' => array(
            'Authorization' => 'Bearer ' . $stripe_secret_key,
            'Content-Type'  => 'application/x-www-form-urlencoded',
        ),
        'body' => http_build_query($checkout_data),
    ));

    // 5. Handle unexpected server or network drops gracefully
    if (is_wp_error($response)) {
        return new WP_Error('stripe_fail', 'Could not establish connection with Stripe.', array('status' => 500));
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);

    // 6. Send the generated secure checkout URL back to your React app
    return array(
        'checkoutUrl' => isset($body['url']) ? $body['url'] : ''
    );
}

/**
 * ============================================================================
 * 1. Register 'FAQ' Custom Post Type for Headless React App
 * ============================================================================
 */
add_action('init', function() {
    register_post_type('faq', array(
        'labels' => array(
            'name'          => 'FAQs',
            'singular_name' => 'FAQ',
            'add_new_item'  => 'Add New FAQ',
            'edit_item'     => 'Edit FAQ',
            'menu_name'     => 'FAQs',
        ),
        'public'       => true,
        'has_archive'  => false,
        'menu_icon'    => 'dashicons-editor-help', // WordPress help/question icon
        'supports'     => array('title'), // The title box will be the FAQ QUESTION
        
        // ⚠️ CRITICAL FOR HEADLESS: Enables REST API at /wp-json/wp/v2/faqs
        'show_in_rest' => true,
        'rest_base'    => 'faqs',
    ));
});

/**
 * ============================================================================
 * 2. Expose Author Social Links in REST API ('user' endpoint & embedded author)
 * ============================================================================
 */
add_action('rest_api_init', function () {
    // Expose LinkedIn URL
    register_rest_field('user', 'linkedin', array(
        'get_callback' => function ($user) {
            return get_user_meta($user['id'], 'linkedin', true) ?: '';
        },
        'schema' => null,
    ));

    // Expose Twitter / X handle
    register_rest_field('user', 'twitter', array(
        'get_callback' => function ($user) {
            return get_user_meta($user['id'], 'twitter', true) ?: '';
        },
        'schema' => null,
    ));

    // Expose Website / URL
    register_rest_field('user', 'website', array(
        'get_callback' => function ($user) {
            return get_the_author_meta('user_url', $user['id']) ?: '';
        },
        'schema' => null,
    ));
});

/**
 * ============================================================================
 * 3. Allow Anonymous / Guest Comment Submissions via Headless REST API
 * ============================================================================
 */
add_filter('rest_allow_anonymous_comments', '__return_true');






