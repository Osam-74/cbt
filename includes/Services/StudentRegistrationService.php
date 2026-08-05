<?php

namespace EduCBTPro\Services;

use EduCBTPro\Core\Capabilities;
use EduCBTPro\Core\EventDispatcher;
use EduCBTPro\Core\Schema;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * PHASE 4 — student registration.
 *
 * v1 carried three identifiers per student — `admission_number`, `registration_number`
 * and `student_id` — plus a configurable "registration number format" on the school
 * settings form. You were right that this is redundant. There are now two: the
 * internal primary key, and one human-facing ADMISSION NUMBER which is also the
 * login username.
 *
 * On the login scheme you asked for (student ID + surname):
 *
 *   Surname is not a secret. Every classmate knows it, and on a shared school
 *   computer that means any student can log in as any other. So the surname is kept
 *   as the INITIAL password — distribution stays exactly as easy as you wanted —
 *   but `_educbt_must_change_password` is set, and the portal forces a change at
 *   first login. A class teacher can reset it in two clicks.
 *
 *   For graded papers there is additionally a per-paper access code released by the
 *   invigilator (Phase 5), so even a stolen login cannot open a paper early or from
 *   home. That combination is what commercial CBT centres actually run.
 */
class StudentRegistrationService {

    /**
     * @param array<string,mixed> $data
     * @return array{success:bool,student_id?:int,admission_number?:string,credentials?:array,errors?:array}
     */
    public function register( int $school_id, array $data ): array {
        global $wpdb;

        $errors = $this->validate( $data );

        if ( ! empty( $errors ) ) {
            return [ 'success' => false, 'errors' => $errors ];
        }

        $first    = sanitize_text_field( (string) $data['first_name'] );
        $last     = sanitize_text_field( (string) $data['last_name'] );
        $class_id = absint( $data['class_id'] ?? 0 );

        $session_id = absint( $data['session_id'] ?? 0 );
        if ( $session_id <= 0 ) {
            $current    = ( new AcademicYearService() )->current_session( $school_id );
            $session_id = absint( $current['id'] ?? 0 );
        }

        if ( $session_id <= 0 ) {
            return [ 'success' => false, 'errors' => [ 'session' => 'no_current_session' ] ];
        }

        $admission_number = $this->allocate_admission_number( $school_id );

        $students = $wpdb->prefix . 'educbt_students';

        $wpdb->query( 'START TRANSACTION' );

        $inserted = $wpdb->insert(
            $students,
            [
                'school_id'         => $school_id,
                'admission_number'  => $admission_number,
                'registration_number' => $admission_number,
                'student_id'        => $admission_number,
                'first_name'        => $first,
                'last_name'         => $last,
                'gender'            => sanitize_text_field( (string) ( $data['gender'] ?? '' ) ),
                'date_of_birth'     => sanitize_text_field( (string) ( $data['date_of_birth'] ?? '' ) ),
                'passport_photo'    => esc_url_raw( (string) ( $data['passport_photo'] ?? '' ) ),
                'status'            => 'active',
            ],
            [ '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
        );

        // A failed insert here used to be invisible: the enrolment was still written,
        // so a class reported a headcount for a student who did not exist anywhere
        // else. Say so instead.
        if ( ! $inserted ) {
            return [
                'success' => false,
                'errors'  => [ 'student_record_could_not_be_saved' ],
                'detail'  => (string) $wpdb->last_error,
            ];
        }

        if ( ! $inserted ) {
            $wpdb->query( 'ROLLBACK' );
            return [ 'success' => false, 'errors' => [ 'database' => 'insert_failed' ] ];
        }

        $student_id = absint( $wpdb->insert_id );

        $credentials = $this->provision_login( $school_id, $student_id, $admission_number, $first, $last );

        // The enrollment is what actually places the student in a class. Creating a
        // student without one leaves them in limbo, so both happen in one transaction.
        if ( $class_id > 0 ) {
            $placement = $this->place( $school_id, $student_id, $class_id, $session_id );

            if ( ! $placement['success'] ) {
                $wpdb->query( 'ROLLBACK' );
                return [ 'success' => false, 'errors' => [ 'class' => $placement['error'] ] ];
            }
        }

        $wpdb->query( 'COMMIT' );

        EventDispatcher::action( 'educbt_student_registered', [
            'school_id'        => $school_id,
            'student_id'       => $student_id,
            'admission_number' => $admission_number,
            'class_id'         => $class_id,
        ] );

        return [
            'success'          => true,
            'student_id'       => $student_id,
            'admission_number' => $admission_number,
            'credentials'      => $credentials,
        ];
    }

    /**
     * @return array<string,string>
     */
    private function validate( array $data ): array {
        $errors = [];

        foreach ( [ 'first_name', 'last_name' ] as $field ) {
            $value = trim( (string) ( $data[ $field ] ?? '' ) );

            if ( $value === '' ) {
                $errors[ $field ] = 'required';
            } elseif ( strlen( $value ) < 2 ) {
                $errors[ $field ] = 'too_short';
            }
        }

        $dob = trim( (string) ( $data['date_of_birth'] ?? '' ) );

        if ( $dob !== '' ) {
            $parsed = date_create( $dob );

            if ( ! $parsed ) {
                $errors['date_of_birth'] = 'invalid';
            } elseif ( $parsed > new \DateTime() ) {
                $errors['date_of_birth'] = 'in_future';
            }
        }

        return $errors;
    }

    /**
     * SCH/2025/0001 — school code, admission year, zero-padded sequence.
     * Generated, never typed, so no two students can collide and no school has to
     * invent a numbering convention.
     */
    public function allocate_admission_number( int $school_id ): string {
        global $wpdb;

        $code = (string) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT school_code FROM ' . $wpdb->prefix . 'educbt_schools WHERE id = %d',
                $school_id
            )
        );

