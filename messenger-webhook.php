<?php
/**
 * Messenger Webhook for Cart Mate
 */
require_once dirname( __FILE__ ) . '/../../../../wp-load.php'; // adjust if needed

// Verify webhook during setup
if ( $_SERVER['REQUEST_METHOD'] === 'GET' && isset( $_GET['hub_mode'] ) && $_GET['hub_mode'] === 'subscribe' ) {
    $verify_token = get_option( 'cartmate_fb_verify_token' );
    if ( $_GET['hub_verify_token'] === $verify_token ) {
        echo sanitize_text_field( $_GET['hub_challenge'] );
        exit;
    }
    status_header(403);
    exit;
}

// Handle POST events from Facebook
$input = json_decode( file_get_contents( 'php://input' ), true );
if ( isset( $input['entry'][0]['messaging'][0]['optin'] ) ) {
    $optin = $input['entry'][0]['messaging'][0];
    $psid  = sanitize_text_field( $optin['sender']['id'] );
    $ref   = isset( $optin['optin']['ref'] ) ? absint( $optin['optin']['ref'] ) : 0;

    if ( $ref > 0 && $psid ) {
        update_user_meta( $ref, 'cartmate_messenger_psid', $psid );
        update_user_meta( $ref, 'cartmate_messenger_optin_time', current_time( 'mysql' ) );
    }
}

status_header(200);
echo 'EVENT_RECEIVED';
exit;
