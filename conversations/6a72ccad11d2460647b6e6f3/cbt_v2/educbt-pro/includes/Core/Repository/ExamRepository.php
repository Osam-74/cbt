<?php

namespace EduCBTPro\Core\Repository;

use EduCBTPro\Core\Models\Exam;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ExamRepository {
    public function get_all_exams( int $school_id ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'educbt_exams';
        return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE school_id = %d", $school_id ), ARRAY_A ) ?: [];
    }

    public function create_exam( int $school_id, array $data ): int {
        global $wpdb;
        $table = $wpdb->prefix . 'educbt_exams';

        $wpdb->insert(
            $table,
            [
                'school_id'       => $school_id,
                'title'           => sanitize_text_field( $data['title'] ?? '' ),
                'exam_type'       => sanitize_text_field( $data['exam_type'] ?? '' ),
                'description'     => sanitize_textarea_field( $data['description'] ?? '' ),
                'start_time'      => sanitize_text_field( $data['start_time'] ?? '' ),
                'end_time'        => sanitize_text_field( $data['end_time'] ?? '' ),
                'duration_minutes'=> absint( $data['duration_minutes'] ?? 0 ),
                'is_published'    => ! empty( $data['is_published'] ) ? 1 : 0,
            ],
            [ '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d' ]
        );

        return (int) $wpdb->insert_id;
    }

    public function assign_questions( int $school_id, int $exam_id, array $question_ids ): int {
        global $wpdb;
        $table = $wpdb->prefix . 'educbt_exam_questions';
        $assigned = 0;

        foreach ( $question_ids as $question_id ) {
            $question_id = absint( $question_id );
            if ( $question_id <= 0 ) {
                continue;
            }

            $inserted = $wpdb->insert(
                $table,
                [
                    'school_id'   => $school_id,
                    'exam_id'     => absint( $exam_id ),
                    'question_id' => $question_id,
                ],
                [ '%d', '%d', '%d' ]
            );

            if ( $inserted ) {
                $assigned++;
            }
        }

        return $assigned;
    }

    public function get_exam_questions( int $school_id, int $exam_id ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'educbt_exam_questions';

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE school_id = %d AND exam_id = %d",
                $school_id,
                $exam_id
            ),
            ARRAY_A
        ) ?: [];
    }

    public function get_exam_question_ids( int $school_id, int $exam_id ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'educbt_exam_questions';

        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT question_id FROM {$table} WHERE school_id = %d AND exam_id = %d ORDER BY id ASC",
                $school_id,
                $exam_id
            ),
            ARRAY_A
        ) ?: [];

        return array_map( function ( $row ) {
            return absint( $row['question_id'] );
        }, $results );
    }

    public function get_exam( int $exam_id ): ?array {
        global $wpdb;
        $table = $wpdb->prefix . 'educbt_exams';

        $result = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE id = %d",
                $exam_id
            ),
            ARRAY_A
        );

        return $result ?: null;
    }
}
