<?php

namespace EduCBTPro\Services;

use EduCBTPro\Core\Capabilities;
use EduCBTPro\Core\EventDispatcher;
use EduCBTPro\Core\Schema;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * PHASE 4 — guardians.
 *
 * v1 stored parent contact as a text blob on the student row. Two consequences:
 * a parent with three children existed as three unrelated records, and no parent
 * could ever log in, because there was no account to log in to.
 *
 * Guardians are now real records with a many-to-many link, so one login sees all
 * a family's children, and a child can have two guardians with different
 * permissions — a separated household where only one parent may view results is an
 * ordinary case, not an edge case.
 *
 * Accounts are created by INVITE rather than by the school choosing a password.
 * A school should never hold a parent's credentials.
 */
class GuardianService {

    /**
     * Link a guardian to a student, creating the guardian on first sight.
     * Deduplicates on email, falling back to phone.
     *
     * @return array{success:bool,guardian_id?:int,created?:bool,invite_token?:string,errors?:array}
     */
    public function link_to_student( int $school_id, int $student_id, array $data ): array {
        global $wpdb;

        $email = trim( (string) ( $data['email'] ?? '' ) );
        $phone = trim( (string) ( $data['phone'] ?? '' ) );
        $first = trim( (string) ( $data['first_name'] ?? '' ) );
        $last  = trim( (string) ( $data['last_name'] ?? '' ) );

        if ( $email === '' && $phone === '' ) {
            return [ 'success' => false, 'errors' => [ 'contact' => 'email_or_phone_required' ] ];
        }

        if ( $email !== '' && ! is_email( $email ) ) {
            return [ 'success' => false, 'errors' => [ 'email' => 'invalid' ] ];
        }

        $table    = Schema::table( 'guardians' );
        $existing = null;

        if ( $email !== '' ) {
            $existing = $wpdb->get_var(
                $wpdb->prepare( "SELECT id FROM {$table} WHERE school_id = %d AND email = %s LIMIT 1", $school_id, $email )
            );
        }

        if ( ! $existing && $phone !== '' ) {
            $existing = $wpdb->get_var(
                $wpdb->prepare( "SELECT id FROM {$table} WHERE school_id = %d AND phone = %s LIMIT 1", $school_id, $phone )
            );
        }

        $created = false;
        $token   = '';

        if ( $existing ) {
            $guardian_id = absint( $existing );
        } else {
            $token = wp_generate_password( 32, false, false );

            $wpdb->insert(
                $table,
                [
                    'school_id'     => $school_id,
                    'first_name'    => sanitize_text_field( $first ),
                    'last_name'     => sanitize_text_field( $last ),
                    'email'         => sanitize_email( $email ),
                    'phone'         => sanitize_text_field( $phone ),
                    'address'       => sanitize_textarea_field( (string) ( $data['address'] ?? '' ) ),
                    'occupation'    => sanitize_text_field( (string) ( $data['occupation'] ?? '' ) ),
                    'invite_token'  => $token,
                    'invite_status' => 'pending',
                    'status'        => 'active',
                ],
                [ '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
            );

            $guardian_id = absint( $wpdb->insert_id );
            $created     = true;
        }

        if ( $guardian_id <= 0 ) {
            return [ 'success' => false, 'errors' => [ 'database' => 'insert_failed' ] ];
        }

        $wpdb->query(
            $wpdb->prepare(
                'INSERT IGNORE INTO ' . Schema::table( 'guardian_student' ) .
                ' (school_id, guardian_id, student_id, relationship, is_primary, can_view_results) VALUES (%d, %d, %d, %s, %d, %d)',
                $school_id,
                $guardian_id,
                $student_id,
                sanitize_text_field( (string) ( $data['relationship'] ?? 'parent' ) ),
                ! empty( $data['is_primary'] ) ? 1 : 0,
                isset( $data['can_view_results'] ) ? absint( $data['can_view_results'] ) : 1
            )
        );

        EventDispatcher::action( 'educbt_guardian_linked', [
            'school_id'   => $school_id,
            'guardian_id' => $guardian_id,
            'student_id'  => $student_id,
            'created'     => $created,
        ] );

        return [
            'success'      => true,
            'guardian_id'  => $guardian_id,
            'created'      => $created,
            'invite_token' => $token,
        ];
    }

    /**
     * A guardian sets their own password by redeeming the invite. The school never
     * holds it.
     *
     * @return array{success:bool,user_id?:int,error?:string}
     */
    public function accept_invite( string $token, string $password ): array {
        global $wpdb;

        $token = trim( $token );

        if ( strlen( $token ) < 20 ) {
            return [ 'success' => false, 'error' => 'invalid_token' ];
        }

        if ( strlen( $password ) < 8 ) {
            return [ 'success' => false, 'error' => 'password_too_short' ];
        }

        $table = Schema::table( 'guardians' );

        $guardian = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$table} WHERE invite_token = %s AND invite_status = 'pending' LIMIT 1", $token ),
            ARRAY_A
        );

