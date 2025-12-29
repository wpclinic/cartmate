<?php
/**
 * Cart Mate - SMS engine (ClickSend).
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'CartMate_SMS' ) ) :

class CartMate_SMS {
    
    /**
 * Return base/default country code for SMS formatting.
 *
 * @return string
 */
protected static function get_default_sms_country(): string {
    $base = 'AU';

    if ( function_exists( 'WC' ) && WC() && isset( WC()->countries ) ) {
        $base = (string) WC()->countries->get_base_country();
    }

    $opt = (string) get_option( 'cartmate_sms_default_country', $base );
    return $opt ?: $base;
}

/**
 * Return country code used for test SMS formatting.
 *
 * @return string
 */
protected static function get_test_sms_country(): string {
    $fallback = self::get_default_sms_country();
    $opt      = (string) get_option( 'cartmate_sms_test_country', $fallback );
    return $opt ?: $fallback;
}

/**
 * Get calling code digits for a country code (best effort across WC versions).
 *
 * @param string $country_code
 * @return string Digits only, e.g. "61"
 */
protected static function get_calling_code_digits( string $country_code ): string {
    if ( function_exists( 'WC' ) && WC() && isset( WC()->countries ) ) {
        $countries = WC()->countries;

        if ( method_exists( $countries, 'get_country_calling_code' ) ) {
            $code = $countries->get_country_calling_code( $country_code );
            if ( is_array( $code ) ) {
                $code = reset( $code );
            }
            $digits = preg_replace( '/\D+/', '', (string) $code );
            return $digits ?: '';
        }
    }
    return '';
}

/**
 * Convert a phone number to E.164 using a WC country calling code.
 *
 * - If number already begins with "+", we keep it (after sanitising).
 * - If number begins with "00", convert to "+".
 * - Otherwise, prepend "+" + calling_code and normalise leading 0.
 *
 * @param string $raw_phone
 * @param string $country_code Two-letter country code (AU, US, etc.)
 * @return string E.164 string like "+61412345678" or "" on failure
 */
