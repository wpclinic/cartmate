<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) exit;

global $wpdb;
$table = $wpdb->prefix . 'cartmate_carts';
$wpdb->query( "DROP TABLE IF EXISTS $table" );