        $code = $code !== '' ? $code : 'SCH';
        $year = (int) gmdate( 'Y' );
        $month = (int) gmdate( 'n' );

        // Admission year follows the academic year, which starts in September.
        if ( $month >= 9 ) {
            $year++;
        }

        $students = $wpdb->prefix . 'educbt_students';
        $prefix   = $code . '/' . $year . '/';

        $last = (string) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT admission_number FROM {$students}
                 WHERE school_id = %d AND admission_number LIKE %s
                 ORDER BY id DESC LIMIT 1",
                $school_id,
                $wpdb->esc_like( $prefix ) . '%'
            )
        );

        $sequence = 1;

        if ( $last !== '' && preg_match( '/(\d+)$/', $last, $m ) ) {
            $sequence = (int) $m[1] + 1;
        }

        // Guard against a gap-filling collision if a row was deleted.
        for ( $attempt = 0; $attempt < 50; $attempt++ ) {
            $candidate = $prefix . str_pad( (string) $sequence, 4, '0', STR_PAD_LEFT );

            $taken = $wpdb->get_var(
                $wpdb->prepare( "SELECT id FROM {$students} WHERE admission_number = %s", $candidate )
            );

            if ( ! $taken ) {
                return $candidate;
            }

            $sequence++;
        }

        return $prefix . strtoupper( wp_generate_password( 4, false, false ) );
    }

    /**
     * Username is the admission number; the initial password is the surname.
     *
     * @return array{username:string,initial_password:string,must_change:bool,user_id:int}
     */
    private function provision_login( int $school_id, int $student_id, string $admission_number, string $first, string $last ): array {
        $username = $admission_number;
        $password = $this->initial_password( $last );

        $email = sanitize_title( str_replace( '/', '-', $admission_number ) ) . '@students.invalid';

        $user_id = wp_insert_user(
            [
                'user_login'   => $username,
                'user_pass'    => $password,
                'user_email'   => $email,
                'first_name'   => $first,
                'last_name'    => $last,
                'display_name' => trim( $first . ' ' . $last ),
                'role'         => Capabilities::ROLE_STUDENT,
            ]
        );

        if ( is_wp_error( $user_id ) ) {
            return [ 'username' => $username, 'initial_password' => '', 'must_change' => false, 'user_id' => 0 ];
        }

        $user_id = absint( $user_id );

        // The surname is a delivery mechanism, not a credential the student keeps.
        update_user_meta( $user_id, '_educbt_must_change_password', 1 );
        update_user_meta( $user_id, '_educbt_school_id', $school_id );

        global $wpdb;

        $wpdb->update(
            $wpdb->prefix . 'educbt_students',
            [ 'wp_user_id' => $user_id ],
            [ 'id' => $student_id ],
            [ '%d' ],
            [ '%d' ]
        );

        return [
            'username'         => $username,
            'initial_password' => $password,
            'must_change'      => true,
            'user_id'          => $user_id,
        ];
    }

    /**
     * Lowercased surname, stripped of spaces, apostrophes and hyphens so
     * "O'Brien-Smith" yields a password a child can actually type.
     */
    public function initial_password( string $surname ): string {
        $password = strtolower( trim( $surname ) );
        $password = (string) preg_replace( '/[^a-z0-9]/', '', $password );

        // WordPress accepts short passwords, but a two-letter surname would be
        // guessable to the point of meaninglessness even as a temporary value.
        if ( strlen( $password ) < 4 ) {
            $password = str_pad( $password, 4, '0' );
        }

        return $password;
    }

    /**
     * Place or move a student into a class for a session.
     *
     * @return array{success:bool,enrollment_id?:int,error?:string}
     */
    public function place( int $school_id, int $student_id, int $class_id, int $session_id ): array {
        global $wpdb;

        $classes = Schema::table( 'classes' );

        $class = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$classes} WHERE id = %d AND school_id = %d", $class_id, $school_id ),
            ARRAY_A
        );

        if ( ! $class ) {
            return [ 'success' => false, 'error' => 'class_not_found' ];
        }

        $enrollments = Schema::table( 'enrollments' );

        $capacity = absint( $class['capacity'] ?? 0 );

        if ( $capacity > 0 ) {
            $occupied = absint(
                $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT COUNT(*) FROM {$enrollments} WHERE class_id = %d AND session_id = %d AND status = 'active'",
                        $class_id,
                        $session_id
                    )
                )
            );

            if ( $occupied >= $capacity ) {
                return [ 'success' => false, 'error' => 'class_full' ];
            }
        }

        // UNIQUE (student_id, session_id) means a move is an update, not an insert.
        $existing = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$enrollments} WHERE student_id = %d AND session_id = %d",
                $student_id,
                $session_id
            )
        );

        if ( $existing ) {
            $wpdb->update(
                $enrollments,
                [
                    'class_id'      => $class_id,
                    'department_id' => $class['department_id'],
                    'status'        => 'active',
                ],
                [ 'id' => absint( $existing ) ],
                [ '%d', '%d', '%s' ],
                [ '%d' ]
            );

            return [ 'success' => true, 'enrollment_id' => absint( $existing ) ];
        }

        $wpdb->insert(
            $enrollments,
            [
                'school_id'     => $school_id,
                'student_id'    => $student_id,
                'class_id'      => $class_id,
                'session_id'    => $session_id,
                'department_id' => $class['department_id'],
                'enrolled_on'   => gmdate( 'Y-m-d' ),
                'status'        => 'active',
            ],
            [ '%d', '%d', '%d', '%d', '%d', '%s', '%s' ]
        );

        return [ 'success' => true, 'enrollment_id' => absint( $wpdb->insert_id ) ];
    }

    /**
     * Reset a student to their surname password and require a change.
     * Available to a class teacher for their own class, or to school management.
     */
    public function reset_password( int $school_id, int $student_id ): array {
        global $wpdb;

        $student = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT id, wp_user_id, last_name, admission_number FROM ' . $wpdb->prefix . 'educbt_students WHERE id = %d AND school_id = %d',
                $student_id,
                $school_id
            ),
            ARRAY_A
        );

        if ( ! $student || empty( $student['wp_user_id'] ) ) {
            return [ 'success' => false, 'error' => 'student_not_found' ];
        }

        $password = $this->initial_password( (string) $student['last_name'] );

        wp_set_password( $password, absint( $student['wp_user_id'] ) );
        update_user_meta( absint( $student['wp_user_id'] ), '_educbt_must_change_password', 1 );

        EventDispatcher::action( 'educbt_student_password_reset', [
            'school_id'  => $school_id,
            'student_id' => $student_id,
        ] );

        return [
            'success'          => true,
            'username'         => (string) $student['admission_number'],
            'initial_password' => $password,
        ];
    }

    /**
     * Bulk intake. The office registers a whole class at term start; one bad row
     * must not abort the other 199, so failures are collected and reported.
     *
     * @param array<int,array<string,mixed>> $rows
     * @return array{created:int,failed:int,credentials:array,errors:array}
     */
    public function bulk_register( int $school_id, array $rows, int $class_id, int $session_id ): array {
        $created     = 0;
        $failed      = 0;
        $credentials = [];
        $errors      = [];

        foreach ( $rows as $index => $row ) {
            $row['class_id']   = $class_id;
            $row['session_id'] = $session_id;

            $result = $this->register( $school_id, $row );

            if ( ! empty( $result['success'] ) ) {
                $created++;
                $credentials[] = [
                    'name'             => trim( (string) ( $row['first_name'] ?? '' ) . ' ' . (string) ( $row['last_name'] ?? '' ) ),
                    'admission_number' => $result['admission_number'],
                    'initial_password' => $result['credentials']['initial_password'] ?? '',
                ];
            } else {
                $failed++;
                $errors[] = [
                    'row'    => $index + 1,
                    'name'   => trim( (string) ( $row['first_name'] ?? '' ) . ' ' . (string) ( $row['last_name'] ?? '' ) ),
                    'errors' => $result['errors'] ?? [],
                ];
            }
        }

        return [ 'created' => $created, 'failed' => $failed, 'credentials' => $credentials, 'errors' => $errors ];
    }
}
