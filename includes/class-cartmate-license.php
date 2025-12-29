<?php
/**
 * Cart Mate - License handling (Free vs Pro).
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'CartMate_License' ) ) :

class CartMate_License {

    const OPTION_KEY   = 'cartmate_license_key';
    const OPTION_STATE = 'cartmate_license_status'; // 'valid' or 'invalid'

    /**
     * Bootstrap licensing.
     */
    public static function init() {
        // Filter to ask "is this Pro?" anywhere in the plugin.
        add_filter( 'cartmate_is_pro', array( __CLASS__, 'filter_is_pro' ) );
    }

    /**
     * Return true if plugin should be treated as Pro.
     *
     * For now: any non-empty license key is "valid".
     * Later: replace with remote API check if you want.
     *
     * @param bool $is_pro
     * @return bool
     */
    public static function filter_is_pro( $is_pro ) {
        $key   = trim( get_option( self::OPTION_KEY, '' ) );
        $state = get_option( self::OPTION_STATE, '' );

        if ( ! empty( $key ) && $state === 'valid' ) {
            return true;
        }

        return false;
    }

    /**
     * Get the current license key (masked optionally).
     *
     * @param bool $masked
     * @return string
     */
    public static function get_license_key( $masked = false ) {
        $key = trim( get_option( self::OPTION_KEY, '' ) );

        if ( ! $masked || $key === '' ) {
            return $key;
        }

        // Mask all but last 4 chars for display.
        $len = strlen( $key );
        if ( $len <= 4 ) {
            return str_repeat( '*', $len );
        }

        return str_repeat( '*', $len - 4 ) . substr( $key, -4 );
    }

    /**
     * Save license key and mark as valid/invalid.
     *
     * For now:
     * - Any non-empty key -> valid.
     * - Empty key -> invalid.
     *
     * @param string $key
     * @return bool True if treated as "valid".
     */
    public static function save_license( $key ) {
        $key = trim( (string) $key );

        if ( $key === '' ) {
            delete_option( self::OPTION_KEY );
            update_option( self::OPTION_STATE, 'invalid' );
            return false;
        }

        // TODO: call external API to verify license if desired.
        // For now, treat any non-empty key as valid.
        update_option( self::OPTION_KEY, $key );
        update_option( self::OPTION_STATE, 'valid' );

        return true;
    }

    /**
     * Clear license (back to Free mode).
     */
    public static function clear_license() {
        delete_option( self::OPTION_KEY );
        update_option( self::OPTION_STATE, 'invalid' );
    }
}

endif;
