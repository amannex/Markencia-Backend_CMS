<?php
/**
 * Markencia Custom Theme functions and definitions
 */

// Enable support for Menus, Title Tags, and Custom Logo
add_theme_support('menus');
add_theme_support('title-tag'); // Essential for SEO (allows WordPress to generate <title>)
add_theme_support('custom-logo'); // Allows you to change the logo in Appearance > Customize

// Register Menus
register_nav_menus(array(
    'primary_menu' => __('Main Menu', 'markencia'),
    'primary_menu_mobile'=> __('Mobile Menu','markencia'),
));

/**
 * Enqueue scripts and styles.
 */
function markencia_assets()
{
    // Enqueue styles to the theme with dynamic versioning for cache-busting
    wp_register_style(
        'markencia-style', 
        get_theme_file_uri('css/styles.css'), 
        [], 
        filemtime(get_theme_file_path('css/styles.css')), // <--- Auto-updates version on save
        'all'
    );
    wp_enqueue_style('markencia-style');

    // Enqueue scripts
    wp_enqueue_script(
        'markencia-script', 
        get_theme_file_uri('js/scripts.js'), 
        [], 
        filemtime(get_theme_file_path('js/scripts.js')), 
        true // true loads the script in the footer
    );
}
add_action('wp_enqueue_scripts', 'markencia_assets');

// ============================================================
// HEADLESS WORDPRESS — REST API Extensions
// Added for the React + Vite headless frontend
// ============================================================

/**
 * Add CORS headers to allow the React frontend (localhost:5173) to
 * communicate with the WordPress REST API during development.
 * Update the allowed origin when deploying to production.
 */
add_action('init', function () {
    $allowed_origins = array(
        'http://localhost:3000',  // Next.js dev
        'http://localhost:3001',  // Next.js fallback port
        'http://127.0.0.1:3000',
        'http://localhost:5173',  // Vite dev (kept for compatibility)
        'http://127.0.0.1:5173',
    );

    $origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';

    if (in_array($origin, $allowed_origins, true)) {
        header("Access-Control-Allow-Origin: {$origin}");
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Authorization, Content-Type, X-WP-Nonce');
        header('Access-Control-Allow-Credentials: true');
    }

    if ('OPTIONS' === $_SERVER['REQUEST_METHOD']) {
        status_header(200);
        exit();
    }
});

/**
 * Register custom REST API routes for the headless frontend.
 * Namespace: markencia/v1
 */
add_action('rest_api_init', function () {
    // Contact form endpoint
    register_rest_route('markencia/v1', '/contact', array(
        'methods'             => 'POST',
        'callback'            => 'markencia_handle_contact_form',
        'permission_callback' => '__return_true',
        'args'                => array(
            'firstName' => array('required' => true, 'sanitize_callback' => 'sanitize_text_field'),
            'lastName'  => array('required' => true, 'sanitize_callback' => 'sanitize_text_field'),
            'email'     => array('required' => true, 'sanitize_callback' => 'sanitize_email'),
            'message'   => array('required' => true, 'sanitize_callback' => 'sanitize_textarea_field'),
        ),
    ));

    // Newsletter subscription endpoint
    register_rest_route('markencia/v1', '/newsletter', array(
        'methods'             => 'POST',
        'callback'            => 'markencia_handle_newsletter',
        'permission_callback' => '__return_true',
        'args'                => array(
            'email' => array('required' => true, 'sanitize_callback' => 'sanitize_email'),
        ),
    ));

    // ── Razorpay: Create Order ────────────────────────────────────────────────
    // Called by the React frontend when the user clicks a pricing plan button.
    // Returns a Razorpay order_id that the frontend uses to open the checkout modal.
    register_rest_route('markencia/v1', '/create-razorpay-order', array(
        'methods'             => 'POST',
        'callback'            => 'markencia_create_razorpay_order',
        'permission_callback' => '__return_true',
        'args'                => array(
            'amount'   => array('required' => true,  'validate_callback' => function($v) { return is_numeric($v); }),
            'currency' => array('required' => false, 'sanitize_callback' => 'sanitize_text_field', 'default' => 'INR'),
            'planName' => array('required' => true,  'sanitize_callback' => 'sanitize_text_field'),
        ),
    ));

    // ── Razorpay: Confirm Payment + Send Email ────────────────────────────────
    // Called by React after Razorpay modal closes with a successful payment.
    // Verifies the signature, then sends confirmation + admin notification emails.
    register_rest_route('markencia/v1', '/confirm-payment', array(
        'methods'             => 'POST',
        'callback'            => 'markencia_confirm_razorpay_payment',
        'permission_callback' => '__return_true',
        'args'                => array(
            'razorpay_payment_id' => array('required' => true,  'sanitize_callback' => 'sanitize_text_field'),
            'razorpay_order_id'   => array('required' => true,  'sanitize_callback' => 'sanitize_text_field'),
            'razorpay_signature'  => array('required' => true,  'sanitize_callback' => 'sanitize_text_field'),
            'planName'            => array('required' => true,  'sanitize_callback' => 'sanitize_text_field'),
            'customerEmail'       => array('required' => false, 'sanitize_callback' => 'sanitize_email'),
            'customerName'        => array('required' => false, 'sanitize_callback' => 'sanitize_text_field'),
        ),
    ));
    // ─────────────────────────────────────────────────────────────────────────
});

