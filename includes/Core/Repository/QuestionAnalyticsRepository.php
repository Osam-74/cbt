<?php

namespace EduCBTPro\Core\Repository;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class QuestionAnalyticsRepository {
    /**
     * Get all results containing question responses for analysis
     */
    public function get_question_response_data( int $school_id, int $exam_id = 0 ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'educbt_results';

        if ( $exam_id > 0 ) {
            $results = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM {$table} WHERE school_id = %d AND exam_id = %d",
                    $school_id,
                    $exam_id
                ),
                ARRAY_A
            );
        } else {
            $results = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM {$table} WHERE school_id = %d",
                    $school_id
                ),
                ARRAY_A
            );
        }

        return $results ?: [];
    }

    /**
     * Get questions in an exam with their correct answers
     */
    public function get_exam_questions_with_answers( int $school_id, int $exam_id ): array {
        global $wpdb;
        $eq_table = $wpdb->prefix . 'educbt_exam_questions';
        $q_table = $wpdb->prefix . 'educbt_questions';

        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT q.* FROM {$q_table} q
                INNER JOIN {$eq_table} eq ON q.id = eq.question_id
                WHERE q.school_id = %d AND eq.exam_id = %d
                ORDER BY eq.id ASC",
                $school_id,
                $exam_id
            ),
            ARRAY_A
        );

        return $results ?: [];
    }
}
