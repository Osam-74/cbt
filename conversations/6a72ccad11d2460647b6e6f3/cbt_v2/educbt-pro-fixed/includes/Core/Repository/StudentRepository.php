<?php

namespace EduCBTPro\Core\Repository;

use EduCBTPro\Core\Models\Student;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class StudentRepository {
    public function get_all_students( int $school_id ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'educbt_students';
        if ( $school_id > 0 ) {
            return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE school_id = %d", $school_id ), ARRAY_A ) ?: [];
        }

        return $wpdb->get_results( "SELECT * FROM {$table}", ARRAY_A ) ?: [];
    }

    public function create_student( int $school_id, array $data ): int {
        global $wpdb;
        $table = $wpdb->prefix . 'educbt_students';

        $registration_number = sanitize_text_field( $data['registration_number'] ?? $data['admission_number'] ?? '' );
        if ( $registration_number === '' ) {
            $registration_number = sanitize_text_field( $data['student_id'] ?? '' );
        }

        $wpdb->insert(
            $table,
            [
                'school_id'          => $school_id,
                'admission_number'   => sanitize_text_field( $data['admission_number'] ?? '' ),
                'registration_number'=> $registration_number,
                'student_id'         => sanitize_text_field( $data['student_id'] ?? '' ),
                'wp_user_id'         => absint( $data['wp_user_id'] ?? 0 ) ?: null,
                'login_username'     => sanitize_user( $data['login_username'] ?? '' ),
                'passport_photo'     => sanitize_text_field( $data['passport_photo'] ?? '' ),
                'first_name'         => sanitize_text_field( $data['first_name'] ?? '' ),
                'last_name'          => sanitize_text_field( $data['last_name'] ?? '' ),
                'full_name'          => sanitize_text_field( $data['full_name'] ?? '' ),
                'gender'             => sanitize_text_field( $data['gender'] ?? '' ),
                'date_of_birth'      => sanitize_text_field( $data['date_of_birth'] ?? '' ),
                'parent_information' => sanitize_textarea_field( $data['parent_information'] ?? '' ),
                'parent_phone'       => sanitize_text_field( $data['parent_phone'] ?? '' ),
                'parent_email'       => sanitize_email( $data['parent_email'] ?? '' ),
                'address'            => sanitize_textarea_field( $data['address'] ?? '' ),
                'class'              => sanitize_text_field( $data['class'] ?? '' ),
                'arm'                => sanitize_text_field( $data['arm'] ?? '' ),
                'department'         => sanitize_text_field( $data['department'] ?? '' ),
                'session_year'       => sanitize_text_field( $data['session_year'] ?? '' ),
                'subject_bundle'     => wp_json_encode( $data['subject_bundle'] ?? [] ),
                'status'             => sanitize_text_field( $data['status'] ?? 'active' ),
            ],
            [ '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
        );

        return (int) $wpdb->insert_id;
    }

    public function get_student_by_id( int $school_id, int $student_id ): ?array {
        global $wpdb;
        $table = $wpdb->prefix . 'educbt_students';

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE id = %d AND school_id = %d LIMIT 1",
                $student_id,
                $school_id
            ),
            ARRAY_A
        );

        return is_array( $row ) ? $row : null;
    }

    public function find_student_for_login( string $identifier ): ?array {
        global $wpdb;
        $table = $wpdb->prefix . 'educbt_students';

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE registration_number = %s OR admission_number = %s OR login_username = %s LIMIT 1",
                $identifier,
                $identifier,
                $identifier
            ),
            ARRAY_A
        );

        return is_array( $row ) ? $row : null;
    }

    public function find_student_by_wp_user_id( int $wp_user_id ): ?array {
        global $wpdb;
        $table = $wpdb->prefix . 'educbt_students';

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE wp_user_id = %d LIMIT 1",
                $wp_user_id
            ),
            ARRAY_A
        );

        return is_array( $row ) ? $row : null;
    }

    public function update_student( int $school_id, int $student_id, array $data ): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'educbt_students';

        $update_data = [];
        $format = [];

        if ( isset( $data['full_name'] ) ) {
            $update_data['full_name'] = sanitize_text_field( $data['full_name'] );
            $format[] = '%s';
        }
        if ( isset( $data['first_name'] ) ) {
            $update_data['first_name'] = sanitize_text_field( $data['first_name'] );
            $format[] = '%s';
        }
        if ( isset( $data['last_name'] ) ) {
            $update_data['last_name'] = sanitize_text_field( $data['last_name'] );
            $format[] = '%s';
        }
        if ( isset( $data['class'] ) ) {
            $update_data['class'] = sanitize_text_field( $data['class'] );
            $format[] = '%s';
        }
        if ( isset( $data['arm'] ) ) {
            $update_data['arm'] = sanitize_text_field( $data['arm'] );
            $format[] = '%s';
        }
        if ( isset( $data['status'] ) ) {
            $update_data['status'] = sanitize_text_field( $data['status'] );
            $format[] = '%s';
        }
        if ( isset( $data['department'] ) ) {
            $update_data['department'] = sanitize_text_field( $data['department'] );
            $format[] = '%s';
        }
        if ( isset( $data['gender'] ) ) {
            $update_data['gender'] = sanitize_text_field( $data['gender'] );
            $format[] = '%s';
        }
        if ( isset( $data['date_of_birth'] ) ) {
            $update_data['date_of_birth'] = sanitize_text_field( $data['date_of_birth'] );
            $format[] = '%s';
        }
        if ( isset( $data['parent_information'] ) ) {
            $update_data['parent_information'] = sanitize_textarea_field( $data['parent_information'] );
            $format[] = '%s';
        }
        if ( isset( $data['parent_phone'] ) ) {
            $update_data['parent_phone'] = sanitize_text_field( $data['parent_phone'] );
            $format[] = '%s';
        }
        if ( isset( $data['parent_email'] ) ) {
            $update_data['parent_email'] = sanitize_email( $data['parent_email'] );
            $format[] = '%s';
        }
        if ( isset( $data['address'] ) ) {
            $update_data['address'] = sanitize_textarea_field( $data['address'] );
            $format[] = '%s';
        }
        if ( isset( $data['subject_bundle'] ) ) {
            $update_data['subject_bundle'] = wp_json_encode( (array) $data['subject_bundle'] );
            $format[] = '%s';
        }
        if ( isset( $data['wp_user_id'] ) ) {
            $update_data['wp_user_id'] = absint( $data['wp_user_id'] );
            $format[] = '%d';
        }
        if ( isset( $data['login_username'] ) ) {
            $update_data['login_username'] = sanitize_user( (string) $data['login_username'] );
            $format[] = '%s';
        }
        if ( isset( $data['registration_number'] ) ) {
            $update_data['registration_number'] = sanitize_text_field( (string) $data['registration_number'] );
            $format[] = '%s';
        }

        if ( empty( $update_data ) ) {
            return false;
        }

        $result = $wpdb->update(
            $table,
            $update_data,
            [ 'id' => $student_id, 'school_id' => $school_id ],
            $format,
            [ '%d', '%d' ]
        );

        return $result !== false;
    }

    public function find_student_by_registration_number( string $registration_number ): ?array {
        global $wpdb;
        $table = $wpdb->prefix . 'educbt_students';

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE registration_number = %s OR admission_number = %s LIMIT 1",
                $registration_number,
                $registration_number
            ),
            ARRAY_A
        );

        return is_array( $row ) ? $row : null;
    }

    public function registration_number_exists( string $registration_number, int $school_id = 0 ): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'educbt_students';

        if ( $school_id > 0 ) {
            $count = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$table} WHERE (registration_number = %s OR admission_number = %s) AND school_id <> %d",
                    $registration_number,
                    $registration_number,
                    $school_id
                )
            );

            return $count > 0;
        }

        $count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE registration_number = %s OR admission_number = %s",
                $registration_number,
                $registration_number
            )
        );

        return $count > 0;
    }
}
