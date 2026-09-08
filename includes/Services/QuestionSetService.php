<?php

namespace EduCBTPro\Services;

use EduCBTPro\Core\EventDispatcher;
use EduCBTPro\Core\Schema;
use EduCBTPro\Core\Scope;
use EduCBTPro\Core\Gate;
use EduCBTPro\Core\Capabilities;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Manages Question Sets — the unit of work for teacher question submission.
 *
 * A Question Set groups every question a teacher writes for one
 * subject + class + exam_type in one session/term. The unique key
 * (school_id, session_id, term_id, subject_id, level_id, department_id, exam_type)
 * makes resume reliable: selecting the same scope always finds the
 * same set.
 *
 * Lifecycle: draft → submitted → under_review → (returned → draft) | (approved → published)
 */
class QuestionSetService {

    /**
     * Find or create a Question Set for the given scope.
     *
     * The unique key means there can only be one. If it exists in draft or
     * returned status, we load it. If it doesn't exist, we return null —
     * the set is created on the first question added, not here, to avoid
     * creating empty sets for every scope combination a teacher browses.
     *
     * @return array<string,mixed>|null
     */
    /**
     * A set is identified by its scope AND the series it belongs to.
     *
     * Without series_id a CA test set and a terminal examination set for the same
     * subject, level and term are the same row — so writing CA questions would have
     * loaded the examination set and mixed the two banks together.
     */
    public function find_set( int $school_id, int $session_id, int $term_id, int $subject_id, int $level_id, int $department_id, string $exam_type, int $teacher_id = 0, int $series_id = 0, ?bool $waec_mode = null ): ?array {
        global $wpdb;

        $table = Schema::table( 'question_sets' );

        $set = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table}
                 WHERE school_id = %d AND session_id = %d AND term_id = %d
                   AND subject_id = %d AND level_id = %d AND department_id = %d AND exam_type = %s
                   AND COALESCE(series_id, 0) = %d
                   /* A WAEC paper and an ordinary school paper for the same subject
                      are two different papers. Sharing one row meant a teacher who
                      finished a WAEC set and then went to write the school's own
                      examination was handed the WAEC draft, sections and all. */
                   AND waec_mode = %d
                 LIMIT 1",
                $school_id, $session_id, $term_id, $subject_id, $level_id, $department_id, $exam_type, $series_id,
                $waec_mode === null ? 0 : ( $waec_mode ? 1 : 0 )
            ),
            ARRAY_A
        );

        if ( ! $set ) {
            return null;
        }

        return $this->normalize_set( $set );
    }

    /**
     * Resolve a class arm to the level and department that now identify a set.
     *
     * @return array{level_id:int,department_id:int}
     */
    public function scope_for_class( int $school_id, int $class_id ): array {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT level_id, department_id FROM ' . Schema::table( 'classes' ) . '
                 WHERE id = %d AND school_id = %d',
                $class_id,
                $school_id
            ),
            ARRAY_A
        );

        return [
            'level_id'      => absint( $row['level_id'] ?? 0 ),
            'department_id' => absint( $row['department_id'] ?? 0 ),
        ];
    }

    /**
     * Every class arm covered by a level + department.
     *
     * @return array<int,int>
     */
    public function classes_in_scope( int $school_id, int $level_id, int $department_id ): array {
        global $wpdb;

        $sql = 'SELECT id FROM ' . Schema::table( 'classes' ) . "
                WHERE school_id = %d AND level_id = %d AND status = 'active'";
        $params = [ $school_id, $level_id ];

        if ( $department_id > 0 ) {
            $sql     .= ' AND department_id = %d';
            $params[] = $department_id;
        }

        return array_map( 'absint', (array) $wpdb->get_col( $wpdb->prepare( $sql, $params ) ) );
    }

    /**
     * Create a new Question Set.
     */
    public function create_set( int $school_id, int $session_id, int $term_id, int $subject_id, int $level_id, int $department_id, string $exam_type, int $teacher_id, float $default_marks = 1.00, string $delivery_mode = 'cbt', bool $waec_mode = false, int $series_id = 0 ): array {
        global $wpdb;

        $table = Schema::table( 'question_sets' );

        $existing = $this->find_set( $school_id, $session_id, $term_id, $subject_id, $level_id, $department_id, $exam_type, 0, $series_id, $waec_mode );
        if ( $existing ) {
            return $existing;
        }

        // Snapshot the minimum requirement at creation time so later changes
        // by management don't retroactively alter submitted sets.
        $min_required = $this->get_min_required( $school_id, $subject_id, $level_id, $exam_type );

        // class_id is kept only so paper generation and older reports can still
        // resolve a representative arm. It no longer identifies the set.
        $classes            = $this->classes_in_scope( $school_id, $level_id, $department_id );
        $representative     = $classes[0] ?? 0;

        $wpdb->insert(
            $table,
            [
                'school_id'     => $school_id,
                'session_id'    => $session_id,
                'term_id'       => $term_id,
                'subject_id'    => $subject_id,
                'level_id'      => $level_id,
                'department_id' => $department_id,
                'class_id'      => $representative,
                'exam_type'     => $exam_type,
                'delivery_mode' => $delivery_mode,
                'waec_mode'     => $waec_mode ? 1 : 0,
                // Which series this belongs to: a CA window, or null for the term's examination.
                'series_id'     => $series_id ?: null,
                'teacher_id'    => $teacher_id,
                'default_marks' => $default_marks,
                'status'        => 'draft',
                'min_required'  => $min_required,
            ],
            // No explicit format list. It has to be kept in step with the column
            // list by hand, and adding a column above without adding a matching
            // placeholder here silently shifts every value after it into the wrong
            // column. wpdb infers the types correctly from the values.
        );

        $set_id = absint( $wpdb->insert_id );

        if ( $set_id <= 0 ) {
            return [ 'success' => false, 'error' => 'set_could_not_be_created' ];
        }

        return $this->get_set( $school_id, $set_id ) ?? [];
    }

    /**
     * Get all questions in a set, ordered by sequence.
     *
     * @return array<int,array<string,mixed>>
     */
    public function get_questions( int $set_id ): array {
        global $wpdb;

        $questions    = $wpdb->prefix . 'educbt_questions';
        $options      = Schema::table( 'question_options' );
        $sub_items    = Schema::table( 'question_sub_items' );

        $rows = (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$questions} WHERE question_set_id = %d AND (parent_id IS NULL OR parent_id = 0) ORDER BY sequence ASC, id ASC",
                $set_id
            ),
            ARRAY_A
        );

        foreach ( $rows as &$row ) {
            $row['id'] = absint( $row['id'] );
            $row['marks'] = (float) $row['marks'];

            if ( $row['question_type'] === 'single_choice' || $row['question_type'] === 'objective' ) {
                $row['options'] = (array) $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT id, option_key, option_text, option_image, is_correct, sort_order
                         FROM {$options} WHERE question_id = %d ORDER BY sort_order ASC",
                        absint( $row['id'] )
                    ),
                    ARRAY_A
                );
            } else {
                $row['options'] = [];
            }

            if ( $row['question_type'] === 'theory' ) {
                $row['sub_items'] = (array) $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT id, label, text, marks, sequence
                         FROM {$sub_items} WHERE question_id = %d ORDER BY sequence ASC",
                        absint( $row['id'] )
                    ),
                    ARRAY_A
                );
            } else {
                $row['sub_items'] = [];
            }
        }

        return $rows;
    }

    /**
     * Add a question to a set.
     *
     * @param array<string,mixed> $data Question data
     * @return array{success:bool,id?:int,error?:string}
     */
    public function add_question( int $school_id, int $set_id, array $data, int $teacher_id = 0, bool $can_approve = false ): array {
        global $wpdb;

        // Verify the set exists and is editable.
        $set = $this->get_set( $school_id, $set_id );
        if ( ! $set ) {
            return [ 'success' => false, 'error' => 'set_not_found' ];
        }

        if ( ! $this->is_editable( $set['status'] ) ) {
            return [ 'success' => false, 'error' => 'set_locked' ];
        }

        // Verify teacher assignment to this subject/class (security).
        if ( ! $this->verify_assignment( $school_id, $set['teacher_id'], $set['subject_id'], $set['level_id'], $set['department_id'] ) ) {
            return [ 'success' => false, 'error' => 'not_assigned' ];
        }

        $exam_type  = $set['exam_type'];
        $questions   = $wpdb->prefix . 'educbt_questions';
        $options     = Schema::table( 'question_options' );
        $sub_items   = Schema::table( 'question_sub_items' );

        $stem        = wp_kses_post( (string) ( $data['stem'] ?? '' ) );
        $marks       = (float) ( $data['marks'] ?? $set['default_marks'] );
        $source      = sanitize_key( (string) ( $data['source_method'] ?? 'manual' ) );
        $passage_id  = absint( $data['passage_id'] ?? 0 ) ?: null;
        $explanation = wp_kses_post( (string) ( $data['explanation'] ?? '' ) );
        $image_ref   = esc_url_raw( (string) ( $data['stem_image_id'] ?? '' ) );

        if ( $stem === '' ) {
            return [ 'success' => false, 'error' => 'stem_required' ];
        }

        if ( $marks <= 0 ) {
            return [ 'success' => false, 'error' => 'marks_required' ];
        }

        // Get next sequence number.
        $next_seq = absint(
            $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COALESCE(MAX(sequence), 0) + 1 FROM {$questions} WHERE question_set_id = %d",
                    $set_id
                )
            )
        );

        $question_type = $exam_type === 'theory' ? 'theory' : 'single_choice';

        $insert_data = [
            'question_set_id'  => $set_id,
            'school_id'        => $school_id,
            'subject_id'       => $set['subject_id'],
            'subject'          => (string) $wpdb->get_var( $wpdb->prepare( "SELECT name FROM " . Schema::table( 'subjects_v2' ) . " WHERE id = %d", $set['subject_id'] ) ),
            'class'            => (string) $wpdb->get_var( $wpdb->prepare( "SELECT name FROM " . Schema::table( 'class_levels' ) . " WHERE id = %d", $set['level_id'] ) ),
            'question_text'    => $stem,
            'question_type'    => $question_type,
            'marks'            => $marks,
            'difficulty'       => sanitize_key( (string) ( $data['difficulty'] ?? 'medium' ) ),
            'status'           => 'active',
            'approval_status'  => $can_approve ? 'approved' : 'pending',
            'created_by_staff' => $teacher_id > 0 ? $teacher_id : (int) ( $set['teacher_id'] ?? 0 ),
            'passage_id'       => $passage_id,
            'explanations'     => $explanation ?: null,
            'image_reference'  => $image_ref,
            'instructions'     => wp_kses_post( (string) ( $data['instructions'] ?? '' ) ) ?: null,
            'section_label'    => sanitize_text_field( (string) ( $data['section_label'] ?? '' ) ),
            'source_method'    => $source,
            'section'          => sanitize_text_field( (string) ( $data['section'] ?? '' ) ),
            'sequence'         => $next_seq,
        ];

        $format = [ '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%f', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d' ];

        // Theory questions: save marking guide.
        if ( $question_type === 'theory' ) {
            $insert_data['marking_guide'] = wp_kses_post( (string) ( $data['marking_guide'] ?? '' ) );
            $format[] = '%s';
        }

        $wpdb->insert( $questions, $insert_data, $format );

        $question_id = absint( $wpdb->insert_id );

        if ( $question_id <= 0 ) {
            // wpdb::insert returns false BEFORE touching MySQL when a named column
            // does not exist on the table, so a half-applied migration looks exactly
            // like a validation failure from the browser. Record what actually
            // happened rather than leaving "save failed" as the only evidence.
            if ( function_exists( 'error_log' ) ) {
                error_log(
                    'EduCBT: question insert failed for set ' . $set_id . ' — '
                    . ( $wpdb->last_error !== '' ? $wpdb->last_error : 'wpdb rejected the row (likely a missing column; run the plugin upgrade)' )
                );
            }

            return [ 'success' => false, 'error' => 'insert_failed' ];
        }

        // Objective: save options.
        if ( $question_type === 'single_choice' ) {
            $opts = (array) ( $data['options'] ?? [] );
            if ( count( $opts ) < 2 ) {
                $wpdb->delete( $questions, [ 'id' => $question_id ], [ '%d' ] );
                return [ 'success' => false, 'error' => 'min_two_options' ];
            }

            // Defensive: a fresh question id should own no option rows. If any
            // exist they are orphans from an earlier row that reused the id, and
            // leaving them produces the duplicated A-H lists with two answers
            // marked correct.
            $wpdb->delete( $options, [ 'question_id' => $question_id ], [ '%d' ] );

            $correct_found = false;
            $sort = 0;
            $seen = [];

            foreach ( $opts as $opt ) {
                $text = wp_kses_post( (string) ( $opt['text'] ?? '' ) );
                if ( $text === '' ) {
                    continue;
                }

                // Never write the same option twice into one question.
                $fingerprint = strtolower( trim( wp_strip_all_tags( $text ) ) );
                if ( isset( $seen[ $fingerprint ] ) ) {
                    continue;
                }
                $seen[ $fingerprint ] = true;

                // Exactly one answer key. Anything after the first is stored as
                // incorrect rather than silently creating a second correct answer.
                $is_correct = ! empty( $opt['is_correct'] ) && ! $correct_found;
                if ( $is_correct ) {
                    $correct_found = true;
                }

                $wpdb->insert(
                    $options,
                    [
                        'school_id'    => $school_id,
                        'question_id'  => $question_id,
                        'option_key'   => chr( 65 + $sort ),
                        'option_text'  => $text,
                        'is_correct'   => $is_correct ? 1 : 0,
                        'sort_order'   => $sort,
                    ],
                    [ '%d', '%d', '%s', '%s', '%d', '%d' ]
                );
                $sort++;
            }

            if ( $sort < 2 ) {
                $wpdb->delete( $options, [ 'question_id' => $question_id ], [ '%d' ] );
                $wpdb->delete( $questions, [ 'id' => $question_id ], [ '%d' ] );
                return [ 'success' => false, 'error' => 'min_two_options' ];
            }

            if ( ! $correct_found ) {
                $wpdb->delete( $options, [ 'question_id' => $question_id ], [ '%d' ] );
                $wpdb->delete( $questions, [ 'id' => $question_id ], [ '%d' ] );
                return [ 'success' => false, 'error' => 'no_correct_answer' ];
            }
        }

        // Theory: save sub-questions if provided.
        if ( $question_type === 'theory' && ! empty( $data['sub_items'] ) ) {
            $seq = 0;
            $total_marks = 0;

            // Delete any existing child records (from a previous version).
            $wpdb->delete( $questions, [ 'parent_id' => $question_id ], [ '%d' ] );

            foreach ( (array) $data['sub_items'] as $sub ) {
                $text = wp_kses_post( (string) ( $sub['text'] ?? '' ) );
                if ( $text === '' ) {
                    continue;
                }
                $sub_marks = (float) ( $sub['marks'] ?? 0 );
                $label     = chr( 97 + $seq );

                // Save to question_sub_items (for the editor UI).
                $wpdb->insert(
                    $sub_items,
                    [
                        'school_id'   => $school_id,
                        'question_id' => $question_id,
                        'label'       => $label,
                        'text'        => $text,
                        'marks'       => $sub_marks,
                        'sequence'    => $seq,
                    ],
                    [ '%d', '%d', '%s', '%s', '%f', '%d' ]
                );

                // Also create a child record in educbt_questions so the delivery,
                // marking, and answer-saving systems can use a real question ID.
                $wpdb->insert(
                    $questions,
                    [
                        'question_set_id'  => $set_id,
                        'school_id'        => $school_id,
                        'subject_id'       => $set['subject_id'],
                        'subject'          => (string) ( $insert_data['subject'] ?? '' ),
                        'class'            => (string) ( $insert_data['class'] ?? '' ),
                        'question_text'    => $text,
                        'question_type'    => 'theory',
                        'parent_id'        => $question_id,
                        'part_label'       => $label,
                        'marks'            => $sub_marks,
                        'status'           => 'active',
                        'approval_status'  => $can_approve ? 'approved' : 'pending',
                        'created_by_staff' => $teacher_id > 0 ? $teacher_id : (int) ( $set['teacher_id'] ?? 0 ),
                        'sequence'         => $seq,
                    ]
                );

                $total_marks += $sub_marks;
                $seq++;
            }

            // The main question keeps the marks the teacher entered. Sub-questions
            // carry their own marks independently. Do NOT overwrite the parent
            // with the sub-question total -- that was destroying the main mark.
        }

        return [ 'success' => true, 'id' => $question_id ];
    }

    /**
     * Update a question within a set.
     */
    public function update_question( int $school_id, int $set_id, int $question_id, array $data ): array {
        global $wpdb;

        $set = $this->get_set( $school_id, $set_id );
        if ( ! $set ) {
            return [ 'success' => false, 'error' => 'set_not_found' ];
        }

        if ( ! $this->is_editable( $set['status'] ) ) {
            return [ 'success' => false, 'error' => 'set_locked' ];
        }

        if ( ! $this->verify_assignment( $school_id, $set['teacher_id'], $set['subject_id'], $set['level_id'], $set['department_id'] ) ) {
            return [ 'success' => false, 'error' => 'not_assigned' ];
        }

        $questions    = $wpdb->prefix . 'educbt_questions';
        $options      = Schema::table( 'question_options' );
        $sub_items    = Schema::table( 'question_sub_items' );

        // Verify question belongs to this set.
        $belongs = $wpdb->get_var(
            $wpdb->prepare( "SELECT id FROM {$questions} WHERE id = %d AND question_set_id = %d AND school_id = %d", $question_id, $set_id, $school_id )
        );

        if ( ! $belongs ) {
            return [ 'success' => false, 'error' => 'question_not_found' ];
        }

        $update = [];

        if ( isset( $data['stem'] ) ) {
            $update['question_text'] = wp_kses_post( (string) $data['stem'] );
        }
        if ( isset( $data['marks'] ) ) {
            $update['marks'] = (float) $data['marks'];
        }
        if ( isset( $data['explanation'] ) ) {
            $update['explanations'] = wp_kses_post( (string) $data['explanation'] ) ?: null;
        }
        if ( isset( $data['marking_guide'] ) ) {
            $update['marking_guide'] = wp_kses_post( (string) $data['marking_guide'] ) ?: null;
        }
        if ( isset( $data['difficulty'] ) ) {
            $update['difficulty'] = sanitize_key( (string) $data['difficulty'] );
        }
        if ( isset( $data['passage_id'] ) ) {
            $update['passage_id'] = absint( $data['passage_id'] ) ?: null;
        }
        if ( isset( $data['image_reference'] ) ) {
            $update['image_reference'] = esc_url_raw( (string) $data['image_reference'] );
        }
        if ( isset( $data['instructions'] ) ) {
            $update['instructions'] = wp_kses_post( (string) $data['instructions'] ) ?: null;
        }
        if ( isset( $data['section_label'] ) ) {
            $update['section_label'] = sanitize_text_field( (string) $data['section_label'] );
        }

        if ( ! empty( $update ) ) {
            $wpdb->update( $questions, $update, [ 'id' => $question_id, 'school_id' => $school_id ], null, [ '%d', '%d' ] );
        }

        // Update options if provided (objective).
        if ( $set['exam_type'] === 'objective' && isset( $data['options'] ) ) {
            // Validate BEFORE deleting. add_question refuses a question with no
            // correct option; an edit that dropped the answer key was allowed
            // through, leaving an ungradeable question in an otherwise valid set.
            $incoming = [];
            $seen     = [];
            $marked   = false;

            foreach ( (array) $data['options'] as $opt ) {
                $text = wp_kses_post( (string) ( $opt['text'] ?? '' ) );
                if ( $text === '' ) {
                    continue;
                }

                $fingerprint = strtolower( trim( wp_strip_all_tags( $text ) ) );
                if ( isset( $seen[ $fingerprint ] ) ) {
                    continue;
                }
                $seen[ $fingerprint ] = true;

                $is_correct = ! empty( $opt['is_correct'] ) && ! $marked;
                if ( $is_correct ) {
                    $marked = true;
                }

                $incoming[] = [ 'text' => $text, 'is_correct' => $is_correct ];
            }

            if ( count( $incoming ) < 2 ) {
                return [ 'success' => false, 'error' => 'min_two_options' ];
            }

            $has_correct = false;
            foreach ( $incoming as $opt ) {
                if ( $opt['is_correct'] ) {
                    $has_correct = true;
                    break;
                }
            }

            if ( ! $has_correct ) {
                return [ 'success' => false, 'error' => 'no_correct_answer' ];
            }

            $wpdb->delete( $options, [ 'question_id' => $question_id ], [ '%d' ] );

            $sort = 0;
            foreach ( $incoming as $opt ) {
                $text = $opt['text'];
                $wpdb->insert(
                    $options,
                    [
                        'school_id'    => $school_id,
                        'question_id'  => $question_id,
                        'option_key'   => chr( 65 + $sort ),
                        'option_text'  => $text,
                        'is_correct'   => ! empty( $opt['is_correct'] ) ? 1 : 0,
                        'sort_order'   => $sort,
                    ],
                    [ '%d', '%d', '%s', '%s', '%d', '%d' ]
                );
                $sort++;
            }
        }

        // Update sub-items if provided (theory).
        if ( $set['exam_type'] === 'theory' && isset( $data['sub_items'] ) ) {
            $wpdb->delete( $sub_items, [ 'question_id' => $question_id ], [ '%d' ] );
            $wpdb->delete( $questions, [ 'parent_id' => $question_id ], [ '%d' ] );

            $parent_row = $wpdb->get_row(
                $wpdb->prepare( "SELECT * FROM {$questions} WHERE id = %d", $question_id ),
                ARRAY_A
            );

            $seq = 0;
            $total_marks = 0;
            foreach ( (array) $data['sub_items'] as $sub ) {
                $text = wp_kses_post( (string) ( $sub['text'] ?? '' ) );
                if ( $text === '' ) {
                    continue;
                }
                $sub_marks = (float) ( $sub['marks'] ?? 0 );
                $label     = chr( 97 + $seq );

                $wpdb->insert(
                    $sub_items,
                    [
                        'school_id'   => $school_id,
                        'question_id' => $question_id,
                        'label'       => $label,
                        'text'        => $text,
                        'marks'       => $sub_marks,
                        'sequence'    => $seq,
                    ],
                    [ '%d', '%d', '%s', '%s', '%f', '%d' ]
                );

                // Sync child record in educbt_questions.
                if ( $parent_row ) {
                    $wpdb->insert(
                        $questions,
                        [
                            'question_set_id'  => $set_id,
                            'school_id'        => $school_id,
                            'subject_id'       => $parent_row['subject_id'] ?? null,
                            'subject'          => $parent_row['subject'] ?? '',
                            'class'            => $parent_row['class'] ?? '',
                            'question_text'    => $text,
                            'question_type'    => 'theory',
                            'parent_id'        => $question_id,
                            'part_label'       => $label,
                            'marks'            => $sub_marks,
                            'status'           => $parent_row['status'] ?? 'active',
                            'approval_status'  => $parent_row['approval_status'] ?? 'pending',
                            'created_by_staff' => $parent_row['created_by_staff'] ?? 0,
                            'sequence'         => $seq,
                        ]
                    );
                }

                $total_marks += $sub_marks;
                $seq++;
            }

            // The main question keeps its own marks; sub-questions carry theirs.
            // Do NOT overwrite the parent mark with the sub-question total.
        }

        return [ 'success' => true ];
    }

    /**
     * Delete a question from a set.
     */
    public function delete_question( int $school_id, int $set_id, int $question_id ): array {
        global $wpdb;

        $set = $this->get_set( $school_id, $set_id );
        if ( ! $set ) {
            return [ 'success' => false, 'error' => 'set_not_found' ];
        }

        if ( ! $this->is_editable( $set['status'] ) ) {
            return [ 'success' => false, 'error' => 'set_locked' ];
        }

        if ( ! $this->verify_assignment( $school_id, $set['teacher_id'], $set['subject_id'], $set['level_id'], $set['department_id'] ) ) {
            return [ 'success' => false, 'error' => 'not_assigned' ];
        }

        $questions    = $wpdb->prefix . 'educbt_questions';
        $options      = Schema::table( 'question_options' );
        $sub_items    = Schema::table( 'question_sub_items' );

        $wpdb->delete( $sub_items, [ 'question_id' => $question_id ], [ '%d' ] );
        $wpdb->delete( $options, [ 'question_id' => $question_id ], [ '%d' ] );
        $wpdb->delete( $questions, [ 'id' => $question_id, 'question_set_id' => $set_id, 'school_id' => $school_id ], [ '%d', '%d', '%d' ] );

        return [ 'success' => true ];
    }

    /**
     * Duplicate a question (append to end of set).
     */
    public function duplicate_question( int $school_id, int $set_id, int $question_id ): array {
        global $wpdb;

        $set = $this->get_set( $school_id, $set_id );
        if ( ! $set || ! $this->is_editable( $set['status'] ) ) {
            return [ 'success' => false, 'error' => 'set_locked' ];
        }

        $questions = $wpdb->prefix . 'educbt_questions';
        $options   = Schema::table( 'question_options' );
        $sub_items = Schema::table( 'question_sub_items' );

        $src = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$questions} WHERE id = %d AND question_set_id = %d", $question_id, $set_id ),
            ARRAY_A
        );

        if ( ! $src ) {
            return [ 'success' => false, 'error' => 'question_not_found' ];
        }

        $next_seq = absint(
            $wpdb->get_var(
                $wpdb->prepare( "SELECT COALESCE(MAX(sequence), 0) + 1 FROM {$questions} WHERE question_set_id = %d", $set_id )
            )
        );

        // Clone the question row.
        unset( $src['id'] );
        $src['sequence'] = $next_seq;
        $src['question_text'] .= ' (copy)';

        $wpdb->insert( $questions, $src );

        $new_id = absint( $wpdb->insert_id );

        // Clone options.
        $opts = (array) $wpdb->get_results(
            $wpdb->prepare( "SELECT * FROM {$options} WHERE question_id = %d", $question_id ),
            ARRAY_A
        );

        foreach ( $opts as $opt ) {
            unset( $opt['id'] );
            $opt['question_id'] = $new_id;
            $wpdb->insert( $options, $opt );
        }

        // Clone sub-items.
        $subs = (array) $wpdb->get_results(
            $wpdb->prepare( "SELECT * FROM {$sub_items} WHERE question_id = %d", $question_id ),
            ARRAY_A
        );

        foreach ( $subs as $sub ) {
            unset( $sub['id'] );
            $sub['question_id'] = $new_id;
            $wpdb->insert( $sub_items, $sub );
        }

        return [ 'success' => true, 'id' => $new_id ];
    }

    /**
     * Reorder questions within a set.
     *
     * @param array<int,array{question_id:int,sequence:int}> $order
     */
    public function reorder( int $school_id, int $set_id, array $order ): array {
        global $wpdb;

        $set = $this->get_set( $school_id, $set_id );
        if ( ! $set || ! $this->is_editable( $set['status'] ) ) {
            return [ 'success' => false, 'error' => 'set_locked' ];
        }

        $questions = $wpdb->prefix . 'educbt_questions';

        foreach ( $order as $item ) {
            $wpdb->update(
                $questions,
                [ 'sequence' => absint( $item['sequence'] ) ],
                [ 'id' => absint( $item['question_id'] ), 'question_set_id' => $set_id, 'school_id' => $school_id ],
                [ '%d' ],
                [ '%d', '%d', '%d' ]
            );
        }

        return [ 'success' => true ];
    }

    /**
     * Submit a set for review.
     */
    /**
     * Argument order matches every other method on this class: school, set, actor.
     * It previously read (school, set, teacher) while withdraw_set and delete_set
     * read (school, teacher, set) — a swap the type system cannot catch, since all
     * three are ints. Named consistently here to close that trap.
     */
    public function submit_set( int $school_id, int $set_id, int $teacher_id ): array {
        global $wpdb;

        $questions_table = $wpdb->prefix . 'educbt_questions';

        $set = $this->get_set( $school_id, $set_id );
        if ( ! $set ) {
            return [ 'success' => false, 'error' => 'set_not_found' ];
        }

        if ( $set['status'] !== 'draft' && $set['status'] !== 'returned' ) {
            return [ 'success' => false, 'error' => 'wrong_status' ];
        }

        if ( ! $this->verify_assignment( $school_id, $set['teacher_id'], $set['subject_id'], $set['level_id'], $set['department_id'] ) ) {
            return [ 'success' => false, 'error' => 'not_assigned' ];
        }

        // Written examinations have no questions in the bank — they are paper-based.
        // Submitting the intent is enough: no minimum-question check, no sibling
        // requirement, no objective/theory pairing. The exam office just needs to
        // know this subject will be examined on paper.
        $delivery_mode = (string) ( $set['delivery_mode'] ?? 'cbt' );
        if ( $delivery_mode === 'written' ) {
            $set_id_int = absint( $set['id'] );
            $table      = Schema::table( 'question_sets' );

            // There is nothing to review. A written paper carries no questions in
            // the bank, so a reviewer opening it would see an empty list and could
            // only ever click approve. Holding it at 'submitted' meant a subject
            // silently missed the timetable while everyone assumed it had been
            // handled, so it is approved on submission.
            //
            // The exam office is still told, because the INTENT matters: they need
            // to know this subject will be sat on paper so it gets a slot, an
            // invigilator and a room.
            $wpdb->update(
                $table,
                [
                    'status'       => 'approved',
                    'submitted_at' => current_time( 'mysql' ),
                    'submitted_by' => $teacher_id,
                    'reviewed_at'  => current_time( 'mysql' ),
                    'reviewed_by'  => $teacher_id,
                    'reviewer_comment' => 'Written paper — approved automatically on submission. No questions are held in the bank for paper-based examinations.',
                ],
                [ 'id' => $set_id_int, 'school_id' => $school_id ],
                [ '%s', '%s', '%d', '%s', '%d', '%s' ],
                [ '%d', '%d' ]
            );

            $this->append_revision( $set_id_int, 'approved', $teacher_id );

            // Names for the notice. Resolved here because the shared lookups below
            // sit after the objective/theory path and are never reached from here.
            $subject_name = (string) $wpdb->get_var(
                $wpdb->prepare( 'SELECT name FROM ' . Schema::table( 'subjects_v2' ) . ' WHERE id = %d', $set['subject_id'] )
            );
            $class_name = (string) $wpdb->get_var(
                $wpdb->prepare( 'SELECT name FROM ' . Schema::table( 'class_levels' ) . ' WHERE id = %d', $set['level_id'] )
            );
            $teacher_name = (string) $wpdb->get_var(
                $wpdb->prepare( 'SELECT CONCAT(first_name, " ", last_name) FROM ' . Schema::table( 'staff' ) . ' WHERE id = %d', $teacher_id )
            );

            $notif        = new NotificationService();
            $reviewer_ids = $this->get_reviewer_user_ids( $school_id );

            $title = 'Written paper registered — ' . $subject_name . ' (' . $class_name . ')';
            $body  = $teacher_name . ' will examine ' . $subject_name . ' (' . $class_name . ') on paper rather than in the CBT engine. '
                . 'No questions are held in the bank for it. It needs a slot on the timetable, an invigilator and a room, '
                . 'and the marks are entered directly by the subject teacher afterwards.';

            foreach ( $reviewer_ids as $rid ) {
                $notif->notify(
                    $school_id,
                    $rid,
                    NotificationService::QUESTION_SUBMITTED,
                    $title,
                    $body,
                    home_url( '/portal/exams/approvals/' )
                );
            }

            EventDispatcher::action( 'educbt_question_set_submitted', [
                'school_id'     => $school_id,
                'id'            => $set_id_int,
                'subject_id'    => absint( $set['subject_id'] ),
                'subject_name'  => $subject_name,
                'class_name'    => $class_name,
                'exam_type'     => (string) $set['exam_type'],
                'delivery_mode' => 'written',
                'count'         => 0,
                'teacher_id'    => $teacher_id,
            ] );

            return [ 'success' => true, 'set_id' => $set_id_int, 'auto_approved' => true ];
        }

        // A terminal examination is submitted as one paper: objective AND theory go
        // together. Submitting them separately let a teacher hand in half a paper and
        // left reviewers chasing the other half.
        //
        // CA tests and practice exams are objective-only by design — they do NOT
        // require a theory sibling. The sibling pairing is for terminal exams only
        // (series_id is 0/null for the term's examination).
        $set_series_id = absint( $set['series_id'] ?? 0 );
        if ( $set_series_id > 0 ) {
            // CA test or practice exam — no sibling needed. Check only this set.
            $count = $this->question_count( absint( $set['id'] ) );
            $min   = $this->get_min_required( $school_id, absint( $set['subject_id'] ), absint( $set['level_id'] ), $set['exam_type'] );

            if ( $count < $min ) {
                return [
                    'success'   => false,
                    'error'     => 'below_minimum',
                    'shortfall' => [ [ 'exam_type' => $set['exam_type'], 'count' => $count, 'min' => $min ] ],
                    'count'     => $count,
                    'min'       => $min,
                ];
            }

            $table = Schema::table( 'question_sets' );
            $wpdb->update(
                $table,
                [
                    'status'       => 'submitted',
                    'submitted_at' => current_time( 'mysql' ),
                    'submitted_by' => $teacher_id,
                ],
                [ 'id' => absint( $set['id'] ), 'school_id' => $school_id ],
                [ '%s', '%s', '%d' ],
                [ '%d', '%d' ]
            );

            $this->append_revision( absint( $set['id'] ), 'submitted', $teacher_id );

            return [ 'success' => true, 'set_id' => absint( $set['id'] ) ];
        }

        $sibling_type = $set['exam_type'] === 'objective' ? 'theory' : 'objective';
        $sibling      = $this->find_set(
            $school_id,
            absint( $set['session_id'] ),
            absint( $set['term_id'] ),
            absint( $set['subject_id'] ),
            absint( $set['level_id'] ),
            absint( $set['department_id'] ),
            $sibling_type
        );

        $pending  = [];
        $shortfall = [];

        foreach ( [ $set, $sibling ] as $candidate ) {
            if ( ! $candidate ) {
                // No sibling set at all — the other half has not been started.
                $min = $this->get_min_required( $school_id, absint( $set['subject_id'] ), absint( $set['level_id'] ), $sibling_type );
                if ( $min > 0 ) {
                    $shortfall[] = [ 'exam_type' => $sibling_type, 'count' => 0, 'min' => $min ];
                }
                continue;
            }

            $count = $this->question_count( absint( $candidate['id'] ) );
            $min   = $this->get_min_required( $school_id, absint( $set['subject_id'] ), absint( $set['level_id'] ), $candidate['exam_type'] );

            if ( $count < $min ) {
                $shortfall[] = [ 'exam_type' => $candidate['exam_type'], 'count' => $count, 'min' => $min ];
                continue;
            }

            // Already-submitted siblings are left alone rather than re-submitted.
            if ( in_array( $candidate['status'], [ 'draft', 'returned' ], true ) ) {
                $pending[] = $candidate;
            }
        }

        if ( ! empty( $shortfall ) ) {
            return [
                'success'   => false,
                'error'     => 'below_minimum',
                'shortfall' => $shortfall,
                'count'     => $shortfall[0]['count'],
                'min'       => $shortfall[0]['min'],
            ];
        }

        if ( empty( $pending ) ) {
            return [ 'success' => false, 'error' => 'wrong_status' ];
        }

        $table = Schema::table( 'question_sets' );

        foreach ( $pending as $candidate ) {
            $wpdb->update(
                $table,
                [
                    'status'       => 'submitted',
                    'submitted_at' => current_time( 'mysql' ),
                    'submitted_by' => $teacher_id,
                ],
                [ 'id' => absint( $candidate['id'] ), 'school_id' => $school_id ],
                [ '%s', '%s', '%d' ],
                [ '%d', '%d' ]
            );

            // Move questions to pending so reviewers see them as awaiting review.
            $wpdb->query(
                $wpdb->prepare(
                    "UPDATE {$questions_table} SET approval_status = 'pending'
                     WHERE question_set_id = %d AND school_id = %d AND status = 'active'",
                    absint( $candidate['id'] ),
                    $school_id
                )
            );

            $this->append_revision( absint( $candidate['id'] ), 'submitted', $teacher_id );
        }

        // Notify exam officers and principals that a set was submitted for review.
        $subject_name = (string) $wpdb->get_var(
            $wpdb->prepare( "SELECT name FROM " . Schema::table( 'subjects_v2' ) . " WHERE id = %d", $set['subject_id'] )
        );
        $class_name = (string) $wpdb->get_var(
            $wpdb->prepare( "SELECT name FROM " . Schema::table( 'class_levels' ) . " WHERE id = %d", $set['level_id'] )
        );
        $teacher_name = (string) $wpdb->get_var(
            $wpdb->prepare( "SELECT CONCAT(first_name, ' ', last_name) FROM " . Schema::table( 'staff' ) . " WHERE id = %d", $teacher_id )
        );

        $notif        = new NotificationService();
        $reviewer_ids = $this->get_reviewer_user_ids( $school_id );

        $parts = [];
        foreach ( $pending as $candidate ) {
            $parts[] = $this->question_count( absint( $candidate['id'] ) ) . ' ' . $candidate['exam_type'];
        }

        // The subject line is what the notification list shows, so it has to carry
        // the identifying detail on its own.
        $title = 'Questions submitted — ' . $subject_name . ' (' . $class_name . ')';
        $delivery_label = (string) ( $set['delivery_mode'] ?? 'cbt' ) === 'written' ? ' [Written]' : '';
        $body  = $teacher_name . ' submitted ' . implode( ' and ', $parts ) . ' questions for ' . $subject_name . ' (' . $class_name . ')' . $delivery_label . '.';

        // Deep link so "Review" opens this exact subject in the question bank.
        $link = add_query_arg(
            [
                'subject_id' => absint( $set['subject_id'] ),
                'class_id'   => absint( $set['class_id'] ),
                'exam_type'  => $set['exam_type'],
            ],
            home_url( '/portal/exams/questions/' )
        );

        foreach ( $reviewer_ids as $rid ) {
            $notif->notify( $school_id, $rid, NotificationService::QUESTION_SUBMITTED, $title, $body, $link );
        }

        // Snapshot the moment the work is finished. Questions cannot be recomputed
        // from anything else, so this is the point at which a durable copy is worth
        // taking — see QuestionVaultService.
        $vault = new QuestionVaultService();

        foreach ( $pending as $candidate ) {
            $vault->snapshot( $school_id, absint( $candidate['id'] ) );
        }

        foreach ( $pending as $candidate ) {
            EventDispatcher::action( 'educbt_question_set_submitted', [
                'school_id'     => $school_id,
                'id'            => absint( $candidate['id'] ),
                'subject_id'    => absint( $set['subject_id'] ),
                'subject_name'  => $subject_name,
                'class_name'    => $class_name,
                'exam_type'     => (string) $candidate['exam_type'],
                'delivery_mode' => (string) ( $candidate['delivery_mode'] ?? $set['delivery_mode'] ?? 'cbt' ),
                'count'         => $this->question_count( absint( $candidate['id'] ) ),
                'teacher_id'    => $teacher_id,
            ] );
        }

        return [ 'success' => true ];
    }

    /**
     * Withdraw a submitted set back to draft status.
     * Only works if the set is 'submitted' or 'under_review' (not yet approved/published).
     */
    /**
     * (school, set, actor) — matches submit_set and every add/update/delete method
     * on this class. It used to take (school, teacher, set); since all three are
     * ints, transposing them was silent and would have acted on the wrong row.
     */
    public function withdraw_set( int $school_id, int $set_id, int $teacher_id ): array {
        global $wpdb;

        $set = $this->get_set( $school_id, $set_id );
        if ( ! $set ) {
            return [ 'success' => false, 'error' => 'set_not_found' ];
        }

        if ( ! in_array( $set['status'], [ 'submitted', 'under_review' ], true ) ) {
            return [ 'success' => false, 'error' => 'cannot_withdraw' ];
        }

        if ( ! $this->verify_assignment( $school_id, $set['teacher_id'], $set['subject_id'], $set['level_id'], $set['department_id'] ) ) {
            return [ 'success' => false, 'error' => 'not_assigned' ];
        }

        $table = Schema::table( 'question_sets' );

        $wpdb->update(
            $table,
            [
                'status'       => 'draft',
                'submitted_at' => null,
                'submitted_by' => null,
            ],
            [ 'id' => $set_id, 'school_id' => $school_id ],
            [ '%s', null, null ],
            [ '%d', '%d' ]
        );

        // Also revert any questions in this set that were 'submitted' back to 'draft'.
        $questions = $wpdb->prefix . 'educbt_questions';
        $wpdb->update(
            $questions,
            [ 'approval_status' => 'draft' ],
            [ 'question_set_id' => $set_id, 'school_id' => $school_id ],
            [ '%s' ],
            [ '%d', '%d' ]
        );

        $this->append_revision( $set_id, 'withdrawn', $teacher_id );

        // Notify exam officers and principals.
        $subject_name = (string) $wpdb->get_var(
            $wpdb->prepare( "SELECT name FROM " . Schema::table( 'subjects_v2' ) . " WHERE id = %d", $set['subject_id'] )
        );
        $class_name = (string) $wpdb->get_var(
            $wpdb->prepare( "SELECT name FROM " . Schema::table( 'class_levels' ) . " WHERE id = %d", $set['level_id'] )
        );

        $notif = new NotificationService();
        $reviewer_ids = $this->get_reviewer_user_ids( $school_id );
        $title = 'Submission withdrawn';
        $body = 'A ' . $set['exam_type'] . ' question set for ' . $subject_name . ' (' . $class_name . ') has been withdrawn back to draft.';
        foreach ( $reviewer_ids as $rid ) {
            $notif->notify( $school_id, $rid, NotificationService::QUESTION_WITHDRAWN, $title, $body, '' );
        }

        return [ 'success' => true ];
    }

    /**
     * Delete a draft set and all its questions.
     * Only works if the set is in 'draft' or 'returned' status.
     */
    /** (school, set, actor) — see withdraw_set. */
    public function delete_set( int $school_id, int $set_id, int $teacher_id ): array {
        global $wpdb;

        $set = $this->get_set( $school_id, $set_id );
        if ( ! $set ) {
            return [ 'success' => false, 'error' => 'set_not_found' ];
        }

        if ( ! $this->is_editable( $set['status'] ) ) {
            return [ 'success' => false, 'error' => 'cannot_delete' ];
        }

        if ( ! $this->verify_assignment( $school_id, $set['teacher_id'], $set['subject_id'], $set['level_id'], $set['department_id'] ) ) {
            return [ 'success' => false, 'error' => 'not_assigned' ];
        }

        $questions = $wpdb->prefix . 'educbt_questions';
        $options   = Schema::table( 'question_options' );
        $table     = Schema::table( 'question_sets' );

        // Delete question options for questions in this set.
        $question_ids = (array) $wpdb->get_col(
            $wpdb->prepare(
                "SELECT id FROM {$questions} WHERE question_set_id = %d AND school_id = %d",
                $set_id, $school_id
            )
        );

        if ( ! empty( $question_ids ) ) {
            $id_list = implode( ',', array_map( 'absint', $question_ids ) );
            $wpdb->query( "DELETE FROM {$options} WHERE question_id IN ({$id_list})" );
        }

        // Delete the questions.
        $wpdb->delete(
            $questions,
            [ 'question_set_id' => $set_id, 'school_id' => $school_id ],
            [ '%d', '%d' ]
        );

        // Delete the set itself.
        $wpdb->delete(
            $table,
            [ 'id' => $set_id, 'school_id' => $school_id ],
            [ '%d', '%d' ]
        );

        $this->append_revision( $set_id, 'deleted', $teacher_id );

        // Notify exam officers and principals.
        $subject_name = (string) $wpdb->get_var(
            $wpdb->prepare( "SELECT name FROM " . Schema::table( 'subjects_v2' ) . " WHERE id = %d", $set['subject_id'] )
        );
        $class_name = (string) $wpdb->get_var(
            $wpdb->prepare( "SELECT name FROM " . Schema::table( 'class_levels' ) . " WHERE id = %d", $set['level_id'] )
        );

        $notif = new NotificationService();
        $reviewer_ids = $this->get_reviewer_user_ids( $school_id );
        $title = 'Draft set deleted';
        $body = 'A ' . $set['exam_type'] . ' draft set for ' . $subject_name . ' (' . $class_name . ') has been deleted.';
        foreach ( $reviewer_ids as $rid ) {
            $notif->notify( $school_id, $rid, NotificationService::QUESTION_DELETED, $title, $body, '' );
        }

        return [ 'success' => true ];
    }

    /**
     * Get a set by ID (with school scoping).
     */

    /**
     * Turn WAEC structure on or off for an existing set.
     *
     * Questions already written are left untouched. A teacher switching the mode is
     * changing how the paper is ORGANISED, not discarding the work.
     */
    public function set_waec_mode( int $school_id, int $set_id, bool $on ): bool {
        global $wpdb;

        $updated = $wpdb->update(
            Schema::table( 'question_sets' ),
            [ 'waec_mode' => $on ? 1 : 0 ],
            [ 'id' => $set_id, 'school_id' => $school_id ],
            [ '%d' ],
            [ '%d', '%d' ]
        );

        return $updated !== false;
    }

    public function get_set( int $school_id, int $set_id ): ?array {
        global $wpdb;

        $table = Schema::table( 'question_sets' );

        $set = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d AND school_id = %d", $set_id, $school_id ),
            ARRAY_A
        );

        if ( ! $set ) {
            return null;
        }

        return $this->normalize_set( $set );
    }

    /**
     * Count questions in a set.
     */
    public function question_count( int $set_id ): int {
        global $wpdb;

        $questions = $wpdb->prefix . 'educbt_questions';

        return absint(
            $wpdb->get_var(
                $wpdb->prepare( "SELECT COUNT(*) FROM {$questions} WHERE question_set_id = %d AND (parent_id IS NULL OR parent_id = 0)", $set_id )
            )
        );
    }

    /**
     * Total marks across all questions in a set.
     */
    public function total_marks( int $set_id ): float {
        global $wpdb;

        $questions = $wpdb->prefix . 'educbt_questions';

        return (float) $wpdb->get_var(
            $wpdb->prepare( "SELECT COALESCE(SUM(marks), 0) FROM {$questions} WHERE question_set_id = %d AND (parent_id IS NULL OR parent_id = 0)", $set_id )
        );
    }

    /**
     * Get the sibling set (the other exam_type for the same subject+class).
     */
    public function get_sibling_set( int $school_id, int $session_id, int $term_id, int $subject_id, int $level_id, int $department_id, string $current_exam_type ): ?array {
        $sibling_type = $current_exam_type === 'objective' ? 'theory' : 'objective';

        global $wpdb;

        $table = Schema::table( 'question_sets' );

        $set = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, status, exam_type, min_required FROM {$table}
                 WHERE school_id = %d AND session_id = %d AND term_id = %d
                   AND subject_id = %d AND level_id = %d AND department_id = %d AND exam_type = %s
                 LIMIT 1",
                $school_id, $session_id, $term_id, $subject_id, $level_id, $department_id, $sibling_type
            ),
            ARRAY_A
        );

        return $set ?: null;
    }

    /**
     * Get the configured minimum for a subject+class+exam_type.
     * Falls back to the school's minimum_questions_per_subject.
     */
    public function get_min_required( int $school_id, int $subject_id, int $level_id, string $exam_type ): int {
        // The quota the school actually configures is per exam type and lives in
        // QuestionApprovalService. This used to read minimum_questions_per_subject
        // instead and applied one number to BOTH types, so a school that set 4
        // theory questions was still refused submission until it had 20.
        $quotas = ( new QuestionApprovalService() )->quotas( $school_id );

        $min = $exam_type === 'theory'
            ? absint( $quotas['theory'] ?? 4 )
            : absint( $quotas['objective'] ?? 20 );

        return max( 1, $min );
    }

    /**
     * Check if a set status allows editing.
     */
    private function is_editable( string $status ): bool {
        return in_array( $status, [ 'draft', 'returned' ], true );
    }

    /**
     * Verify that a teacher is assigned to the subject+class pair.
     */
    private function verify_assignment( int $school_id, int $teacher_id, int $subject_id, int $level_id, int $department_id = 0 ): bool {
        global $wpdb;

        $assign = Schema::table( 'staff_assignments' );

        // School-wide users (exam officers, principals) can always write.
        $scope = new Scope();
        if ( $scope->is_school_wide() ) {
            return true;
        }

        // Always check the CURRENTLY LOGGED-IN actor's assignment, not whoever
        // originally created the set. If a subject is reassigned from Teacher A
        // to Teacher B, Teacher B must be able to pick up the existing set — the
        // set's stored teacher_id is provenance, not an access-control field.
        $actor_id = (int) $scope->actor()['id'];

        // Assignments are still per arm, but a set now covers the whole level. A
        // teacher who takes the subject for ANY arm of that level may author it —
        // requiring an assignment to every arm would lock out the common case
        // where JS1 A and JS1 B have different teachers for the same subject.
        $classes = $this->classes_in_scope( $school_id, $level_id, $department_id );

        if ( empty( $classes ) ) {
            return false;
        }

        $placeholders = implode( ',', array_fill( 0, count( $classes ), '%d' ) );

        $found = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$assign}
                 WHERE school_id = %d AND staff_id = %d AND subject_id = %d
                   AND class_id IN ({$placeholders})
                   AND status = 'active' LIMIT 1",
                array_merge( [ $school_id, $actor_id, $subject_id ], $classes )
            )
        );

        return ! empty( $found );
    }

    /**
     * Append a revision history entry.
     */
    private function append_revision( int $set_id, string $action, int $actor_id ): void {
        global $wpdb;

        $table = Schema::table( 'question_sets' );

        $history = $wpdb->get_var(
            $wpdb->prepare( "SELECT revision_history FROM {$table} WHERE id = %d", $set_id )
        );

        $entries = $history ? json_decode( $history, true ) : [];
        if ( ! is_array( $entries ) ) {
            $entries = [];
        }

        $entries[] = [
            'action'    => $action,
            'actor_id'  => $actor_id,
            'timestamp' => current_time( 'mysql' ),
        ];

        $wpdb->update(
            $table,
            [ 'revision_history' => wp_json_encode( $entries ) ],
            [ 'id' => $set_id ],
            [ '%s' ],
            [ '%d' ]
        );
    }

    /**
     * Normalize a raw DB row into a typed array.
     */

    /**
     * Get wp_user_id list for all school-wide staff (exam officers, principals, VP).
     * These are the people who need to know when a teacher submits/withdraws/deletes.
     *
     * @return array<int,int>
     */
    private function get_reviewer_user_ids( int $school_id ): array {
        global $wpdb;
        $staff_table = Schema::table( 'staff' );
        $reviewer_roles = Capabilities::school_wide_roles();

        $holder = implode( ',', array_fill( 0, count( $reviewer_roles ), '%s' ) );
        $params = array_merge( [ $school_id ], $reviewer_roles );

        $rows = (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT wp_user_id FROM {$staff_table}
                 WHERE school_id = %d AND wp_user_id IS NOT NULL AND status = 'active'
                   AND role_slug IN ($holder)",
                $params
            ),
            ARRAY_A
        );

        $ids = [];
        foreach ( $rows as $r ) {
            $uid = absint( $r['wp_user_id'] ?? 0 );
            if ( $uid > 0 ) $ids[] = $uid;
        }
        return $ids;
    }

    private function normalize_set( array $row ): array {
        return [
            'id'              => absint( $row['id'] ),
            'school_id'       => absint( $row['school_id'] ),
            'session_id'      => absint( $row['session_id'] ),
            'term_id'         => absint( $row['term_id'] ?? 0 ),
            'subject_id'      => absint( $row['subject_id'] ),
            'level_id'        => absint( $row['level_id'] ?? 0 ),
            'department_id'   => absint( $row['department_id'] ?? 0 ),
            'class_id'        => absint( $row['class_id'] ?? 0 ),
            'exam_type'       => (string) $row['exam_type'],
            'delivery_mode'   => (string) ( $row['delivery_mode'] ?? 'cbt' ),
            'waec_mode'       => (int) ( $row['waec_mode'] ?? 0 ),
            'series_id'       => absint( $row['series_id'] ?? 0 ),
            'teacher_id'      => absint( $row['teacher_id'] ),
            'default_marks'   => (float) $row['default_marks'],
            'status'          => (string) $row['status'],
            'min_required'    => absint( $row['min_required'] ?? 0 ),
            'submitted_at'    => (string) ( $row['submitted_at'] ?? '' ),
            'submitted_by'    => absint( $row['submitted_by'] ?? 0 ),
            'reviewed_at'     => (string) ( $row['reviewed_at'] ?? '' ),
            'reviewed_by'     => absint( $row['reviewed_by'] ?? 0 ),
            'reviewer_comment' => (string) ( $row['reviewer_comment'] ?? '' ),
        ];
    }

    // ---------------------------------------------------------------
    // Passages — shared stimulus (comprehension, cloze, instructions)
    // ---------------------------------------------------------------

    /**
     * List passages for a school, optionally filtered by subject/level.
     *
     * @return array<int,array<string,mixed>>
     */
    public function list_passages( int $school_id, int $subject_id = 0, string $class_level = '' ): array {
        global $wpdb;

        $table = Schema::table( 'passages' );
        $where = $wpdb->prepare( "school_id = %d AND status = 'active'", $school_id );

        if ( $subject_id > 0 ) {
            $where .= $wpdb->prepare( " AND (subject_id = %d OR subject_id IS NULL)", $subject_id );
        }

        if ( $class_level !== '' ) {
            $where .= $wpdb->prepare( " AND (class_level = %s OR class_level = '')", $class_level );
        }

        $sql = "SELECT id, title, passage_type, body, image, class_level, subject_id FROM {$table} WHERE {$where} ORDER BY id DESC LIMIT 100";

        return (array) $wpdb->get_results( $sql, ARRAY_A );
    }

    /**
     * Create a passage.
     *
     * @return array{success:bool,id?:int,error?:string}
     */
    public function create_passage( int $school_id, array $data, int $staff_id = 0 ): array {
        global $wpdb;

        $table = Schema::table( 'passages' );

        $title        = sanitize_text_field( (string) ( $data['title'] ?? '' ) );
        $passage_type = sanitize_key( (string) ( $data['passage_type'] ?? 'comprehension' ) );
        $body         = wp_kses_post( (string) ( $data['body'] ?? '' ) );
        $image        = esc_url_raw( (string) ( $data['image'] ?? '' ) );
        $subject_id   = absint( $data['subject_id'] ?? 0 ) ?: null;
        $class_level  = sanitize_text_field( (string) ( $data['class_level'] ?? '' ) );

        if ( $title === '' ) {
            return [ 'success' => false, 'error' => 'title_required' ];
        }

        if ( $body === '' && $image === '' ) {
            return [ 'success' => false, 'error' => 'body_required' ];
        }

        $wpdb->insert(
            $table,
            [
                'school_id'       => $school_id,
                'subject_id'      => $subject_id,
                'class_level'     => $class_level,
                'title'           => $title,
                'passage_type'    => $passage_type,
                'body'            => $body ?: null,
                'image'           => $image,
                'author_staff_id' => $staff_id ?: null,
                'status'          => 'active',
            ],
            [ '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s' ]
        );

        $id = absint( $wpdb->insert_id );

        if ( $id <= 0 ) {
            return [ 'success' => false, 'error' => 'insert_failed' ];
        }

        return [ 'success' => true, 'id' => $id ];
    }

    /**
     * Get a single passage by ID.
     *
     * @return array<string,mixed>|null
     */
    public function get_passage( int $school_id, int $passage_id ): ?array {
        global $wpdb;

        $table = Schema::table( 'passages' );

        $row = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d AND school_id = %d", $passage_id, $school_id ),
            ARRAY_A
        );

        return $row ?: null;
    }

    /**
     * Delete a passage (soft delete — sets status to 'archived').
     */
    public function delete_passage( int $school_id, int $passage_id ): bool {
        global $wpdb;

        $table = Schema::table( 'passages' );

        $updated = $wpdb->update(
            $table,
            [ 'status' => 'archived' ],
            [ 'id' => $passage_id, 'school_id' => $school_id ],
            [ '%s' ],
            [ '%d', '%d' ]
        );

        return $updated !== false;
    }
}