/**
 * Handle contact form submission — sends email to admin.
 */
function markencia_handle_contact_form(WP_REST_Request $request) {
    $first_name = $request->get_param('firstName');
    $last_name  = $request->get_param('lastName');
    $email      = $request->get_param('email');
    $company    = $request->get_param('company') ?: 'Not specified';
    $interest   = $request->get_param('interest') ?: 'General Inquiry';
    $message    = $request->get_param('message');

    $to      = get_option('admin_email');
    $subject = "New Contact Form Submission from {$first_name} {$last_name}";
    $body    = "Name: {$first_name} {$last_name}\n";
    $body   .= "Email: {$email}\n";
    $body   .= "Company: {$company}\n";
    $body   .= "Interest: {$interest}\n\n";
    $body   .= "Message:\n{$message}";
    $headers = array('Content-Type: text/plain; charset=UTF-8');

    $sent = wp_mail($to, $subject, $body, $headers);

    if ($sent) {
        return rest_ensure_response(array('success' => true, 'message' => 'Thank you! We\'ll be in touch within 24 hours.'));
    }

    return new WP_Error('mail_failed', 'Could not send the message. Please try again.', array('status' => 500));
}

/**
 * Handle newsletter subscription.
 */
function markencia_handle_newsletter(WP_REST_Request $request) {
    $email = $request->get_param('email');
    // TODO: Integrate with your email marketing platform (Mailchimp, etc.)
    // For now, we log the subscription to the database.
    $subscribers = get_option('markencia_newsletter_subscribers', array());
    if (!in_array($email, $subscribers, true)) {
        $subscribers[] = $email;
        update_option('markencia_newsletter_subscribers', $subscribers);
    }
    return rest_ensure_response(array('success' => true, 'message' => 'You\'re subscribed!'));
}

// ============================================================
// RAZORPAY PAYMENT INTEGRATION
// Keys are stored securely in wp-config.php — never here.
// ============================================================

/**
 * Create a Razorpay order via their Orders API.
 *
 * POST /wp-json/markencia/v1/create-razorpay-order
 * Body: { amount: 25000, currency: "INR", planName: "Starter" }
 *
 * Razorpay expects amount in paise (1 INR = 100 paise).
 * The React frontend sends the amount already in paise.
 *
 * Returns: { orderId, amount, currency, keyId }
 */