public static function normalize_phone_e164( string $raw_phone, string $country_code ): string {

    $phone = trim( (string) $raw_phone );
    if ( $phone === '' ) {
        return '';
    }

    // Keep a leading +, strip everything else to digits.
    $has_plus = ( strpos( $phone, '+' ) === 0 );

    // Convert "00" international prefix to "+"
    if ( ! $has_plus && strpos( $phone, '00' ) === 0 ) {
        $phone = '+' . substr( $phone, 2 );
        $has_plus = true;
    }

    // Remove spaces, brackets, hyphens etc.
    $clean = preg_replace( '/[^\d\+]/', '', $phone );

    if ( $has_plus ) {
        // "+(digits)" only.
        $digits = preg_replace( '/\D+/', '', $clean );
        return $digits ? ( '+' . $digits ) : '';
    }

    // Local number path: digits only.
    $digits = preg_replace( '/\D+/', '', $clean );
    if ( $digits === '' ) {
        return '';
    }

    // Remove a single leading 0 (common in AU/NZ/UK etc).
    $digits = preg_replace( '/^0+/', '', $digits );

    $cc = self::get_calling_code_digits( $country_code );
    if ( $cc === '' ) {
        // Last-resort: cannot format without calling code.
        return '';
    }

    return '+' . $cc . $digits;
}


    /**
     * Log to debug.log when WP_DEBUG is on.
     *
     * @param string $message
     */
    protected static function log( $message ) {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( '[CartMate_SMS] ' . $message );
        }
    }

    /**
     * Process SMS follow-ups for abandoned carts (Pro only).
     *
     * For now we treat the "Follow-up delay (days)" setting as:
     * - minutes when WP_DEBUG is true (so you can test quickly)
     * - days in production.
     */
    public static function process_followups() {
        if ( function_exists( 'cartmate_is_pro' ) && ! cartmate_is_pro() ) {
            self::log( 'process_followups(): skipped (not Pro).' );
            return;
        }

        global $wpdb;

        $table_name = $wpdb->prefix . 'cartmate_carts';
        $now        = time();

        // Base email delay (so SMS comes AFTER email).
        $email_delay_minutes = (int) get_option( 'cartmate_email_1_delay_minutes', 30 );
        if ( $email_delay_minutes < 0 ) {
            $email_delay_minutes = 0;
        }

        // SMS delay setting (UI says days).
        $sms_delay_setting = (int) get_option( 'cartmate_sms_delay_days', 3 );
        if ( $sms_delay_setting < 0 ) {
            $sms_delay_setting = 0;
        }

        // In debug, treat the SMS delay value as MINUTES to make testing easy.
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            $sms_delay_seconds = $sms_delay_setting * MINUTE_IN_SECONDS;
        } else {
            $sms_delay_seconds = $sms_delay_setting * DAY_IN_SECONDS;
        }

        // Cutoff: must be abandoned long enough that:
        // - first email could have gone out (email_delay_minutes)
        // - plus the SMS delay on top.
        $cutoff = $now - ( $email_delay_minutes * MINUTE_IN_SECONDS ) - $sms_delay_seconds;

        self::log(
            sprintf(
                'process_followups() started. EmailDelay=%d min, SmsSetting=%d, cutoff=%d (%s)',
                $email_delay_minutes,
                $sms_delay_setting,
                $cutoff,
                gmdate( 'Y-m-d H:i:s', $cutoff )
            )
        );

        // If the table doesn't exist, bail.
        if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table_name ) ) !== $table_name ) {
            self::log( 'Table ' . $table_name . ' does not exist. Aborting SMS follow-ups.' );
            return;
        }

        // Debug: show a few rows.
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            $total_rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name}" );
            self::log( 'Total rows in table: ' . $total_rows );
            if ( $total_rows > 0 ) {
                $debug_rows = $wpdb->get_results(
                    "SELECT id, contact_phone, sms_opt_in, recovered, abandoned_at, sms_first_sent_at, email_first_sent_at
                     FROM {$table_name}
                     ORDER BY id DESC
                     LIMIT 5",
                    ARRAY_A
                );
                foreach ( $debug_rows as $row ) {
                    self::log( 'Row: ' . print_r( $row, true ) );
                }
            }
        }

        // Find carts eligible for FIRST SMS:
        // - not recovered
        // - sms_opt_in = 1
        // - first email has been sent
        // - SMS not yet sent
        // - abandoned_at > 0 and older than cutoff
        // - phone is present
        $eligible = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT *
                 FROM {$table_name}
                 WHERE recovered = 0
                   AND sms_opt_in = 1
                   AND email_first_sent_at > 0
                   AND (sms_first_sent_at IS NULL OR sms_first_sent_at = 0)
                   AND abandoned_at > 0
                   AND abandoned_at <= %d
                   AND contact_phone <> ''",
                $cutoff
            )
        );

        $eligible_count = is_array( $eligible ) ? count( $eligible ) : 0;
        self::log( 'Found ' . $eligible_count . ' cart(s) eligible for SMS follow-up.' );

        if ( ! $eligible_count ) {
            return;
        }

        $settings = self::get_clicksend_settings();

        foreach ( $eligible as $cart_row ) {

            $tokens = array(
                'site_name' => get_bloginfo( 'name' ),
                'cart_url'  => function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : home_url( '/cart/' ),
                'name'      => ! empty( $cart_row->contact_name ) ? $cart_row->contact_name : __( 'there', 'cartmate' ),
            );

            $phone = isset( $cart_row->contact_phone ) ? trim( $cart_row->contact_phone ) : '';

            if ( empty( $phone ) ) {
                self::log( 'Skipping cart ID ' . $cart_row->id . ' – no phone.' );
                continue;
            }

            $message = '';
            if ( ! empty( $settings['template'] ) ) {
                $message = self::replace_template_tokens( $settings['template'], $tokens );
            } else {
                $message = self::replace_template_tokens(
                    'Hi {name}, you left something in your cart at {site_name}. Finish your order here: {cart_url}',
                    $tokens
                );
            }

            self::log( 'Sending SMS for cart ID ' . $cart_row->id . ' to ' . $phone );

            $result = self::send_sms_clicksend( $phone, $message, $settings );

            if ( is_wp_error( $result ) ) {
                self::log(
                    'Failed to send SMS for cart ID ' . $cart_row->id .
                    ' – ' . $result->get_error_message()
                );
                continue;
            }

            // Mark first SMS as sent.
            $wpdb->update(
                $table_name,
                array(
                    'sms_first_sent_at' => $now,
                    'updated_at'        => $now,
                ),
                array( 'id' => $cart_row->id ),
                array( '%d', '%d' ),
                array( '%d' )
            );

            self::log( 'Marked sms_first_sent_at for cart ID ' . $cart_row->id );
        }
    }

    /**
     * Get ClickSend settings from WP options.
     *
     * @return array
     */
    protected static function get_clicksend_settings() {
        return array(
            'username' => trim( get_option( 'cartmate_sms_clicksend_username', '' ) ),
            'api_key'  => trim( get_option( 'cartmate_sms_clicksend_api_key', '' ) ),
            'sender'   => trim( get_option( 'cartmate_sms_sender_name', 'CartMate' ) ),
            'template' => get_option( 'cartmate_sms_template', '' ),
        );
    }

