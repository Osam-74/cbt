<?php

namespace EduCBTPro\Core\Repository;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ExamIntegrityEventRepository {
    public function create_event( int $school_id, array $data ): int {
        global $wpdb;
        $table = $wpdb->prefix . 'educbt_exam_integrity_events';

        $wpdb->insert(
            $table,
            [
                'school_id'   => $school_id,
                'attempt_id'  => absint( $data['attempt_id'] ?? 0 ),
                'exam_id'     => absint( $data['exam_id'] ?? 0 ),
                'student_id'  => absint( $data['student_id'] ?? 0 ),
                'event_type'  => sanitize_text_field( $data['event_type'] ?? '' ),
                'event_payload' => wp_json_encode( $data['event_payload'] ?? [] ),
                'created_at'  => current_time( 'mysql' ),
            ],
            [ '%d', '%d', '%d', '%d', '%s', '%s', '%s' ]
        );

        return (int) $wpdb->insert_id;
    }

    public function list_events( int $school_id, array $filters = [] ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'educbt_exam_integrity_events';

        $sql = "SELECT * FROM {$table} WHERE school_id = %d";
        $args = [ $school_id ];

        if ( ! empty( $filters['attempt_id'] ) ) {
            $sql .= ' AND attempt_id = %d';
            $args[] = absint( $filters['attempt_id'] );
        }

        if ( ! empty( $filters['exam_id'] ) ) {
            $sql .= ' AND exam_id = %d';
            $args[] = absint( $filters['exam_id'] );
        }

        if ( ! empty( $filters['student_id'] ) ) {
            $sql .= ' AND student_id = %d';
            $args[] = absint( $filters['student_id'] );
        }

        if ( ! empty( $filters['event_type'] ) ) {
            $sql .= ' AND event_type = %s';
            $args[] = sanitize_text_field( $filters['event_type'] );
        }

        if ( ! empty( $filters['date_from'] ) ) {
            $sql .= ' AND created_at >= %s';
            $args[] = sanitize_text_field( $filters['date_from'] );
        }

        if ( ! empty( $filters['date_to'] ) ) {
            $sql .= ' AND created_at <= %s';
            $args[] = sanitize_text_field( $filters['date_to'] );
        }

        $limit = isset( $filters['limit'] ) ? absint( $filters['limit'] ) : 200;
        $limit = max( 1, min( 500, $limit ) );

        $sql .= " ORDER BY id DESC LIMIT {$limit}";

        return $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ) ?: [];
    }
}
