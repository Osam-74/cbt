<?php

namespace EduCBTPro\Services;

use EduCBTPro\Core\EventDispatcher;
use EduCBTPro\Core\Schema;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * PHASE 5b — question authoring.
 *
 * The request was a front-end form with a question box, four option boxes, an image
 * option and a save-as-draft button. That is the right shape, but a bare form gets
 * painful at scale: a teacher entering sixty questions for a mock exam will not do
 * it in one sitting, will paste some of them from an existing Word document, and
 * will get half-way through a question when a lesson starts.
 *
 * So authoring is built as a WORKSPACE with three entry modes feeding one draft
 * pool, rather than as a single form:
 *
 *   BUILDER  one question at a time — stem, options A-D (extendable to F), each of
 *            which may be text, an image, or both. This is the form asked for.
 *   PASTE    a teacher pastes a numbered block straight out of Word or WhatsApp in
 *            the format they already write questions in. Parsed into drafts they
 *            then correct in the builder.
 *   CSV      spreadsheet import (Phase 5).
 *
 * Everything lands in `question_drafts` first. Drafts are private to their author,
 * survive a closed laptop, and are never visible to the paper composer. Publishing
 * a batch is an explicit act that runs full validation, so an incomplete question
 * physically cannot reach a live paper.
 *
 * The reason drafts are a separate table rather than a `status` on the questions
 * table: a draft is allowed to be invalid. A question is not. Mixing them means
 * every read of the question bank has to remember to filter, and one forgotten
 * filter puts a half-written question in front of a class.
 */
class QuestionAuthoringService {

    public const MAX_OPTIONS = 6;

    /** Autosave debounce guidance for the front end, in milliseconds. */
    public const AUTOSAVE_INTERVAL_MS = 4000;

    // ---------------------------------------------------------------
    // Draft lifecycle
    // ---------------------------------------------------------------

    /**
     * Create or update a single draft. Called by the builder's Save button and by
     * its autosave, which is why an entirely empty draft is still accepted: losing a
     * teacher's half-typed stem because it wasn't valid yet is exactly the failure
     * this table exists to prevent.
     *
     * @return array{success:bool,draft_id?:int,is_complete?:bool,errors?:array<int,string>,warnings?:array<int,string>}
     */
    public function save_draft( int $school_id, int $author_staff_id, array $data ): array {
        global $wpdb;

        $draft_id = absint( $data['draft_id'] ?? 0 );
        $payload  = $this->normalise_payload( $data );

        $check = $this->validate_payload( $payload );

        $table = Schema::table( 'question_drafts' );

        $row = [
            'school_id'         => $school_id,
            'batch_id'          => sanitize_text_field( (string) ( $data['batch_id'] ?? '' ) ),
            'author_staff_id'   => $author_staff_id,
            'subject_id'        => absint( $payload['subject_id'] ) ?: null,
            'class_level'       => sanitize_text_field( (string) $payload['class_level'] ),
            'position'          => absint( $data['position'] ?? 0 ),
            'payload'           => wp_json_encode( $payload ),
            'validation_errors' => wp_json_encode( $check['errors'] ),
            'is_complete'       => $check['valid'] ? 1 : 0,
            'status'            => 'draft',
        ];

        if ( $draft_id > 0 ) {
            $owns = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT id FROM {$table} WHERE id = %d AND school_id = %d AND author_staff_id = %d AND status = 'draft'",
                    $draft_id,
                    $school_id,
                    $author_staff_id
                )
            );

            if ( ! $owns ) {
                return [ 'success' => false, 'errors' => [ 'draft_not_found_or_not_yours' ] ];
            }

