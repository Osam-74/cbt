<?php

namespace EduCBTPro\Services;

use EduCBTPro\Core\EventDispatcher;
use EduCBTPro\Core\Schema;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * PHASE 5 — question bank.
 *
 * Two changes from v1.
 *
 * First, options are ROWS, not a JSON blob with a parallel `answers` array. The v1
 * shape meant the correct answer could only be found by decoding two fields and
 * hoping they agreed; the backfill in Phase 1 found questions where they didn't,
 * and every one of those would have marked every student wrong, silently.
 *
 * Second, the form is shorter. v1 carried `bloom_level`, `learning_objective`,
 * `estimated_duration`, `examination_year` and `sub_topic` — university assessment
 * apparatus that a teacher entering sixty questions before Friday will not fill in.
 * What's kept is topic, difficulty, marks and type; anything else goes in an
 * optional `meta` JSON so the form stays short and nothing is lost.
 *
 * Validation is strict on one point in particular: a question MUST have exactly one
 * correct option. Zero means every student is marked wrong; two means the marking is
 * arbitrary. Both are silent failures, so they are refused at the door.
 */
class QuestionBankService {

    public const TYPE_SINGLE   = 'single_choice';
    public const TYPE_MULTIPLE = 'multiple_choice';
    public const TYPE_BOOLEAN  = 'true_false';

    /**
     * A written answer, marked by a human.
     *
     * WAEC papers are not all objective: English Paper 2 is essay, comprehension and
     * summary, and most subjects have a theory section. A platform that can only
     * express multiple choice cannot represent the exam a Nigerian school actually
     * sits.
     */
    public const TYPE_THEORY = 'theory';

    /**
     * @return array{success:bool,question_id?:int,errors?:array<int,string>}
     */
    public function create( int $school_id, array $data ): array {
        $validation = $this->validate( $data );

        if ( ! $validation['valid'] ) {
            return [ 'success' => false, 'errors' => $validation['errors'] ];
        }

        global $wpdb;

        $questions = $wpdb->prefix . 'educbt_questions';

        $wpdb->query( 'START TRANSACTION' );

        $wpdb->insert(
            $questions,
            [
                'school_id'    => $school_id,
                'subject'      => sanitize_text_field( (string) ( $data['subject_name'] ?? '' ) ),
                'subject_id'   => absint( $data['subject_id'] ?? 0 ),
                'class_level'  => sanitize_text_field( (string) ( $data['class_level'] ?? '' ) ),
                'topic'        => sanitize_text_field( (string) ( $data['topic'] ?? '' ) ),
                'difficulty'   => $this->normalise_difficulty( (string) ( $data['difficulty'] ?? 'medium' ) ),
                'question_type' => (string) ( $data['question_type'] ?? self::TYPE_SINGLE ),
                'question_text' => wp_kses_post( (string) ( $data['question_text'] ?? '' ) ),
                'image_reference' => esc_url_raw( (string) ( $data['question_image'] ?? '' ) ),
                'marks'        => (float) ( $data['marks'] ?? 1 ),
                'status'       => 'active',
            ],
            [ '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%f', '%s' ]
        );

        $question_id = absint( $wpdb->insert_id );

        if ( $question_id <= 0 ) {
            $wpdb->query( 'ROLLBACK' );
            return [ 'success' => false, 'errors' => [ 'insert_failed' ] ];
        }

        // A written/theory question has no options — saving empty A-D rows
        // made the question bank display them as if they were real choices.
        if ( (string) ( $data['question_type'] ?? self::TYPE_SINGLE ) !== self::TYPE_THEORY ) {
            $this->save_options( $school_id, $question_id, $data['options'] ?? [] );
        }

        $wpdb->query( 'COMMIT' );

        EventDispatcher::action( 'educbt_question_created', [
            'school_id'   => $school_id,
            'question_id' => $question_id,
            'subject_id'  => absint( $data['subject_id'] ?? 0 ),
        ] );

        return [ 'success' => true, 'question_id' => $question_id ];
    }

