<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class CartMate_Messenger {

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'add_settings_tab' ] );
    }

    /**
     * Add a Messenger settings page under Cart Mate.
     */
    public function add_settings_tab() {
        add_submenu_page(
            'cartmate',
            'Messenger',
            'Messenger',
            'manage_options',
            'cartmate-messenger',
            [ $this, 'render_settings_page' ]
        );
    }

    /**
     * Render Messenger settings and test button.
     */
    public function render_settings_page() {
        echo '<div class="wrap"><h1>Cart Mate – Messenger Integration</h1>';

        // Handle save
        if ( isset( $_POST['cartmate_save_messenger'] ) ) {
            update_option( 'cartmate_fb_app_id', sanitize_text_field( $_POST['cartmate_fb_app_id'] ) );
            update_option( 'cartmate_fb_page_id', sanitize_text_field( $_POST['cartmate_fb_page_id'] ) );
            update_option( 'cartmate_fb_page_token', sanitize_text_field( $_POST['cartmate_fb_page_token'] ) );
            update_option( 'cartmate_fb_verify_token', sanitize_text_field( $_POST['cartmate_fb_verify_token'] ) );
            echo '<div class="updated"><p>Messenger settings saved.</p></div>';
        }

        // Handle test message
        if ( isset( $_POST['cartmate_test_message'] ) ) {
            $psid = sanitize_text_field( $_POST['cartmate_test_psid'] );
            $ok   = self::send_message( $psid, '✅ Test message from Cart Mate. Your Messenger integration is working!' );
            if ( $ok ) {
                echo '<div class="updated"><p>Test message sent successfully.</p></div>';
            } else {
                echo '<div class="error"><p>Failed to send test message. Check your Page Access Token and PSID.</p></div>';
            }
        }

        $app_id       = get_option( 'cartmate_fb_app_id', '' );
        $page_id      = get_option( 'cartmate_fb_page_id', '' );
        $page_token   = get_option( 'cartmate_fb_page_token', '' );
        $verify_token = get_option( 'cartmate_fb_verify_token', '' );

        echo '<form method="post">';
        echo '<h2>Messenger App Settings</h2>';
        echo '<table class="form-table">';
        echo '<tr><th>Facebook App ID</th><td><input type="text" name="cartmate_fb_app_id" value="' . esc_attr( $app_id ) . '" class="regular-text"></td></tr>';
        echo '<tr><th>Facebook Page ID</th><td><input type="text" name="cartmate_fb_page_id" value="' . esc_attr( $page_id ) . '" class="regular-text"></td></tr>';
        echo '<tr><th>Page Access Token</th><td><input type="text" name="cartmate_fb_page_token" value="' . esc_attr( $page_token ) . '" class="regular-text"></td></tr>';
        echo '<tr><th>Verify Token</th><td><input type="text" name="cartmate_fb_verify_token" value="' . esc_attr( $verify_token ) . '" class="regular-text"></td></tr>';
        echo '</table>';
        echo '<p><input type="submit" name="cartmate_save_messenger" class="button-primary" value="Save Messenger Settings"></p>';
        echo '</form>';

        echo '<hr style="margin: 30px 0;">';
        echo '<h2>Send a Test Message</h2>';
        echo '<p>Enter a valid PSID from an opt-in user to confirm your Messenger connection is live.</p>';
        echo '<form method="post">';
        echo '<input type="text" name="cartmate_test_psid" placeholder="Enter PSID..." class="regular-text" required> ';
        echo '<input type="submit" name="cartmate_test_message" class="button" value="Send Test Message">';
        echo '</form>';

        echo '</div>';
    }

    /**
     * Send a plain text message to a PSID through the Send API.
     */
    public static function send_message( $psid, $text ) {
        $token = get_option( 'cartmate_fb_page_token' );
        if ( empty( $token ) || empty( $psid ) ) return false;

        $body = json_encode([
            'recipient' => [ 'id' => $psid ],
            'message'   => [ 'text' => $text ],
        ]);

        $response = wp_remote_post(
            "https://graph.facebook.com/v20.0/me/messages?access_token={$token}",
            [
                'headers' => [ 'Content-Type' => 'application/json' ],
                'body'    => $body,
                'timeout' => 15,
            ]
        );

        return ! is_wp_error( $response );
    }
}
