<?php
/**
 * Plugin Name: Stripe Hosted Payment by Z
 * Description: Accept payments through Stripe
 * Version: 1.0.1
 * Author: Z. Islam
 * Text Domain: stripe-hosted-payment-by-z
 */

if (!defined('ABSPATH')) {
    exit;
}

// Make sure WooCommerce is active
if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    return;
}

// Include the Stripe PHP SDK (using Composer)
require_once plugin_dir_path(__FILE__) . 'vendor/autoload.php';

// Include main gateway class
require_once plugin_dir_path(__FILE__) . 'includes/class-stripe-gateway.php';

// Add the gateway to WooCommerce
add_filter('woocommerce_payment_gateways', 'add_stripe_gateway');
function add_stripe_gateway($gateways) {
    $gateways[] = 'WC_Stripe_Gateway';
    return $gateways;
}

// Add at the start of your main plugin file
if (!defined('STRIPE_PLUGIN_VERSION')) {
    define('STRIPE_PLUGIN_VERSION', '1.0.1');
}

// Add error logging
function stripe_log($message) {
    if (WP_DEBUG === true) {
        if (is_array($message) || is_object($message)) {
            error_log(print_r($message, true));
        } else {
            error_log($message);
        }
    }
}

// Add update checker
require_once plugin_dir_path(__FILE__) . 'vendor/autoload.php';

function init_stripe_plugin_updater() {
    // Check if Composer autoload exists
    if (class_exists('Puc_v4_Factory')) {
        $updater = Puc_v4_Factory::buildUpdateChecker(
            'https://github.com/zahidtwt/stripe-hosted-payment',
            __FILE__,
            'stripe-hosted-payment-by-z'
        );

        // Optional: Set branch name (default is 'master' or 'main')
        $updater->setBranch('main');

        // Optional: If using private repository
        // $updater->setAuthentication('your-access-token');
    }
}
add_action('init', 'init_stripe_plugin_updater');