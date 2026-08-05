<?php

namespace EduCBTPro\Core\Repository;

use EduCBTPro\Core\Models\Subject;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SubjectRepository {
    public function get_all_subjects( int $school_id ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'educbt_subjects';
        if ( $school_id > 0 ) {
            return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE school_id = %d", $school_id ), ARRAY_A ) ?: [];
        }

        return $wpdb->get_results( "SELECT * FROM {$table}", ARRAY_A ) ?: [];
    }

    public function create_subject( int $school_id, array $data ): int {
        global $wpdb;
        $table = $wpdb->prefix . 'educbt_subjects';

        $subject_name = sanitize_text_field( $data['subject_name'] ?? '' );
        $subject_code = sanitize_text_field( $data['subject_code'] ?? '' );

        if ( $subject_name === '' ) {
            return 0;
        }

        $existing_id = $this->find_existing_subject_id( $school_id, $subject_name, $subject_code );
        if ( $existing_id > 0 ) {
            return $existing_id;
        }

        $wpdb->insert(
            $table,
            [
                'school_id'    => $school_id,
                'subject_name' => $subject_name,
                'subject_code' => $subject_code,
                'subject_type' => sanitize_text_field( $data['subject_type'] ?? 'core' ),
            ],
            [ '%d', '%s', '%s', '%s' ]
        );

        return (int) $wpdb->insert_id;
    }

    public function find_existing_subject_id( int $school_id, string $subject_name, string $subject_code = '' ): int {
        global $wpdb;
        $table = $wpdb->prefix . 'educbt_subjects';

        if ( $subject_name === '' && $subject_code === '' ) {
            return 0;
        }

        if ( $subject_code !== '' ) {
            $id = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT id FROM {$table} WHERE school_id = %d AND (LOWER(subject_name) = LOWER(%s) OR LOWER(subject_code) = LOWER(%s)) LIMIT 1",
                    $school_id,
                    $subject_name,
                    $subject_code
                )
            );
            return $id;
        }

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$table} WHERE school_id = %d AND LOWER(subject_name) = LOWER(%s) LIMIT 1",
                $school_id,
                $subject_name
            )
        );
    }
}