        if ( ! $guardian ) {
            return [ 'success' => false, 'error' => 'invalid_or_used_token' ];
        }

        $email = (string) $guardian['email'];

        if ( $email === '' || ! is_email( $email ) ) {
            $email = 'guardian' . absint( $guardian['id'] ) . '@guardians.invalid';
        }

        $username = 'p' . absint( $guardian['id'] ) . '.' . sanitize_user( strtolower( (string) $guardian['last_name'] ), true );

        $user_id = wp_insert_user(
            [
                'user_login'   => $username,
                'user_pass'    => $password,
                'user_email'   => $email,
                'first_name'   => (string) $guardian['first_name'],
                'last_name'    => (string) $guardian['last_name'],
                'display_name' => trim( $guardian['first_name'] . ' ' . $guardian['last_name'] ),
                'role'         => Capabilities::ROLE_GUARDIAN,
            ]
        );

        if ( is_wp_error( $user_id ) ) {
            return [ 'success' => false, 'error' => 'account_creation_failed' ];
        }

        $user_id = absint( $user_id );

        update_user_meta( $user_id, '_educbt_school_id', absint( $guardian['school_id'] ) );

        $wpdb->update(
            $table,
            [ 'wp_user_id' => $user_id, 'invite_status' => 'accepted', 'invite_token' => '' ],
            [ 'id' => absint( $guardian['id'] ) ],
            [ '%d', '%s', '%s' ],
            [ '%d' ]
        );

        return [ 'success' => true, 'user_id' => $user_id ];
    }

    /**
     * Every child linked to a guardian, across classes. This is the query that
     * v1 structurally could not answer.
     */
    public function children( int $school_id, int $guardian_id ): array {
        global $wpdb;

        $students    = $wpdb->prefix . 'educbt_students';
        $link        = Schema::table( 'guardian_student' );
        $enrollments = Schema::table( 'enrollments' );
        $classes     = Schema::table( 'classes' );

        return (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT st.id, st.admission_number, st.first_name, st.last_name, st.passport_photo,
                        gs.relationship, gs.can_view_results,
                        c.display_name AS class_name, c.id AS class_id
                 FROM {$link} gs
                 INNER JOIN {$students} st ON st.id = gs.student_id
                 LEFT JOIN {$enrollments} e ON e.student_id = st.id AND e.status = 'active'
                 LEFT JOIN {$classes} c ON c.id = e.class_id
                 WHERE gs.school_id = %d AND gs.guardian_id = %d
                 ORDER BY st.last_name ASC",
                $school_id,
                $guardian_id
            ),
            ARRAY_A
        );
    }

    public function guardians_of( int $school_id, int $student_id ): array {
        global $wpdb;

        $guardians = Schema::table( 'guardians' );
        $link      = Schema::table( 'guardian_student' );

        return (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT g.*, gs.relationship, gs.is_primary, gs.can_view_results
                 FROM {$link} gs
                 INNER JOIN {$guardians} g ON g.id = gs.guardian_id
                 WHERE gs.school_id = %d AND gs.student_id = %d
                 ORDER BY gs.is_primary DESC",
                $school_id,
                $student_id
            ),
            ARRAY_A
        );
    }

    /**
     * Regenerate an invite for a guardian who never redeemed the first one.
     */
    public function reissue_invite( int $school_id, int $guardian_id ): array {
        global $wpdb;

        $token = wp_generate_password( 32, false, false );

        $updated = $wpdb->update(
            Schema::table( 'guardians' ),
            [ 'invite_token' => $token, 'invite_status' => 'pending' ],
            [ 'id' => $guardian_id, 'school_id' => $school_id ],
            [ '%s', '%s' ],
            [ '%d', '%d' ]
        );

        if ( ! $updated ) {
            return [ 'success' => false, 'error' => 'guardian_not_found' ];
        }

        return [ 'success' => true, 'invite_token' => $token ];
    }
}