public static function send_test_sms( $phone ) {

    $country = self::get_test_sms_country();
    $to      = self::normalize_phone_e164( $phone, $country );

    if ( empty( $to ) ) {
        return new WP_Error( 'cartmate_invalid_phone', 'Invalid test phone number.' );
    }

    $settings = self::get_clicksend_settings();

    // Use template if set, otherwise a default test message.
    $message = ! empty( $settings['template'] )
        ? $settings['template']
        : 'Cart Mate test SMS: If you received this, ClickSend is working.';

    // Log the E.164 number we’re actually sending to
    self::log( 'Sending test SMS to ' . $to . ' (input: ' . $phone . ')' );

    // IMPORTANT: send to $to (E.164), not $phone (raw)
    return self::send_sms_clicksend( $to, $message, $settings );
}

    /**
     * Low-level ClickSend sender.
     *
     * @param string $phone
     * @param string $message
     * @param array  $settings
     * @return true|\WP_Error
     */
    protected static function send_sms_clicksend( $phone, $message, $settings ) {
        $phone   = trim( $phone );
        $message = trim( $message );

if ( empty( $phone ) ) {
    return new \WP_Error( 'cartmate_no_phone', 'Phone number is empty.' );
}

if ( empty( $message ) ) {
    return new \WP_Error( 'cartmate_no_message', 'Message is empty.' );
}

// ✅ Log the final E.164 number being sent to ClickSend
self::log( 'ClickSend payload target phone=' . $phone );

// Continue with ClickSend API request...


        $username = isset( $settings['username'] ) ? $settings['username'] : '';
        $api_key  = isset( $settings['api_key'] ) ? $settings['api_key'] : '';
        $sender   = isset( $settings['sender'] ) ? $settings['sender'] : 'CartMate';

        if ( empty( $username ) || empty( $api_key ) ) {
            return new \WP_Error( 'cartmate_no_creds', 'ClickSend username or API key not configured.' );
        }

        $endpoint = 'https://rest.clicksend.com/v3/sms/send';

        $payload = array(
            'messages' => array(
                array(
                    'source' => 'wordpress',
                    'from'   => $sender,
                    'body'   => $message,
                    'to'     => $phone,
                ),
            ),
        );

        $args = array(
            'method'  => 'POST',
            'timeout' => 20,
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode( $username . ':' . $api_key ),
                'Content-Type'  => 'application/json',
            ),
            'body'    => wp_json_encode( $payload ),
        );

        self::log( 'Sending SMS to ' . $phone . ' via ClickSend. Payload: ' . print_r( $payload, true ) );

        $response = wp_remote_post( $endpoint, $args );

        if ( is_wp_error( $response ) ) {
            self::log( 'wp_remote_post error: ' . $response->get_error_message() );
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );

        self::log( 'ClickSend HTTP ' . $code . ' response: ' . $body );

        // If HTTP is not 2xx, treat as error.
        if ( $code < 200 || $code >= 300 ) {
            return new \WP_Error(
                'cartmate_http_error',
                'ClickSend returned HTTP ' . $code,
                array( 'body' => $body )
            );
        }

        // HTTP is 2xx – try to parse JSON, but be forgiving.
        $decoded = json_decode( $body, true );

        if ( json_last_error() !== JSON_ERROR_NONE ) {
            // HTTP OK but not valid JSON – assume success but log it.
            self::log( 'Could not decode ClickSend JSON response; treating as success.' );
            return true;
        }

        // 1) Classic ClickSend success: response_code = 200.
        if ( isset( $decoded['response_code'] ) && (int) $decoded['response_code'] === 200 ) {
            return true;
        }

        // 2) Some responses use response_msg like "Messages queued for delivery."
        if ( isset( $decoded['response_msg'] ) ) {
            $msg = (string) $decoded['response_msg'];

            // Treat any "queued for delivery" style message as SUCCESS.
            if ( stripos( $msg, 'queued for delivery' ) !== false ) {
                self::log( 'ClickSend response_msg indicates success: ' . $msg );
                return true;
            }
        }

        // 3) Fallback: explicit errors.
        if ( isset( $decoded['errors'] ) && ! empty( $decoded['errors'] ) ) {
            return new \WP_Error(
                'cartmate_clicksend_error',
                'ClickSend error: ' . print_r( $decoded['errors'], true ),
                $decoded
            );
        }

        // 4) At this point we had HTTP 2xx and no obvious error -> treat as success.
        self::log( 'ClickSend returned 2xx with no obvious error; treating as success.' );
        return true;
    }
}

endif;
