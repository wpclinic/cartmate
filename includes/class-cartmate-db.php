<?php
/**
 * CartMate - Database schema (install + versioned upgrades)
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'CartMate_DB' ) ) :

class CartMate_DB {

    const DB_VERSION = '1.0.0';
    const OPTION_KEY = 'cartmate_db_version';

    /**
     * Install / ensure schema exists (idempotent).
     */
    public static function install() {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();

        $t_carts      = $wpdb->prefix . 'cartmate_carts';
        $t_abandoned  = $wpdb->prefix . 'cartmate_abandoned_carts';
        $t_sequences  = $wpdb->prefix . 'cartmate_email_sequences';

        /**
         * 1) cartmate_carts
         * Stores contact details + messaging state (email/sms steps) for a cart identity.
         */
        $sql_carts = "CREATE TABLE {$t_carts} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            cart_key VARCHAR(128) NOT NULL DEFAULT '',
            session_key VARCHAR(128) NOT NULL DEFAULT '',
            user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,

            contact_name VARCHAR(190) NOT NULL DEFAULT '',
            contact_email VARCHAR(190) NOT NULL DEFAULT '',
            contact_phone VARCHAR(64) NOT NULL DEFAULT '',

            email_opt_in TINYINT(1) NOT NULL DEFAULT 0,
            sms_opt_in   TINYINT(1) NOT NULL DEFAULT 0,
            whatsapp_opt_in TINYINT(1) NOT NULL DEFAULT 0,

            recovered TINYINT(1) NOT NULL DEFAULT 0,

            abandoned_at BIGINT(20) NOT NULL DEFAULT 0,

            email_first_sent_at BIGINT(20) NOT NULL DEFAULT 0,
            sms_first_sent_at   BIGINT(20) NOT NULL DEFAULT 0,

            email_step_sent INT(11) NOT NULL DEFAULT 0,

            created_at BIGINT(20) NOT NULL DEFAULT 0,
            updated_at BIGINT(20) NOT NULL DEFAULT 0,

            PRIMARY KEY  (id),
            KEY cart_key (cart_key),
            KEY session_key (session_key),
            KEY contact_email (contact_email),
            KEY abandoned_at (abandoned_at),
            KEY recovered (recovered),
            KEY email_step_sent (email_step_sent)
        ) {$charset_collate};";

        /**
         * 2) cartmate_abandoned_carts
         * Stores cart contents snapshots (optional, but useful for future "cart restore" links and analytics).
         */
        $sql_abandoned = "CREATE TABLE {$t_abandoned} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            cart_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,

            cart_hash VARCHAR(64) NOT NULL DEFAULT '',
            cart_contents LONGTEXT NULL,
            cart_totals LONGTEXT NULL,
            currency VARCHAR(12) NOT NULL DEFAULT '',

            created_at BIGINT(20) NOT NULL DEFAULT 0,
            updated_at BIGINT(20) NOT NULL DEFAULT 0,

            PRIMARY KEY (id),
            KEY cart_id (cart_id),
            KEY cart_hash (cart_hash)
        ) {$charset_collate};";

        /**
         * 3) cartmate_email_sequences
         * Stores user-defined scheduled followup emails (editable list).
         */
        $sql_sequences = "CREATE TABLE {$t_sequences} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,

            is_enabled TINYINT(1) NOT NULL DEFAULT 1,

            delay_days INT(11) NOT NULL DEFAULT 1,

            email_subject VARCHAR(255) NOT NULL DEFAULT '',
            email_body LONGTEXT NULL,

            sort_order INT(11) NOT NULL DEFAULT 0,

            created_at BIGINT(20) NOT NULL DEFAULT 0,
            updated_at BIGINT(20) NOT NULL DEFAULT 0,

            PRIMARY KEY (id),
            KEY is_enabled (is_enabled),
            KEY delay_days (delay_days),
            KEY sort_order (sort_order)
        ) {$charset_collate};";

        // Create/upgrade tables
        dbDelta( $sql_carts );
        dbDelta( $sql_abandoned );
        dbDelta( $sql_sequences );

        // Lock schema version
        update_option( self::OPTION_KEY, self::DB_VERSION );

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( 'Cart Mate DB: install() executed for tables: ' . $t_carts . ', ' . $t_abandoned . ', ' . $t_sequences );
        }
    }

    /**
     * Run schema upgrades when DB_VERSION increases.
     * Keep this intentionally conservative: add columns/indexes only.
     */
    public static function maybe_upgrade() {
        $installed = get_option( self::OPTION_KEY, '' );

        if ( empty( $installed ) ) {
            // Fresh or legacy site without version marker -> ensure everything exists.
            self::install();
            return;
        }

        if ( version_compare( $installed, self::DB_VERSION, '>=' ) ) {
            return;
        }

        // If we ever bump DB_VERSION, add controlled upgrade steps here.
        // Example:
        // if ( version_compare( $installed, '1.0.1', '<' ) ) { self::upgrade_to_101(); }

        // After upgrade steps:
        update_option( self::OPTION_KEY, self::DB_VERSION );
    }
}

endif;