function markencia_create_razorpay_order(WP_REST_Request $request) {
    $amount   = (int) $request->get_param('amount');   // in paise e.g. 2500000 = ₹25,000
    $currency = $request->get_param('currency') ?: 'INR';
    $planName = $request->get_param('planName');

    $key_id     = defined('RAZORPAY_KEY_ID')     ? RAZORPAY_KEY_ID     : '';
    $key_secret = defined('RAZORPAY_KEY_SECRET') ? RAZORPAY_KEY_SECRET : '';

    if (empty($key_id) || empty($key_secret)) {
        return new WP_Error('razorpay_config', 'Razorpay keys are not configured.', array('status' => 500));
    }

    // Build a unique receipt ID for this order
    $receipt = 'mkn_' . sanitize_title($planName) . '_' . time();

    // Call the Razorpay Orders API using WordPress HTTP API (no SDK needed)
    $response = wp_remote_post('https://api.razorpay.com/v1/orders', array(
        'headers' => array(
            'Authorization' => 'Basic ' . base64_encode("{$key_id}:{$key_secret}"),
            'Content-Type'  => 'application/json',
        ),
        'body'    => wp_json_encode(array(
            'amount'          => $amount,
            'currency'        => $currency,
            'receipt'         => $receipt,
            'payment_capture' => 1, // auto-capture on success
        )),
        'timeout' => 15,
    ));

    // Handle HTTP-level errors
    if (is_wp_error($response)) {
        return new WP_Error('razorpay_request_failed', $response->get_error_message(), array('status' => 502));
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);
    $code = wp_remote_retrieve_response_code($response);

    // Handle Razorpay API-level errors
    if ($code !== 200 || empty($body['id'])) {
        $err_msg = isset($body['error']['description']) ? $body['error']['description'] : 'Unknown Razorpay error';
        return new WP_Error('razorpay_api_error', $err_msg, array('status' => $code));
    }

    // Return the order details to the React frontend
    return rest_ensure_response(array(
        'success'  => true,
        'orderId'  => $body['id'],
        'amount'   => $body['amount'],
        'currency' => $body['currency'],
        'keyId'    => $key_id, // safe to send — this is the publishable key
    ));
}

// ============================================================
// RAZORPAY: CONFIRM PAYMENT + SEND EMAILS
// ============================================================

/**
 * Verify a completed Razorpay payment and dispatch confirmation emails.
 *
 * POST /wp-json/markencia/v1/confirm-payment
 * Body: {
 *   razorpay_payment_id, razorpay_order_id, razorpay_signature,
 *   planName, customerEmail (optional), customerName (optional)
 * }
 *
 * Security: Uses HMAC-SHA256 signature verification — if the signature
 * doesn't match, the payment is rejected as tampered/fraudulent.
 */
