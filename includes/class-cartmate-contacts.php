<?php
/**
 * Cart Mate - Contacts helper (uses WordPress users).
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'CartMate_Contacts' ) ) :

class CartMate_Contacts {

    const ROLE_KEY = 'cartmate_contact';

    /**
     * Init hooks if needed later.
     */
    public static function init() {
        // Nothing heavy yet – most is on activation via add_role().
    }

    /**
     * Add CartMate contact role (called on activation).
     */
    public static function add_role() {
        add_role(
            self::ROLE_KEY,
            __( 'CartMate Contact', 'cartmate' ),
            array(
                'read' => true,
            )
        );
    }

    /**
     * Create or update a contact as a WordPress user.
     *
     * @param string $email
     * @param string $phone
     * @param string $name
     * @return int|\WP_Error User ID or error.
     */
    public static function create_contact( $email, $phone = '', $name = '' ) {
        $email = sanitize_email( $email );
        $phone = sanitize_text_field( $phone );
        $name  = sanitize_text_field( $name );

        if ( empty( $email ) ) {
            return new \WP_Error( 'cartmate_no_email', 'Contact email is required.' );
        }

        $user = get_user_by( 'email', $email );

        if ( $user ) {
            // Update existing user meta and role (if not already).
            $user_id = $user->ID;

            if ( ! in_array( self::ROLE_KEY, (array) $user->roles, true ) ) {
                $user->add_role( self::ROLE_KEY );
            }

            if ( $phone ) {
                update_user_meta( $user_id, 'cartmate_phone', $phone );
            }

            update_user_meta( $user_id, 'cartmate_is_contact', 1 );

            return $user_id;
        }

        // New user.
        $username = sanitize_user( current( explode( '@', $email ) ), true );
        if ( username_exists( $username ) ) {
            $username .= '_' . wp_generate_password( 4, false );
        }

        $password = wp_generate_password( 16, true, true );

        $user_id = wp_insert_user( array(
            'user_login' => $username,
            'user_pass'  => $password,
            'user_email' => $email,
            'display_name' => $name ?: $email,
            'role'       => self::ROLE_KEY,
        ) );

        if ( is_wp_error( $user_id ) ) {
            return $user_id;
        }

        if ( $phone ) {
            update_user_meta( $user_id, 'cartmate_phone', $phone );
        }
        update_user_meta( $user_id, 'cartmate_is_contact', 1 );

        return $user_id;
    }
}

endif;