    /**
     * @param array<int,array{text:string,is_correct?:bool|int}> $options
     */
    private function save_options( int $school_id, int $question_id, array $options ): void {
        global $wpdb;

        $table = Schema::table( 'question_options' );

        $wpdb->delete( $table, [ 'question_id' => $question_id ], [ '%d' ] );

        $keys  = range( 'A', 'Z' );
        $order = 0;

        foreach ( array_values( $options ) as $index => $option ) {
            $wpdb->insert(
                $table,
                [
                    'school_id'    => $school_id,
                    'question_id'  => $question_id,
                    'option_key'   => $keys[ $index ] ?? (string) ( $index + 1 ),
                    'option_text'  => wp_kses_post( (string) ( $option['text'] ?? '' ) ),
                    'option_image' => esc_url_raw( (string) ( $option['image'] ?? '' ) ),
                    'is_correct'   => ! empty( $option['is_correct'] ) ? 1 : 0,
                    'sort_order'   => $order++,
                ],
                [ '%d', '%d', '%s', '%s', '%s', '%d', '%d' ]
            );
        }
    }

    /**
     * @return array{valid:bool,errors:array<int,string>}
     */
    public function validate( array $data ): array {
        $errors = [];

        // A question is populated by TEXT OR AN IMAGE. A diagram-based question with
        // a picture and no wording is valid; requiring text would make images useless.
        $text  = trim( wp_strip_all_tags( (string) ( $data['question_text'] ?? '' ) ) );
        $image = trim( (string) ( $data['question_image'] ?? '' ) );

        if ( $text === '' && $image === '' ) {
            $errors[] = 'question_needs_text_or_image';
        } elseif ( $text !== '' && strlen( $text ) < 5 && $image === '' ) {
            $errors[] = 'question_text_too_short';
        }

        if ( absint( $data['subject_id'] ?? 0 ) <= 0 ) {
            $errors[] = 'subject_required';
        }

        $type    = (string) ( $data['question_type'] ?? self::TYPE_SINGLE );
        $options = is_array( $data['options'] ?? null ) ? $data['options'] : [];

        if ( count( $options ) < 2 ) {
            $errors[] = 'at_least_two_options_required';
        }

        if ( $type === self::TYPE_BOOLEAN && count( $options ) !== 2 ) {
            $errors[] = 'true_false_needs_exactly_two_options';
        }

        $correct = 0;
        $blank   = 0;

        foreach ( $options as $option ) {
            if ( ! empty( $option['is_correct'] ) ) {
                $correct++;
            }

            if ( trim( (string) ( $option['text'] ?? '' ) ) === '' && empty( $option['image'] ) ) {
                $blank++;
            }
        }

        if ( $blank > 0 ) {
            $errors[] = 'blank_option:' . $blank;
        }

        // The two silent-failure cases.
        if ( $correct === 0 ) {
            $errors[] = 'no_correct_answer';
        }

        if ( $correct > 1 && $type !== self::TYPE_MULTIPLE ) {
            $errors[] = 'multiple_correct_answers_on_single_choice';
        }

        if ( $type === self::TYPE_MULTIPLE && $correct < 2 ) {
            $errors[] = 'multiple_choice_needs_two_or_more_correct';
        }

        // Duplicate option text makes a question unanswerable in practice.
        $texts = array_map(
            static fn( $o ): string => strtolower( trim( (string) ( $o['text'] ?? '' ) ) ),
            $options
        );
        $texts = array_filter( $texts );

        if ( count( $texts ) !== count( array_unique( $texts ) ) ) {
            $errors[] = 'duplicate_option_text';
        }

        if ( (float) ( $data['marks'] ?? 1 ) <= 0 ) {
            $errors[] = 'marks_must_be_positive';
        }

        return [ 'valid' => empty( $errors ), 'errors' => $errors ];
    }

    /**
     * Shared hosting cannot be assumed to have mbstring, so truncation goes through
     * a guarded helper rather than calling mb_substr directly.
     */
    private static function truncate( string $text, int $length ): string {
        if ( function_exists( 'mb_substr' ) ) {
            return mb_substr( $text, 0, $length );
        }

        return substr( $text, 0, $length );
    }

    private function normalise_difficulty( string $value ): string {
        $value = strtolower( trim( $value ) );

        $map = [
            'easy' => 'easy', 'simple' => 'easy', '1' => 'easy', 'low' => 'easy',
            'medium' => 'medium', 'moderate' => 'medium', 'average' => 'medium', '2' => 'medium',
            'hard' => 'hard', 'difficult' => 'hard', '3' => 'hard', 'high' => 'hard',
        ];

        return $map[ $value ] ?? 'medium';
    }