function markencia_confirm_razorpay_payment(WP_REST_Request $request) {
    $payment_id    = $request->get_param('razorpay_payment_id');
    $order_id      = $request->get_param('razorpay_order_id');
    $signature     = $request->get_param('razorpay_signature');
    $plan_name     = $request->get_param('planName');
    $customer_email = $request->get_param('customerEmail') ?: '';
    $customer_name  = $request->get_param('customerName')  ?: 'Valued Customer';

    $key_secret = defined('RAZORPAY_KEY_SECRET') ? RAZORPAY_KEY_SECRET : '';

    if (empty($key_secret)) {
        return new WP_Error('razorpay_config', 'Payment gateway is not configured.', array('status' => 500));
    }

    // ── Step 1: Verify the payment signature ─────────────────────────────────
    // Razorpay signs: SHA256( order_id + "|" + payment_id ) with the key secret.
    // If this doesn't match, someone tampered with the request — reject it.
    $generated_signature = hash_hmac(
        'sha256',
        $order_id . '|' . $payment_id,
        $key_secret
    );

    if (!hash_equals($generated_signature, $signature)) {
        return new WP_Error(
            'invalid_signature',
            'Payment signature verification failed. This request may be fraudulent.',
            array('status' => 400)
        );
    }

    // ── Step 2: Map plan name to its display price ────────────────────────────
    $plan_prices = array(
        'Starter'    => '₹25,000/month',
        'Growth'     => '₹60,000/month',
        'Enterprise' => 'Custom Pricing',
    );
    $plan_price = isset($plan_prices[$plan_name]) ? $plan_prices[$plan_name] : 'Custom';

    $admin_email = get_option('admin_email');
    $site_name   = get_bloginfo('name');
    $headers     = array('Content-Type: text/html; charset=UTF-8');

    // ── Step 3a: Customer confirmation email ─────────────────────────────────
    if (!empty($customer_email)) {
        $customer_subject = "🎉 Payment Confirmed — Welcome to Markencia {$plan_name} Plan!";
        $customer_body = "
        <html><body style='font-family: Arial, sans-serif; color: #1a1a2e; line-height: 1.6;'>
            <div style='max-width:600px; margin:0 auto; padding:30px;'>
                <h2 style='color:#7C3AED;'>Welcome to Markencia! 🚀</h2>
                <p>Hi <strong>{$customer_name}</strong>,</p>
                <p>Your payment has been successfully received. Here's a summary:</p>
                <table style='width:100%; border-collapse:collapse; margin:20px 0;'>
                    <tr style='background:#f5f0ff;'>
                        <td style='padding:10px; font-weight:bold;'>Plan</td>
                        <td style='padding:10px;'>{$plan_name}</td>
                    </tr>
                    <tr>
                        <td style='padding:10px; font-weight:bold;'>Price</td>
                        <td style='padding:10px;'>{$plan_price}</td>
                    </tr>
                    <tr style='background:#f5f0ff;'>
                        <td style='padding:10px; font-weight:bold;'>Payment ID</td>
                        <td style='padding:10px;'><code>{$payment_id}</code></td>
                    </tr>
                    <tr>
                        <td style='padding:10px; font-weight:bold;'>Order ID</td>
                        <td style='padding:10px;'><code>{$order_id}</code></td>
                    </tr>
                </table>
                <p>Our team will reach out within <strong>24 hours</strong> to onboard you and get started.</p>
                <p>In the meantime, feel free to reach us at <a href='mailto:{$admin_email}'>{$admin_email}</a>.</p>
                <hr style='border:1px solid #eee; margin:30px 0;'>
                <p style='color:#888; font-size:12px;'>
                    This is an automated confirmation from {$site_name}.<br>
                    Please keep your Payment ID for your records.
                </p>
            </div>
        </body></html>";

        wp_mail($customer_email, $customer_subject, $customer_body, $headers);
    }

    // ── Step 3b: Admin sale notification email ────────────────────────────────
    $admin_subject = "💰 New Payment Received — {$plan_name} Plan";
    $admin_body = "
    <html><body style='font-family: Arial, sans-serif; color: #1a1a2e;'>
        <div style='max-width:600px; margin:0 auto; padding:30px;'>
            <h2 style='color:#7C3AED;'>New Sale Alert! 🎉</h2>
            <table style='width:100%; border-collapse:collapse;'>
                <tr style='background:#f5f0ff;'>
                    <td style='padding:10px; font-weight:bold;'>Customer</td>
                    <td style='padding:10px;'>{$customer_name}" . (!empty($customer_email) ? " &lt;{$customer_email}&gt;" : '') . "</td>
                </tr>
                <tr>
                    <td style='padding:10px; font-weight:bold;'>Plan Purchased</td>
                    <td style='padding:10px;'>{$plan_name} — {$plan_price}</td>
                </tr>
                <tr style='background:#f5f0ff;'>
                    <td style='padding:10px; font-weight:bold;'>Payment ID</td>
                    <td style='padding:10px;'>{$payment_id}</td>
                </tr>
                <tr>
                    <td style='padding:10px; font-weight:bold;'>Order ID</td>
                    <td style='padding:10px;'>{$order_id}</td>
                </tr>
            </table>
            <p><a href='https://dashboard.razorpay.com/app/payments/{$payment_id}' style='background:#7C3AED; color:white; padding:10px 20px; text-decoration:none; border-radius:5px;'>View in Razorpay Dashboard →</a></p>
        </div>
    </body></html>";

    wp_mail($admin_email, $admin_subject, $admin_body, $headers);

    return rest_ensure_response(array(
        'success' => true,
        'message' => 'Payment verified and confirmation email sent.',
    ));
}

// ============================================================
// HEADLESS ROUTING & ADMIN BAR INTEGRATION
// ============================================================

// 1. Redirect any frontend visitors on cms.markencia.com to the live Next.js website
add_action('template_redirect', function () {
    if (
        !is_admin() &&
        isset($_SERVER['HTTP_HOST']) &&
        $_SERVER['HTTP_HOST'] === 'cms.markencia.com'
    ) {
        wp_redirect('https://markencia.com', 301);
        exit;
    }
});

// 2. Make the Top Bar "Markencia" Button & "Visit Site" open the live Next.js website in a new tab
add_action('admin_bar_menu', function ($wp_admin_bar) {
    $frontend_url = 'https://markencia.com';

    // Update the main site title link
    $site_name = $wp_admin_bar->get_node('site-name');
    if ($site_name) {
        $site_name->href = $frontend_url;
        $site_name->meta['target'] = '_blank'; // Opens live website in a new tab
        $wp_admin_bar->add_node($site_name);
    }

    // Update the "Visit Site" dropdown link
    $view_site = $wp_admin_bar->get_node('view-site');
    if ($view_site) {
        $view_site->href = $frontend_url;
        $view_site->meta['target'] = '_blank';
        $wp_admin_bar->add_node($view_site);
    }
}, 999);


