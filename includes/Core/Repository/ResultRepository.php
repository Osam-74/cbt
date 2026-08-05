<?php

namespace EduCBTPro\Core\Repository;

use EduCBTPro\Core\Models\Result;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ResultRepository {
    public function get_all_results( int $school_id ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'educbt_results';
        return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE school_id = %d", $school_id ), ARRAY_A ) ?: [];
    }

    public function get_exam_results( int $school_id, int $exam_id ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'educbt_results';
        return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE school_id = %d AND exam_id = %d", $school_id, $exam_id ), ARRAY_A ) ?: [];
    }

    public function get_student_exam_result( int $school_id, int $exam_id, int $student_id ): ?array {
        global $wpdb;
        $table = $wpdb->prefix . 'educbt_results';
        $result = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE school_id = %d AND exam_id = %d AND student_id = %d",
                $school_id,
                $exam_id,
                $student_id
            ),
            ARRAY_A
        );
        return $result ?: null;
    }

    public function create_result( int $school_id, array $data ): int {
        global $wpdb;
        $table = $wpdb->prefix . 'educbt_results';

        $wpdb->insert(
            $table,
            [
                'school_id'            => $school_id,
                'exam_id'              => absint( $data['exam_id'] ?? 0 ),
                'exam_attempt_id'      => absint( $data['exam_attempt_id'] ?? 0 ),
                'student_id'           => absint( $data['student_id'] ?? 0 ),
                'term'                 => sanitize_text_field( $data['term'] ?? '' ),
                'session_year'         => sanitize_text_field( $data['session_year'] ?? '' ),
                'subject'              => sanitize_text_field( $data['subject'] ?? '' ),
                'score'                => floatval( $data['score'] ?? 0 ),
                'grade'                => sanitize_text_field( $data['grade'] ?? '' ),
                'remark'               => sanitize_text_field( $data['remark'] ?? '' ),
                'student_responses'    => isset( $data['student_responses'] ) ? wp_json_encode( $data['student_responses'] ) : null,
                'grading_scheme'       => sanitize_text_field( $data['grading_scheme'] ?? 'percentage' ),
                'status'               => sanitize_text_field( $data['status'] ?? 'draft' ),
            ],
            [ '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%f', '%s', '%s', '%s', '%s', '%s' ]
        );

        return (int) $wpdb->insert_id;
    }

    public function update_result( int $school_id, int $result_id, array $data ): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'educbt_results';

        $update_data = [];
        $format = [];

        if ( isset( $data['score'] ) ) {
            $update_data['score'] = floatval( $data['score'] );
            $format[] = '%f';
        }
        if ( isset( $data['grade'] ) ) {
            $update_data['grade'] = sanitize_text_field( $data['grade'] );
            $format[] = '%s';
        }
        if ( isset( $data['remark'] ) ) {
            $update_data['remark'] = sanitize_text_field( $data['remark'] );
            $format[] = '%s';
        }
        if ( isset( $data['status'] ) ) {
            $update_data['status'] = sanitize_text_field( $data['status'] );
            $format[] = '%s';
        }
        if ( isset( $data['student_responses'] ) ) {
            $update_data['student_responses'] = wp_json_encode( $data['student_responses'] );
            $format[] = '%s';
        }

        if ( empty( $update_data ) ) {
            return false;
        }

        $result = $wpdb->update(
            $table,
            $update_data,
            [ 'id' => $result_id, 'school_id' => $school_id ],
            $format,
            [ '%d', '%d' ]
        );

        return $result !== false;
    }
}
