<?php

namespace EduCBTPro\Core\Repository;

use EduCBTPro\Core\Models\Question;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class QuestionRepository {
    public function get_all_questions( int $school_id ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'educbt_questions';

        if ( $school_id > 0 ) {
            // Include school-specific questions AND global seed questions (school_id=0)
            return $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM {$table} WHERE school_id = %d OR school_id = 0 ORDER BY id ASC",
                    $school_id
                ),
                ARRAY_A
            ) ?: [];
        }

        // When no school is selected, show only global seed questions
        return $wpdb->get_results(
            "SELECT * FROM {$table} ORDER BY id ASC",
            ARRAY_A
        ) ?: [];
    }

    public function create_question( int $school_id, array $data ): int {
        global $wpdb;
        $table = $wpdb->prefix . 'educbt_questions';

        $wpdb->insert(
            $table,
            [
                'school_id'    => $school_id,
                'subject'      => sanitize_text_field( $data['subject'] ?? '' ),
                'section'      => sanitize_text_field( $data['section'] ?? '' ),
                'passage_text' => sanitize_textarea_field( $data['passage_text'] ?? '' ),
                'topic'        => sanitize_text_field( $data['topic'] ?? '' ),
                'sub_topic'    => sanitize_text_field( $data['sub_topic'] ?? '' ),
                'class'        => sanitize_text_field( $data['class'] ?? '' ),
                'department'   => sanitize_text_field( $data['department'] ?? '' ),
                'difficulty'   => sanitize_text_field( $data['difficulty'] ?? '' ),
                'learning_objective' => sanitize_text_field( $data['learning_objective'] ?? '' ),
                'bloom_level'  => sanitize_text_field( $data['bloom_level'] ?? '' ),
                'examination_type' => sanitize_text_field( $data['examination_type'] ?? '' ),
                'examination_year' => sanitize_text_field( $data['examination_year'] ?? '' ),
                'estimated_duration' => absint( $data['estimated_duration'] ?? 0 ),
                'marks'        => floatval( $data['marks'] ?? 0 ),
                'image_reference' => sanitize_text_field( $data['image_reference'] ?? '' ),
                'question_text'=> sanitize_textarea_field( $data['question_text'] ?? '' ),
                'options'      => wp_json_encode( $data['options'] ?? [] ),
                'answers'      => wp_json_encode( $data['answers'] ?? [] ),
                'explanations' => sanitize_textarea_field( $data['explanations'] ?? '' ),
                'question_type'=> sanitize_text_field( $data['question_type'] ?? '' ),
                'status'       => sanitize_text_field( $data['status'] ?? 'published' ),
            ],
            [ '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%f', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
        );

        return (int) $wpdb->insert_id;
    }

    public function get_question( int $question_id ): ?array {
        global $wpdb;
        $table = $wpdb->prefix . 'educbt_questions';

        $result = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE id = %d",
                $question_id
            ),
            ARRAY_A
        );

        return $result ?: null;
    }
}
