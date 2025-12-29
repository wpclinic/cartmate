<?php
/**
 * Admin UI for WPCartMate.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'CartMate_Admin' ) ) :

class CartMate_Admin {

    /**
     * Singleton instance.
     *
     * @var CartMate_Admin|null
     */
    protected static $instance = null;

    /**
     * Bootstrap – called from cartmate.php
     *
     * @return CartMate_Admin
     */
    public static function init() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Private constructor.
     */
    private function __construct() {
        add_action( 'admin_menu', array( $this, 'register_menu' ) );
        add_action( 'admin_init', array( $this, 'handle_form_submission' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
    }

    /* -------------------------------------------------------------------------
     * MENU
     * ---------------------------------------------------------------------- */

    /**
     * Register top-level WPCartMate menu + submenus.
     */
    public function register_menu() {
        $cap = 'manage_woocommerce';

        // Top-level menu.
        add_menu_page(
            __( 'WPCartMate', 'cartmate' ),
            __( 'WPCartMate', 'cartmate' ),
            $cap,
            'cartmate',
            array( $this, 'render_settings_page' ),
            'dashicons-cart',
            56
        );

        // Submenus.
        $pages = array(
            'cartmate'               => __( 'Dashboard', 'cartmate' ),
            'cartmate-email'         => __( 'Email Reminder', 'cartmate' ),
            'cartmate-sequences'     => __( 'Scheduled Email Followups', 'cartmate' ),
            'cartmate-sms'           => __( 'SMS Settings', 'cartmate' ),
            'cartmate-notifications' => __( 'Admin Notifications', 'cartmate' ),
            'cartmate-license'       => __( 'License & Upgrades', 'cartmate' ),
            'cartmate-status'        => __( 'System Status', 'cartmate' ),
        );

        foreach ( $pages as $slug => $label ) {
            add_submenu_page(
                'cartmate',
                $label,
                $label,
                $cap,
                $slug,
                array( $this, 'render_settings_page' )
            );
        }
    }

    /* -------------------------------------------------------------------------
     * ASSETS
     * ---------------------------------------------------------------------- */

    /**
     * Enqueue admin CSS on any WPCartMate page.
     *
     * @param string $hook
     */
    public function enqueue_assets( $hook ) {
        if ( ! isset( $_GET['page'] ) ) {
            return;
        }

        $page = sanitize_text_field( wp_unslash( $_GET['page'] ) );
        if ( strpos( $page, 'cartmate' ) !== 0 ) {
            return;
        }

        // We are in /cartmate/includes/admin/.
        $plugin_main_file = dirname( dirname( dirname( __FILE__ ) ) ) . '/cartmate.php';
        $css_url          = plugins_url( 'assets/css/cartmate-admin.css', $plugin_main_file );

        wp_enqueue_style(
            'cartmate-admin',
            $css_url,
            array(),
            '1.0.0'
        );
    }

    /* -------------------------------------------------------------------------
     * HELPERS
     * ---------------------------------------------------------------------- */

    /**
     * Get country list (WooCommerce if available).
     *
     * @return array
     */
    protected function get_wc_countries() {
        if ( function_exists( 'WC' ) && WC() && isset( WC()->countries ) ) {
            return WC()->countries->get_countries();
        }
        return array();
    }

    /**
     * Get base country.
     *
     * @return string
     */
    protected function get_wc_base_country() {
        if ( function_exists( 'WC' ) && WC() && isset( WC()->countries ) ) {
            return WC()->countries->get_base_country();
        }
        return 'AU';
    }

    /**
     * Get calling code string for a country (best-effort across WC versions).
     *
     * @param string $country_code
     * @return string
     */
    protected function get_calling_code( $country_code ) {
        if ( function_exists( 'WC' ) && WC() && isset( WC()->countries ) ) {
            $countries = WC()->countries;

            if ( method_exists( $countries, 'get_country_calling_code' ) ) {
                $code = $countries->get_country_calling_code( $country_code );
                if ( is_array( $code ) ) {
                    $code = reset( $code );
                }
                $code = preg_replace( '/\D+/', '', (string) $code );
                return $code ? $code : '';
            }

            // Fallback: small built-in map (filterable).
            return $this->cartmate_get_calling_code( $country_code );
        }
        return '';
    }

    /**
     * Determine current tab based on ?page= slug.
     *
     * @return string
     */
    protected function get_current_tab() {
        $page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : 'cartmate';

        switch ( $page ) {
            case 'cartmate-email':
                return 'email';
            case 'cartmate-sequences':
                return 'sequences';
            case 'cartmate-sms':
                return 'sms';
            case 'cartmate-notifications':
                return 'notifications';
            case 'cartmate-license':
                return 'license';
            case 'cartmate-status':
                return 'status';
            case 'cartmate':
            default:
                return 'dashboard';
        }
    }

    /* -------------------------------------------------------------------------
     * FORM HANDLING
     * ---------------------------------------------------------------------- */

    /**
     * Handle saving of settings + scheduled emails CRUD.
     */
     /**
 * Minimal calling code map (extendable).
 */
protected function cartmate_get_calling_code( $country_code ) {
    $country_code = strtoupper( (string) $country_code );

    $map = array(
        'AU' => '61',
        'NZ' => '64',
        'US' => '1',
        'CA' => '1',
        'GB' => '44',
        'IE' => '353',
    );

    /**
     * Allow devs to extend this map without editing core.
     */
    $map = apply_filters( 'cartmate_calling_code_map', $map );

    return isset( $map[ $country_code ] ) ? $map[ $country_code ] : '';
}

/**
 * Normalize a phone number to E.164 using selected country.
 */
protected function cartmate_normalize_phone_e164( $raw, $country_code ) {
    $raw = trim( (string) $raw );
    if ( '' === $raw ) {
        return new WP_Error( 'cartmate_phone_empty', __( 'Phone number is empty.', 'cartmate' ) );
    }

    // Keep digits and leading + only.
    $raw = preg_replace( '/(?!^\\+)\\D+/', '', $raw );

    // Convert 00 prefix to +.
    if ( 0 === strpos( $raw, '00' ) ) {
        $raw = '+' . substr( $raw, 2 );
    }

    // Already E.164.
    if ( 0 === strpos( $raw, '+' ) ) {
        $digits = preg_replace( '/\\D+/', '', $raw );
        if ( strlen( $digits ) < 8 ) {
            return new WP_Error( 'cartmate_phone_invalid', __( 'Phone number looks too short.', 'cartmate' ) );
        }
        return '+' . $digits;
    }

    $digits = preg_replace( '/\\D+/', '', $raw );
    if ( '' === $digits ) {
        return new WP_Error( 'cartmate_phone_invalid', __( 'Phone number is invalid.', 'cartmate' ) );
    }

    $calling = $this->get_calling_code( $country_code );
    if ( '' === $calling ) {
        return new WP_Error( 'cartmate_country_no_calling', __( 'Unsupported country for calling code mapping.', 'cartmate' ) );
    }

    // Strip leading 0s for local formats, then prefix.
    $digits = ltrim( $digits, '0' );
    if ( strlen( $digits ) < 6 ) {
        return new WP_Error( 'cartmate_phone_invalid', __( 'Phone number looks too short.', 'cartmate' ) );
    }

    return '+' . $calling . $digits;
}

/**
 * Send a test SMS via ClickSend REST.
 */
protected function cartmate_send_clicksend_test_sms( $to_e164, $message, $username, $api_key, $sender = '' ) {

    $username = trim( (string) $username );
    $api_key  = trim( (string) $api_key );
    $sender   = trim( (string) $sender );
    $to_e164  = trim( (string) $to_e164 );
    $message  = (string) $message;

    if ( '' === $username || '' === $api_key ) {
        return new WP_Error( 'cartmate_clicksend_missing_creds', __( 'ClickSend username/API key are missing.', 'cartmate' ) );
    }

    $payload_message = array(
        'source' => 'php',
        'body'   => $message,
        'to'     => $to_e164,
    );

    if ( '' !== $sender ) {
        $payload_message['from'] = $sender;
    }

    $payload = array(
        'shorten_urls' => false,
        'messages'     => array( $payload_message ),
    );

    $auth = base64_encode( $username . ':' . $api_key );

    $resp = wp_remote_post(
        'https://rest.clicksend.com/v3/sms/send',
        array(
            'timeout' => 20,
            'headers' => array(
                'Authorization' => 'Basic ' . $auth,
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ),
            'body' => wp_json_encode( $payload ),
        )
    );

    if ( is_wp_error( $resp ) ) {
        return $resp;
    }

    $code = (int) wp_remote_retrieve_response_code( $resp );
    $body = wp_remote_retrieve_body( $resp );
    $data = json_decode( $body, true );

    if ( ! is_array( $data ) ) {
        return new WP_Error(
            'cartmate_clicksend_bad_response',
            sprintf( __( 'ClickSend returned an unexpected response (HTTP %d).', 'cartmate' ), $code )
        );
    }

    // ClickSend typically returns response_code = SUCCESS on success.
    if ( isset( $data['response_code'] ) && 'SUCCESS' === $data['response_code'] ) {
        return true;
    }

    $msg = isset( $data['response_msg'] ) ? (string) $data['response_msg'] : __( 'Unknown error from ClickSend.', 'cartmate' );
    return new WP_Error( 'cartmate_clicksend_failed', $msg );
}

    public function handle_form_submission() {

        if ( ! isset( $_POST['cartmate_settings_nonce'] ) ) {
            return;
        }
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }
        if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['cartmate_settings_nonce'] ) ), 'cartmate_save_settings' ) ) {
            return;
        }

        $get_post = function( $key, $default = '' ) {
            return isset( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : $default;
        };

        // Determine tab (so we can safely process tab-specific actions).
        $page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : 'cartmate';

        /* -----------------------------------------------------------------
         * GLOBAL: save the standard options (safe even if not on that tab).
         * ----------------------------------------------------------------- */

        // Email reminder (Email #1).
        $delay_minutes = intval( $get_post( 'cartmate_email_delay_minutes', 60 ) );
        if ( $delay_minutes < 0 ) { $delay_minutes = 0; }
        update_option( 'cartmate_email_delay_minutes', $delay_minutes );

        $email_subject = sanitize_text_field( $get_post( 'cartmate_email_subject', '' ) );
        update_option( 'cartmate_email_subject', $email_subject );

        $email_body = wp_kses_post( $get_post( 'cartmate_email_body', '' ) );
        update_option( 'cartmate_email_body', $email_body );

        // SMS / ClickSend.
        $sms_delay_minutes = intval( $get_post( 'cartmate_sms_followup_delay', 2880 ) );
        if ( $sms_delay_minutes < 0 ) { $sms_delay_minutes = 0; }
        update_option( 'cartmate_sms_followup_delay', $sms_delay_minutes );

        update_option( 'cartmate_clicksend_username', sanitize_text_field( $get_post( 'cartmate_clicksend_username', '' ) ) );
        update_option( 'cartmate_clicksend_api_key', sanitize_text_field( $get_post( 'cartmate_clicksend_api_key', '' ) ) );
        update_option( 'cartmate_clicksend_sender', sanitize_text_field( $get_post( 'cartmate_clicksend_sender', 'WPCartMate' ) ) );
        update_option( 'cartmate_sms_template', wp_kses_post( $get_post( 'cartmate_sms_template', '' ) ) );
        update_option( 'cartmate_test_phone', sanitize_text_field( $get_post( 'cartmate_test_phone', '' ) ) );

        // NEW: Country selectors for SMS formatting.
        $base_country = $this->get_wc_base_country();
        update_option( 'cartmate_sms_default_country', sanitize_text_field( $get_post( 'cartmate_sms_default_country', $base_country ) ) );
        update_option( 'cartmate_sms_test_country', sanitize_text_field( $get_post( 'cartmate_sms_test_country', $base_country ) ) );
        // Send Test SMS (only when button is clicked on the SMS tab).
if ( 'cartmate-sms' === $page && isset( $_POST['cartmate_send_test_sms'] ) ) {

    $test_phone_raw = sanitize_text_field( $get_post( 'cartmate_test_phone', '' ) );
    $test_country   = sanitize_text_field( $get_post( 'cartmate_sms_test_country', $base_country ) );

    if ( empty( $test_phone_raw ) ) {
        add_settings_error(
            'cartmate_messages',
            'cartmate_test_sms_missing_phone',
            __( 'Please enter a Test Phone Number before sending a test SMS.', 'cartmate' ),
            'error'
        );
    } else {

        // Convert to E.164 (uses the country selector).
        $to_e164 = $this->cartmate_normalize_phone_e164( $test_phone_raw, $test_country );

        if ( is_wp_error( $to_e164 ) ) {

            add_settings_error(
                'cartmate_messages',
                'cartmate_test_sms_invalid_phone',
                $to_e164->get_error_message(),
                'error'
            );

        } else {

            $cs_user   = sanitize_text_field( $get_post( 'cartmate_clicksend_username', '' ) );
            $cs_key    = sanitize_text_field( $get_post( 'cartmate_clicksend_api_key', '' ) );
            $cs_sender = sanitize_text_field( $get_post( 'cartmate_clicksend_sender', 'WPCartMate' ) );

            $site_name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
            $message   = sprintf(
                __( 'WPCartMate test SMS from %s. If you received this, ClickSend is configured correctly.', 'cartmate' ),
                $site_name
            );

            $result = $this->cartmate_send_clicksend_test_sms( (string) $to_e164, $message, $cs_user, $cs_key, $cs_sender );

            if ( is_wp_error( $result ) ) {
                add_settings_error(
                    'cartmate_messages',
                    'cartmate_test_sms_failed',
                    sprintf( __( 'Test SMS failed: %s', 'cartmate' ), $result->get_error_message() ),
                    'error'
                );
            } else {
                add_settings_error(
                    'cartmate_messages',
                    'cartmate_test_sms_sent',
                    sprintf( __( 'Test SMS queued to %s.', 'cartmate' ), esc_html( (string) $to_e164 ) ),
                    'updated'
                );
            }
        }
    }
}


        // Admin notification email.
        update_option( 'cartmate_admin_email', sanitize_email( $get_post( 'cartmate_admin_email', '' ) ) );

        // License key.
        update_option( 'cartmate_license_key', sanitize_text_field( $get_post( 'cartmate_license_key', '' ) ) );

        /* -----------------------------------------------------------------
         * TAB-SPECIFIC: Scheduled Email Followups CRUD
         * ----------------------------------------------------------------- */
        if ( 'cartmate-sequences' === $page ) {
            $this->handle_sequences_crud();
        }

// Avoid double notices when clicking "Send test SMS".
if ( ! isset( $_POST['cartmate_send_test_sms'] ) ) {
    add_settings_error(
        'cartmate_messages',
        'cartmate_settings_saved',
        __( 'WPCartMate settings saved.', 'cartmate' ),
        'updated'
    );
}
}

    /**
     * CRUD handler for scheduled email sequences.
     * Table: {$wpdb->prefix}cartmate_email_sequences
     */
    protected function handle_sequences_crud() {
        global $wpdb;

        $table = $wpdb->prefix . 'cartmate_email_sequences';
        $now   = time();

        // ADD new follow-up.
        if ( isset( $_POST['cartmate_add_sequence'] ) ) {

            $enabled   = isset( $_POST['sequence_enabled'] ) ? 1 : 0;
            $delay_days = intval( isset( $_POST['sequence_delay_days'] ) ? wp_unslash( $_POST['sequence_delay_days'] ) : 1 );
            if ( $delay_days < 0 ) { $delay_days = 0; }

            $subject = sanitize_text_field( isset( $_POST['sequence_subject'] ) ? wp_unslash( $_POST['sequence_subject'] ) : '' );
            $body    = wp_kses_post( isset( $_POST['sequence_body'] ) ? wp_unslash( $_POST['sequence_body'] ) : '' );

            // Limit to 5 follow-ups (step_number 2..6) after Email #1.
            $max_followups = 5;
            $current_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
            if ( $current_count >= $max_followups ) {
                add_settings_error(
                    'cartmate_messages',
                    'cartmate_seq_limit',
                    __( 'You already have 5 scheduled follow-ups. Edit an existing one instead.', 'cartmate' ),
                    'error'
                );
                return;
            }

            // Next step number: 2..6
            $max_step = (int) $wpdb->get_var( "SELECT MAX(step_number) FROM {$table}" );
            $next_step = $max_step ? ( $max_step + 1 ) : 2;

            // Sort order: keep it stable by delay, then id.
            $sort_order = (int) $wpdb->get_var( "SELECT COALESCE(MAX(sort_order), 0) FROM {$table}" ) + 1;

            $wpdb->insert(
                $table,
                array(
                    'step_number' => $next_step,
                    'delay_days'  => $delay_days,
                    'enabled'     => $enabled,
                    'subject'     => $subject,
                    'body'        => $body,
                    'sort_order'  => $sort_order,
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ),
                array( '%d','%d','%d','%s','%s','%d','%d','%d' )
            );

            add_settings_error(
                'cartmate_messages',
                'cartmate_seq_added',
                __( 'Scheduled follow-up added.', 'cartmate' ),
                'updated'
            );
        }

        // UPDATE existing follow-up.
        if ( isset( $_POST['cartmate_update_sequence_id'] ) ) {
            $id = intval( wp_unslash( $_POST['cartmate_update_sequence_id'] ) );
            if ( $id > 0 ) {
                $enabled   = isset( $_POST['sequence_enabled_' . $id ] ) ? 1 : 0;
                $delay_days = intval( isset( $_POST['sequence_delay_days_' . $id ] ) ? wp_unslash( $_POST['sequence_delay_days_' . $id ] ) : 1 );
                if ( $delay_days < 0 ) { $delay_days = 0; }

                $subject = sanitize_text_field( isset( $_POST['sequence_subject_' . $id ] ) ? wp_unslash( $_POST['sequence_subject_' . $id ] ) : '' );
                $body    = wp_kses_post( isset( $_POST['sequence_body_' . $id ] ) ? wp_unslash( $_POST['sequence_body_' . $id ] ) : '' );

                $wpdb->update(
                    $table,
                    array(
                        'enabled'    => $enabled,
                        'delay_days' => $delay_days,
                        'subject'    => $subject,
                        'body'       => $body,
                        'updated_at' => $now,
                    ),
                    array( 'id' => $id ),
                    array( '%d','%d','%s','%s','%d' ),
                    array( '%d' )
                );

                add_settings_error(
                    'cartmate_messages',
                    'cartmate_seq_updated',
                    __( 'Scheduled follow-up updated.', 'cartmate' ),
                    'updated'
                );
            }
        }

        // DELETE existing follow-up.
        if ( isset( $_POST['cartmate_delete_sequence_id'] ) ) {
            $id = intval( wp_unslash( $_POST['cartmate_delete_sequence_id'] ) );
            if ( $id > 0 ) {
                $wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) );

                add_settings_error(
                    'cartmate_messages',
                    'cartmate_seq_deleted',
                    __( 'Scheduled follow-up deleted.', 'cartmate' ),
                    'updated'
                );
            }
        }
    }

    /* -------------------------------------------------------------------------
     * PAGE RENDER
     * ---------------------------------------------------------------------- */

    /**
     * Render the settings screen (all tabs).
     */
    public function render_settings_page() {
        $current_tab = $this->get_current_tab();

        // Shared options.
        $email_delay   = intval( get_option( 'cartmate_email_delay_minutes', 60 ) );
        $email_subject = get_option( 'cartmate_email_subject', __( '{name}, you left something in your cart', 'cartmate' ) );
        $email_body    = get_option(
            'cartmate_email_body',
            "Hi {name},\n\nLooks like you didn't finish your order.\nClick below to return to your cart:\n\n{cart_url}\n\n– {site_name}"
        );

        $sms_delay     = intval( get_option( 'cartmate_sms_followup_delay', 2880 ) );
        $cs_username   = get_option( 'cartmate_clicksend_username', '' );
        $cs_api_key    = get_option( 'cartmate_clicksend_api_key', '' );
        $cs_sender     = get_option( 'cartmate_clicksend_sender', 'WPCartMate' );
        $sms_template  = get_option(
            'cartmate_sms_template',
            __( "Hi {name}, you left your cart at {site_name}. Finish here: {cart_url}", 'cartmate' )
        );

        $base_country        = $this->get_wc_base_country();
        $sms_default_country = get_option( 'cartmate_sms_default_country', $base_country );
        $sms_test_country    = get_option( 'cartmate_sms_test_country', $sms_default_country );
        $test_phone          = get_option( 'cartmate_test_phone', '' );

        $admin_email   = get_option( 'cartmate_admin_email', get_option( 'admin_email' ) );
        $license_key   = get_option( 'cartmate_license_key', '' );

        $is_pro = false;
        if ( function_exists( 'cartmate_is_pro' ) ) {
            $is_pro = cartmate_is_pro();
        } else {
            $is_pro = ! empty( $license_key );
        }

        // Tabs config.
        $tabs = array(
            'dashboard'     => array(
                'label' => __( 'Dashboard', 'cartmate' ),
                'slug'  => 'cartmate',
            ),
            'email'         => array(
                'label' => __( 'Email Reminder', 'cartmate' ),
                'slug'  => 'cartmate-email',
            ),
            'sequences'     => array(
                'label' => __( 'Scheduled Email Followups', 'cartmate' ),
                'slug'  => 'cartmate-sequences',
            ),
            'sms'           => array(
                'label' => __( 'SMS Settings', 'cartmate' ),
                'slug'  => 'cartmate-sms',
            ),
            'notifications' => array(
                'label' => __( 'Admin Notifications', 'cartmate' ),
                'slug'  => 'cartmate-notifications',
            ),
            'license'       => array(
                'label' => __( 'License & Upgrades', 'cartmate' ),
                'slug'  => 'cartmate-license',
            ),
            'status'        => array(
                'label' => __( 'System Status', 'cartmate' ),
                'slug'  => 'cartmate-status',
            ),
        );
        ?>
        <div class="wrap cartmate-wrap">
            <div class="cartmate-header">
                <div class="cartmate-header-left">
                    <div class="cartmate-logo-mark">
                        <span class="cartmate-logo-check">✓</span>
                    </div>
                    <div class="cartmate-header-text">
                        <h1 class="cartmate-title">WPCartMate</h1>
                        <p class="cartmate-subtitle">
                            <?php esc_html_e( 'Email & SMS recovery for WooCommerce carts', 'cartmate' ); ?>
                        </p>
                    </div>
                </div>

                <div class="cartmate-header-right">
                    <?php if ( $is_pro ) : ?>
                        <span class="cartmate-badge cartmate-badge--pro">
                            <?php esc_html_e( 'PRO ACTIVE', 'cartmate' ); ?>
                        </span>
                    <?php else : ?>
                        <span class="cartmate-badge cartmate-badge--free">
                            <?php esc_html_e( 'FREE VERSION', 'cartmate' ); ?>
                        </span>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Banner #1 -->
            <div class="cartmate-banner">
                <div class="cartmate-banner-left">
                    <span class="cartmate-chip cartmate-chip--autopilot">
                        <?php esc_html_e( 'RECOVER MORE CARTS ON AUTOPILOT', 'cartmate' ); ?>
                    </span>
                    <h2 class="cartmate-banner-title">
                        <?php esc_html_e( 'Start with a simple email, then automate follow-ups.', 'cartmate' ); ?>
                    </h2>
                    <p class="cartmate-banner-text">
                        <?php esc_html_e( 'Configure Email #1, then add scheduled follow-up emails. Customers get nudged without you lifting a finger.', 'cartmate' ); ?>
                    </p>
                </div>
                <div class="cartmate-banner-right">
                    <a href="https://hustlemate.au" target="_blank" class="button cartmate-button-outline">
                        <?php esc_html_e( 'See WPCartMate Pro plans', 'cartmate' ); ?>
                    </a>
                </div>
            </div>

            <!-- Banner #2 (keeps the "double CTA" feel) -->
            <div class="cartmate-banner cartmate-banner--secondary">
                <div class="cartmate-banner-left">
                    <h3 class="cartmate-banner-title" style="margin:0;">
                        <?php esc_html_e( 'Tip: keep your recovery messages short and friendly.', 'cartmate' ); ?>
                    </h3>
                    <p class="cartmate-banner-text">
                        <?php esc_html_e( 'Most stores see the best results with 1 email + 3–6 follow-ups and a single SMS nudge.', 'cartmate' ); ?>
                    </p>
                </div>
                <div class="cartmate-banner-right">
                    <a href="https://hustlemate.au" target="_blank" class="button cartmate-button-secondary">
                        <?php esc_html_e( 'View templates', 'cartmate' ); ?>
                    </a>
                </div>
            </div>

            <nav class="cartmate-nav">
                <ul class="cartmate-nav__list">
                    <?php foreach ( $tabs as $tab_id => $tab ) : ?>
                        <?php
                        $is_active = ( $tab_id === $current_tab );
                        $url       = admin_url( 'admin.php?page=' . $tab['slug'] );
                        ?>
                        <li class="cartmate-nav__item <?php echo $is_active ? 'cartmate-nav__item--active' : ''; ?>">
                            <a href="<?php echo esc_url( $url ); ?>" class="cartmate-nav__link">
                                <?php echo esc_html( $tab['label'] ); ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </nav>

            <?php settings_errors( 'cartmate_messages' ); ?>

            <form method="post" action="">
                <?php wp_nonce_field( 'cartmate_save_settings', 'cartmate_settings_nonce' ); ?>

                <?php
                switch ( $current_tab ) {
                    case 'email':
                        $this->render_tab_email( $email_delay, $email_subject, $email_body );
                        break;

                    case 'sequences':
                        $this->render_tab_sequences();
                        break;

                    case 'sms':
                        $this->render_tab_sms(
                            $sms_delay,
                            $cs_username,
                            $cs_api_key,
                            $cs_sender,
                            $sms_template,
                            $test_phone,
                            $sms_default_country,
                            $sms_test_country
                        );
                        break;

                    case 'notifications':
                        $this->render_tab_notifications( $admin_email );
                        break;

                    case 'license':
                        $this->render_tab_license( $license_key, $is_pro );
                        break;

                    case 'status':
                        $this->render_tab_status();
                        break;

                    case 'dashboard':
                    default:
                        $this->render_tab_dashboard( $email_delay, $sms_delay, $is_pro );
                        break;
                }
                ?>
            </form>
        </div>
        <?php
    }

    /* -------------------------------------------------------------------------
     * TABS
     * ---------------------------------------------------------------------- */

    protected function render_tab_dashboard( $email_delay, $sms_delay, $is_pro ) {
        ?>
        <div class="cartmate-grid">
            <div class="cartmate-card">
                <h2 class="cartmate-card-title"><?php esc_html_e( 'Quick overview', 'cartmate' ); ?></h2>
                <p class="cartmate-card-description"><?php esc_html_e( 'Here’s how WPCartMate is set up on your store right now.', 'cartmate' ); ?></p>

                <ul class="cartmate-metrics">
                    <li class="cartmate-metrics__item">
                        <span class="cartmate-metrics__label"><?php esc_html_e( 'Email reminder delay', 'cartmate' ); ?></span>
                        <span class="cartmate-metrics__value"><?php echo esc_html( $email_delay ); ?> <?php esc_html_e( 'min', 'cartmate' ); ?></span>
                    </li>
                    <li class="cartmate-metrics__item">
                        <span class="cartmate-metrics__label"><?php esc_html_e( 'SMS follow-up delay', 'cartmate' ); ?></span>
                        <span class="cartmate-metrics__value"><?php echo esc_html( $sms_delay ); ?> <?php esc_html_e( 'min', 'cartmate' ); ?></span>
                    </li>
                    <li class="cartmate-metrics__item">
                        <span class="cartmate-metrics__label"><?php esc_html_e( 'License mode', 'cartmate' ); ?></span>
                        <span class="cartmate-metrics__value"><?php echo $is_pro ? esc_html__( 'Pro active', 'cartmate' ) : esc_html__( 'Free mode', 'cartmate' ); ?></span>
                    </li>
                </ul>

                <p class="cartmate-card-footer"><?php esc_html_e( 'Use the tabs above to configure follow-ups.', 'cartmate' ); ?></p>
            </div>

            <div class="cartmate-card">
                <h2 class="cartmate-card-title"><?php esc_html_e( 'Recommended next steps', 'cartmate' ); ?></h2>
                <ol class="cartmate-steps">
                    <li><?php esc_html_e( 'Tune your Email #1 subject/body to match your store’s tone.', 'cartmate' ); ?></li>
                    <li><?php esc_html_e( 'Add 1–5 scheduled follow-up emails in the Scheduled Email Followups tab.', 'cartmate' ); ?></li>
                    <li><?php esc_html_e( 'Configure ClickSend and send a test SMS.', 'cartmate' ); ?></li>
                </ol>
            </div>
        </div>
        <?php
    }

    protected function render_tab_email( $email_delay, $email_subject, $email_body ) {
        ?>
        <div class="cartmate-grid">
            <div class="cartmate-card">
                <h2 class="cartmate-card-title"><?php esc_html_e( 'Email reminder (Email #1)', 'cartmate' ); ?></h2>
                <p class="cartmate-card-description"><?php esc_html_e( 'Sent after a cart is abandoned and an email is captured.', 'cartmate' ); ?></p>

                <div class="cartmate-field">
                    <label for="cartmate_email_delay_minutes"><?php esc_html_e( 'Delay before email (minutes)', 'cartmate' ); ?></label>
                    <input type="number" min="0" id="cartmate_email_delay_minutes" name="cartmate_email_delay_minutes" value="<?php echo esc_attr( $email_delay ); ?>" class="cartmate-input cartmate-input--sm" />
                </div>

                <div class="cartmate-field">
                    <label for="cartmate_email_subject"><?php esc_html_e( 'Email subject', 'cartmate' ); ?></label>
                    <input type="text" id="cartmate_email_subject" name="cartmate_email_subject" value="<?php echo esc_attr( $email_subject ); ?>" class="cartmate-input" />
                </div>

                <div class="cartmate-field">
                    <label for="cartmate_email_body"><?php esc_html_e( 'Email body', 'cartmate' ); ?></label>
                    <textarea id="cartmate_email_body" name="cartmate_email_body" rows="8" class="cartmate-textarea"><?php echo esc_textarea( $email_body ); ?></textarea>
                    <p class="description"><?php esc_html_e( 'Tokens: {name}, {site_name}, {cart_url}.', 'cartmate' ); ?></p>
                </div>

                <button type="submit" class="button button-primary cartmate-button-primary">
                    <?php esc_html_e( 'Save email settings', 'cartmate' ); ?>
                </button>
            </div>
        </div>
        <?php
    }

    /**
     * Scheduled Email Followups (CRUD)
     */
    protected function render_tab_sequences() {
        global $wpdb;

        $table = $wpdb->prefix . 'cartmate_email_sequences';

        $rows = $wpdb->get_results(
            "SELECT id, step_number, delay_days, enabled, subject, body, sort_order
             FROM {$table}
             ORDER BY sort_order ASC, id ASC",
            ARRAY_A
        );
        ?>
        <div class="cartmate-grid">
            <div class="cartmate-card">
                <h2 class="cartmate-card-title"><?php esc_html_e( 'Scheduled Email Followups', 'cartmate' ); ?></h2>
                <p class="cartmate-card-description">
                    <?php esc_html_e( 'Add up to 5 follow-up emails (scheduled in days after Email #1).', 'cartmate' ); ?>
                </p>

                <div class="cartmate-grid" style="grid-template-columns: 1fr 1fr; gap: 16px;">
                    <div class="cartmate-card" style="margin:0;">
                        <h3 class="cartmate-card-title" style="margin-top:0;"><?php esc_html_e( 'Add a new follow-up', 'cartmate' ); ?></h3>

                        <div class="cartmate-field">
                            <label>
                                <input type="checkbox" name="sequence_enabled" value="1" checked />
                                <?php esc_html_e( 'Enabled', 'cartmate' ); ?>
                            </label>
                        </div>

                        <div class="cartmate-field">
                            <label for="sequence_delay_days"><?php esc_html_e( 'Send after (days from Email #1)', 'cartmate' ); ?></label>
                            <input type="number" min="0" id="sequence_delay_days" name="sequence_delay_days" value="1" class="cartmate-input cartmate-input--sm" />
                        </div>

                        <div class="cartmate-field">
                            <label for="sequence_subject"><?php esc_html_e( 'Subject', 'cartmate' ); ?></label>
                            <input type="text" id="sequence_subject" name="sequence_subject" value="<?php echo esc_attr( __( 'Quick reminder: your cart is still waiting at {site_name}', 'cartmate' ) ); ?>" class="cartmate-input" />
                        </div>

                        <div class="cartmate-field">
                            <label for="sequence_body"><?php esc_html_e( 'Body', 'cartmate' ); ?></label>
                            <textarea id="sequence_body" name="sequence_body" rows="6" class="cartmate-textarea"><?php echo esc_textarea( "Hi {name},\n\nJust checking in — your cart is still saved.\n{cart_url}" ); ?></textarea>
                            <p class="description"><?php esc_html_e( 'Tokens: {name}, {site_name}, {cart_url}.', 'cartmate' ); ?></p>
                        </div>

                        <button type="submit" name="cartmate_add_sequence" value="1" class="button button-primary cartmate-button-primary">
                            <?php esc_html_e( 'Add follow-up', 'cartmate' ); ?>
                        </button>
                    </div>

                    <div class="cartmate-card" style="margin:0;">
                        <h3 class="cartmate-card-title" style="margin-top:0;"><?php esc_html_e( 'Existing follow-ups', 'cartmate' ); ?></h3>

                        <?php if ( empty( $rows ) ) : ?>
                            <p style="margin-top:8px;"><?php esc_html_e( 'No follow-ups created yet. Add one on the left.', 'cartmate' ); ?></p>
                        <?php else : ?>
                            <?php foreach ( $rows as $r ) : ?>
                                <div class="cartmate-card" style="margin:0 0 12px 0;">
                                    <div style="display:flex; align-items:center; justify-content:space-between; gap:12px;">
                                        <strong>
                                            <?php
                                            printf(
                                                /* translators: %d = step number */
                                                esc_html__( 'Follow-up Email #%d', 'cartmate' ),
                                                (int) $r['step_number']
                                            );
                                            ?>
                                        </strong>
                                        <span style="opacity:.75;">
                                            <?php
                                            printf(
                                                /* translators: %d = delay in days */
                                                esc_html__( '%d day(s) after Email #1', 'cartmate' ),
                                                (int) $r['delay_days']
                                            );
                                            ?>
                                        </span>
                                    </div>

                                    <div class="cartmate-field" style="margin-top:10px;">
                                        <label>
                                            <input type="checkbox" name="<?php echo esc_attr( 'sequence_enabled_' . (int) $r['id'] ); ?>" value="1" <?php checked( (int) $r['enabled'], 1 ); ?> />
                                            <?php esc_html_e( 'Enabled', 'cartmate' ); ?>
                                        </label>
                                    </div>

                                    <div class="cartmate-field">
                                        <label><?php esc_html_e( 'Delay (days)', 'cartmate' ); ?></label>
                                        <input type="number" min="0" name="<?php echo esc_attr( 'sequence_delay_days_' . (int) $r['id'] ); ?>" value="<?php echo esc_attr( (int) $r['delay_days'] ); ?>" class="cartmate-input cartmate-input--sm" />
                                    </div>

                                    <div class="cartmate-field">
                                        <label><?php esc_html_e( 'Subject', 'cartmate' ); ?></label>
                                        <input type="text" name="<?php echo esc_attr( 'sequence_subject_' . (int) $r['id'] ); ?>" value="<?php echo esc_attr( $r['subject'] ); ?>" class="cartmate-input" />
                                    </div>

                                    <div class="cartmate-field">
                                        <label><?php esc_html_e( 'Body', 'cartmate' ); ?></label>
                                        <textarea name="<?php echo esc_attr( 'sequence_body_' . (int) $r['id'] ); ?>" rows="5" class="cartmate-textarea"><?php echo esc_textarea( $r['body'] ); ?></textarea>
                                    </div>

                                    <div style="display:flex; gap:10px; align-items:center;">
                                        <button type="submit" name="cartmate_update_sequence_id" value="<?php echo esc_attr( (int) $r['id'] ); ?>" class="button cartmate-button-secondary">
                                            <?php esc_html_e( 'Save changes', 'cartmate' ); ?>
                                        </button>

                                        <button type="submit" name="cartmate_delete_sequence_id" value="<?php echo esc_attr( (int) $r['id'] ); ?>" class="button cartmate-button-outline" onclick="return confirm('Delete this follow-up?');">
                                            <?php esc_html_e( 'Delete', 'cartmate' ); ?>
                                        </button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>

                        <p class="cartmate-card-footer" style="margin-top:10px;">
                            <?php esc_html_e( 'Follow-ups are evaluated by WP-Cron. They should only send when the prior step has already been sent.', 'cartmate' ); ?>
                        </p>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    protected function render_tab_sms( $sms_delay, $cs_username, $cs_api_key, $cs_sender, $sms_template, $test_phone, $sms_default_country, $sms_test_country ) {
        $countries = $this->get_wc_countries();
        ?>
        <div class="cartmate-grid">
            <div class="cartmate-card">
                <h2 class="cartmate-card-title"><?php esc_html_e( 'SMS reminder (ClickSend)', 'cartmate' ); ?></h2>
                <p class="cartmate-card-description"><?php esc_html_e( 'Send a gentle SMS nudge after your email to recover even more carts.', 'cartmate' ); ?></p>

                <div class="cartmate-field">
                    <label for="cartmate_sms_default_country"><?php esc_html_e( 'Default SMS country', 'cartmate' ); ?></label>
                    <select id="cartmate_sms_default_country" name="cartmate_sms_default_country" class="cartmate-input">
                        <?php foreach ( $countries as $code => $name ) : ?>
                            <?php $calling = $this->get_calling_code( $code ); ?>
                            <option value="<?php echo esc_attr( $code ); ?>" <?php selected( $sms_default_country, $code ); ?>>
                                <?php echo esc_html( $name . ( $calling ? ' (+' . $calling . ')' : '' ) ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description"><?php esc_html_e( 'Used to format customer phone numbers when a local number is captured.', 'cartmate' ); ?></p>
                </div>

                <div class="cartmate-field">
                    <label for="cartmate_sms_followup_delay"><?php esc_html_e( 'Follow-up delay after email (minutes)', 'cartmate' ); ?></label>
                    <input type="number" min="0" id="cartmate_sms_followup_delay" name="cartmate_sms_followup_delay" value="<?php echo esc_attr( $sms_delay ); ?>" class="cartmate-input cartmate-input--sm" />
                </div>

                <div class="cartmate-field">
                    <label for="cartmate_clicksend_username"><?php esc_html_e( 'ClickSend username', 'cartmate' ); ?></label>
                    <input type="text" id="cartmate_clicksend_username" name="cartmate_clicksend_username" value="<?php echo esc_attr( $cs_username ); ?>" class="cartmate-input" />
                </div>

                <div class="cartmate-field">
                    <label for="cartmate_clicksend_api_key"><?php esc_html_e( 'ClickSend API key', 'cartmate' ); ?></label>
                    <input type="text" id="cartmate_clicksend_api_key" name="cartmate_clicksend_api_key" value="<?php echo esc_attr( $cs_api_key ); ?>" class="cartmate-input" />
                </div>

                <div class="cartmate-field">
                    <label for="cartmate_clicksend_sender"><?php esc_html_e( 'Sender name', 'cartmate' ); ?></label>
                    <input type="text" id="cartmate_clicksend_sender" name="cartmate_clicksend_sender" value="<?php echo esc_attr( $cs_sender ); ?>" class="cartmate-input" />
                </div>

                <div class="cartmate-field">
                    <label for="cartmate_sms_template"><?php esc_html_e( 'SMS template', 'cartmate' ); ?></label>
                    <textarea id="cartmate_sms_template" name="cartmate_sms_template" rows="4" class="cartmate-textarea"><?php echo esc_textarea( $sms_template ); ?></textarea>
                    <p class="description"><?php esc_html_e( 'Tokens: {name}, {site_name}, {cart_url}. Keep it short and clear.', 'cartmate' ); ?></p>
                </div>

                <hr style="margin:18px 0;">

                <div class="cartmate-field">
                    <label for="cartmate_sms_test_country"><?php esc_html_e( 'Test SMS country', 'cartmate' ); ?></label>
                    <select id="cartmate_sms_test_country" name="cartmate_sms_test_country" class="cartmate-input cartmate-input--sm">
                        <?php foreach ( $countries as $code => $name ) : ?>
                            <?php $calling = $this->get_calling_code( $code ); ?>
                            <option value="<?php echo esc_attr( $code ); ?>" <?php selected( $sms_test_country, $code ); ?>>
                                <?php echo esc_html( $name . ( $calling ? ' (+' . $calling . ')' : '' ) ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="cartmate-field">
                    <label for="cartmate_test_phone"><?php esc_html_e( 'Test phone number (local)', 'cartmate' ); ?></label>
                    <input type="text" id="cartmate_test_phone" name="cartmate_test_phone" value="<?php echo esc_attr( $test_phone ); ?>" class="cartmate-input cartmate-input--sm" />
                    <p class="description"><?php esc_html_e( 'Enter the local number only. Country code will be applied based on the selector above.', 'cartmate' ); ?></p>
                </div>

                <button type="submit" class="button button-primary cartmate-button-primary">
                    <?php esc_html_e( 'Save SMS settings', 'cartmate' ); ?>
                </button>
                <button type="submit" name="cartmate_send_test_sms" value="1" class="button cartmate-button-secondary" style="margin-left:10px;">
    <?php esc_html_e( 'Send test SMS', 'cartmate' ); ?>
</button>
            </div>
        </div>
        <?php
    }

    protected function render_tab_notifications( $admin_email ) {
        ?>
        <div class="cartmate-grid">
            <div class="cartmate-card cartmate-card--narrow">
                <h2 class="cartmate-card-title"><?php esc_html_e( 'Admin notifications', 'cartmate' ); ?></h2>

                <div class="cartmate-field">
                    <label for="cartmate_admin_email"><?php esc_html_e( 'Admin notification email', 'cartmate' ); ?></label>
                    <input type="email" id="cartmate_admin_email" name="cartmate_admin_email" value="<?php echo esc_attr( $admin_email ); ?>" class="cartmate-input" />
                </div>

                <button type="submit" class="button cartmate-button-secondary">
                    <?php esc_html_e( 'Save admin email', 'cartmate' ); ?>
                </button>
            </div>
        </div>
        <?php
    }

    protected function render_tab_license( $license_key, $is_pro ) {
        ?>
        <div class="cartmate-grid">
            <div class="cartmate-card cartmate-card--narrow">
                <h2 class="cartmate-card-title"><?php esc_html_e( 'License & upgrades', 'cartmate' ); ?></h2>

                <div class="cartmate-field">
                    <label for="cartmate_license_key"><?php esc_html_e( 'License key', 'cartmate' ); ?></label>
                    <input type="text" id="cartmate_license_key" name="cartmate_license_key" value="<?php echo esc_attr( $license_key ); ?>" class="cartmate-input" />
                </div>

                <button type="submit" class="button cartmate-button-secondary">
                    <?php esc_html_e( 'Save license', 'cartmate' ); ?>
                </button>

                <p class="cartmate-status-line" style="margin-top:12px;">
                    <strong><?php esc_html_e( 'Status:', 'cartmate' ); ?></strong>
                    <?php echo $is_pro ? esc_html__( 'Pro active on this site.', 'cartmate' ) : esc_html__( 'Free version active.', 'cartmate' ); ?>
                </p>
            </div>
        </div>
        <?php
    }

    protected function render_tab_status() {
        ?>
        <div class="cartmate-grid">
            <div class="cartmate-card">
                <h2 class="cartmate-card-title"><?php esc_html_e( 'System status', 'cartmate' ); ?></h2>
                <ul class="cartmate-status-list">
                    <li><strong><?php esc_html_e( 'Cron:', 'cartmate' ); ?></strong> <?php esc_html_e( 'Email/SMS processors run via WP-Cron.', 'cartmate' ); ?></li>
                    <li><strong><?php esc_html_e( 'DB:', 'cartmate' ); ?></strong> <?php esc_html_e( 'Sequences are stored in cartmate_email_sequences.', 'cartmate' ); ?></li>
                </ul>
            </div>
        </div>
        <?php
    }
}

endif;