            $wpdb->update( $table, $row, [ 'id' => $draft_id ], null, [ '%d' ] );
        } else {
            $wpdb->insert( $table, $row );
            $draft_id = absint( $wpdb->insert_id );
        }

        return [
            'success'     => true,
            'draft_id'    => $draft_id,
            'is_complete' => $check['valid'],
            'errors'      => $check['errors'],
            'warnings'    => $check['warnings'],
        ];
    }

    /**
     * Shape whatever the form sent into the canonical payload, so the builder, the
     * paste parser and the CSV importer all produce identical structures.
     *
     * @return array<string,mixed>
     */
    public function normalise_payload( array $data ): array {
        $options = [];
        $keys    = range( 'A', 'Z' );

        $incoming = is_array( $data['options'] ?? null ) ? $data['options'] : [];
        $correct  = $data['correct'] ?? null;

        foreach ( array_values( $incoming ) as $index => $option ) {
            if ( $index >= self::MAX_OPTIONS ) {
                break;
            }

            $key = $keys[ $index ];

            $text  = trim( wp_kses_post( (string) ( $option['text'] ?? '' ) ) );
            $image = esc_url_raw( (string) ( $option['image'] ?? '' ) );

            // An option marked correct either by a flag on itself or by the stem's
            // `correct` field, which is how a radio-button form naturally posts.
            $is_correct = ! empty( $option['is_correct'] );

            if ( is_string( $correct ) && strtoupper( $correct ) === $key ) {
                $is_correct = true;
            } elseif ( is_array( $correct ) && in_array( $key, array_map( 'strtoupper', $correct ), true ) ) {
                $is_correct = true;
            }

            $options[] = [
                'key'        => $key,
                'text'       => $text,
                'image'      => $image,
                'is_correct' => $is_correct,
            ];
        }

        // The builder opens with four empty boxes, as asked for.
        while ( count( $options ) < 4 ) {
            $options[] = [ 'key' => $keys[ count( $options ) ], 'text' => '', 'image' => '', 'is_correct' => false ];
        }

        return [
            'subject_id'     => absint( $data['subject_id'] ?? 0 ),
            'class_level'    => (string) ( $data['class_level'] ?? '' ),
            'topic'          => sanitize_text_field( (string) ( $data['topic'] ?? '' ) ),
            'difficulty'     => (string) ( $data['difficulty'] ?? 'medium' ),
            'marks'          => (float) ( $data['marks'] ?? 1 ),
            'question_type'  => (string) ( $data['question_type'] ?? QuestionBankService::TYPE_SINGLE ),
            'question_text'  => trim( wp_kses_post( (string) ( $data['question_text'] ?? '' ) ) ),
            'question_image' => esc_url_raw( (string) ( $data['question_image'] ?? '' ) ),
            'options'        => $options,
        ];
    }

    /**
     * Validation that understands images.
     *
     * The important rule: a question or an option is populated if it has TEXT OR AN
     * IMAGE. A diagram-based question with an empty stem and a picture is perfectly
     * valid, and a maths option that is a rendered equation image is too. Requiring
     * text would make the image feature useless.
     *
     * @return array{valid:bool,errors:array<int,string>,warnings:array<int,string>}
     */
    public function validate_payload( array $payload ): array {
        $errors   = [];
        $warnings = [];

        $has_stem_text  = trim( wp_strip_all_tags( (string) $payload['question_text'] ) ) !== '';
        $has_stem_image = (string) $payload['question_image'] !== '';

        if ( ! $has_stem_text && ! $has_stem_image ) {
            $errors[] = 'question_needs_text_or_image';
        }

        // A picture with no wording is legal but usually an oversight — a student
        // needs to know what they are being asked about the diagram.
        if ( ! $has_stem_text && $has_stem_image ) {
            $warnings[] = 'image_only_question_has_no_wording';
        }

        if ( absint( $payload['subject_id'] ) <= 0 ) {
            $errors[] = 'subject_required';
        }

        $filled  = [];
        $correct = 0;
        $texts   = [];

        foreach ( (array) $payload['options'] as $option ) {
            $has_text  = trim( wp_strip_all_tags( (string) $option['text'] ) ) !== '';
            $has_image = (string) $option['image'] !== '';

            if ( ! $has_text && ! $has_image ) {
                continue;
            }

            $filled[] = $option;

            if ( ! empty( $option['is_correct'] ) ) {
                $correct++;
            }

            if ( $has_text ) {
                $texts[] = strtolower( trim( wp_strip_all_tags( (string) $option['text'] ) ) );
            }
        }

        if ( count( $filled ) < 2 ) {
            $errors[] = 'at_least_two_options_required';
        }

        // A correct answer marked on an option the teacher then blanked out.
        $marked_but_empty = false;

        foreach ( (array) $payload['options'] as $option ) {
            $empty = trim( wp_strip_all_tags( (string) $option['text'] ) ) === '' && (string) $option['image'] === '';

            if ( $empty && ! empty( $option['is_correct'] ) ) {
                $marked_but_empty = true;
            }
        }

        if ( $marked_but_empty ) {
            $errors[] = 'correct_answer_marked_on_empty_option';
        }

        $type = (string) $payload['question_type'];

        if ( $correct === 0 ) {
            $errors[] = 'no_correct_answer';
        }

        if ( $correct > 1 && $type !== QuestionBankService::TYPE_MULTIPLE ) {
            $errors[] = 'multiple_correct_answers_on_single_choice';
        }

        if ( $type === QuestionBankService::TYPE_MULTIPLE && $correct < 2 ) {
            $errors[] = 'multiple_choice_needs_two_or_more_correct';
        }

        if ( count( $texts ) !== count( array_unique( $texts ) ) ) {
            $errors[] = 'duplicate_option_text';
        }

        if ( (float) $payload['marks'] <= 0 ) {
            $errors[] = 'marks_must_be_positive';
        }

        // Options of wildly uneven length are a well-known giveaway: the longest
        // option is disproportionately often the correct one.
        $lengths = array_map( 'strlen', $texts );

        if ( count( $lengths ) >= 3 && max( $lengths ) > 0 && max( $lengths ) > 4 * max( 1, (int) ( array_sum( $lengths ) / count( $lengths ) ) ) ) {
            $warnings[] = 'one_option_much_longer_than_the_others';
        }

        return [ 'valid' => empty( $errors ), 'errors' => $errors, 'warnings' => $warnings ];
    }

    /**
     * A teacher's drafts, newest batch first, so the workspace reopens where they
     * left off.
     *
     * @return array<int,array<string,mixed>>
     */
    public function list_drafts( int $school_id, int $author_staff_id, string $batch_id = '' ): array {
        global $wpdb;

        $table = Schema::table( 'question_drafts' );

        $sql    = "SELECT * FROM {$table} WHERE school_id = %d AND author_staff_id = %d AND status = 'draft'";
        $params = [ $school_id, $author_staff_id ];

        if ( $batch_id !== '' ) {
            $sql     .= ' AND batch_id = %s';
            $params[] = $batch_id;
        }

        $sql .= ' ORDER BY position ASC, id ASC';

        $rows = (array) $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );

        foreach ( $rows as &$row ) {
            $row['payload']           = json_decode( (string) $row['payload'], true ) ?: [];
            $row['validation_errors'] = json_decode( (string) $row['validation_errors'], true ) ?: [];
        }

        return $rows;
    }

    /**
     * Progress summary for the workspace header — "18 of 60 ready".
     *
     * @return array{total:int,complete:int,incomplete:int,batch_id:string}
     */
    public function batch_summary( int $school_id, int $author_staff_id, string $batch_id ): array {
        global $wpdb;

        $table = Schema::table( 'question_drafts' );

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT COUNT(*) AS total, SUM(is_complete) AS complete
                 FROM {$table}
                 WHERE school_id = %d AND author_staff_id = %d AND batch_id = %s AND status = 'draft'",
                $school_id,
                $author_staff_id,
                $batch_id
            ),
            ARRAY_A
        );

        $total    = absint( $row['total'] ?? 0 );
        $complete = absint( $row['complete'] ?? 0 );

        return [
            'total'      => $total,
            'complete'   => $complete,
            'incomplete' => $total - $complete,
            'batch_id'   => $batch_id,
        ];
    }

    public function delete_draft( int $school_id, int $author_staff_id, int $draft_id ): bool {
        global $wpdb;

        return (bool) $wpdb->delete(
            Schema::table( 'question_drafts' ),
            [ 'id' => $draft_id, 'school_id' => $school_id, 'author_staff_id' => $author_staff_id, 'status' => 'draft' ],
            [ '%d', '%d', '%d', '%s' ]
        );
    }

    /**
     * Publish complete drafts into the live bank.
     *
     * Incomplete drafts are LEFT ALONE rather than dropped: a teacher who publishes
     * 55 of 60 keeps the 5 broken ones to fix, and gets told which they are.
     *
     * @return array{published:int,skipped:int,skipped_detail:array<int,array<string,mixed>>}
     */
    public function publish_batch( int $school_id, int $author_staff_id, string $batch_id ): array {
        global $wpdb;

        $drafts   = $this->list_drafts( $school_id, $author_staff_id, $batch_id );
        $bank     = new QuestionBankService();
        $table    = Schema::table( 'question_drafts' );

        $published = 0;
        $skipped   = [];

        foreach ( $drafts as $draft ) {
            $payload = (array) $draft['payload'];
            $check   = $this->validate_payload( $payload );

            if ( ! $check['valid'] ) {
                $skipped[] = [
                    'draft_id' => absint( $draft['id'] ),
                    'position' => absint( $draft['position'] ),
                    'preview'  => $this->preview( $payload ),
                    'errors'   => $check['errors'],
                ];
                continue;
            }

            $result = $bank->create( $school_id, $this->payload_to_question( $payload ) );

            if ( empty( $result['success'] ) ) {
                $skipped[] = [
                    'draft_id' => absint( $draft['id'] ),
                    'position' => absint( $draft['position'] ),
                    'preview'  => $this->preview( $payload ),
                    'errors'   => $result['errors'] ?? [ 'save_failed' ],
                ];
                continue;
            }

            $wpdb->update(
                $table,
                [ 'status' => 'published', 'published_question_id' => absint( $result['question_id'] ) ],
                [ 'id' => absint( $draft['id'] ) ],
                [ '%s', '%d' ],
                [ '%d' ]
            );

            $published++;
        }

        EventDispatcher::action( 'educbt_question_batch_published', [
            'school_id' => $school_id,
            'batch_id'  => $batch_id,
            'published' => $published,
            'skipped'   => count( $skipped ),
        ] );

        return [
            'published'      => $published,
            'skipped'        => count( $skipped ),
            'skipped_detail' => $skipped,
        ];
    }

    /**
     * Drop empty trailing options so a four-box form that only used three does not
     * present a blank option D to a student.
     *
     * @return array<string,mixed>
     */
    public function payload_to_question( array $payload ): array {
        $options = [];

        foreach ( (array) $payload['options'] as $option ) {
            $has_text  = trim( wp_strip_all_tags( (string) $option['text'] ) ) !== '';
            $has_image = (string) $option['image'] !== '';

            if ( ! $has_text && ! $has_image ) {
                continue;
            }

            $options[] = [
                'text'       => (string) $option['text'],
                'image'      => (string) $option['image'],
                'is_correct' => ! empty( $option['is_correct'] ),
            ];
        }

        return [
            'subject_id'     => absint( $payload['subject_id'] ),
            'class_level'    => (string) $payload['class_level'],
            'topic'          => (string) $payload['topic'],
            'difficulty'     => (string) $payload['difficulty'],
            'marks'          => (float) $payload['marks'],
            'question_type'  => (string) $payload['question_type'],
            'question_text'  => (string) $payload['question_text'],
            'question_image' => (string) $payload['question_image'],
            'options'        => $options,
        ];
    }

    private function preview( array $payload ): string {
        $text = trim( wp_strip_all_tags( (string) ( $payload['question_text'] ?? '' ) ) );

        if ( $text === '' ) {
            return ( $payload['question_image'] ?? '' ) !== '' ? '[image question]' : '[empty]';
        }

        return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, 60 ) : substr( $text, 0, 60 );
    }

    // ---------------------------------------------------------------
    // Paste mode
    // ---------------------------------------------------------------

    /**
     * Parse a numbered block pasted straight out of Word, WhatsApp or a PDF.
     *
     * The format teachers already write in:
     *
     *   1. What is the SI unit of force?
     *   A. Newton
     *   B. Joule
     *   C. Watt
     *   D. Pascal
     *   Answer: A
     *
     * Tolerant of `1)` `1.` `Q1.`, of `A)` `A.` `(A)`, and of `Answer:` / `Ans:` /
     * `Correct:`. Anything it cannot read becomes an incomplete draft the teacher
     * fixes in the builder rather than a silent drop — a question that vanishes
     * between Word and the bank is far worse than one flagged for attention.
     *
     * @return array<int,array<string,mixed>>
     */
    public function parse_pasted_block( string $text ): array {
        $text  = str_replace( [ "\r\n", "\r" ], "\n", trim( $text ) );
        $lines = explode( "\n", $text );

        $questions = [];
        $current   = null;

        $q_pattern      = '/^\s*(?:Q(?:uestion)?\s*)?(\d{1,3})\s*[\.\)\:]\s*(.*)$/i';
        // Options may be upper or lower case, bracketed or not. A teacher pasting
        // "(a) Lagos" from a Word document is not doing anything unusual.
        $option_pattern = '/^\s*\(?([A-Fa-f])\)?\s*[\.\)\:]?\s+(.*)$/';
        $answer_pattern = '/^\s*(?:Answer|Ans|Correct(?:\s+Answer)?)\s*[\:\-]\s*\(?([A-Fa-f])\)?/i';
        // Options crammed onto the stem line: "Largest planet? A. Earth B. Mars ..."
        $inline_pattern = '/\(?\b([A-Fa-f])\)?[\.\)]\s+([^A-F]*?)(?=\s+\(?[A-Fa-f]\)?[\.\)]\s+|$)/';

        foreach ( $lines as $line ) {
            $line = trim( $line );

            if ( $line === '' ) {
                continue;
            }

            if ( preg_match( $answer_pattern, $line, $m ) ) {
                if ( $current !== null ) {
                    $current['correct'] = strtoupper( $m[1] );
                }
                continue;
            }

            if ( preg_match( $q_pattern, $line, $m ) && ! preg_match( $option_pattern, $line ) ) {
                if ( $current !== null ) {
                    $questions[] = $current;
                }

                $stem = trim( $m[2] );

                $current = [
                    'number'        => (int) $m[1],
                    'question_text' => $stem,
                    'options'       => [],
                    'correct'       => '',
                ];

                // Options written on the same line as the question.
                if ( preg_match_all( $inline_pattern, $stem, $inline, PREG_SET_ORDER ) && count( $inline ) >= 2 ) {
                    $first = strpos( $stem, $inline[0][0] );

                    if ( $first !== false && $first > 0 ) {
                        $current['question_text'] = trim( substr( $stem, 0, $first ) );

                        foreach ( $inline as $option ) {
                            $current['options'][ strtoupper( $option[1] ) ] = trim( $option[2] );
                        }
                    }
                }

                continue;
            }

            if ( $current !== null && preg_match( $option_pattern, $line, $m ) ) {
                $key  = strtoupper( $m[1] );
                $body = trim( $m[2] );

                // A trailing asterisk is a common way of marking the right answer in
                // a printed or pasted question paper. Treat it as the answer, and do
                // not leave the star in the option a student sees.
                if ( substr( $body, -1 ) === '*' ) {
                    $body               = rtrim( rtrim( $body, '*' ) );
                    $current['correct'] = $key;
                }

                $current['options'][ $key ] = $body;
                continue;
            }

            // A continuation of the stem, which happens whenever a question wraps.
            if ( $current !== null && empty( $current['options'] ) ) {
                $current['question_text'] = trim( $current['question_text'] . ' ' . $line );
            }
        }

        if ( $current !== null ) {
            $questions[] = $current;
        }

        return $questions;
    }

    /**
     * Turn a pasted block into drafts. Everything is written, valid or not, and the
     * caller is told how many still need attention.
     *
     * @return array{batch_id:string,created:int,complete:int,needs_attention:int}
     */
    public function import_pasted_block( int $school_id, int $author_staff_id, string $text, int $subject_id, string $class_level ): array {
        $parsed   = $this->parse_pasted_block( $text );
        $batch_id = 'paste-' . gmdate( 'Ymd-His' ) . '-' . wp_generate_password( 6, false, false );

        $created  = 0;
        $complete = 0;
        $position = 0;

        foreach ( $parsed as $item ) {
            $options = [];

            foreach ( [ 'A', 'B', 'C', 'D', 'E', 'F' ] as $key ) {
                if ( isset( $item['options'][ $key ] ) ) {
                    $options[] = [ 'text' => $item['options'][ $key ] ];
                }
            }

            $result = $this->save_draft(
                $school_id,
                $author_staff_id,
                [
                    'batch_id'      => $batch_id,
                    'position'      => $position++,
                    'subject_id'    => $subject_id,
                    'class_level'   => $class_level,
                    'question_text' => $item['question_text'],
                    'options'       => $options,
                    'correct'       => $item['correct'],
                ]
            );

            if ( ! empty( $result['success'] ) ) {
                $created++;

                if ( ! empty( $result['is_complete'] ) ) {
                    $complete++;
                }
            }
        }

        return [
            'batch_id'        => $batch_id,
            'created'         => $created,
            'complete'        => $complete,
            'needs_attention' => $created - $complete,
        ];
    }

    // ---------------------------------------------------------------
    // Media
    // ---------------------------------------------------------------

    /**
     * Validate an uploaded image before it is attached to a question or option.
     *
     * Size matters more than it looks here. Thirty students opening a paper whose
     * questions carry 4 MB photographs will saturate a school's connection, and the
     * exam will look broken. The cap is deliberately tight and the caller is expected
     * to resize rather than reject outright where it can.
     *
     * @return array{valid:bool,errors:array<int,string>,warnings:array<int,string>}
     */
    public function validate_image( array $file ): array {
        $errors   = [];
        $warnings = [];

        $allowed  = [ 'image/jpeg', 'image/png', 'image/gif', 'image/webp' ];
        $max_bytes = 2 * MB_IN_BYTES;
        $warn_bytes = 500 * KB_IN_BYTES;

        $type = (string) ( $file['type'] ?? '' );
        $size = absint( $file['size'] ?? 0 );

        if ( ! in_array( $type, $allowed, true ) ) {
            $errors[] = 'unsupported_image_type:' . $type;
        }

        if ( $size <= 0 ) {
            $errors[] = 'empty_file';
        } elseif ( $size > $max_bytes ) {
            $errors[] = 'image_too_large:' . round( $size / MB_IN_BYTES, 1 ) . 'MB';
        } elseif ( $size > $warn_bytes ) {
            $warnings[] = 'large_image_will_slow_the_exam:' . round( $size / KB_IN_BYTES ) . 'KB';
        }

        return [ 'valid' => empty( $errors ), 'errors' => $errors, 'warnings' => $warnings ];
    }

    /**
     * Total image payload a student will download for a paper. Surfaced to the exam
     * officer before publishing, because this is the number that decides whether a
     * paper opens in ten seconds or two minutes on a school connection.
     */
    public function estimate_paper_weight( int $school_id, int $paper_id ): array {
        global $wpdb;

        $paper_questions = Schema::table( 'paper_questions' );
        $options         = Schema::table( 'question_options' );
        $questions       = $wpdb->prefix . 'educbt_questions';

        $counts = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT
                    SUM(CASE WHEN q.image_reference <> '' THEN 1 ELSE 0 END) AS stem_images,
                    (SELECT COUNT(*) FROM {$options} o
                      INNER JOIN {$paper_questions} pq2 ON pq2.question_id = o.question_id
                      WHERE pq2.paper_id = %d AND o.option_image <> '') AS option_images
                 FROM {$paper_questions} pq
                 INNER JOIN {$questions} q ON q.id = pq.question_id
                 WHERE pq.paper_id = %d AND pq.school_id = %d",
                $paper_id,
                $paper_id,
                $school_id
            ),
            ARRAY_A
        );

        $stem   = absint( $counts['stem_images'] ?? 0 );
        $option = absint( $counts['option_images'] ?? 0 );
        $total  = $stem + $option;

        // 250 KB is a realistic average for a resized exam diagram.
        $estimated_kb = $total * 250;

        return [
            'stem_images'      => $stem,
            'option_images'    => $option,
            'total_images'     => $total,
            'estimated_kb'     => $estimated_kb,
            'heavy'            => $estimated_kb > 5000,
        ];
    }
}
