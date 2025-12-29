<?php
/**
 * Cart Mate – helper + capture functions.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Simple logger.
 *
 * @param string $message
 */
if ( ! function_exists( 'cartmate_log' ) ) {
    function cartmate_log( $message ) {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( 'Cart Mate Functions: ' . $message );
        }
    }
}

cartmate_log( 'loaded.' );

/**
 * Get the CartMate DB table name.
 *
 * @return string
 */
function cartmate_get_carts_table() {
    global $wpdb;
    return $wpdb->prefix . 'cartmate_carts';
}

/**
 * Shared helper: insert or update a cart row.
 *
 * @param array $data {
 *   @type string contact_email
 *   @type string contact_phone
 *   @type int    email_opt_in
 *   @type int    sms_opt_in
 * }
 *
 * @return true|WP_Error
 */
function cartmate_upsert_cart_row( $data ) {
    global $wpdb;

    $table = cartmate_get_carts_table();

    $email = isset( $data['contact_email'] ) ? sanitize_email( $data['contact_email'] ) : '';
    $phone = isset( $data['contact_phone'] ) ? sanitize_text_field( $data['contact_phone'] ) : '';

    if ( empty( $email ) && empty( $phone ) ) {
        return new WP_Error( 'cartmate_no_contact', 'No email or phone supplied.' );
    }

    $email_opt_in = ! empty( $data['email_opt_in'] ) ? 1 : 0;
    $sms_opt_in   = ! empty( $data['sms_opt_in'] )   ? 1 : 0;

    $now = current_time( 'timestamp', true );

    // Try to find an existing, unrecovered cart for this email.
    $existing = null;

    if ( ! empty( $email ) ) {
        $existing = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, contact_email, contact_phone 
                 FROM {$table} 
                 WHERE contact_email = %s 
                   AND recovered = 0 
                 ORDER BY id DESC 
                 LIMIT 1",
                $email
            ),
            ARRAY_A
        );
    }

    if ( $existing ) {
        $new_phone = $phone ? $phone : $existing['contact_phone'];

        $wpdb->update(
            $table,
            array(
                'contact_phone' => $new_phone,
                'updated_at'    => $now,
            ),
            array( 'id' => (int) $existing['id'] ),
            array( '%s', '%d' ),
            array( '%d' )
        );

        cartmate_log( 'Upsert: updated existing cart row ID ' . $existing['id'] );
        return true;
    }

    // Insert a fresh row.
    $inserted = $wpdb->insert(
        $table,
        array(
            'contact_email'       => $email,
            'contact_phone'       => $phone,
            'email_opt_in'        => $email_opt_in,
            'sms_opt_in'          => $sms_opt_in,
            'recovered'           => 0,
            'abandoned_at'        => $now,
            'email_first_sent_at' => 0,
            'sms_first_sent_at'   => 0,
            'created_at'          => $now,
            'updated_at'          => $now,
        ),
        array(
            '%s', // contact_email
            '%s', // contact_phone
            '%d', // email_opt_in
            '%d', // sms_opt_in
            '%d', // recovered
            '%d', // abandoned_at
            '%d', // email_first_sent_at
            '%d', // sms_first_sent_at
            '%d', // created_at
            '%d', // updated_at
        )
    );

    if ( false === $inserted ) {
        cartmate_log( 'Upsert insert error: ' . $wpdb->last_error );
        return new WP_Error( 'cartmate_db_insert_failed', $wpdb->last_error );
    }

    cartmate_log( 'Upsert: inserted new cart row ID ' . $wpdb->insert_id );
    return true;
}

/**
 * SAFETY NET: capture any front-end POST that includes email/phone.
 */
function cartmate_capture_from_request_init() {

    // Only front-end.
    if ( is_admin() ) {
        return;
    }

    // Only POST requests.
    if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'POST' !== $_SERVER['REQUEST_METHOD'] ) {
        return;
    }

    if ( empty( $_POST ) ) {
        return;
    }

    $uri = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '';
    cartmate_log( 'init capture: POST detected on URI ' . $uri );

    $keys = array_keys( $_POST );
    cartmate_log( 'init capture: POST keys = ' . implode( ', ', $keys ) );
	
	// If WooCommerce is calling update_order_review, all checkout fields (including billing_email)
