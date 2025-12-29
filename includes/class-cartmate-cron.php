<?php
/**
 * CartMate Cron handler
 *
 * - Registers five_minutes schedule
 * - Ensures cartmate_check_abandoned_carts is scheduled
 * - Prevents double-run via cooldown + overlap transient locks
 * - Sends step 1 + follow-ups based on cartmate_email_sequences
 * - Updates status + next_email_at
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'CartMate_Cron' ) ) {

	class CartMate_Cron {

		const CRON_HOOK    = 'cartmate_check_abandoned_carts';
		const SCHEDULE_KEY = 'five_minutes';

		// Overlap lock (prevents concurrent runs)
		const LOCK_KEY         = 'cartmate_cron_lock';
		const LOCK_TTL_SECONDS = 240; // 4 minutes (safe vs 5-minute recurrence)

		// Cooldown lock (prevents back-to-back duplicate hits a few seconds apart)
		const COOLDOWN_KEY         = 'cartmate_cron_cooldown';
		const COOLDOWN_TTL_SECONDS = 90; // blocks duplicate hits within 90 seconds

		private static $did_init = false;

		/**
		 * Bootstraps hooks.
		 */
		public static function init() {
			if ( self::$did_init ) {
				return;
			}
			self::$did_init = true;

			add_filter( 'cron_schedules', array( __CLASS__, 'register_schedules' ) );

			// Ensure the event exists even if activation didn't run for some reason.
			add_action( 'init', array( __CLASS__, 'ensure_event_scheduled' ) );

			// Cron handler.
			add_action( self::CRON_HOOK, array( __CLASS__, 'process_abandoned_carts' ) );
		}

		/**
		 * Add custom schedules.
		 */
		public static function register_schedules( $schedules ) {
			if ( ! isset( $schedules[ self::SCHEDULE_KEY ] ) ) {
				$schedules[ self::SCHEDULE_KEY ] = array(
					'interval' => 5 * MINUTE_IN_SECONDS,
					'display'  => __( 'Every 5 Minutes', 'cartmate' ),
				);
			}
			return $schedules;
		}

		/**
		 * Ensure the cron event is scheduled (without creating duplicates).
		 */
		public static function ensure_event_scheduled() {
			$schedules = wp_get_schedules();
			if ( ! isset( $schedules[ self::SCHEDULE_KEY ] ) ) {
				// Schedule key not registered yet on this request.
				return;
			}

			$next = wp_next_scheduled( self::CRON_HOOK );
			if ( false === $next ) {
				// Start in ~60 seconds to avoid immediate scheduling storms.
				wp_schedule_event( time() + 60, self::SCHEDULE_KEY, self::CRON_HOOK );
				self::log( 'Scheduled cron event ' . self::CRON_HOOK . ' with schedule=' . self::SCHEDULE_KEY );
			}
		}

		/**
		 * Main cron runner: sends step1 + follow-ups and updates DB.
		 */
		public static function process_abandoned_carts() {
			global $wpdb;

			$run_id = self::new_run_id();
			self::log( 'RUN_ID=' . $run_id . ' START process_abandoned_carts()' );

			// 1) Cooldown first: prevents back-to-back wp-cron hits a few seconds apart.
			if ( self::in_cooldown( $run_id ) ) {
				return;
			}

			// 2) Overlap lock: prevents concurrent overlap.
			if ( ! self::acquire_lock( $run_id ) ) {
				self::log( 'RUN_ID=' . $run_id . ' LOCKED - overlap lock active (' . self::LOCK_KEY . ')' );
				return;
			}

			try {
				$now_ts    = current_time( 'timestamp', true ); // GMT timestamp
				$now_mysql = gmdate( 'Y-m-d H:i:s', $now_ts );

				$carts_table     = $wpdb->prefix . 'cartmate_abandoned_carts';
				$sequences_table = $wpdb->prefix . 'cartmate_email_sequences';

				// Basic existence checks (avoid fatal errors if tables missing).
				if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $carts_table ) ) !== $carts_table ) {
					self::log( 'RUN_ID=' . $run_id . ' ERROR: missing table ' . $carts_table );
					return;
				}
				if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $sequences_table ) ) !== $sequences_table ) {
					self::log( 'RUN_ID=' . $run_id . ' ERROR: missing table ' . $sequences_table );
					return;
				}

				// Determine column names defensively.
				$cart_cols = self::get_table_columns( $carts_table );

				$cart_id_col = in_array( 'id', $cart_cols, true )
					? 'id'
					: ( in_array( 'cart_id', $cart_cols, true ) ? 'cart_id' : 'id' );

				if ( ! in_array( 'status', $cart_cols, true ) ) {
					self::log( 'RUN_ID=' . $run_id . ' ERROR: carts table missing status column' );
					return;
				}
				if ( ! in_array( 'next_email_at', $cart_cols, true ) ) {
					self::log( 'RUN_ID=' . $run_id . ' ERROR: carts table missing next_email_at column' );
					return;
				}

				// Load enabled sequence steps in step order.
				$seq = self::load_sequence_steps( $sequences_table );
				if ( empty( $seq ) ) {
					self::log( 'RUN_ID=' . $run_id . ' No enabled email sequence steps found. Nothing to do.' );
					return;
				}

				$max_step = max( array_keys( $seq ) );

				// Exclude obvious terminal states.
				$terminal_statuses = array(
					'recovered',
					'converted',
					'completed',
					'cancelled',
					'unsubscribed',
					'opted_out',
				);

				$terminal_sql = "'" . implode( "','", array_map( 'esc_sql', $terminal_statuses ) ) . "'";

				// Batch limit per run.
				$limit = 50;

				$sql = "
					SELECT *
					FROM {$carts_table}
					WHERE
						(
							next_email_at IS NULL
							OR next_email_at = '0000-00-00 00:00:00'
							OR next_email_at <= %s
						)
						AND ( status IS NULL OR status = '' OR status NOT IN ( {$terminal_sql} ) )
					ORDER BY next_email_at ASC
					LIMIT %d
				";

				$carts = $wpdb->get_results( $wpdb->prepare( $sql, $now_mysql, $limit ), ARRAY_A );

				self::log(
					'RUN_ID=' . $run_id .
					' Due carts found=' . ( is_array( $carts ) ? count( $carts ) : 0 ) .
					' now=' . $now_mysql
				);

				if ( empty( $carts ) ) {
					return;
				}

				$sent = 0;

				foreach ( $carts as $cart ) {

					$cart_id = isset( $cart[ $cart_id_col ] ) ? (int) $cart[ $cart_id_col ] : 0;
					if ( $cart_id <= 0 ) {
						continue;
					}

					$status = isset( $cart['status'] ) ? (string) $cart['status'] : '';

					$current_step_sent = self::parse_emailed_step_from_status( $status ); // 0 if none
					$next_step         = $current_step_sent + 1;

					// If next step isn't enabled/defined, mark sequence complete by clearing next_email_at.
					if ( ! isset( $seq[ $next_step ] ) ) {
						$wpdb->update(
							$carts_table,
							array( 'next_email_at' => null ),
							array( $cart_id_col => $cart_id )
						);
						self::log(
							'RUN_ID=' . $run_id .
							' cart_id=' . $cart_id .
							' No next enabled step (next_step=' . $next_step . '). Cleared next_email_at.'
						);
						continue;
					}

					// Send email for next_step
					$ok = self::send_step_email( $cart_id, $next_step );

					if ( ! $ok ) {
						self::log(
							'RUN_ID=' . $run_id .
							' cart_id=' . $cart_id .
							' step=' . $next_step .
							' SEND FAILED (no DB update)'
						);
						continue;
					}

					$sent++;

					$new_status = 'emailed_' . $next_step;

					$next_enabled_step = self::find_next_enabled_step( $seq, $next_step, $max_step );
					$next_email_at     = null;

					if ( null !== $next_enabled_step ) {
						$delay_days    = (int) $seq[ $next_enabled_step ]['delay_days'];
						$delay_seconds = max( 0, $delay_days ) * DAY_IN_SECONDS;
						$next_email_at = gmdate( 'Y-m-d H:i:s', $now_ts + $delay_seconds );
					}

					$update = array(
						'status'        => $new_status,
						'next_email_at' => $next_email_at,
					);

					// Optional columns if they exist.
					if ( in_array( 'last_email_at', $cart_cols, true ) ) {
						$update['last_email_at'] = $now_mysql;
					} elseif ( in_array( 'emailed_at', $cart_cols, true ) ) {
						$update['emailed_at'] = $now_mysql;
					}

					$wpdb->update(
						$carts_table,
						$update,
						array( $cart_id_col => $cart_id )
					);

					self::log(
						'RUN_ID=' . $run_id .
						' cart_id=' . $cart_id .
						' sent_step=' . $next_step .
						' status=' . $new_status .
						' next_email_at=' . ( $next_email_at ? $next_email_at : 'NULL' )
					);
				}

				self::log( 'RUN_ID=' . $run_id . ' Completed batch. Emails sent=' . $sent );

			} finally {
				self::release_lock( $run_id );
				self::log( 'RUN_ID=' . $run_id . ' END process_abandoned_carts()' );
			}
		}

		/* ---------------------------
		 * Internal helpers
		 * -------------------------*/

		private static function new_run_id() {
			if ( function_exists( 'wp_generate_uuid4' ) ) {
				return wp_generate_uuid4();
			}
			return uniqid( 'cartmate_', true );
		}

		private static function in_cooldown( $run_id ) {
			$last = get_transient( self::COOLDOWN_KEY );
			if ( ! empty( $last ) ) {
				self::log( 'RUN_ID=' . $run_id . ' LOCKED - cooldown active (' . self::COOLDOWN_KEY . ')' );
				return true;
			}

			// Set a short cooldown so back-to-back hits skip.
			set_transient( self::COOLDOWN_KEY, $run_id, self::COOLDOWN_TTL_SECONDS );
			return false;
		}

		private static function acquire_lock( $run_id ) {
			$existing = get_transient( self::LOCK_KEY );
			if ( ! empty( $existing ) ) {
				return false;
			}
			set_transient( self::LOCK_KEY, $run_id, self::LOCK_TTL_SECONDS );
			return true;
		}

		private static function release_lock( $run_id ) {
			// Only delete if we still own it (best effort).
			$existing = get_transient( self::LOCK_KEY );
			if ( $existing === $run_id ) {
				delete_transient( self::LOCK_KEY );
			}
		}

		private static function parse_emailed_step_from_status( $status ) {
			$status = strtolower( trim( $status ) );
			if ( preg_match( '/^emailed_(\d+)$/', $status, $m ) ) {
				return (int) $m[1];
			}
			return 0;
		}

		private static function get_table_columns( $table_name ) {
			global $wpdb;

			$cols = array();

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

		private static function load_sequence_steps( $sequences_table ) {
			global $wpdb;

			$cols = self::get_table_columns( $sequences_table );

			$step_col_candidates  = array( 'step_number', 'step', 'sequence_step' );
			$en_col_candidates    = array( 'enabled', 'is_enabled', 'active', 'is_active' );
			$delay_col_candidates = array( 'delay_days', 'delay_day', 'delay', 'delay_in_days' );

			$step_col  = self::pick_first_existing_column( $cols, $step_col_candidates );
			$en_col    = self::pick_first_existing_column( $cols, $en_col_candidates );
			$delay_col = self::pick_first_existing_column( $cols, $delay_col_candidates );

			if ( ! $step_col || ! $delay_col ) {
				self::log( 'ERROR: sequences table missing step and/or delay columns.' );
				return array();
			}

			$where_enabled = '';
			if ( $en_col ) {
				$where_enabled = "WHERE {$en_col} = 1";
			}

			$sql = "
				SELECT {$step_col} AS step_num, {$delay_col} AS delay_days
				FROM {$sequences_table}
				{$where_enabled}
				ORDER BY {$step_col} ASC
			";

			$rows = $wpdb->get_results( $sql, ARRAY_A );
			if ( empty( $rows ) ) {
				return array();
			}

			$out = array();
			foreach ( $rows as $r ) {
				$step = isset( $r['step_num'] ) ? (int) $r['step_num'] : 0;
				if ( $step <= 0 ) {
					continue;
				}
				$out[ $step ] = array(
					'delay_days' => isset( $r['delay_days'] ) ? (int) $r['delay_days'] : 0,
				);
			}

			return $out;
		}

		private static function pick_first_existing_column( $table_cols, $candidates ) {
			foreach ( $candidates as $c ) {
				if ( in_array( strtolower( $c ), $table_cols, true ) ) {
					return strtolower( $c );
				}
			}
			return null;
		}

		private static function find_next_enabled_step( $seq, $current_step, $max_step ) {
			for ( $s = $current_step + 1; $s <= $max_step; $s++ ) {
				if ( isset( $seq[ $s ] ) ) {
					return $s;
				}
			}
			return null;
		}

		private static function send_step_email( $cart_id, $step ) {
			// Prefer CartMate_Emailer if available.
			if ( class_exists( 'CartMate_Emailer' ) ) {

				if ( method_exists( 'CartMate_Emailer', 'send_recovery_sequence_email' ) ) {
					$result = call_user_func(
						array( 'CartMate_Emailer', 'send_recovery_sequence_email' ),
						$cart_id,
						$step
					);
					return self::is_send_success( $result );
				}

				if ( method_exists( 'CartMate_Emailer', 'send_recovery_email' ) ) {
					try {
						$result = call_user_func( array( 'CartMate_Emailer', 'send_recovery_email' ), $cart_id, $step );
						return self::is_send_success( $result );
					} catch ( \Throwable $e ) {
						$result = call_user_func( array( 'CartMate_Emailer', 'send_recovery_email' ), $cart_id );
						return self::is_send_success( $result );
					}
				}
			}

			self::log( 'ERROR: No usable emailer method found for cart_id=' . (int) $cart_id . ' step=' . (int) $step );
			return false;
		}

		private static function is_send_success( $result ) {
			if ( is_wp_error( $result ) ) {
				return false;
			}
			if ( $result === false || $result === 0 || $result === '0' ) {
				return false;
			}
			return true;
		}

		private static function log( $message ) {
			if ( class_exists( 'CartMate_Logger' ) && method_exists( 'CartMate_Logger', 'log' ) ) {
				CartMate_Logger::log( $message );
				return;
			}

			error_log( '[CartMate Cron] ' . $message );
		}
	}

	// Auto-init if this file is loaded directly by the plugin.
	CartMate_Cron::init();
}