<?php

namespace EduCBTPro\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Let a staff member sign in with their staff number exactly as issued.
 *
 * Mirrors StudentLogin: WordPress mangles staff numbers when creating usernames
 * (slashes become dots, etc.), so a teacher typing their staff ID as printed is
 * told it is not recognised. This resolves the typed number back to the staff
 * row and its linked WP account before WordPress checks the password.
 *
 * Runs at priority 9, just before StudentLogin (priority 10), so staff numbers
 * are checked first. A staff number will never collide with a student admission
 * number because they come from different tables.
 */
class StaffLogin {

    public function init(): void {
        add_filter( 'authenticate', [ $this, 'resolve_staff_number' ], 9, 3 );
    }

    /**
     * @param \WP_User|\WP_Error|null $user
     * @return \WP_User|\WP_Error|null
     */
    public function resolve_staff_number( $user, $username, $password ) {
        if ( $user instanceof \WP_User ) {
            return $user;
        }

        $username = trim( (string) $username );

        if ( $username === '' || $password === '' ) {
            return $user;
        }

        $staff_user_id = $this->find_by_staff_number( $username );

        if ( $staff_user_id === 0 ) {
            return $user;
        }

        $found = get_user_by( 'id', $staff_user_id );

        if ( ! $found ) {
            return $user;
        }

        if ( ! wp_check_password( $password, $found->user_pass, $found->ID ) ) {
            return new \WP_Error(
                'educbt_staff_incorrect_password',
                __( 'That staff number or password was not recognised.', 'educbt-pro' )
            );
        }

        return $found;
    }

    /**
     * Match a staff number however it was typed.
     *
     * Case is ignored, and so are spaces, slashes, hyphens and backslashes.
     */
    private function find_by_staff_number( string $typed ): int {
        global $wpdb;

        $table = Schema::table( 'staff' );

        // Exact match first.
        $user_id = absint(
            $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT wp_user_id FROM {$table} WHERE staff_number = %s AND wp_user_id IS NOT NULL LIMIT 1",
                    $typed
                )
            )
        );

        if ( $user_id > 0 ) {
            return $user_id;
        }

        $normalised = self::normalise( $typed );

        if ( $normalised === '' ) {
            return 0;
        }

        // Fall back to normalised comparison, restricted by leading chars.
        $prefix = substr( $normalised, 0, 3 );

        $candidates = (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT staff_number, wp_user_id FROM {$table}
                 WHERE wp_user_id IS NOT NULL AND staff_number LIKE %s
                 LIMIT 200",
                $wpdb->esc_like( $prefix ) . '%'
            ),
            ARRAY_A
        );

        foreach ( $candidates as $row ) {
            if ( self::normalise( (string) $row['staff_number'] ) === $normalised ) {
                return absint( $row['wp_user_id'] );
            }
        }

        return 0;
    }

    public static function normalise( string $value ): string {
        return strtoupper( (string) preg_replace( '/[^A-Za-z0-9]/', '', $value ) );
    }
}
