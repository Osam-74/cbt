<?php

namespace EduCBTPro\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * PHASE 1 — data migration from v1 to v2.
 *
 * The v1 schema stored academic relationships as free text: `students.class`
 * ("JSS 1A", "jss1 a", "JSS1-A"), `students.session_year` ("2024/2025"),
 * `questions.subject` ("Mathematics"). The backfill's job is to turn those strings
 * into rows with IDs, without losing anything it cannot confidently interpret.
 *
 * Three rules govern this class:
 *
 *  1. IDEMPOTENT. Every step checks `migration_map` before inserting, so a rerun
 *     after a timeout resumes rather than duplicating.
 *  2. NON-DESTRUCTIVE. No legacy table is dropped, truncated or altered. If the
 *     backfill produces a wrong result, v1 data is still intact underneath.
 *  3. HONEST ABOUT AMBIGUITY. Anything it cannot map with confidence is recorded in
 *     the report as `unresolved` rather than guessed at. A silent wrong guess in a
 *     student's academic history is worse than a row a human has to look at.
 */
class Backfill {

    /** @var array<string,array<string,int>> in-memory cache of legacy_key => new_id */
    private array $map_cache = [];

    /** @var array<string,mixed> */
    private array $report = [];

    public function run( int $school_id ): array {
        $this->report = [
            'school_id'   => $school_id,
            'sessions'    => 0,
            'terms'       => 0,
            'classes'     => 0,
            'subjects'    => 0,
            'staff'       => 0,
            'enrollments' => 0,
            'guardians'   => 0,
            'options'     => 0,
            'unresolved'  => [],
        ];

        if ( $school_id <= 0 ) {
            return $this->report;
        }

        Seeder::seed_school( $school_id );

        $this->backfill_sessions( $school_id );
        $this->backfill_classes( $school_id );
        $this->backfill_subjects( $school_id );
        $this->backfill_staff( $school_id );
        $this->backfill_enrollments( $school_id );
        $this->backfill_guardians( $school_id );
        $this->backfill_question_options( $school_id );

        EventDispatcher::action( 'educbt_backfill_completed', $this->report );

        return $this->report;
    }

    // ===============================================================
    // Sessions and terms
    // ===============================================================

    /**
     * Legacy `session_year` strings appear on students, results and timetables.
     * Collect the distinct set and create a real session (plus three terms) for each.
     */
    private function backfill_sessions( int $school_id ): void {
        global $wpdb;

        $sources = [
            [ $wpdb->prefix . 'educbt_students', 'session_year' ],
            [ $wpdb->prefix . 'educbt_results', 'session_year' ],
            [ $wpdb->prefix . 'educbt_exam_timetables', 'session_year' ],
        ];

        $labels = [];

        foreach ( $sources as [ $table, $column ] ) {
            if ( ! $this->table_exists( $table ) ) {
                continue;
            }

            $values = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT DISTINCT {$column} FROM {$table} WHERE school_id = %d AND {$column} <> ''",
                    $school_id
                )
            );

