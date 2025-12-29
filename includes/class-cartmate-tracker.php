<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class CartMate_Tracker {
    public function __construct() {
        add_action( 'template_redirect', [ $this, 'capture_cart' ] );
        add_action( 'woocommerce_cart_updated', [ $this, 'capture_cart' ] );
    }

    public function capture_cart() {
        if ( ! WC()->cart || WC()->cart->is_empty() ) return;

        $cart = WC()->cart->get_cart();
        $email = '';
        $user_id = get_current_user_id();

        if ( $user_id ) {
            $user = get_userdata( $user_id );
            $email = $user->user_email;
        } elseif ( isset( $_POST['billing_email'] ) ) {
            $email = sanitize_email( $_POST['billing_email'] );
        }

        $session_id = WC()->session->get_customer_id();
        global $wpdb;
        $table = $wpdb->prefix . 'cartmate_carts';

        $data = [
            'user_id' => $user_id ?: null,
            'session_id' => $session_id,
            'cart_contents' => maybe_serialize( $cart ),
            'email' => $email,
            'last_updated' => current_time( 'mysql' ),
        ];

        $exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM $table WHERE session_id = %s LIMIT 1",
            $session_id
        ));

        if ( $exists ) {
            $wpdb->update( $table, $data, [ 'id' => $exists ] );
        } else {
            $wpdb->insert( $table, $data );
        }
    }
}
add_action( 'woocommerce_order_status_completed', function( $order_id ) {
    global $wpdb;
    $order = wc_get_order( $order_id );
    $email = $order->get_billing_email();
    $table = $wpdb->prefix . 'cartmate_carts';
    $wpdb->update( $table, [ 'status' => 'recovered' ], [ 'email' => $email ] );
});
