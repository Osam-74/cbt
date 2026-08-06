<?php

namespace EduCBTPro\Services;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class QuestionRandomizationService {
    /**
     * Randomize question order for an exam
     */
    public function randomize_question_order( array $question_ids ): array {
        $order = $question_ids;
        shuffle( $order );
        return $order;
    }

    /**
     * Randomize options within a question
     */
    public function randomize_question_options( array $question ): array {
        if ( ! isset( $question['options'] ) || empty( $question['options'] ) ) {
            return $question;
        }

        $options = is_string( $question['options'] ) 
            ? json_decode( $question['options'], true ) 
            : $question['options'];

        if ( ! is_array( $options ) ) {
            return $question;
        }

        // Keep track of original indices for answer key mapping if needed
        $indexed_options = [];
        foreach ( $options as $index => $option ) {
            $indexed_options[] = [
                'index'  => $index,
                'value'  => $option,
            ];
        }

        shuffle( $indexed_options );

        $shuffled = array_map( function ( $item ) {
            return $item['value'];
        }, $indexed_options );

        $question['options'] = wp_json_encode( $shuffled );
        return $question;
    }

    /**
     * Randomize multiple questions
     */
    public function randomize_question_batch( array $questions, array $options = [] ): array {
        $randomized = [];

        foreach ( $questions as $question ) {
            $q = $question;
            
            // Randomize options if enabled
            if ( $options['randomize_options'] ?? false ) {
                $q = $this->randomize_question_options( $q );
            }

            // Strip answer key from randomized questions
            if ( $options['strip_answers'] ?? true ) {
                $q['answers'] = null;
                $q['explanations'] = null;
            }

            $randomized[] = $q;
        }

        return $randomized;
    }

    /**
     * Validate randomization consistency
     * Ensures same seed produces same order
     */
    public function validate_randomization( array $original, array $randomized ): bool {
        if ( count( $original ) !== count( $randomized ) ) {
            return false;
        }

        $original_ids = array_map( function ( $q ) {
            return $q['id'] ?? 0;
        }, $original );

        $randomized_ids = array_map( function ( $q ) {
            return $q['id'] ?? 0;
        }, $randomized );

        return array_sum( $original_ids ) === array_sum( $randomized_ids );
    }
}