    // ---------------------------------------------------------------
    // CSV import
    // ---------------------------------------------------------------

    /**
     * Expected header:
     *   question, option_a, option_b, option_c, option_d, answer, topic, difficulty, marks
     *
     * `answer` accepts a letter (A/B/C/D) or the literal option text, because both
     * conventions turn up in files teachers actually have.
     *
     * One bad row must not abort the other 199, so failures are collected per row
     * with the line number a human can find in their spreadsheet.
     *
     * @return array{imported:int,failed:int,errors:array<int,array<string,mixed>>}
     */
    public function import_csv( int $school_id, string $csv, int $subject_id, string $class_level ): array {
        $rows = $this->parse_csv( $csv );

        $imported = 0;
        $failed   = 0;
        $errors   = [];

        foreach ( $rows as $line => $row ) {
            $parsed = $this->row_to_question( $row, $subject_id, $class_level );

            if ( ! $parsed['valid'] ) {
                $failed++;
                $errors[] = [
                    'line'     => $line + 2, // +1 for zero index, +1 for the header row
                    'question' => self::truncate( (string) ( $row['question'] ?? '' ), 60 ),
                    'errors'   => $parsed['errors'],
                ];
                continue;
            }

            $result = $this->create( $school_id, $parsed['data'] );

            if ( ! empty( $result['success'] ) ) {
                $imported++;
            } else {
                $failed++;
                $errors[] = [
                    'line'     => $line + 2,
                    'question' => self::truncate( (string) ( $row['question'] ?? '' ), 60 ),
                    'errors'   => $result['errors'] ?? [],
                ];
            }
        }

        return [ 'imported' => $imported, 'failed' => $failed, 'errors' => $errors ];
    }

