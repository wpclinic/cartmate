<?php
/**
 * Cart Mate - Email sending & follow-ups for abandoned carts.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'CartMate_Email' ) ) :

class CartMate_Email {

    /**
     * Process email follow-ups for abandoned carts.
     *
     * Looks for carts that:
     * - are not recovered
     * - have email_opt_in = 1
     * - have a non-empty contact_email
     * - have been abandoned longer than the configured delay
     * - have not yet had the first email sent
     */
    public static function process_followups() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'cartmate_carts';
        $now        = time();

        // Delay in minutes before the FIRST email.
        $delay_minutes = (int) get_option( 'cartmate_email_1_delay_minutes', 30 );
        if ( $delay_minutes < 0 ) {
            $delay_minutes = 0;
        }

        $cutoff = $now - ( $delay_minutes * MINUTE_IN_SECONDS );

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            // High-level debug: how many rows exist in the table?
            $total_rows = 0;
            if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table_name ) ) === $table_name ) {
                $total_rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name}" );
            }
            error_log( 'Cart Mate Email DEBUG: table = ' . $table_name . ', total rows = ' . $total_rows );

            // Dump the last few rows so we can see flags/timestamps.
            if ( $total_rows > 0 ) {
                $debug_rows = $wpdb->get_results(
                    "SELECT id, contact_email, email_opt_in, recovered, abandoned_at, email_first_sent_at, created_at, updated_at
                     FROM {$table_name}
                     ORDER BY id DESC
                     LIMIT 5",
                    ARRAY_A
                );

                foreach ( $debug_rows as $row ) {
                    error_log( 'Cart Mate Email DEBUG row: ' . print_r( $row, true ) );
                }
            }

            error_log(
                sprintf(
                    'Cart Mate Email: process_followups() started. Delay=%d min, cutoff=%d (%s)',
                    $delay_minutes,
                    $cutoff,
                    gmdate( 'Y-m-d H:i:s', $cutoff )
                )
            );
        }

        // If the table doesn't exist, bail out gracefully.
        if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table_name ) ) !== $table_name ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( 'Cart Mate Email: table ' . $table_name . ' does not exist. Aborting follow-ups.' );
            }
            return;
        }

        // Find carts eligible for the FIRST email.
        $eligible = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT *
                 FROM {$table_name}
                 WHERE recovered = 0
                   AND email_opt_in = 1
                   AND (email_first_sent_at IS NULL OR email_first_sent_at = 0)
                   AND abandoned_at > 0
                   AND abandoned_at <= %d
                   AND contact_email <> ''",
                $cutoff
            )
        );

        $eligible_count = is_array( $eligible ) ? count( $eligible ) : 0;

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( 'Cart Mate Email: found ' . $eligible_count . ' cart(s) eligible for first follow-up.' );
        }

        if ( ! $eligible_count ) {
            return;
        }

        foreach ( $eligible as $cart_row ) {

            $tokens = array(
                'site_name' => get_bloginfo( 'name' ),
                'cart_url'  => function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : home_url( '/cart/' ),
                'name'      => ! empty( $cart_row->contact_name ) ? $cart_row->contact_name : __( 'there', 'cartmate' ),
            );

            $result = self::send_abandoned_cart_email( $cart_row, $tokens );

            if ( is_wp_error( $result ) ) {
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    error_log(
                        'Cart Mate Email: failed to send email for cart ID ' . $cart_row->id .
                        ' – ' . $result->get_error_message()
                    );
                }
                continue;
            }

            // Mark first email as sent.
            $wpdb->update(
                $table_name,
                array(
                    'email_first_sent_at' => $now,
                    'updated_at'          => $now,
                ),
                array( 'id' => $cart_row->id ),
                array( '%d', '%d' ),
                array( '%d' )
            );

            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( 'Cart Mate Email: marked email_first_sent_at for cart ID ' . $cart_row->id );
            }
        }
    }

    /**
     * Send the abandoned cart email for a cart row.
     *
     * @param \stdClass $cart_row
     * @param array     $extra_tokens  Optional tokens: site_name, cart_url, name
     *
     * @return true|\WP_Error
     */
    public static function send_abandoned_cart_email( $cart_row, $extra_tokens = array() ) {

        $to = isset( $cart_row->contact_email ) ? sanitize_email( $cart_row->contact_email ) : '';

        if ( empty( $to ) || ! is_email( $to ) ) {
            return new \WP_Error( 'cartmate_invalid_email', 'Invalid email address.' );
        }

        $subject_template = get_option(
            'cartmate_email_1_subject',
            'We saved your cart at {site_name}'
        );

        $body_template = get_option(
            'cartmate_email_1_body',
            "Hi {name},\n\nYou left some items in your cart at {site_name}. Click below to complete your order:\n\n{cart_url}"
        );

        $name      = ! empty( $extra_tokens['name'] )
            ? $extra_tokens['name']
            : ( ! empty( $cart_row->contact_name ) ? $cart_row->contact_name : __( 'there', 'cartmate' ) );

        $site_name = ! empty( $extra_tokens['site_name'] )
            ? $extra_tokens['site_name']
            : get_bloginfo( 'name' );

        $cart_url  = ! empty( $extra_tokens['cart_url'] )
            ? $extra_tokens['cart_url']
            : ( function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : home_url( '/cart/' ) );

        $tokens = array(
            '{name}'      => $name,
            '{site_name}' => $site_name,
            '{cart_url}'  => $cart_url,
        );

        $subject = strtr( $subject_template, $tokens );
        $body    = strtr( $body_template, $tokens );

        $headers = array( 'Content-Type: text/html; charset=UTF-8' );

        $sent = wp_mail( $to, $subject, nl2br( $body ), $headers );

        if ( ! $sent ) {
            return new \WP_Error( 'cartmate_mail_failed', 'wp_mail() returned false.' );
        }

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( 'Cart Mate Email: sent abandoned cart email to ' . $to );
        }

        return true;
    }
}

endif;
