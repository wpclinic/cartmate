<?php
/**
 * Core engine for CartMate
 *
 * Handles initialization, abandoned cart processing, and triggering
 * email/SMS follow-ups via WP-Cron.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CartMate_Core {

    /**
     * Initialise plugin core.
     * Called from cartmate_bootstrap() after plugins_loaded.
     */
    public static function init() {
        // Any shared setup logic goes here.
        // Logging for debugging:
        error_log( 'Cart Mate Core: init() called.' );
    }

    /**
     * Cron handler: processes abandoned carts and triggers email + SMS follow-ups.
     * This method is called by WP-Cron via the 'cartmate_process_abandoned_carts' event.
     */
    public static function process_abandoned_carts() {
        error_log( 'Cart Mate Core: process_abandoned_carts() fired.' );

        /**
         * ---------------------------------------------------------
         *  EMAIL FOLLOW-UPS  (FREE + PRO)
         * ---------------------------------------------------------
         */
        if ( class_exists( 'CartMate_Email' ) ) {

            // Preferred naming in modern builds.
            if ( method_exists( 'CartMate_Email', 'process_followups' ) ) {
                error_log( 'Cart Mate Core: calling CartMate_Email::process_followups().' );
                CartMate_Email::process_followups();

            // Backwards compatibility for older builds.
            } elseif ( method_exists( 'CartMate_Email', 'process_abandoned_carts' ) ) {
                error_log( 'Cart Mate Core: calling CartMate_Email::process_abandoned_carts().' );
                CartMate_Email::process_abandoned_carts();

            } else {
                error_log( 'Cart Mate Core: CartMate_Email class loaded, but no processor method found.' );
            }

        } else {
            error_log( 'Cart Mate Core: CartMate_Email class not found.' );
        }


        /**
         * ---------------------------------------------------------
         *  SMS FOLLOW-UPS (PRO ONLY)
         * ---------------------------------------------------------
         */
        $is_pro = function_exists( 'cartmate_is_pro' ) ? cartmate_is_pro() : true;

        if ( $is_pro ) {

            if ( class_exists( 'CartMate_SMS' ) ) {

                if ( method_exists( 'CartMate_SMS', 'process_followups' ) ) {
                    error_log( 'Cart Mate Core: calling CartMate_SMS::process_followups().' );
                    CartMate_SMS::process_followups();

                } elseif ( method_exists( 'CartMate_SMS', 'process_abandoned_carts' ) ) {
                    error_log( 'Cart Mate Core: calling CartMate_SMS::process_abandoned_carts().' );
                    CartMate_SMS::process_abandoned_carts();

                } else {
                    error_log( 'Cart Mate Core: CartMate_SMS class loaded, but no processor method found.' );
                }

            } else {
                error_log( 'Cart Mate Core: CartMate_SMS class not found.' );
            }

        } else {
            error_log( 'Cart Mate Core: SMS follow-ups skipped (not Pro).' );
        }
    }
}
