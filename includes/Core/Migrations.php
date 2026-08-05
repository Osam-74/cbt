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
