<?php

namespace EduCBTPro\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Let a student sign in with their admission number exactly as printed.
 *
 * WordPress strips slashes from usernames, so an admission number of
 * TST001/2026/0001 is stored as user_login "TST00120260001". The school hands the
 * student the number with slashes, they type it, and are told it is not recognised —
 * with no way to discover the real login short of looking in the database.
 *
 * Rather than mangling admission numbers to suit WordPress, this resolves what the
 * student typed back to their account: by the admission number recorded on the
 * student row, which is the identifier the school actually issued.
 */
class StudentLogin {

    public function init(): void {
        // Priority 10 runs before WordPress checks the password, so by the time it
        // does it has the right user.
        add_filter( 'authenticate', [ $this, 'resolve_identifier' ], 10, 3 );
    }

    /**
     * @param \WP_User|\WP_Error|null $user
     * @return \WP_User|\WP_Error|null
     */
    public function resolve_identifier( $user, $username, $password ) {
        if ( $user instanceof \WP_User ) {
            return $user;
        }

        $username = trim( (string) $username );

        if ( $username === '' || $password === '' ) {
            return $user;
        }

        $student_user_id = $this->find_by_admission_number( $username );

        if ( $student_user_id === 0 ) {
            return $user;
        }

        $found = get_user_by( 'id', $student_user_id );

        if ( ! $found ) {
            return $user;
        }

        // Check the password ourselves, because returning the user object alone would
        // sign anyone in who merely knew an admission number.
        if ( ! wp_check_password( $password, $found->user_pass, $found->ID ) ) {
            return new \WP_Error(
                'educbt_incorrect_password',
                __( 'That username or password was not recognised.', 'educbt-pro' )
            );
        }

        return $found;
    }

    /**
     * Match an admission number however it was typed.
     *
     * Case is ignored, and so are spaces, slashes, hyphens and backslashes — a
     * student copying from a printed slip may use any of them, and none of the
     * variations are meaningfully different.
     */
    private function find_by_admission_number( string $typed ): int {
        global $wpdb;

        $table = $wpdb->prefix . 'educbt_students';

        // Exact match first: cheap, indexed, and covers almost every sign-in.
        $user_id = absint(
            $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT wp_user_id FROM {$table} WHERE admission_number = %s AND wp_user_id IS NOT NULL LIMIT 1",
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

        // Fall back to a normalised comparison. Restricted to rows that share the
        // leading characters so this never becomes a full table scan.
        $prefix = substr( $normalised, 0, 3 );

        $candidates = (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT admission_number, wp_user_id FROM {$table}
                 WHERE wp_user_id IS NOT NULL AND admission_number LIKE %s
                 LIMIT 200",
                $wpdb->esc_like( $prefix ) . '%'
            ),
            ARRAY_A
        );

        foreach ( $candidates as $row ) {
            if ( self::normalise( (string) $row['admission_number'] ) === $normalised ) {
                return absint( $row['wp_user_id'] );
            }
        }

        return 0;
    }

    public static function normalise( string $value ): string {
        return strtoupper( (string) preg_replace( '/[^A-Za-z0-9]/', '', $value ) );
    }
}
