<?php

namespace EduCBTPro\Core\Repository;

use EduCBTPro\Core\Models\Teacher;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class TeacherRepository {
    public function get_all_teachers( int $school_id ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'educbt_teachers';
        if ( $school_id > 0 ) {
            return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE school_id = %d", $school_id ), ARRAY_A ) ?: [];
        }

        return $wpdb->get_results( "SELECT * FROM {$table}", ARRAY_A ) ?: [];
    }

    public function get_teacher_by_id( int $school_id, int $teacher_id ): ?array {
        global $wpdb;
        $table = $wpdb->prefix . 'educbt_teachers';

        $row = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$table} WHERE school_id = %d AND id = %d", $school_id, $teacher_id ),
            ARRAY_A
        );

        return is_array( $row ) ? $row : null;
    }

    public function create_teacher( int $school_id, array $data ): int {
        global $wpdb;
        $table = $wpdb->prefix . 'educbt_teachers';

        $wpdb->insert(
            $table,
            [
                'school_id'        => $school_id,
                'teacher_id'       => sanitize_text_field( $data['teacher_id'] ?? '' ),
                'full_name'        => sanitize_text_field( $data['full_name'] ?? '' ),
                'teacher_group'    => sanitize_text_field( $data['teacher_group'] ?? '' ),
                'contact_details'  => sanitize_textarea_field( $data['contact_details'] ?? '' ),
                'subjects'         => wp_json_encode( $data['subjects'] ?? [] ),
                'assigned_classes' => wp_json_encode( $data['assigned_classes'] ?? [] ),
            ],
            [ '%d', '%s', '%s', '%s', '%s', '%s', '%s' ]
        );

        return (int) $wpdb->insert_id;
    }

    public function update_teacher( int $school_id, int $teacher_id, array $data ): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'educbt_teachers';

        $updated = $wpdb->update(
            $table,
            [
                'teacher_id'       => sanitize_text_field( $data['teacher_id'] ?? '' ),
                'full_name'        => sanitize_text_field( $data['full_name'] ?? '' ),
                'teacher_group'    => sanitize_text_field( $data['teacher_group'] ?? '' ),
                'contact_details'  => sanitize_textarea_field( $data['contact_details'] ?? '' ),
                'subjects'         => wp_json_encode( $data['subjects'] ?? [] ),
                'assigned_classes' => wp_json_encode( $data['assigned_classes'] ?? [] ),
            ],
            [
                'school_id' => $school_id,
                'id'        => $teacher_id,
            ],
            [ '%s', '%s', '%s', '%s', '%s', '%s' ],
            [ '%d', '%d' ]
        );

        return $updated !== false;
    }
}
