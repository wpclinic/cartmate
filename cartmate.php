<?php
/**
 * Plugin Name: WPCartMate
 * Description: Email & SMS recovery for WooCommerce abandoned carts.
 * Version: 1.0.0
 * Author: Hustlemate
 * Text Domain: cartmate
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Basic constants.
 */
define( 'CARTMATE_VERSION', '1.0.0' );
define( 'CARTMATE_PLUGIN_FILE', __FILE__ );
define( 'CARTMATE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'CARTMATE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Includes (DB first so activation + upgrades always work).
 */
require_once CARTMATE_PLUGIN_DIR . 'includes/class-cartmate-db.php';
require_once __DIR__ . '/includes/class-cartmate-capture.php';


/**
 * Your existing includes may differ. Keep these paths aligned with YOUR plugin.
 * If any file name differs, update the require_once path (do not partially paste).
 */
 // Emailer (contains send_recovery_email + wp_mail logic).
if ( file_exists( CARTMATE_PLUGIN_DIR . 'includes/class-cartmate-emailer.php' ) ) {
    require_once CARTMATE_PLUGIN_DIR . 'includes/class-cartmate-emailer.php';
}
if ( file_exists( CARTMATE_PLUGIN_DIR . 'includes/class-cartmate-core.php' ) ) {
    require_once CARTMATE_PLUGIN_DIR . 'includes/class-cartmate-core.php';
}

if ( file_exists( CARTMATE_PLUGIN_DIR . 'includes/class-cartmate-email.php' ) ) {
    require_once CARTMATE_PLUGIN_DIR . 'includes/class-cartmate-email.php';
}

if ( file_exists( CARTMATE_PLUGIN_DIR . 'includes/class-cartmate-sms.php' ) ) {
    require_once CARTMATE_PLUGIN_DIR . 'includes/class-cartmate-sms.php';
}

if ( file_exists( CARTMATE_PLUGIN_DIR . 'includes/class-cartmate-cron.php' ) ) {
    require_once CARTMATE_PLUGIN_DIR . 'includes/class-cartmate-cron.php';
}

if ( file_exists( CARTMATE_PLUGIN_DIR . 'includes/admin/class-cartmate-admin.php' ) ) {
    require_once CARTMATE_PLUGIN_DIR . 'includes/admin/class-cartmate-admin.php';
}
if ( file_exists( CARTMATE_PLUGIN_DIR . 'includes/class-cartmate-cron.php' ) ) {
    require_once CARTMATE_PLUGIN_DIR . 'includes/class-cartmate-cron.php';
}
add_action( 'plugins_loaded', function () {
    if ( class_exists( 'CartMate_Cron' ) ) {
        static $cartmate_cron = null;
        if ( null === $cartmate_cron ) {
            $cartmate_cron = new CartMate_Cron();
        }
    }
} );

/**
 * Optional: plugin-local capture helpers file (you told me you have it in the plugin root).
 */
if ( file_exists( CARTMATE_PLUGIN_DIR . 'cartmate-functions.php' ) ) {
    require_once CARTMATE_PLUGIN_DIR . 'cartmate-functions.php';
}

/**
 * Activation hook: ensure schema is installed and cron is scheduled by core (if applicable).
 */
function cartmate_activate() {
    if ( class_exists( 'CartMate_DB' ) ) {
        CartMate_DB::install();
    }

    // If your core schedules cron, we don't do it here.
    // If not, you can schedule your processing event here as a fallback.
    if ( ! wp_next_scheduled( 'cartmate_process_abandoned_carts' ) ) {
        wp_schedule_event( time() + 120, 'minute', 'cartmate_process_abandoned_carts' );
    }

    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
        error_log( 'Cart Mate: activation complete.' );
    }
}
register_activation_hook( __FILE__, 'cartmate_activate' );

/**
 * Deactivation hook: clear cron.
 */
function cartmate_deactivate() {
    $ts = wp_next_scheduled( 'cartmate_process_abandoned_carts' );
    while ( $ts ) {
        wp_unschedule_event( $ts, 'cartmate_process_abandoned_carts' );
        $ts = wp_next_scheduled( 'cartmate_process_abandoned_carts' );
    }

    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
        error_log( 'Cart Mate: deactivation complete (cron cleared).' );
    }
}
register_deactivation_hook( __FILE__, 'cartmate_deactivate' );

/**
 * Ensure DB upgrades run early.
 */
add_action( 'plugins_loaded', function() {
    if ( class_exists( 'CartMate_DB' ) ) {
        CartMate_DB::maybe_upgrade();
    }
}, 5 );

/**
 * Bootstrap plugin runtime.
 */
function cartmate_bootstrap() {

    // Admin UI
    if ( is_admin() && class_exists( 'CartMate_Admin' ) ) {
        // Your admin class uses static init() (as per the file you pasted).
        CartMate_Admin::init();
    }

    // Core runtime
    if ( class_exists( 'CartMate_Core' ) ) {
        CartMate_Core::init();
    }
    if ( class_exists( 'CartMate_Capture' ) ) {
	CartMate_Capture::init();
}
}
add_action( 'plugins_loaded', 'cartmate_bootstrap', 20 );