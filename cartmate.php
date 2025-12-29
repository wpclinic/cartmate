<?php
/**
 * Plugin Name: CartMate
 * Description: Shopping cart management plugin for WooCommerce
 * Version: 1.0.0
 * Author: WP Clinic
 * Text Domain: cartmate
 * Domain Path: /languages
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('CARTMATE_VERSION', '1.0.0');
define('CARTMATE_DIR', plugin_dir_path(__FILE__));
define('CARTMATE_URL', plugin_dir_url(__FILE__));
define('CARTMATE_BASENAME', plugin_basename(__FILE__));

// Include required files (no duplicates)
require_once CARTMATE_DIR . 'includes/class-cartmate-admin.php';
require_once CARTMATE_DIR . 'includes/class-cartmate-frontend.php';
require_once CARTMATE_DIR . 'includes/class-cartmate-cron.php';
require_once CARTMATE_DIR . 'includes/functions.php';

/**
 * Initialize CartMate Plugin
 */
function cartmate_init() {
    // Load text domain for translations
    load_plugin_textdomain('cartmate', false, dirname(CARTMATE_BASENAME) . '/languages');
    
    // Initialize admin functionality
    if (is_admin()) {
        new CartMate_Admin();
    }
    
    // Initialize frontend functionality
    new CartMate_Frontend();
    
    // Initialize cron scheduler
    new CartMate_Cron();
}

add_action('plugins_loaded', 'cartmate_init');

/**
 * Activation Hook
 */
function cartmate_activate() {
    // Schedule cron event on activation
    if (!wp_next_scheduled('cartmate_daily_cleanup')) {
        wp_schedule_event(time(), 'daily', 'cartmate_daily_cleanup');
    }
    
    // Flush rewrite rules
    flush_rewrite_rules();
}

register_activation_hook(__FILE__, 'cartmate_activate');

/**
 * Deactivation Hook
 */
function cartmate_deactivate() {
    // Clear scheduled cron events
    $timestamp = wp_next_scheduled('cartmate_daily_cleanup');
    if ($timestamp) {
        wp_unschedule_event($timestamp, 'cartmate_daily_cleanup');
    }
    
    // Flush rewrite rules
    flush_rewrite_rules();
}

register_deactivation_hook(__FILE__, 'cartmate_deactivate');

/**
 * Handle cron event
 */
add_action('cartmate_daily_cleanup', 'cartmate_handle_daily_cleanup');

function cartmate_handle_daily_cleanup() {
    // Call the cron handler from the CartMate_Cron class
    CartMate_Cron::handle_daily_cleanup();
}
