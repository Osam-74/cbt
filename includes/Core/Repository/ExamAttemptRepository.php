<?php

namespace EduCBTPro\Core\Repository;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ExamAttemptRepository {
    public function get_all_attempts( int $school_id ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'educbt_exam_attempts';
        return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE school_id = %d", $school_id ), ARRAY_A ) ?: [];
    }

    public function get_student_attempts( int $school_id, int $student_id, int $exam_id ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'educbt_exam_attempts';
        return $wpdb->get_results( 
            $wpdb->prepare( 
                "SELECT * FROM {$table} WHERE school_id = %d AND student_id = %d AND exam_id = %d ORDER BY created_at DESC",
                $school_id,
                $student_id,
                $exam_id
            ),
            ARRAY_A
        ) ?: [];
    }

    public function get_attempt_by_session( int $school_id, string $session_key ): ?array {
        global $wpdb;
        $table = $wpdb->prefix . 'educbt_exam_attempts';
        $result = $wpdb->get_row( 
            $wpdb->prepare( 
                "SELECT * FROM {$table} WHERE school_id = %d AND session_key = %s",
                $school_id,
                $session_key
            ),
            ARRAY_A
        );
        return $result ?: null;
    }

    public function get_attempt_by_id( int $school_id, int $attempt_id ): ?array {
        global $wpdb;
        $table = $wpdb->prefix . 'educbt_exam_attempts';

        $result = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE school_id = %d AND id = %d LIMIT 1",
                $school_id,
                $attempt_id
            ),
            ARRAY_A
        );

        return $result ?: null;
    }

    public function create_attempt( int $school_id, array $data ): int {
        global $wpdb;
        $table = $wpdb->prefix . 'educbt_exam_attempts';

        $wpdb->insert(
            $table,
            [
                'school_id'                => $school_id,
                'exam_id'                  => absint( $data['exam_id'] ?? 0 ),
                'student_id'               => absint( $data['student_id'] ?? 0 ),
                'session_key'              => sanitize_text_field( $data['session_key'] ?? wp_generate_uuid4() ),
                'question_order'           => isset( $data['question_order'] ) ? wp_json_encode( $data['question_order'] ) : null,
                'randomize_options'        => absint( $data['randomize_options'] ?? 0 ),
                'time_started'             => isset( $data['time_started'] ) ? $data['time_started'] : current_time( 'mysql' ),
                'timer_seconds_remaining'  => absint( $data['timer_seconds_remaining'] ?? 0 ),
                'status'                   => sanitize_text_field( $data['status'] ?? 'in_progress' ),
            ],
            [ '%d', '%d', '%d', '%s', '%s', '%d', '%s', '%d', '%s' ]
        );

        return (int) $wpdb->insert_id;
    }

    public function update_attempt( int $school_id, int $attempt_id, array $data ): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'educbt_exam_attempts';

        $update_data = [];
        $format = [];

        if ( isset( $data['timer_seconds_remaining'] ) ) {
            $update_data['timer_seconds_remaining'] = absint( $data['timer_seconds_remaining'] );
            $format[] = '%d';
        }
        if ( isset( $data['status'] ) ) {
            $update_data['status'] = sanitize_text_field( $data['status'] );
            $format[] = '%s';
        }
        if ( isset( $data['time_submitted'] ) ) {
            $update_data['time_submitted'] = $data['time_submitted'];
            $format[] = '%s';
        }

        if ( empty( $update_data ) ) {
            return false;
        }

        $result = $wpdb->update(
            $table,
            $update_data,
            [ 'id' => $attempt_id, 'school_id' => $school_id ],
            $format,
            [ '%d', '%d' ]
        );

        return $result !== false;
    }

    public function get_active_attempt( int $school_id, int $exam_id, int $student_id ): ?array {
        global $wpdb;
        $table = $wpdb->prefix . 'educbt_exam_attempts';
        $result = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE school_id = %d AND exam_id = %d AND student_id = %d AND status = 'in_progress' LIMIT 1",
                $school_id,
                $exam_id,
                $student_id
            ),
            ARRAY_A
        );
        return $result ?: null;
    }

    public function delete_attempt( int $school_id, int $attempt_id ): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'educbt_exam_attempts';
        return (bool) $wpdb->delete( $table, [ 'id' => $attempt_id, 'school_id' => $school_id ], [ '%d', '%d' ] );
    }

    public function count_student_attempts_for_day( int $school_id, int $student_id, string $day_ymd ): int {
        global $wpdb;
        $table = $wpdb->prefix . 'educbt_exam_attempts';

        $count = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE school_id = %d AND student_id = %d AND DATE(time_started) = %s",
                $school_id,
                $student_id,
                sanitize_text_field( $day_ymd )
            )
        );

        return absint( $count );
    }
}
