<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CartMate_Emailer {

    /**
     * Build a cart URL (safe fallback).
     */
    private static function get_cart_url() {
        if ( function_exists( 'wc_get_cart_url' ) ) {
            return wc_get_cart_url();
        }
        return site_url( '/cart/' );
    }

    /**
     * Best-effort token replacement.
     *
     * Supported tokens:
     * - {site_name}
     * - {cart_url}
     * - {name}
     */
    private static function apply_tokens( $text, $cart_data ) {
        $site_name = get_bloginfo( 'name' );
        $cart_url  = self::get_cart_url();

        // Best effort for {name}
        $name = 'there';
        if ( is_array( $cart_data ) ) {
            // If you ever store name in cart data, these are common keys.
            foreach ( array( 'first_name', 'billing_first_name', 'name' ) as $k ) {
                if ( ! empty( $cart_data[ $k ] ) && is_string( $cart_data[ $k ] ) ) {
                    $name = trim( $cart_data[ $k ] );
                    break;
                }
            }
        }

        $replacements = array(
            '{site_name}' => $site_name,
            '{cart_url}'  => $cart_url,
            '{name}'      => $name,
        );

        return strtr( (string) $text, $replacements );
    }

    public static function send_recovery_email( $email, $cart_data ) {
        $subject = get_option( 'cartmate_email_subject', 'You left something in your cart!' );
        $message = get_option(
            'cartmate_email_body',
            "Hi {name},\n\nLooks like you didn’t finish your order.\nClick below to return to your cart:\n\n{cart_url}"
        );

        $subject = self::apply_tokens( $subject, $cart_data );
        $message = self::apply_tokens( $message, $cart_data );

        $headers = array( 'From: Hustlemate <noreply@hustlemate.au>' );
        wp_mail( $email, $subject, $message, $headers );
    }

    /**
     * Send a scheduled follow-up email using the sequences table row.
     *
     * @param string $email
     * @param mixed  $cart_data
     * @param array  $seq  Array with keys: subject, body (from DB).
     */
    public static function send_recovery_sequence_email( $email, $cart_data, $seq ) {

        $subject = isset( $seq['subject'] ) ? (string) $seq['subject'] : '';
        $message = isset( $seq['body'] ) ? (string) $seq['body'] : '';

        // Fallbacks (avoid sending empty emails)
        if ( '' === trim( $subject ) ) {
            $subject = get_option( 'cartmate_email_subject', 'You left something in your cart!' );
        }
        if ( '' === trim( $message ) ) {
            $message = get_option(
                'cartmate_email_body',
                "Hi {name},\n\nLooks like you didn’t finish your order.\n\n{cart_url}"
            );
        }

        $subject = self::apply_tokens( $subject, $cart_data );
        $message = self::apply_tokens( $message, $cart_data );

        $headers = array( 'From: Hustlemate <noreply@hustlemate.au>' );
        wp_mail( $email, $subject, $message, $headers );
    }
}