            foreach ( (array) $values as $value ) {
                $normalised = $this->normalise_session( (string) $value );
                if ( $normalised !== '' ) {
                    $labels[ $normalised ] = true;
                }
            }
        }

        if ( empty( $labels ) ) {
            $labels[ $this->current_session_label() ] = true;
        }

        $labels = array_keys( $labels );
        sort( $labels );

        foreach ( $labels as $index => $label ) {
            $session_id = $this->get_mapped( $school_id, 'session', $label );

            if ( $session_id === 0 ) {
                $wpdb->insert(
                    Schema::table( 'academic_sessions' ),
                    [
                        'school_id'  => $school_id,
                        'title'      => $label,
                        'is_current' => ( $index === count( $labels ) - 1 ) ? 1 : 0,
                        'status'     => 'active',
                    ],
                    [ '%d', '%s', '%d', '%s' ]
                );

                $session_id = absint( $wpdb->insert_id );
                $this->set_mapped( $school_id, 'session', $label, $session_id );
                $this->report['sessions']++;
            }

            $this->backfill_terms( $school_id, $session_id, $label );
        }
    }

    private function backfill_terms( int $school_id, int $session_id, string $session_label ): void {
        global $wpdb;

        $terms = [ 1 => 'First Term', 2 => 'Second Term', 3 => 'Third Term' ];

        foreach ( $terms as $order => $title ) {
            $key = $session_label . '|' . $order;

            if ( $this->get_mapped( $school_id, 'term', $key ) > 0 ) {
                continue;
            }

            $wpdb->insert(
                Schema::table( 'terms' ),
                [
                    'school_id'  => $school_id,
                    'session_id' => $session_id,
                    'title'      => $title,
                    'term_order' => $order,
                    'is_current' => $order === 1 ? 1 : 0,
                ],
                [ '%d', '%d', '%s', '%d', '%d' ]
            );

            $this->set_mapped( $school_id, 'term', $key, absint( $wpdb->insert_id ) );
            $this->report['terms']++;
        }
    }

    // ===============================================================
    // Classes
    // ===============================================================

    /**
     * Legacy class strings are messy: "JSS 1A", "jss1a", "JSS1 - A", "SS2 Science B".
     * Parse into (level, arm, department) and create the real class row.
     */
    private function backfill_classes( int $school_id ): void {
        global $wpdb;

        $legacy = $wpdb->prefix . 'educbt_classes';
        $rows   = [];

        if ( $this->table_exists( $legacy ) ) {
            $rows = (array) $wpdb->get_results(
                $wpdb->prepare( "SELECT class_name, arm, class_level FROM {$legacy} WHERE school_id = %d", $school_id ),
                ARRAY_A
            );
        }

        // Students may reference classes the classes table never had.
        $students = $wpdb->prefix . 'educbt_students';
        if ( $this->table_exists( $students ) ) {
            $extra = (array) $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT DISTINCT class AS class_name, arm, department FROM {$students} WHERE school_id = %d AND class <> ''",
                    $school_id
                ),
                ARRAY_A
            );
            $rows = array_merge( $rows, $extra );
        }

        foreach ( $rows as $row ) {
            $raw = trim( (string) ( $row['class_name'] ?? '' ) );
            if ( $raw === '' ) {
                continue;
            }

            $arm        = strtoupper( trim( (string) ( $row['arm'] ?? '' ) ) );
            $department = trim( (string) ( $row['department'] ?? '' ) );

            $parsed = $this->parse_class_string( $raw );
            if ( $parsed['level_code'] === '' ) {
                $this->report['unresolved'][] = [ 'type' => 'class', 'value' => $raw, 'reason' => 'level_not_recognised' ];
                continue;
            }

            if ( $arm === '' ) {
                $arm = $parsed['arm'];
            }
            if ( $department === '' ) {
                $department = $parsed['department'];
            }

            $level_id = $this->level_id_by_code( $school_id, $parsed['level_code'] );
            if ( $level_id === 0 ) {
                $this->report['unresolved'][] = [ 'type' => 'class', 'value' => $raw, 'reason' => 'level_missing' ];
                continue;
            }

            $department_id = $department !== '' ? $this->department_id_by_name( $school_id, $department ) : null;

            $display = trim( $parsed['level_code'] . ( $arm !== '' ? ' ' . $arm : '' ) );
            $key     = strtolower( $parsed['level_code'] . '|' . $arm . '|' . (string) $department_id );

            if ( $this->get_mapped( $school_id, 'class', $key ) > 0 ) {
                continue;
            }

            $wpdb->insert(
                Schema::table( 'classes' ),
                [
                    'school_id'     => $school_id,
                    'level_id'      => $level_id,
                    'department_id' => $department_id,
                    'arm'           => $arm,
                    'display_name'  => $display,
                    'status'        => 'active',
                ],
                [ '%d', '%d', '%d', '%s', '%s', '%s' ]
            );

            $class_id = absint( $wpdb->insert_id );
            if ( $class_id > 0 ) {
                $this->set_mapped( $school_id, 'class', $key, $class_id );
                // Also map the raw string so enrollment lookup can find it.
                $this->set_mapped( $school_id, 'class_raw', strtolower( $raw . '|' . $arm ), $class_id );
                $this->report['classes']++;
            }
        }
    }

    /**
     * "JSS 1A" / "jss1a" / "SS2 Science B" -> level code, arm, department.
     */
    public function parse_class_string( string $raw ): array {
        $normalised = strtoupper( preg_replace( '/[^A-Z0-9]/i', ' ', $raw ) ?? '' );
        $normalised = trim( preg_replace( '/\s+/', ' ', $normalised ) ?? '' );

        $level_code = '';
        $arm        = '';
        $department = '';

        if ( preg_match( '/\b(JSS|JS|SSS|SS)\s*([1-3])\b/', $normalised, $m ) ) {
            $prefix     = ( $m[1] === 'JSS' || $m[1] === 'JS' ) ? 'JSS' : 'SS';
            $level_code = $prefix . $m[2];
        } elseif ( preg_match( '/^(JSS|JS|SSS|SS)([1-3])/', str_replace( ' ', '', $normalised ), $m ) ) {
            $prefix     = ( $m[1] === 'JSS' || $m[1] === 'JS' ) ? 'JSS' : 'SS';
            $level_code = $prefix . $m[2];
        }

        foreach ( [ 'SCIENCE' => 'Science', 'COMMERCIAL' => 'Commercial', 'ART' => 'Arts', 'ARTS' => 'Arts', 'HUMANITIES' => 'Arts' ] as $needle => $label ) {
            if ( strpos( $normalised, $needle ) !== false ) {
                $department = $label;
                break;
            }
        }

        // Trailing single letter is the arm, unless it is part of the level token.
        $tail = preg_replace( '/^(JSS|JS|SSS|SS)\s*[1-3]\s*/', '', $normalised ) ?? '';
        $tail = trim( str_replace( [ 'SCIENCE', 'COMMERCIAL', 'ARTS', 'ART', 'HUMANITIES' ], '', $tail ) );

        if ( preg_match( '/\b([A-Z])\b/', $tail, $m ) ) {
            $arm = $m[1];
        }

        return [ 'level_code' => $level_code, 'arm' => $arm, 'department' => $department ];
    }

    // ===============================================================
    // Subjects, staff, enrollments, guardians, options
    // ===============================================================

    private function backfill_subjects( int $school_id ): void {
        global $wpdb;

        $legacy = $wpdb->prefix . 'educbt_subjects';
        if ( ! $this->table_exists( $legacy ) ) {
            return;
        }

        $rows = (array) $wpdb->get_results(
            $wpdb->prepare( "SELECT id, subject_name, subject_code, subject_type FROM {$legacy} WHERE school_id = %d", $school_id ),
            ARRAY_A
        );

        $target = Schema::table( 'subjects_v2' );

        foreach ( $rows as $row ) {
            $name = trim( (string) ( $row['subject_name'] ?? '' ) );
            if ( $name === '' ) {
                continue;
            }

            // Prefer linking to the seeded subject of the same name over duplicating it.
            $existing = $wpdb->get_var(
                $wpdb->prepare( "SELECT id FROM {$target} WHERE school_id = %d AND name = %s LIMIT 1", $school_id, $name )
            );

            if ( $existing ) {
                $wpdb->update( $target, [ 'legacy_subject_id' => absint( $row['id'] ) ], [ 'id' => absint( $existing ) ], [ '%d' ], [ '%d' ] );
                $this->set_mapped( $school_id, 'subject', strtolower( $name ), absint( $existing ) );
                continue;
            }

            $code = trim( (string) ( $row['subject_code'] ?? '' ) );
            if ( $code === '' ) {
                $code = strtoupper( substr( preg_replace( '/[^A-Za-z]/', '', $name ) ?? 'SUB', 0, 6 ) );
            }
            $code = $this->unique_subject_code( $school_id, $code );

            $wpdb->insert(
                $target,
                [
                    'school_id'         => $school_id,
                    'name'              => $name,
                    'code'              => $code,
                    'stage'             => 'both',
                    'category'          => (string) ( $row['subject_type'] ?? 'elective' ),
                    'legacy_subject_id' => absint( $row['id'] ),
                ],
                [ '%d', '%s', '%s', '%s', '%s', '%d' ]
            );

            $this->set_mapped( $school_id, 'subject', strtolower( $name ), absint( $wpdb->insert_id ) );
            $this->report['subjects']++;
        }
    }

    private function backfill_staff( int $school_id ): void {
        global $wpdb;

        $legacy = $wpdb->prefix . 'educbt_teachers';
        if ( ! $this->table_exists( $legacy ) ) {
            return;
        }

        $rows = (array) $wpdb->get_results(
            $wpdb->prepare( "SELECT * FROM {$legacy} WHERE school_id = %d", $school_id ),
            ARRAY_A
        );

        foreach ( $rows as $row ) {
            $legacy_id = absint( $row['id'] ?? 0 );
            if ( $legacy_id <= 0 || $this->get_mapped( $school_id, 'staff', (string) $legacy_id ) > 0 ) {
                continue;
            }

            $full  = trim( (string) ( $row['full_name'] ?? '' ) );
            $parts = preg_split( '/\s+/', $full ) ?: [];
            $first = (string) array_shift( $parts );
            $last  = trim( implode( ' ', $parts ) );

            $contact = json_decode( (string) ( $row['contact_details'] ?? '{}' ), true );
            $contact = is_array( $contact ) ? $contact : [];

            $staff_number = trim( (string) ( $row['teacher_id'] ?? '' ) );
            if ( $staff_number === '' ) {
                $staff_number = 'STF' . str_pad( (string) $legacy_id, 4, '0', STR_PAD_LEFT );
            }

            $wpdb->insert(
                Schema::table( 'staff' ),
                [
                    'school_id'         => $school_id,
                    'staff_number'      => $staff_number,
                    'first_name'        => $first,
                    'last_name'         => $last,
                    'email'             => (string) ( $contact['email'] ?? '' ),
                    'phone'             => (string) ( $contact['phone'] ?? '' ),
                    'role_slug'         => 'educbt_teacher',
                    'status'            => 'active',
                    'legacy_teacher_id' => $legacy_id,
                ],
                [ '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d' ]
            );

            $this->set_mapped( $school_id, 'staff', (string) $legacy_id, absint( $wpdb->insert_id ) );
            $this->report['staff']++;
        }
    }

    /**
     * The heart of the migration: `students.class` becomes an enrollment row.
     */
    private function backfill_enrollments( int $school_id ): void {
        global $wpdb;

        $students = $wpdb->prefix . 'educbt_students';
        if ( ! $this->table_exists( $students ) ) {
            return;
        }

        $rows = (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, class, arm, department, session_year FROM {$students} WHERE school_id = %d",
                $school_id
            ),
            ARRAY_A
        );

        $default_session = $this->current_session_id( $school_id );

        foreach ( $rows as $row ) {
            $student_id = absint( $row['id'] ?? 0 );
            $class_raw  = trim( (string) ( $row['class'] ?? '' ) );

            if ( $student_id <= 0 ) {
                continue;
            }

            if ( $class_raw === '' ) {
                $this->report['unresolved'][] = [ 'type' => 'enrollment', 'student_id' => $student_id, 'reason' => 'no_class_on_record' ];
                continue;
            }

            $arm      = strtoupper( trim( (string) ( $row['arm'] ?? '' ) ) );
            $class_id = $this->get_mapped( $school_id, 'class_raw', strtolower( $class_raw . '|' . $arm ) );

            if ( $class_id === 0 ) {
                $parsed = $this->parse_class_string( $class_raw );
                if ( $parsed['level_code'] !== '' ) {
                    $dept_id  = $parsed['department'] !== '' ? $this->department_id_by_name( $school_id, $parsed['department'] ) : null;
                    $key      = strtolower( $parsed['level_code'] . '|' . ( $arm !== '' ? $arm : $parsed['arm'] ) . '|' . (string) $dept_id );
                    $class_id = $this->get_mapped( $school_id, 'class', $key );
                }
            }

            if ( $class_id === 0 ) {
                $this->report['unresolved'][] = [ 'type' => 'enrollment', 'student_id' => $student_id, 'value' => $class_raw, 'reason' => 'class_unmatched' ];
                continue;
            }

            $session_label = $this->normalise_session( (string) ( $row['session_year'] ?? '' ) );
            $session_id    = $session_label !== '' ? $this->get_mapped( $school_id, 'session', $session_label ) : 0;
            if ( $session_id === 0 ) {
                $session_id = $default_session;
            }

            if ( $session_id === 0 ) {
                $this->report['unresolved'][] = [ 'type' => 'enrollment', 'student_id' => $student_id, 'reason' => 'no_session' ];
                continue;
            }

            $exists = $wpdb->get_var(
                $wpdb->prepare(
                    'SELECT id FROM ' . Schema::table( 'enrollments' ) . ' WHERE student_id = %d AND session_id = %d',
                    $student_id,
                    $session_id
                )
            );

            if ( $exists ) {
                continue;
            }

            $department_name = trim( (string) ( $row['department'] ?? '' ) );

            $wpdb->insert(
                Schema::table( 'enrollments' ),
                [
                    'school_id'     => $school_id,
                    'student_id'    => $student_id,
                    'class_id'      => $class_id,
                    'session_id'    => $session_id,
                    'department_id' => $department_name !== '' ? $this->department_id_by_name( $school_id, $department_name ) : null,
                    'status'        => 'active',
                ],
                [ '%d', '%d', '%d', '%d', '%d', '%s' ]
            );

            $this->report['enrollments']++;
        }
    }

    /**
     * v1 kept parent contact as text on the student row, so siblings produced
     * duplicate parents and no parent could ever log in. Deduplicate on email,
     * falling back to phone, and build the many-to-many link.
     */
    private function backfill_guardians( int $school_id ): void {
        global $wpdb;

        $students = $wpdb->prefix . 'educbt_students';
        if ( ! $this->table_exists( $students ) ) {
            return;
        }

        $rows = (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, parent_information, parent_phone, parent_email FROM {$students} WHERE school_id = %d",
                $school_id
            ),
            ARRAY_A
        );

        foreach ( $rows as $row ) {
            $student_id = absint( $row['id'] ?? 0 );
            $email      = trim( (string) ( $row['parent_email'] ?? '' ) );
            $phone      = trim( (string) ( $row['parent_phone'] ?? '' ) );

            $info = json_decode( (string) ( $row['parent_information'] ?? '{}' ), true );
            $info = is_array( $info ) ? $info : [];
            $name = trim( (string) ( $info['name'] ?? $info['parent_name'] ?? '' ) );

            if ( $email === '' && $phone === '' && $name === '' ) {
                continue;
            }

            $dedupe_key = $email !== '' ? 'email:' . strtolower( $email ) : 'phone:' . $phone;
            $guardian_id = $this->get_mapped( $school_id, 'guardian', $dedupe_key );

            if ( $guardian_id === 0 ) {
                $parts = preg_split( '/\s+/', $name ) ?: [];
                $first = (string) array_shift( $parts );
                $last  = trim( implode( ' ', $parts ) );

                $wpdb->insert(
                    Schema::table( 'guardians' ),
                    [
                        'school_id'     => $school_id,
                        'first_name'    => $first,
                        'last_name'     => $last,
                        'email'         => $email,
                        'phone'         => $phone,
                        'invite_status' => 'pending',
                        'status'        => 'active',
                    ],
                    [ '%d', '%s', '%s', '%s', '%s', '%s', '%s' ]
                );

                $guardian_id = absint( $wpdb->insert_id );
                $this->set_mapped( $school_id, 'guardian', $dedupe_key, $guardian_id );
                $this->report['guardians']++;
            }

            if ( $guardian_id > 0 && $student_id > 0 ) {
                $wpdb->query(
                    $wpdb->prepare(
                        'INSERT IGNORE INTO ' . Schema::table( 'guardian_student' ) .
                        ' (school_id, guardian_id, student_id, relationship, is_primary) VALUES (%d, %d, %d, %s, %d)',
                        $school_id,
                        $guardian_id,
                        $student_id,
                        'parent',
                        1
                    )
                );
            }
        }
    }

    /**
     * Explode the questions.options / questions.answers JSON into real option rows
     * so the correct answer can be checked with a join instead of a decode.
     */
    private function backfill_question_options( int $school_id ): void {
        global $wpdb;

        $questions = $wpdb->prefix . 'educbt_questions';
        if ( ! $this->table_exists( $questions ) ) {
            return;
        }

        $rows = (array) $wpdb->get_results(
            $wpdb->prepare( "SELECT id, options, answers FROM {$questions} WHERE school_id = %d", $school_id ),
            ARRAY_A
        );

        $target = Schema::table( 'question_options' );

        foreach ( $rows as $row ) {
            $question_id = absint( $row['id'] ?? 0 );
            if ( $question_id <= 0 ) {
                continue;
            }

            $already = $wpdb->get_var(
                $wpdb->prepare( "SELECT COUNT(*) FROM {$target} WHERE question_id = %d", $question_id )
            );

            if ( absint( $already ) > 0 ) {
                continue;
            }

            $options = json_decode( (string) ( $row['options'] ?? '[]' ), true );
            $answers = json_decode( (string) ( $row['answers'] ?? '[]' ), true );

            if ( ! is_array( $options ) || empty( $options ) ) {
                $this->report['unresolved'][] = [ 'type' => 'question', 'question_id' => $question_id, 'reason' => 'no_options' ];
                continue;
            }

            $answers = is_array( $answers ) ? array_map( 'strval', $answers ) : [];
            $keys    = range( 'A', 'Z' );
            $order   = 0;
            $has_correct = false;

            foreach ( array_values( $options ) as $index => $option ) {
                $text = is_array( $option ) ? (string) ( $option['text'] ?? '' ) : (string) $option;
                $key  = $keys[ $index ] ?? (string) ( $index + 1 );

                $is_correct = in_array( $text, $answers, true )
                    || in_array( $key, $answers, true )
                    || in_array( (string) $index, $answers, true );

                if ( $is_correct ) {
                    $has_correct = true;
                }

                $wpdb->insert(
                    $target,
                    [
                        'school_id'   => $school_id,
                        'question_id' => $question_id,
                        'option_key'  => $key,
                        'option_text' => $text,
                        'is_correct'  => $is_correct ? 1 : 0,
                        'sort_order'  => $order++,
                    ],
                    [ '%d', '%d', '%s', '%s', '%d', '%d' ]
                );

                $this->report['options']++;
            }

            // A question with no identifiable correct answer would silently mark every
            // student wrong. Surface it instead.
            if ( ! $has_correct ) {
                $this->report['unresolved'][] = [ 'type' => 'question', 'question_id' => $question_id, 'reason' => 'no_correct_answer_identified' ];
            }
        }
    }

    // ===============================================================
    // Helpers
    // ===============================================================

    private function normalise_session( string $raw ): string {
        $raw = trim( $raw );
        if ( $raw === '' ) {
            return '';
        }

        if ( preg_match( '/(\d{4})\s*[\/\-]\s*(\d{2,4})/', $raw, $m ) ) {
            $start = (int) $m[1];
            $end   = strlen( $m[2] ) === 2 ? (int) ( substr( (string) $start, 0, 2 ) . $m[2] ) : (int) $m[2];
            return $start . '/' . $end;
        }

        if ( preg_match( '/^(\d{4})$/', $raw, $m ) ) {
            return $m[1] . '/' . ( (int) $m[1] + 1 );
        }

        return '';
    }

    private function current_session_label(): string {
        $year  = (int) gmdate( 'Y' );
        $month = (int) gmdate( 'n' );

        // Nigerian academic year runs September to July.
        $start = $month >= 9 ? $year : $year - 1;

        return $start . '/' . ( $start + 1 );
    }

    private function current_session_id( int $school_id ): int {
        global $wpdb;

        $id = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT id FROM ' . Schema::table( 'academic_sessions' ) . ' WHERE school_id = %d AND is_current = 1 LIMIT 1',
                $school_id
            )
        );

        return $id ? absint( $id ) : 0;
    }

    private function level_id_by_code( int $school_id, string $code ): int {
        global $wpdb;

        $id = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT id FROM ' . Schema::table( 'class_levels' ) . ' WHERE school_id = %d AND code = %s LIMIT 1',
                $school_id,
                $code
            )
        );

        return $id ? absint( $id ) : 0;
    }

    private function department_id_by_name( int $school_id, string $name ): ?int {
        global $wpdb;

        $name = trim( $name );
        if ( $name === '' ) {
            return null;
        }

        if ( stripos( $name, 'art' ) === 0 || stripos( $name, 'human' ) === 0 ) {
            $name = 'Arts';
        }

        $id = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT id FROM ' . Schema::table( 'departments' ) . ' WHERE school_id = %d AND name = %s LIMIT 1',
                $school_id,
                $name
            )
        );

        return $id ? absint( $id ) : null;
    }

    private function unique_subject_code( int $school_id, string $code ): string {
        global $wpdb;

        $table = Schema::table( 'subjects_v2' );
        $base  = substr( $code, 0, 25 );
        $try   = $base;
        $n     = 1;

        while ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE school_id = %d AND code = %s", $school_id, $try ) ) ) {
            $try = $base . '-' . $n++;
        }

        return $try;
    }

    private function table_exists( string $table ): bool {
        global $wpdb;
        return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
    }

    private function get_mapped( int $school_id, string $entity, string $legacy_key ): int {
        $cache_key = $school_id . ':' . $entity;

        if ( isset( $this->map_cache[ $cache_key ][ $legacy_key ] ) ) {
            return $this->map_cache[ $cache_key ][ $legacy_key ];
        }

        global $wpdb;

        $id = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT new_id FROM ' . Schema::table( 'migration_map' ) . ' WHERE school_id = %d AND entity = %s AND legacy_key = %s',
                $school_id,
                $entity,
                $legacy_key
            )
        );

        $resolved = $id ? absint( $id ) : 0;
        $this->map_cache[ $cache_key ][ $legacy_key ] = $resolved;

        return $resolved;
    }

    private function set_mapped( int $school_id, string $entity, string $legacy_key, int $new_id ): void {
        if ( $new_id <= 0 ) {
            return;
        }

        global $wpdb;

        $wpdb->query(
            $wpdb->prepare(
                'INSERT IGNORE INTO ' . Schema::table( 'migration_map' ) .
                ' (school_id, entity, legacy_key, new_id) VALUES (%d, %s, %s, %d)',
                $school_id,
                $entity,
                $legacy_key,
                $new_id
            )
        );

        $this->map_cache[ $school_id . ':' . $entity ][ $legacy_key ] = $new_id;
    }
}
