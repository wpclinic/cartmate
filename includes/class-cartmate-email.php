<?php
/**
 * WPCartMate - Email sending & scheduled follow-ups for abandoned carts.
 *
 * Strategy:
 * - Email #1: stored in options (existing simple email config)
 * - Follow-ups: stored in DB table {prefix}cartmate_email_sequences
 *   Each row has: enabled, delay_days, subject, body, sort_order
 *
 * Tracking:
 * - carts table has:
 *   - email_first_sent_at (timestamp)
 *   - email_step_sent (INT) 0..6 (1 = email#1 sent, 2..6 follow-ups)
 *
 * Notes:
 * - This file only reads/writes the carts table and the sequences table.
 * - It does NOT depend on abandoned_carts table (legacy).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'CartMate_Email' ) ) :

class CartMate_Email {

    /**
     * Main cron entry point.
     */
    public static function process_followups() {
        global $wpdb;

        $table_carts = $wpdb->prefix . 'cartmate_carts';
        $table_seq   = $wpdb->prefix . 'cartmate_email_sequences';
        $now         = time();

        // Guard: carts table must exist.
        if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table_carts ) ) !== $table_carts ) {
            self::log( '[CartMate_Email] carts table missing: ' . $table_carts );
            return;
        }

        // Ensure column email_step_sent exists (failsafe in case install missed it).
        self::maybe_ensure_email_step_column( $table_carts );

        // Pull follow-up sequences (enabled only), ordered.
        $sequences = array();
        if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table_seq ) ) === $table_seq ) {
            $sequences = $wpdb->get_results(
                "SELECT id, enabled, delay_days, subject, body, sort_order
                 FROM {$table_seq}
                 WHERE enabled = 1
                 ORDER BY sort_order ASC, id ASC",
                ARRAY_A
            );
        } else {
            // Table missing is OK (free users / older versions) - just do email #1.
            self::log( '[CartMate_Email] sequences table missing (OK): ' . $table_seq );
        }

        /**
         * EMAIL #1 (options-based)
         * delay in minutes since abandonment
         */
        self::process_email_1( $table_carts, $now );

        /**
         * FOLLOW-UPS: up to 5 (email steps 2..6)
         *
         * Each follow-up is scheduled as:
         * - must have email_first_sent_at > 0
         * - email_step_sent must be >= previous step
         * - now must be >= email_first_sent_at + (delay_days * DAY_IN_SECONDS)
         */
        if ( ! empty( $sequences ) ) {
            self::process_scheduled_followups( $table_carts, $sequences, $now );
        }
    }

    /**
     * Process first email (step=1). Uses existing admin options.
     */
    protected static function process_email_1( $table_carts, $now ) {
        global $wpdb;

        $enabled = (int) get_option( 'cartmate_email_1_enabled', 1 );
        if ( $enabled !== 1 ) {
            self::log( '[CartMate_Email] Email #1 disabled by setting.' );
            return;
        }

        $delay_minutes = (int) get_option( 'cartmate_email_1_delay_minutes', 30 );
        if ( $delay_minutes < 0 ) $delay_minutes = 0;

        $cutoff = $now - ( $delay_minutes * MINUTE_IN_SECONDS );

        self::log( sprintf(
            '[CartMate_Email] Email #1: enabled=1 delay=%d min cutoff=%d (%s)',
            $delay_minutes, $cutoff, gmdate('Y-m-d H:i:s', $cutoff)
        ) );

        // Eligible carts:
        // - not recovered
        // - opted in
        // - email present
        // - abandoned_at <= cutoff
        // - email_first_sent_at is null/0
        $eligible = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT *
                 FROM {$table_carts}
                 WHERE recovered = 0
                   AND email_opt_in = 1
                   AND contact_email <> ''
                   AND abandoned_at > 0
                   AND abandoned_at <= %d
                   AND (email_first_sent_at IS NULL OR email_first_sent_at = 0)
                 ORDER BY id ASC
                 LIMIT 50",
                $cutoff
            )
        );

        self::log( '[CartMate_Email] Email #1: eligible carts=' . ( is_array($eligible) ? count($eligible) : 0 ) );

        if ( empty( $eligible ) ) return;

        foreach ( $eligible as $cart_row ) {

            $tokens = self::build_tokens( $cart_row );

            $subject = get_option( 'cartmate_email_1_subject', 'We saved your cart at {site_name}' );
            $body    = get_option( 'cartmate_email_1_body', "Hi {name},\n\nYou left items in your cart at {site_name}.\n\nFinish here:\n{cart_url}" );

            $sent = self::send_email( $cart_row->contact_email, $subject, $body, $tokens );

            if ( is_wp_error( $sent ) ) {
                self::log( '[CartMate_Email] Email #1 FAILED for cart ID ' . $cart_row->id . ': ' . $sent->get_error_message() );
                continue;
            }

            // Mark email #1 sent.
            $wpdb->update(
                $table_carts,
                array(
                    'email_first_sent_at' => $now,
                    'email_step_sent'     => 1,
                    'updated_at'          => $now,
                ),
                array( 'id' => (int) $cart_row->id ),
                array( '%d', '%d', '%d' ),
                array( '%d' )
            );

            self::log( '[CartMate_Email] Email #1 sent + marked email_step_sent=1 for cart ID ' . $cart_row->id );
        }
    }

    /**
     * Process DB-driven scheduled follow-ups (steps 2..6).
     *
     * @param array $sequences enabled sequences rows (up to any number, we cap to 5)
     */
    protected static function process_scheduled_followups( $table_carts, $sequences, $now ) {
        global $wpdb;

        // Cap at 5 follow-ups.
        $sequences = array_values( $sequences );
        $sequences = array_slice( $sequences, 0, 5 );

        // Steps 2..6
        $step = 2;

        foreach ( $sequences as $seq ) {

            $delay_days = isset($seq['delay_days']) ? (int)$seq['delay_days'] : 1;
            if ( $delay_days < 0 ) $delay_days = 0;

            $required_prev = $step - 1;
            $cutoff        = $now - ( $delay_days * DAY_IN_SECONDS );

            self::log( sprintf(
                '[CartMate_Email] Email #%d: required_prev=%d delay=%d days cutoff=%d (%s)',
                $step, $required_prev, $delay_days, $cutoff, gmdate('Y-m-d H:i:s', $cutoff)
            ) );

            // We schedule from email_first_sent_at. So cutoff compares against first_sent_at.
            // Eligible:
            // - not recovered
            // - email opted in
            // - email present
            // - email_first_sent_at <= cutoff (meaning: first email was sent at least delay_days ago)
            // - email_step_sent = required_prev (so we only advance one step at a time)
            $eligible = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT *
                     FROM {$table_carts}
                     WHERE recovered = 0
                       AND email_opt_in = 1
                       AND contact_email <> ''
                       AND email_first_sent_at > 0
                       AND email_first_sent_at <= %d
                       AND email_step_sent = %d
                     ORDER BY id ASC
                     LIMIT 50",
                    $cutoff,
                    $required_prev
                )
            );

            self::log( '[CartMate_Email] Email #' . $step . ': eligible carts=' . ( is_array($eligible) ? count($eligible) : 0 ) );

            if ( ! empty( $eligible ) ) {
                foreach ( $eligible as $cart_row ) {

                    $tokens = self::build_tokens( $cart_row );

                    $subject = ! empty( $seq['subject'] ) ? $seq['subject'] : 'Reminder from {site_name}';
                    $body    = ! empty( $seq['body'] )    ? $seq['body']    : "Hi {name},\n\nJust a reminder – your cart is still waiting.\n\n{cart_url}";

                    $sent = self::send_email( $cart_row->contact_email, $subject, $body, $tokens );

                    if ( is_wp_error( $sent ) ) {
                        self::log( '[CartMate_Email] Email #' . $step . ' FAILED for cart ID ' . $cart_row->id . ': ' . $sent->get_error_message() );
                        continue;
                    }

                    // Mark this step sent.
                    $wpdb->update(
                        $table_carts,
                        array(
                            'email_step_sent' => $step,
                            'updated_at'      => $now,
                        ),
                        array( 'id' => (int) $cart_row->id ),
                        array( '%d', '%d' ),
                        array( '%d' )
                    );

                    self::log( '[CartMate_Email] Email #' . $step . ': sent + marked email_step_sent=' . $step . ' for cart ID ' . $cart_row->id );
                }
            }

            $step++;
        }
    }

    /**
     * Build token map from cart row.
     */
    protected static function build_tokens( $cart_row ) {
        $name = 'there';
        if ( isset($cart_row->contact_name) && ! empty($cart_row->contact_name) ) {
            $name = $cart_row->contact_name;
        }

        $tokens = array(
            '{name}'      => $name,
            '{site_name}' => get_bloginfo( 'name' ),
            '{cart_url}'  => function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : home_url( '/cart/' ),
        );

        return $tokens;
    }

    /**
     * Send email with tokens replaced.
     */
    protected static function send_email( $to, $subject_template, $body_template, $tokens ) {

        $to = sanitize_email( $to );
        if ( empty( $to ) || ! is_email( $to ) ) {
            return new WP_Error( 'cartmate_invalid_email', 'Invalid email address.' );
        }

        $subject = strtr( $subject_template, $tokens );
        $body    = strtr( $body_template, $tokens );

        // HTML mail, simple nl2br formatting.
        $headers = array( 'Content-Type: text/html; charset=UTF-8' );

        $sent = wp_mail( $to, $subject, nl2br( $body ), $headers );

        if ( ! $sent ) {
            return new WP_Error( 'cartmate_mail_failed', 'wp_mail() returned false.' );
        }

        self::log( '[CartMate_Email] Email sent to ' . $to . ' subject="' . $subject . '"' );

        return true;
    }

    /**
     * Ensure carts table has email_step_sent column.
     */
    protected static function maybe_ensure_email_step_column( $table_carts ) {
        global $wpdb;

        $col = $wpdb->get_var( $wpdb->prepare(
            "SHOW COLUMNS FROM {$table_carts} LIKE %s",
            'email_step_sent'
        ) );

        if ( empty( $col ) ) {
            // Best-effort add. If it fails, we log and continue.
            $sql = "ALTER TABLE {$table_carts} ADD COLUMN email_step_sent INT(11) NOT NULL DEFAULT 0";
            $wpdb->query( $sql );
            self::log( '[CartMate_Email] Attempted to add missing column email_step_sent.' );
        }
    }

    /**
     * Debug logging wrapper.
     */
    protected static function log( $message ) {
        if ( defined('WP_DEBUG') && WP_DEBUG ) {
            error_log( $message );
        }
    }
}

endif;