// are packed into $_POST['post_data'] as a URL-encoded string.
if ( ! empty( $_POST['post_data'] ) && is_string( $_POST['post_data'] ) ) {
	$parsed = array();
	parse_str( wp_unslash( $_POST['post_data'] ), $parsed );

	// Merge key fields into $_POST so the existing capture logic can find them.
	$map = array(
		'billing_email',
		'billing_phone',
		'billing_first_name',
		'billing_last_name',
	);

	foreach ( $map as $key ) {
		if ( empty( $_POST[ $key ] ) && ! empty( $parsed[ $key ] ) ) {
			$_POST[ $key ] = $parsed[ $key ];
		}
	}

	// Optional: helpful debug (no PII)
	if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
		error_log(
			'Cart Mate Functions: init capture: parsed post_data keys include billing_email=' .
			( ! empty( $parsed['billing_email'] ) ? 'yes' : 'no' ) .
			' billing_phone=' . ( ! empty( $parsed['billing_phone'] ) ? 'yes' : 'no' )
		);
	}
}


    // Try to find email.
    $email = '';
    if ( isset( $_POST['billing_email'] ) ) {
        $email = wp_unslash( $_POST['billing_email'] );
    } else {
        foreach ( $_POST as $key => $value ) {
            if ( stripos( $key, 'email' ) !== false && ! is_array( $value ) ) {
                $email = $value;
                break;
            }
        }
    }

    // Try to find phone.
    $phone = '';
    if ( isset( $_POST['billing_phone'] ) ) {
        $phone = wp_unslash( $_POST['billing_phone'] );
    } else {
        foreach ( $_POST as $key => $value ) {
            if ( ( stripos( $key, 'phone' ) !== false || stripos( $key, 'mobile' ) !== false ) && ! is_array( $value ) ) {
                $phone = $value;
                break;
            }
        }
    }

    $email = sanitize_email( $email );
    $phone = sanitize_text_field( $phone );

    if ( empty( $email ) && empty( $phone ) ) {
        cartmate_log( 'init capture: no email/phone found in POST, skipping.' );
        return;
    }

    cartmate_log(
        sprintf(
            'init capture: found contact email=%s, phone=%s',
            $email,
            $phone
        )
    );

    $result = cartmate_upsert_cart_row( array(
        'contact_email' => $email,
        'contact_phone' => $phone,
        'email_opt_in'  => 1,
        'sms_opt_in'    => $phone ? 1 : 0,
    ) );

    if ( is_wp_error( $result ) ) {
        cartmate_log( 'init capture error: ' . $result->get_error_message() );
    }
}
add_action( 'init', 'cartmate_capture_from_request_init' );

/**
 * MAIN FIX FOR YOUR SETUP:
 * WooCommerce Blocks / Store API checkout capture.
 *
 * This fires whenever the Blocks checkout updates the customer
 * from the request – exactly the flow that snippet you pasted uses.
 *
 * @param WC_Customer     $customer
 * @param WP_REST_Request $request
 */
function cartmate_capture_store_api_customer( $customer, $request ) {

    if ( ! class_exists( 'WC_Customer' ) || ! $customer instanceof WC_Customer ) {
        return;
    }

    $email = trim( (string) $customer->get_billing_email() );
    $phone = trim( (string) $customer->get_billing_phone() );

    if ( empty( $email ) && empty( $phone ) ) {
        cartmate_log( 'store API capture: no email/phone – skipping.' );
        return;
    }

    cartmate_log(
        sprintf(
            'store API capture: email=%s, phone=%s',
            $email,
            $phone
        )
    );

    $result = cartmate_upsert_cart_row( array(
        'contact_email' => $email,
        'contact_phone' => $phone,
        'email_opt_in'  => 1,
        'sms_opt_in'    => $phone ? 1 : 0,
    ) );

    if ( is_wp_error( $result ) ) {
        cartmate_log( 'store API capture error: ' . $result->get_error_message() );
    }
}
add_action(
    'woocommerce_store_api_checkout_update_customer_from_request',
    'cartmate_capture_store_api_customer',
    10,
    2
);