    /**
     * @return array<int,array<string,string>>
     */
    public function parse_csv( string $csv ): array {
        $csv = str_replace( [ "\r\n", "\r" ], "\n", trim( $csv ) );

        if ( $csv === '' ) {
            return [];
        }

        $lines = explode( "\n", $csv );

        $header = str_getcsv( (string) array_shift( $lines ) );
        $header = array_map(
            static fn( $h ): string => strtolower( trim( (string) preg_replace( '/[^A-Za-z0-9_]/', '_', (string) $h ) ) ),
            $header
        );

        $rows = [];

        foreach ( $lines as $line ) {
            if ( trim( $line ) === '' ) {
                continue;
            }

            $values = str_getcsv( $line );
            $row    = [];

            foreach ( $header as $index => $key ) {
                $row[ $key ] = isset( $values[ $index ] ) ? trim( (string) $values[ $index ] ) : '';
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @return array{valid:bool,data?:array<string,mixed>,errors:array<int,string>}
     */
    public function row_to_question( array $row, int $subject_id, string $class_level ): array {
        $errors   = [];
        $question = trim( (string) ( $row['question'] ?? $row['question_text'] ?? '' ) );

        if ( $question === '' ) {
            $errors[] = 'missing_question_text';
        }

        // Theory questions: no options or correct answer needed. If the CSV has
        // a marking_guide column or no option columns at all, treat it as theory.
        $has_option_columns = false;
        foreach ( [ 'a', 'b', 'c', 'd', 'e', 'f' ] as $letter ) {
            if ( isset( $row[ 'option_' . $letter ] ) && trim( (string) $row[ 'option_' . $letter ] ) !== '' ) {
                $has_option_columns = true;
                break;
            }
        }

        if ( ! $has_option_columns ) {
            // No option columns — this is a theory/written question.
            if ( ! empty( $errors ) ) {
                return [ 'valid' => false, 'errors' => $errors ];
            }

            return [
                'valid'  => true,
                'errors' => [],
                'data'   => [
                    'subject_id'     => $subject_id,
                    'class_level'    => $class_level,
                    'question_text' => $question,
                    'topic'          => (string) ( $row['topic'] ?? '' ),
                    'difficulty'     => (string) ( $row['difficulty'] ?? 'medium' ),
                    'marks'          => (float) ( $row['marks'] ?? 1 ) ?: 1.0,
                    'question_type'  => self::TYPE_THEORY,
                    'options'        => [],
                    'marking_guide'  => trim( (string) ( $row['marking_guide'] ?? '' ) ),
                ],
            ];
        }

        $options = [];
        $letters = [ 'a', 'b', 'c', 'd', 'e', 'f' ];

        foreach ( $letters as $letter ) {
            $value = trim( (string) ( $row[ 'option_' . $letter ] ?? '' ) );

            if ( $value !== '' ) {
                $options[ strtoupper( $letter ) ] = $value;
            }
        }

        if ( count( $options ) < 2 ) {
            $errors[] = 'fewer_than_two_options';
        }

        $answer = trim( (string) ( $row['answer'] ?? $row['correct_answer'] ?? '' ) );

        if ( $answer === '' ) {
            $errors[] = 'missing_answer';
        }

        // Accept a letter or the literal option text.
        $correct_key = '';
        $upper       = strtoupper( $answer );

        if ( isset( $options[ $upper ] ) ) {
            $correct_key = $upper;
        } else {
            foreach ( $options as $key => $text ) {
                if ( strcasecmp( trim( $text ), $answer ) === 0 ) {
                    $correct_key = $key;
                    break;
                }
            }
        }

        if ( $answer !== '' && $correct_key === '' ) {
            $errors[] = 'answer_does_not_match_any_option:' . self::truncate( $answer, 20 );
        }

        if ( ! empty( $errors ) ) {
            return [ 'valid' => false, 'errors' => $errors ];
        }

        $built = [];

        foreach ( $options as $key => $text ) {
            $built[] = [ 'text' => $text, 'is_correct' => $key === $correct_key ];
        }

        return [
            'valid'  => true,
            'errors' => [],
            'data'   => [
                'subject_id'    => $subject_id,
                'class_level'   => $class_level,
                'question_text' => $question,
                'topic'         => (string) ( $row['topic'] ?? '' ),
                'difficulty'    => (string) ( $row['difficulty'] ?? 'medium' ),
                'marks'         => (float) ( $row['marks'] ?? 1 ) ?: 1.0,
                'question_type' => count( $built ) === 2 ? self::TYPE_BOOLEAN : self::TYPE_SINGLE,
                'options'       => $built,
            ],
        ];
    }

    // ---------------------------------------------------------------
    // Availability
    // ---------------------------------------------------------------

    /**
     * How many questions exist for a subject and level. An exam officer needs this
     * before scheduling a 60-question paper against a bank of 40.
     */
    public function count_available( int $school_id, int $subject_id, string $class_level = '' ): int {
        global $wpdb;

        $questions = $wpdb->prefix . 'educbt_questions';

        $sql    = "SELECT COUNT(*) FROM {$questions} WHERE school_id = %d AND subject_id = %d AND status = 'active'";
        $params = [ $school_id, $subject_id ];

        if ( $class_level !== '' ) {
            $sql     .= ' AND class_level = %s';
            $params[] = $class_level;
        }

        return absint( $wpdb->get_var( $wpdb->prepare( $sql, $params ) ) );
    }

    /**
     * Questions with no correct option — the silent failure the Phase 1 backfill
     * surfaced. Shown on the exam officer's health screen.
     */
    public function unusable_questions( int $school_id ): array {
        global $wpdb;

        $questions = $wpdb->prefix . 'educbt_questions';
        $options   = Schema::table( 'question_options' );

        return (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT q.id, q.subject, q.topic, LEFT(q.question_text, 80) AS preview,
                        COALESCE(o.total, 0) AS option_count,
                        COALESCE(o.correct, 0) AS correct_count
                 FROM {$questions} q
                 LEFT JOIN (
                     SELECT question_id, COUNT(*) AS total, SUM(is_correct) AS correct
                     FROM {$options} GROUP BY question_id
                 ) o ON o.question_id = q.id
                 WHERE q.school_id = %d AND q.status = 'active'
                   AND ( COALESCE(o.correct, 0) = 0 OR COALESCE(o.total, 0) < 2 )
                 ORDER BY q.id ASC",
                $school_id
            ),
            ARRAY_A
        );
    }
}
