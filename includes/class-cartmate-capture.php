<?php
/**
 * CartMate Capture (Classic + Blocks)
 *
 * Captures customer email and cart contents/totals and upserts into
 * {$wpdb->prefix}cartmate_abandoned_carts.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'CartMate_Capture' ) ) {

	class CartMate_Capture {

		const INITIAL_DELAY_MINUTES = 15; // default; filterable via cartmate_initial_email_delay_minutes
		private static $did_init = false;

		public static function init() {
			if ( self::$did_init ) {
				return;
			}
			self::$did_init = true;

			// Classic checkout: fired by WC_AJAX::update_order_review(), provides $_POST['post_data'] as string.
			add_action( 'woocommerce_checkout_update_order_review', array( __CLASS__, 'capture_from_update_order_review' ), 20, 1 );

			// Blocks checkout / Store API: customer updated from request.
			add_action( 'woocommerce_store_api_cart_update_customer_from_request', array( __CLASS__, 'capture_from_store_api_customer_update' ), 20, 2 );
			add_action( 'woocommerce_store_api_checkout_update_customer_from_request', array( __CLASS__, 'capture_from_store_api_customer_update' ), 20, 2 );
		}

		/**
		 * Classic checkout capture.
		 *
		 * @param string $post_data URL-encoded form string.
		 */
		public static function capture_from_update_order_review( $post_data ) {
			try {
				if ( empty( $post_data ) ) {
					return;
				}
				if ( ! class_exists( 'WC' ) || ! WC()->cart ) {
					return;
				}

				$data = array();
				parse_str( (string) $post_data, $data );

				$email = isset( $data['billing_email'] ) ? sanitize_email( $data['billing_email'] ) : '';
				$first = isset( $data['billing_first_name'] ) ? sanitize_text_field( $data['billing_first_name'] ) : '';
				$last  = isset( $data['billing_last_name'] ) ? sanitize_text_field( $data['billing_last_name'] ) : '';
				$phone = isset( $data['billing_phone'] ) ? sanitize_text_field( $data['billing_phone'] ) : '';

				self::upsert_abandoned_cart( $email, $first, $last, $phone, 'classic_update_order_review' );

			} catch ( \Throwable $e ) {
				self::log( 'ERROR capture_from_update_order_review: ' . $e->getMessage() );
			}
		}

		/**
		 * Store API capture for Blocks checkout.
		 *
		 * @param WC_Customer      $customer
		 * @param WP_REST_Request  $request
		 */
		public static function capture_from_store_api_customer_update( $customer, $request ) {
			try {
				$email = '';
				$first = '';
				$last  = '';
				$phone = '';

				if ( is_object( $customer ) && method_exists( $customer, 'get_email' ) ) {
					$email = sanitize_email( $customer->get_email() );
				}

				if ( is_object( $request ) && method_exists( $request, 'get_param' ) ) {
					$billing = $request->get_param( 'billing_address' );
					if ( is_array( $billing ) ) {
						if ( empty( $email ) && ! empty( $billing['email'] ) ) {
							$email = sanitize_email( $billing['email'] );
						}
						$first = sanitize_text_field( $billing['first_name'] ?? '' );
						$last  = sanitize_text_field( $billing['last_name'] ?? '' );
						$phone = sanitize_text_field( $billing['phone'] ?? '' );
					}
				}

				self::upsert_abandoned_cart( $email, $first, $last, $phone, 'store_api_customer_update' );

			} catch ( \Throwable $e ) {
				self::log( 'ERROR capture_from_store_api_customer_update: ' . $e->getMessage() );
			}
		}

		/* ---------------------------
		 * Core upsert
		 * ------------------------- */

		private static function upsert_abandoned_cart( $email, $first, $last, $phone, $source ) {
			global $wpdb;

			$email = sanitize_email( (string) $email );
			if ( empty( $email ) ) {
				self::log( 'CAPTURE skip: empty email source=' . $source );
				return;
			}

			if ( ! class_exists( 'WC' ) || ! WC()->cart ) {
				self::log( 'CAPTURE skip: WC()->cart missing email=' . $email . ' source=' . $source );
				return;
			}

			// Ensure totals exist.
			WC()->cart->calculate_totals();

			$cart = WC()->cart->get_cart();
			if ( empty( $cart ) ) {
				self::log( 'CAPTURE skip: empty cart email=' . $email . ' source=' . $source );
				return;
			}

			$table = $wpdb->prefix . 'cartmate_abandoned_carts';

			// Table existence check.
			if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table ) ) !== $table ) {
				self::log( 'CAPTURE ERROR: missing table ' . $table );
				return;
			}

			$cols   = self::get_table_columns( $table );
			$id_col = in_array( 'id', $cols, true ) ? 'id' : ( in_array( 'cart_id', $cols, true ) ? 'cart_id' : 'id' );

			$name = trim( trim( (string) $first ) . ' ' . trim( (string) $last ) );

			// Build a stable hash for upsert.
			$cart_hash = method_exists( WC()->cart, 'get_cart_hash' ) ? (string) WC()->cart->get_cart_hash() : '';
			$hash      = md5( strtolower( $email ) . '|' . $cart_hash );

			// Minimal cart contents snapshot.
			$items = array();
			foreach ( $cart as $item_key => $item ) {
				$items[] = array(
					'key'          => (string) $item_key,
					'product_id'   => (int) ( $item['product_id'] ?? 0 ),
					'variation_id' => (int) ( $item['variation_id'] ?? 0 ),
					'quantity'     => (int) ( $item['quantity'] ?? 0 ),
				);
			}
			$cart_contents = maybe_serialize( $items );

			// Cart total (float).
			$total = (float) ( WC()->cart->total ?? 0 );

			$now_gmt = gmdate( 'Y-m-d H:i:s' );

			$delay_minutes = (int) apply_filters( 'cartmate_initial_email_delay_minutes', self::INITIAL_DELAY_MINUTES );
			$delay_minutes = max( 0, $delay_minutes );
			$next_email_at = gmdate( 'Y-m-d H:i:s', time() + ( $delay_minutes * MINUTE_IN_SECONDS ) );

			// Build insert/update arrays defensively (only set columns that exist).
			$data    = array();
			$formats = array();

			self::maybe_set( $cols, $data, $formats, 'cart_hash', $hash, '%s' );
			self::maybe_set( $cols, $data, $formats, 'email', $email, '%s' );
			self::maybe_set( $cols, $data, $formats, 'name', $name, '%s' );
			self::maybe_set( $cols, $data, $formats, 'phone', $phone, '%s' );
			self::maybe_set( $cols, $data, $formats, 'cart_contents', $cart_contents, '%s' );
			self::maybe_set( $cols, $data, $formats, 'cart_total', $total, '%f' );

			// Only set status if not already emailed_* (we don’t want capture overwriting state).
			$existing_status = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT status FROM {$table} WHERE email=%s ORDER BY {$id_col} DESC LIMIT 1",
					$email
				)
			);
			$existing_status = is_string( $existing_status ) ? $existing_status : '';

			$status_to_set = ( preg_match( '/^emailed_\d+$/', $existing_status ) ) ? $existing_status : 'captured';
			self::maybe_set( $cols, $data, $formats, 'status', $status_to_set, '%s' );

			self::maybe_set( $cols, $data, $formats, 'next_email_at', $next_email_at, '%s' );
			self::maybe_set( $cols, $data, $formats, 'updated_at', $now_gmt, '%s' );

			// For inserts only.
			$insert_data    = $data;
			$insert_formats = $formats;
			if ( in_array( 'created_at', $cols, true ) && ! isset( $insert_data['created_at'] ) ) {
				$insert_data['created_at'] = $now_gmt;
				$insert_formats[]          = '%s';
			}

			$existing_id = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT {$id_col} FROM {$table} WHERE email=%s ORDER BY {$id_col} DESC LIMIT 1",
					$email
				)
			);

			if ( $existing_id > 0 ) {
				// Do not overwrite created_at on updates.
				unset( $data['created_at'] );

				$ok = $wpdb->update(
					$table,
					$data,
					array( $id_col => $existing_id ),
					$formats,
					array( '%d' )
				);

				self::log( 'CAPTURE update id=' . $existing_id . ' email=' . $email . ' total=' . $total . ' source=' . $source . ' result=' . ( $ok === false ? 'FAIL' : 'OK' ) );
				if ( $ok === false ) {
					self::log( 'CAPTURE update DB error: ' . $wpdb->last_error );
				}
				return;
			}

			$ok = $wpdb->insert( $table, $insert_data, $insert_formats );

			self::log( 'CAPTURE insert id=' . (int) $wpdb->insert_id . ' email=' . $email . ' total=' . $total . ' source=' . $source . ' result=' . ( $ok === false ? 'FAIL' : 'OK' ) );
			if ( $ok === false ) {
				self::log( 'CAPTURE insert DB error: ' . $wpdb->last_error );
			}
		}

		/* ---------------------------
		 * Helpers
		 * ------------------------- */

		private static function maybe_set( $cols, &$data, &$formats, $key, $value, $format ) {
			if ( in_array( strtolower( $key ), $cols, true ) ) {
				$data[ $key ] = $value;
				$formats[]    = $format;
			}
		}

		private static function get_table_columns( $table_name ) {
			global $wpdb;

			$cols    = array();
			$results = $wpdb->get_results( "SHOW COLUMNS FROM {$table_name}", ARRAY_A );

			if ( is_array( $results ) ) {
				foreach ( $results as $row ) {
					if ( ! empty( $row['Field'] ) ) {
						$cols[] = strtolower( $row['Field'] );
					}
				}
			}

			return $cols;
		}

		private static function log( $msg ) {
			if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				error_log( '[CartMate Capture] ' . $msg );
			}
		}
	}
}
