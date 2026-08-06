<?php

namespace EduCBTPro\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * PHASE 1 — migration registry.
 *
 * MigrationManager already existed but nothing was ever registered with it;
 * activation called create_tables() directly, so there was no version history and
 * no way to run a data migration exactly once. This class supplies the ordered map.
 *
 * Schema changes and data backfills are deliberately separate versions. A schema
 * step is fast and safe to rerun; a backfill over 500 students and thousands of
 * questions is neither, and must be resumable on its own.
 */
class Migrations {

    public static function register( MigrationManager $manager ): void {

        // 1.0.0 — the v1 tables, as they shipped. Retained so an existing install
        // records a truthful starting version rather than jumping straight to 2.x.
        $manager->register( '1.0.0', static function (): void {
            ( new TenantContext() )->create_tables();
        } );

        // 2.0.0 — the relational v2 tables, created alongside v1. Non-destructive.
        $manager->register( '2.0.0', static function (): void {
            Schema::install();
        } );

        // 2.0.1 — seed academic defaults, then migrate v1 string data into v2 rows.
        $manager->register( '2.0.1', static function ( $wpdb ): void {
            $schools = (array) $wpdb->get_col( 'SELECT id FROM ' . $wpdb->prefix . 'educbt_schools' );

            $backfill = new Backfill();
            $reports  = [];

            foreach ( $schools as $school_id ) {
                $reports[ absint( $school_id ) ] = $backfill->run( absint( $school_id ) );
            }

            // Kept so an administrator can inspect what could not be mapped rather
            // than discovering it mid-term.
            update_option( 'educbt_backfill_report', $reports, false );
        } );

        // 2.1.2 — Re-open exam prep for every school. A prior version defaulted
        // exam_prep_enabled to false in default_academic_settings(), so the act
        // of adding the academic_settings column (or saving settings after an
        // update) silently closed question submission. This sets it back to true
        // for all existing schools, exactly once.
        $manager->register( '2.1.2', static function ( $wpdb ): void {
            $schools = (array) $wpdb->get_col( 'SELECT id FROM ' . $wpdb->prefix . 'educbt_schools' );
            $svc     = new \EduCBTPro\Services\SchoolService();

            foreach ( $schools as $sid ) {
                $svc->set_exam_prep_enabled( absint( $sid ), true );
            }
        } );

        // 3.1.2 — Clean up theory questions that have stale option rows. The
        // create() and CSV import paths were not branching on question_type, so
        // written questions landed in the bank with empty A-D options attached.
        // This removes those phantom options so the bank renders correctly.
        $manager->register( '3.1.2', static function ( $wpdb ): void {
            $questions = $wpdb->prefix . 'educbt_questions';
            $options   = \EduCBTPro\Core\Schema::table( 'question_options' );

            // Get all theory question IDs
            $theory_ids = (array) $wpdb->get_col(
                "SELECT id FROM {$questions} WHERE question_type = 'theory'"
            );

            if ( empty( $theory_ids ) ) {
                return;
            }

            $id_list = implode( ',', array_map( 'absint', $theory_ids ) );

            $wpdb->query( "DELETE FROM {$options} WHERE question_id IN ({$id_list})" );
        } );

        // 3.1.3 — exam_papers gained no column to record which staff member created
        // a CA test. The teacher dashboard needed one to show "my tests", so this
        // adds it retroactively rather than guessing at an existing column that was
        // never there.
        $manager->register( '3.1.3', static function ( $wpdb ): void {
            $papers = \EduCBTPro\Core\Schema::table( 'exam_papers' );

            $exists = $wpdb->get_col( "SHOW COLUMNS FROM {$papers} LIKE 'created_by_staff'" );

            if ( empty( $exists ) ) {
                $wpdb->query( "ALTER TABLE {$papers} ADD COLUMN created_by_staff bigint(20) unsigned DEFAULT NULL AFTER legacy_exam_id" );
                $wpdb->query( "ALTER TABLE {$papers} ADD KEY created_by_staff (school_id, created_by_staff)" );
            }
        } );

        // 3.2.0 — Question Bank rebuild. New tables: question_sets, question_sub_items.
        // The v1 questions table gains a question_set_id column linking it to a set.
        // This is the data model that makes the spec's resume/submit lifecycle work.
        $manager->register( '3.2.0', static function ( $wpdb ): void {
            // Install the new v2 tables.
            \EduCBTPro\Core\Schema::install();

            // Add question_set_id to the v1 questions table.
            $questions = $wpdb->prefix . 'educbt_questions';

            $has_col = $wpdb->get_col( "SHOW COLUMNS FROM {$questions} LIKE 'question_set_id'" );

            if ( empty( $has_col ) ) {
                $wpdb->query( "ALTER TABLE {$questions} ADD COLUMN question_set_id bigint(20) unsigned DEFAULT NULL AFTER id" );
                $wpdb->query( "ALTER TABLE {$questions} ADD KEY question_set_id (question_set_id)" );
            }

            // Add sequence column for ordering within a set.
            $has_seq = $wpdb->get_col( "SHOW COLUMNS FROM {$questions} LIKE 'sequence'" );

            if ( empty( $has_seq ) ) {
                $wpdb->query( "ALTER TABLE {$questions} ADD COLUMN sequence int(11) NOT NULL DEFAULT 0 AFTER question_set_id" );
            }

            // Add source_method column (manual/paste/import).
            $has_src = $wpdb->get_col( "SHOW COLUMNS FROM {$questions} LIKE 'source_method'" );

            if ( empty( $has_src ) ) {
                $wpdb->query( "ALTER TABLE {$questions} ADD COLUMN source_method varchar(20) NOT NULL DEFAULT 'manual' AFTER sequence" );
            }

            // Add marking_guide column for theory questions.
            $has_guide = $wpdb->get_col( "SHOW COLUMNS FROM {$questions} LIKE 'marking_guide'" );

            if ( empty( $has_guide ) ) {
                $wpdb->query( "ALTER TABLE {$questions} ADD COLUMN marking_guide longtext DEFAULT NULL AFTER explanations" );
            }

            // Add reviewer_comment for per-question review feedback.
            $has_rc = $wpdb->get_col( "SHOW COLUMNS FROM {$questions} LIKE 'reviewer_comment'" );

            if ( empty( $has_rc ) ) {
                $wpdb->query( "ALTER TABLE {$questions} ADD COLUMN reviewer_comment text DEFAULT NULL AFTER marking_guide" );
            }
        } );

        // 3.2.6 — schema catch-up for the question bank.
        //
        // The 3.2.0 columns only landed if maybe_upgrade() happened to notice a
        // PHASE_VERSION change on that release. Where it did not, the questions
        // table was left without question_set_id / sequence / source_method, and
        // $wpdb->insert() then refused the row before it ever reached MySQL —
        // which the browser could only report as "Save failed".
        //
        // Every step is a no-op when the column already exists, so this is safe
        // to run against a healthy install.
        $manager->register( '3.2.6', static function ( $wpdb ): void {
            Schema::install();
            ( new TenantContext() )->create_tables();

            $questions = $wpdb->prefix . 'educbt_questions';

            $columns = [
                'question_set_id'  => 'bigint(20) unsigned DEFAULT NULL',
                'sequence'         => 'int(11) NOT NULL DEFAULT 0',
                'source_method'    => "varchar(20) NOT NULL DEFAULT 'manual'",
                'marking_guide'    => 'longtext DEFAULT NULL',
                'reviewer_comment' => 'text DEFAULT NULL',
            ];

            foreach ( $columns as $column => $definition ) {
                $exists = $wpdb->get_col( "SHOW COLUMNS FROM {$questions} LIKE '{$column}'" );

                if ( empty( $exists ) ) {
                    $wpdb->query( "ALTER TABLE {$questions} ADD COLUMN {$column} {$definition}" );
                }
            }
        } );

        // 3.2.9 — repair duplicated answer options.
        //
        // Questions written while the answer key was mapped by a shifted array
        // index ended up with their option list written more than once, showing
        // as A-H with two options marked correct. Collapse each question back to
        // one row per distinct option text, renumber the keys, and leave exactly
        // one correct answer standing.
        $manager->register( '3.2.9', static function ( $wpdb ): void {
            $options = Schema::table( 'question_options' );

            $question_ids = (array) $wpdb->get_col(
                "SELECT question_id FROM {$options} GROUP BY question_id HAVING COUNT(*) > 1"
            );

            foreach ( $question_ids as $qid ) {
                $qid = absint( $qid );
                if ( $qid <= 0 ) {
                    continue;
                }

                $rows = (array) $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT id, option_text, is_correct FROM {$options}
                         WHERE question_id = %d ORDER BY sort_order ASC, id ASC",
                        $qid
                    ),
                    ARRAY_A
                );

                $seen    = [];
                $keep    = [];
                $delete  = [];
                $correct = 0;

                foreach ( $rows as $row ) {
                    $fingerprint = strtolower( trim( (string) $row['option_text'] ) );

                    if ( $fingerprint === '' || isset( $seen[ $fingerprint ] ) ) {
                        $delete[] = absint( $row['id'] );
                        continue;
                    }

                    $seen[ $fingerprint ] = true;
                    $keep[]               = $row;

                    if ( absint( $row['is_correct'] ) === 1 ) {
                        $correct++;
                    }
                }

                if ( ! empty( $delete ) ) {
                    $wpdb->query( 'DELETE FROM ' . $options . ' WHERE id IN (' . implode( ',', array_map( 'absint', $delete ) ) . ')' );
                }

                // Renumber keys, and keep only the FIRST correct answer.
                $sort       = 0;
                $kept_first = false;

                foreach ( $keep as $row ) {
                    $is_correct = absint( $row['is_correct'] ) === 1 && ! $kept_first;
                    if ( $is_correct ) {
                        $kept_first = true;
                    }

                    $wpdb->update(
                        $options,
                        [
                            'option_key' => chr( 65 + $sort ),
                            'sort_order' => $sort,
                            'is_correct' => $is_correct ? 1 : 0,
                        ],
                        [ 'id' => absint( $row['id'] ) ],
                        [ '%s', '%d', '%d' ],
                        [ '%d' ]
                    );
                    $sort++;
                }
            }
        } );

        // 3.4.0 — question sets move from class arms to class LEVEL + department.
        //
        // JS1 A and JS1 B sit the same paper, so a set keyed on class_id made a
        // teacher enter identical questions once per arm and produced a review queue
        // full of duplicates. After this, one set covers the whole level (and, for
        // senior classes, the department).
        //
        // Existing per-arm sets are merged: the lowest id survives, every question
        // from its siblings is re-pointed at it, and the emptied siblings are
        // dropped. Questions are moved rather than deleted — nobody's work is lost.
        $manager->register( '3.4.0', static function ( $wpdb ): void {
            $sets      = Schema::table( 'question_sets' );
            $classes   = Schema::table( 'classes' );
            $questions = $wpdb->prefix . 'educbt_questions';

            foreach ( [ 'level_id', 'department_id' ] as $column ) {
                $exists = $wpdb->get_col( "SHOW COLUMNS FROM {$sets} LIKE '{$column}'" );
                if ( empty( $exists ) ) {
                    $wpdb->query( "ALTER TABLE {$sets} ADD COLUMN {$column} bigint(20) unsigned NOT NULL DEFAULT 0" );
                }
            }

            // Backfill from the arm each set was created against.
            $wpdb->query(
                "UPDATE {$sets} s
                 INNER JOIN {$classes} c ON c.id = s.class_id
                 SET s.level_id = c.level_id,
                     s.department_id = COALESCE(c.department_id, 0)
                 WHERE s.level_id = 0"
            );

            // Merge the arms that have now collapsed onto one another.
            $groups = (array) $wpdb->get_results(
                "SELECT school_id, session_id, term_id, subject_id, level_id, department_id, exam_type,
                        MIN(id) AS keep_id, COUNT(*) AS total
                 FROM {$sets}
                 WHERE level_id > 0
                 GROUP BY school_id, session_id, term_id, subject_id, level_id, department_id, exam_type
                 HAVING COUNT(*) > 1",
                ARRAY_A
            );

            foreach ( $groups as $group ) {
                $keep = absint( $group['keep_id'] );

                $siblings = (array) $wpdb->get_col(
                    $wpdb->prepare(
                        "SELECT id FROM {$sets}
                         WHERE school_id = %d AND session_id = %d
                           AND COALESCE(term_id,0) = %d AND subject_id = %d
                           AND level_id = %d AND department_id = %d AND exam_type = %s
                           AND id <> %d",
                        absint( $group['school_id'] ),
                        absint( $group['session_id'] ),
                        absint( $group['term_id'] ),
                        absint( $group['subject_id'] ),
                        absint( $group['level_id'] ),
                        absint( $group['department_id'] ),
                        (string) $group['exam_type'],
                        $keep
                    )
                );

                foreach ( $siblings as $sibling ) {
                    $sibling = absint( $sibling );
                    if ( $sibling <= 0 ) {
                        continue;
                    }

                    // Continue the surviving set's numbering rather than restarting.
                    $offset = absint(
                        $wpdb->get_var(
                            $wpdb->prepare( "SELECT COALESCE(MAX(sequence),0) FROM {$questions} WHERE question_set_id = %d", $keep )
                        )
                    );

                    $wpdb->query(
                        $wpdb->prepare(
                            "UPDATE {$questions} SET question_set_id = %d, sequence = sequence + %d WHERE question_set_id = %d",
                            $keep,
                            $offset,
                            $sibling
                        )
                    );

                    $wpdb->query( $wpdb->prepare( "DELETE FROM {$sets} WHERE id = %d", $sibling ) );
                }
            }

            // Swap the identity key over. Dropping first is safe: if the old key is
            // already gone the DROP simply fails and the ADD still runs.
            $wpdb->query( "ALTER TABLE {$sets} DROP INDEX scope" );
            $wpdb->query(
                "ALTER TABLE {$sets}
                 ADD UNIQUE KEY scope (school_id, session_id, term_id, subject_id, level_id, department_id, exam_type)"
            );
        } );
    }

    /**
     * Run every pending migration. Safe to call on each activation.
     *
     * @return array<int,string> versions applied
     */
    public static function run(): array {
        $manager = new MigrationManager();
        self::register( $manager );

        return $manager->run();
    }

    /**
     * The unresolved rows from the last backfill, for the admin health screen.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function unresolved(): array {
        $reports = get_option( 'educbt_backfill_report', [] );
        if ( ! is_array( $reports ) ) {
            return [];
        }

        $rows = [];

        foreach ( $reports as $report ) {
            foreach ( (array) ( $report['unresolved'] ?? [] ) as $item ) {
                $rows[] = $item;
            }
        }

        return $rows;
    }
}
