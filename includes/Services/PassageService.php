<?php

namespace EduCBTPro\Services;

use EduCBTPro\Core\EventDispatcher;
use EduCBTPro\Core\Schema;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * PHASE 5c — passages and shared instructions.
 *
 * An English Language paper does not consist of independent questions. It has a
 * comprehension passage followed by five questions about it, a cloze paragraph
 * followed by ten, and section instructions ("Choose the option nearest in meaning
 * to the underlined word") that govern a whole block.
 *
 * The same shape appears well beyond English: a Physics diagram used by three
 * questions, a Geography map, an Economics data table, a Literature extract.
 *
 * Two things this must get right, both of which the naive approach breaks:
 *
 *  1. THE PASSAGE IS NOT REPEATED. Storing the passage inside each question's text
 *     means a student on a phone scrolls past 400 words five times, and any edit to
 *     the passage has to be made five times.
 *
 *  2. SHUFFLING MUST NOT SEPARATE A QUESTION FROM ITS PASSAGE. Phase 5 shuffles
 *     question order by default. If question 3 of a comprehension set drifts to the
 *     end of the paper, it is unanswerable. Passage-linked questions therefore
 *     shuffle as a BLOCK, keeping their internal order, and only the blocks move.
 *     This is the single most important rule in this file.
 */
class PassageService {

    public const TYPE_COMPREHENSION = 'comprehension';
    public const TYPE_CLOZE         = 'cloze';
    public const TYPE_INSTRUCTION   = 'instruction';
    public const TYPE_DATA          = 'data';
    public const TYPE_EXTRACT       = 'extract';

    /**
     * @return array{success:bool,passage_id?:int,errors?:array<int,string>}
     */
    public function create( int $school_id, int $author_staff_id, array $data ): array {
        $errors = [];

        $body  = trim( wp_kses_post( (string) ( $data['body'] ?? '' ) ) );
        $image = esc_url_raw( (string) ( $data['image'] ?? '' ) );

        // Same rule as questions: text OR image. A map or data table with no prose
        // is a perfectly ordinary stimulus.
        if ( wp_strip_all_tags( $body ) === '' && $image === '' ) {
            $errors[] = 'passage_needs_text_or_image';
        }

        $type = (string) ( $data['passage_type'] ?? self::TYPE_COMPREHENSION );

        if ( ! in_array( $type, self::types(), true ) ) {
            $errors[] = 'invalid_passage_type';
        }

        if ( absint( $data['subject_id'] ?? 0 ) <= 0 ) {
            $errors[] = 'subject_required';
        }

        if ( ! empty( $errors ) ) {
            return [ 'success' => false, 'errors' => $errors ];
        }

        global $wpdb;

        $wpdb->insert(
            Schema::table( 'passages' ),
            [
                'school_id'       => $school_id,
                'subject_id'      => absint( $data['subject_id'] ),
                'class_level'     => sanitize_text_field( (string) ( $data['class_level'] ?? '' ) ),
                'title'           => sanitize_text_field( (string) ( $data['title'] ?? '' ) ),
                'passage_type'    => $type,
                'body'            => $body,
                'image'           => $image,
                'author_staff_id' => $author_staff_id,
                'status'          => 'active',
            ],
            [ '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s' ]
        );

        $passage_id = absint( $wpdb->insert_id );

        EventDispatcher::action( 'educbt_passage_created', [
            'school_id'  => $school_id,
            'passage_id' => $passage_id,
        ] );

        return [ 'success' => true, 'passage_id' => $passage_id ];
    }

    /**
     * @return array<int,string>
     */
    public static function types(): array {
        return [
            self::TYPE_COMPREHENSION,
            self::TYPE_CLOZE,
            self::TYPE_INSTRUCTION,
            self::TYPE_DATA,
            self::TYPE_EXTRACT,
        ];
    }

    public function update( int $school_id, int $passage_id, array $data ): bool {
        global $wpdb;

        $fields = [];

        foreach ( [ 'title' => 'sanitize_text_field', 'class_level' => 'sanitize_text_field' ] as $field => $sanitizer ) {
            if ( isset( $data[ $field ] ) ) {
                $fields[ $field ] = $sanitizer( (string) $data[ $field ] );
            }
        }

        if ( isset( $data['body'] ) ) {
            $fields['body'] = wp_kses_post( (string) $data['body'] );
        }

        if ( isset( $data['image'] ) ) {
            $fields['image'] = esc_url_raw( (string) $data['image'] );
        }

        if ( empty( $fields ) ) {
            return false;
        }

        // Editing a passage updates it for every question that references it — the
        // whole point of storing it once.
        return (bool) $wpdb->update(
            Schema::table( 'passages' ),
            $fields,
            [ 'id' => $passage_id, 'school_id' => $school_id ],
            array_fill( 0, count( $fields ), '%s' ),
            [ '%d', '%d' ]
        );
    }

    /**
     * Attach questions to a passage in a fixed reading order.
     *
     * @param array<int,int> $question_ids
     */
    public function attach_questions( int $school_id, int $passage_id, array $question_ids ): int {
        global $wpdb;

        $questions = $wpdb->prefix . 'educbt_questions';
        $position  = 0;
        $attached  = 0;

        foreach ( array_map( 'absint', $question_ids ) as $question_id ) {
            if ( $question_id <= 0 ) {
                continue;
            }

            $updated = $wpdb->update(
                $questions,
                [ 'passage_id' => $passage_id, 'passage_position' => $position ],
                [ 'id' => $question_id, 'school_id' => $school_id ],
                [ '%d', '%d' ],
                [ '%d', '%d' ]
            );

            if ( $updated !== false ) {
                $position++;
                $attached++;
            }
        }

        return $attached;
    }

    public function detach_question( int $school_id, int $question_id ): bool {
        global $wpdb;

        return (bool) $wpdb->update(
            $wpdb->prefix . 'educbt_questions',
            [ 'passage_id' => null, 'passage_position' => 0 ],
            [ 'id' => $question_id, 'school_id' => $school_id ],
            [ '%d', '%d' ],
            [ '%d', '%d' ]
        );
    }

    public function get( int $school_id, int $passage_id ): ?array {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM ' . Schema::table( 'passages' ) . ' WHERE id = %d AND school_id = %d',
                $passage_id,
                $school_id
            ),
            ARRAY_A
        );

        return $row ?: null;
    }

    public function list_for_subject( int $school_id, int $subject_id, string $class_level = '' ): array {
        global $wpdb;

        $table     = Schema::table( 'passages' );
        $questions = $wpdb->prefix . 'educbt_questions';

        $sql = "SELECT p.*, COUNT(q.id) AS question_count
                FROM {$table} p
                LEFT JOIN {$questions} q ON q.passage_id = p.id AND q.status = 'active'
                WHERE p.school_id = %d AND p.subject_id = %d AND p.status = 'active'";

        $params = [ $school_id, $subject_id ];

        if ( $class_level !== '' ) {
            $sql     .= ' AND p.class_level = %s';
            $params[] = $class_level;
        }

        $sql .= ' GROUP BY p.id ORDER BY p.created_at DESC';

        return (array) $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
    }

    /**
     * THE ORDERING RULE.
     *
     * Given the questions selected for a paper, produce the delivery order such that
     * passage-linked questions stay contiguous and in their reading order, while
     * standalone questions and whole passage-blocks are shuffled freely.
     *
     * Without this, Phase 5's shuffle would scatter "Question 3 refers to the passage
     * above" to somewhere the passage isn't.
     *
     * @param array<int,array{id:int,passage_id?:int|null,passage_position?:int}> $questions
     * @return array<int,int> ordered question ids
     */
    public function order_for_delivery( array $questions, bool $shuffle = true ): array {
        $blocks     = [];
        $standalone = [];

        foreach ( $questions as $question ) {
            $passage_id = absint( $question['passage_id'] ?? 0 );

            if ( $passage_id > 0 ) {
                $blocks[ $passage_id ][] = $question;
            } else {
                $standalone[] = $question;
            }
        }

        // Within a block, reading order is fixed and must never be shuffled.
        foreach ( $blocks as $passage_id => $items ) {
            usort(
                $items,
                static fn( array $a, array $b ): int => absint( $a['passage_position'] ?? 0 ) <=> absint( $b['passage_position'] ?? 0 )
            );

            $blocks[ $passage_id ] = $items;
        }

        // Units of shuffling: each passage block is one unit, each standalone
        // question is one unit.
        $units = [];

        foreach ( $blocks as $items ) {
            $units[] = $items;
        }

        foreach ( $standalone as $question ) {
            $units[] = [ $question ];
        }

        if ( $shuffle ) {
            shuffle( $units );
        }

        $ordered = [];

        foreach ( $units as $unit ) {
            foreach ( $unit as $question ) {
                $ordered[] = absint( $question['id'] );
            }
        }

        return $ordered;
    }

    /**
     * Passages needed to render a paper, so the client fetches each one once rather
     * than per question.
     *
     * @return array<int,array<string,mixed>>
     */
    public function for_paper( int $school_id, int $paper_id ): array {
        global $wpdb;

        $passages        = Schema::table( 'passages' );
        $paper_questions = Schema::table( 'paper_questions' );
        $questions       = $wpdb->prefix . 'educbt_questions';

        $rows = (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT DISTINCT p.id, p.title, p.passage_type, p.body, p.image
                 FROM {$paper_questions} pq
                 INNER JOIN {$questions} q ON q.id = pq.question_id
                 INNER JOIN {$passages} p ON p.id = q.passage_id
                 WHERE pq.paper_id = %d AND pq.school_id = %d",
                $paper_id,
                $school_id
            ),
            ARRAY_A
        );

        $keyed = [];

        foreach ( $rows as $row ) {
            $keyed[ absint( $row['id'] ) ] = $row;
        }

        return $keyed;
    }

    /**
     * A passage whose questions have been split across different papers is almost
     * always a mistake, and produces an unanswerable paper. Surfaced before publish.
     *
     * @return array<int,array<string,mixed>>
     */
    public function find_orphaned_questions( int $school_id, int $paper_id ): array {
        global $wpdb;

        $paper_questions = Schema::table( 'paper_questions' );
        $questions       = $wpdb->prefix . 'educbt_questions';
        $passages        = Schema::table( 'passages' );

        return (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT p.id AS passage_id, p.title,
                        COUNT(DISTINCT q_all.id) AS total_questions,
                        COUNT(DISTINCT pq.question_id) AS on_this_paper
                 FROM {$paper_questions} pq
                 INNER JOIN {$questions} q ON q.id = pq.question_id
                 INNER JOIN {$passages} p ON p.id = q.passage_id
                 INNER JOIN {$questions} q_all ON q_all.passage_id = p.id AND q_all.status = 'active'
                 WHERE pq.paper_id = %d AND pq.school_id = %d
                 GROUP BY p.id
                 HAVING on_this_paper < total_questions",
                $paper_id,
                $school_id
            ),
            ARRAY_A
        );
    }
}
