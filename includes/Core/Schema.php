<?php

namespace EduCBTPro\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * PHASE 1 — target schema.
 *
 * The v1 schema keyed academic relationships by STRING: `students.class`,
 * `questions.subject`, `results.term`, `exam_timetables.class_name` were all
 * varchars rather than foreign keys. Renaming a class silently orphaned every
 * historical result, and class position could only be computed by string match.
 *
 * The v2 tables below are created ALONGSIDE the v1 tables rather than replacing
 * them, so a live install keeps serving while data is backfilled (see Backfill).
 * Nothing here drops a legacy table; retirement happens in a later, separate step
 * once the backfill has been verified in production.
 *
 * Two structural changes drive everything else:
 *
 *  1. A student's class is an ENROLLMENT (student x class x session), not a field
 *     on the student row. This is what makes transcripts, promotion history and
 *     "who was in JSS2A in 2023/24" answerable.
 *
 *  2. Answers live one row per (attempt, question) in `attempt_answers`, not as a
 *     JSON blob rewritten on every autosave.
 */
class Schema {

    /** Bump when any statement below changes. */
    public const VERSION = '2.0.2';

    public static function table( string $name ): string {
        global $wpdb;
        return $wpdb->prefix . 'educbt_' . $name;
    }

    public static function install(): void {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $collate = $wpdb->get_charset_collate();

        foreach ( self::statements( $collate ) as $sql ) {
            dbDelta( $sql );
        }
    }

    /**
     * One statement per table. dbDelta is fussy: two spaces after PRIMARY KEY,
     * one field per line, lowercase types, no trailing comma.
     *
     * @return array<int,string>
     */
    public static function statements( string $collate ): array {
        $t = static fn( string $n ): string => self::table( $n );

        $sql = [];

        // ---------------------------------------------------------------
        // Academic structure
        // ---------------------------------------------------------------

        $sql[] = "CREATE TABLE {$t('academic_sessions')} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            school_id bigint(20) unsigned NOT NULL,
            title varchar(50) NOT NULL,
            starts_on date DEFAULT NULL,
            ends_on date DEFAULT NULL,
            is_current tinyint(1) NOT NULL DEFAULT 0,
            status varchar(20) NOT NULL DEFAULT 'active',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY school_title (school_id,title),
            KEY is_current (school_id,is_current)
        ) {$collate};";

        $sql[] = "CREATE TABLE {$t('terms')} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            school_id bigint(20) unsigned NOT NULL,
            session_id bigint(20) unsigned NOT NULL,
            title varchar(50) NOT NULL,
            term_order tinyint(3) unsigned NOT NULL DEFAULT 1,
            starts_on date DEFAULT NULL,
            ends_on date DEFAULT NULL,
            is_current tinyint(1) NOT NULL DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY session_order (session_id,term_order),
            KEY school_id (school_id),
            KEY is_current (school_id,is_current)
        ) {$collate};";

        $sql[] = "CREATE TABLE {$t('departments')} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            school_id bigint(20) unsigned NOT NULL,
            name varchar(100) NOT NULL,
            code varchar(20) NOT NULL,
            applies_to varchar(20) NOT NULL DEFAULT 'senior',
            sort_order int(11) NOT NULL DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY school_code (school_id,code),
            KEY school_id (school_id)
        ) {$collate};";

        $sql[] = "CREATE TABLE {$t('class_levels')} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            school_id bigint(20) unsigned NOT NULL,
            name varchar(50) NOT NULL,
            code varchar(20) NOT NULL,
            stage varchar(20) NOT NULL DEFAULT 'junior',
            level_order tinyint(3) unsigned NOT NULL DEFAULT 1,
            is_terminal tinyint(1) NOT NULL DEFAULT 0,
            next_level_id bigint(20) unsigned DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY school_code (school_id,code),
            KEY school_order (school_id,level_order)
        ) {$collate};";

        $sql[] = "CREATE TABLE {$t('classes')} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            school_id bigint(20) unsigned NOT NULL,
            level_id bigint(20) unsigned NOT NULL,
            department_id bigint(20) unsigned DEFAULT NULL,
            arm varchar(10) NOT NULL DEFAULT '',
            display_name varchar(100) NOT NULL,
            capacity int(11) NOT NULL DEFAULT 0,
            status varchar(20) NOT NULL DEFAULT 'active',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY school_level_dept_arm (school_id,level_id,department_id,arm),
            KEY school_id (school_id),
            KEY level_id (level_id)
        ) {$collate};";

        $sql[] = "CREATE TABLE {$t('subjects_v2')} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            school_id bigint(20) unsigned NOT NULL,
            name varchar(150) NOT NULL,
            code varchar(30) NOT NULL,
            stage varchar(20) NOT NULL DEFAULT 'both',
            category varchar(30) NOT NULL DEFAULT 'elective',
            department_id bigint(20) unsigned DEFAULT NULL,
            is_compulsory tinyint(1) NOT NULL DEFAULT 0,
            status varchar(20) NOT NULL DEFAULT 'active',
            legacy_subject_id bigint(20) unsigned DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY school_code (school_id,code),
            KEY school_id (school_id),
            KEY legacy_subject_id (legacy_subject_id)
        ) {$collate};";

        $sql[] = "CREATE TABLE {$t('class_subjects')} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            school_id bigint(20) unsigned NOT NULL,
            level_id bigint(20) unsigned NOT NULL,
            department_id bigint(20) unsigned DEFAULT NULL,
            subject_id bigint(20) unsigned NOT NULL,
            is_compulsory tinyint(1) NOT NULL DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY offering (school_id,level_id,department_id,subject_id),
            KEY subject_id (subject_id)
        ) {$collate};";

        // Which subjects a specific student actually offers this session.
        $sql[] = "CREATE TABLE {$t('student_subjects')} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            school_id bigint(20) unsigned NOT NULL,
            student_id bigint(20) unsigned NOT NULL,
            subject_id bigint(20) unsigned NOT NULL,
            session_id bigint(20) unsigned NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY registration (student_id,subject_id,session_id),
            KEY school_id (school_id),
            KEY subject_session (subject_id,session_id)
        ) {$collate};";

        // ---------------------------------------------------------------
        // Grading
        // ---------------------------------------------------------------

        $sql[] = "CREATE TABLE {$t('grading_scales')} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            school_id bigint(20) unsigned NOT NULL,
            name varchar(100) NOT NULL,
            code varchar(30) NOT NULL,
            applies_to_stage varchar(20) NOT NULL DEFAULT 'both',
            is_default tinyint(1) NOT NULL DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY school_code (school_id,code),
            KEY school_id (school_id)
        ) {$collate};";

        $sql[] = "CREATE TABLE {$t('grade_bands')} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            scale_id bigint(20) unsigned NOT NULL,
            grade varchar(10) NOT NULL,
            min_score decimal(5,2) NOT NULL DEFAULT 0.00,
            max_score decimal(5,2) NOT NULL DEFAULT 100.00,
            remark varchar(100) NOT NULL DEFAULT '',
            is_pass tinyint(1) NOT NULL DEFAULT 1,
            sort_order int(11) NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            KEY scale_id (scale_id),
            KEY scale_sort (scale_id,sort_order)
        ) {$collate};";

        $sql[] = "CREATE TABLE {$t('assessment_components')} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            school_id bigint(20) unsigned NOT NULL,
            name varchar(100) NOT NULL,
            code varchar(30) NOT NULL,
            max_score decimal(5,2) NOT NULL DEFAULT 0.00,
            is_exam tinyint(1) NOT NULL DEFAULT 0,
            sort_order int(11) NOT NULL DEFAULT 0,
            status varchar(20) NOT NULL DEFAULT 'active',
            PRIMARY KEY  (id),
            UNIQUE KEY school_code (school_id,code),
            KEY school_id (school_id)
        ) {$collate};";

        // ---------------------------------------------------------------
        // People
        // ---------------------------------------------------------------

        $sql[] = "CREATE TABLE {$t('staff')} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            school_id bigint(20) unsigned NOT NULL,
            staff_number varchar(50) NOT NULL,
            wp_user_id bigint(20) unsigned DEFAULT NULL,
            first_name varchar(100) NOT NULL DEFAULT '',
            last_name varchar(100) NOT NULL DEFAULT '',
            title varchar(20) NOT NULL DEFAULT '',
            gender varchar(20) NOT NULL DEFAULT '',
            email varchar(191) NOT NULL DEFAULT '',
            phone varchar(50) NOT NULL DEFAULT '',
            photo varchar(255) NOT NULL DEFAULT '',
            role_slug varchar(50) NOT NULL DEFAULT 'educbt_teacher',
            status varchar(20) NOT NULL DEFAULT 'active',
            legacy_teacher_id bigint(20) unsigned DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY school_staff_number (school_id,staff_number),
            KEY school_id (school_id),
            KEY wp_user_id (wp_user_id),
            KEY legacy_teacher_id (legacy_teacher_id)
        ) {$collate};";

        $sql[] = "CREATE TABLE {$t('guardians')} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            school_id bigint(20) unsigned NOT NULL,
            wp_user_id bigint(20) unsigned DEFAULT NULL,
            first_name varchar(100) NOT NULL DEFAULT '',
            last_name varchar(100) NOT NULL DEFAULT '',
            email varchar(191) NOT NULL DEFAULT '',
            phone varchar(50) NOT NULL DEFAULT '',
            address text DEFAULT NULL,
            occupation varchar(150) NOT NULL DEFAULT '',
            invite_token varchar(64) NOT NULL DEFAULT '',
            invite_status varchar(20) NOT NULL DEFAULT 'pending',
            status varchar(20) NOT NULL DEFAULT 'active',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY school_id (school_id),
            KEY wp_user_id (wp_user_id),
            KEY email (email),
            KEY phone (phone),
            KEY invite_token (invite_token)
        ) {$collate};";

        // Many-to-many: siblings, and two guardians per child.
        $sql[] = "CREATE TABLE {$t('guardian_student')} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            school_id bigint(20) unsigned NOT NULL,
            guardian_id bigint(20) unsigned NOT NULL,
            student_id bigint(20) unsigned NOT NULL,
            relationship varchar(30) NOT NULL DEFAULT 'parent',
            is_primary tinyint(1) NOT NULL DEFAULT 0,
            can_view_results tinyint(1) NOT NULL DEFAULT 1,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY link (guardian_id,student_id),
            KEY school_id (school_id),
            KEY student_id (student_id)
        ) {$collate};";

        // THE keystone table. Class belongs here, not on the student row.
        $sql[] = "CREATE TABLE {$t('enrollments')} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            school_id bigint(20) unsigned NOT NULL,
            student_id bigint(20) unsigned NOT NULL,
            class_id bigint(20) unsigned NOT NULL,
            session_id bigint(20) unsigned NOT NULL,
            department_id bigint(20) unsigned DEFAULT NULL,
            roll_number int(11) NOT NULL DEFAULT 0,
            enrolled_on date DEFAULT NULL,
            exited_on date DEFAULT NULL,
            status varchar(20) NOT NULL DEFAULT 'active',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY student_session (student_id,session_id),
            KEY school_id (school_id),
            KEY class_session (class_id,session_id),
            KEY status (school_id,status)
        ) {$collate};";

        // ---------------------------------------------------------------
        // Assignments — scope, not role
        // ---------------------------------------------------------------

        $sql[] = "CREATE TABLE {$t('staff_assignments')} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            school_id bigint(20) unsigned NOT NULL,
            staff_id bigint(20) unsigned NOT NULL,
            assignment_type varchar(30) NOT NULL,
            class_id bigint(20) unsigned DEFAULT NULL,
            subject_id bigint(20) unsigned DEFAULT NULL,
            department_id bigint(20) unsigned DEFAULT NULL,
            session_id bigint(20) unsigned NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'active',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY assignment (staff_id,assignment_type,class_id,subject_id,session_id),
            KEY school_id (school_id),
            KEY type_session (school_id,assignment_type,session_id),
            KEY class_subject (class_id,subject_id)
        ) {$collate};";

        // ---------------------------------------------------------------
        // Question bank
        // ---------------------------------------------------------------

        $sql[] = "CREATE TABLE {$t('question_options')} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            school_id bigint(20) unsigned NOT NULL,
            question_id bigint(20) unsigned NOT NULL,
            option_key varchar(5) NOT NULL DEFAULT '',
            option_text longtext DEFAULT NULL,
            option_image varchar(255) NOT NULL DEFAULT '',
            is_correct tinyint(1) NOT NULL DEFAULT 0,
            sort_order int(11) NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            KEY question_id (question_id),
            KEY school_id (school_id),
            KEY question_sort (question_id,sort_order)
        ) {$collate};";

        // Shared stimulus: a comprehension passage, a data table, a diagram, or a
        // block of instructions that several questions all refer to. Without this,
        // an English paper has to repeat the whole passage on every question, which
        // is unreadable on a phone and makes shuffling questions incoherent.
        $sql[] = "CREATE TABLE {$t('passages')} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            school_id bigint(20) unsigned NOT NULL,
            subject_id bigint(20) unsigned DEFAULT NULL,
            class_level varchar(50) NOT NULL DEFAULT '',
            title varchar(191) NOT NULL DEFAULT '',
            passage_type varchar(30) NOT NULL DEFAULT 'comprehension',
            body longtext DEFAULT NULL,
            image varchar(255) NOT NULL DEFAULT '',
            author_staff_id bigint(20) unsigned DEFAULT NULL,
            status varchar(20) NOT NULL DEFAULT 'active',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY school_id (school_id),
            KEY subject_level (subject_id,class_level),
            KEY status (school_id,status)
        ) {$collate};";

        // Draft authoring workspace. A teacher building sixty questions over several
        // evenings needs their half-finished work to survive a closed laptop, and an
        // incomplete question must never be visible to the paper composer.
        $sql[] = "CREATE TABLE {$t('question_drafts')} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            school_id bigint(20) unsigned NOT NULL,
            batch_id varchar(40) NOT NULL DEFAULT '',
            author_staff_id bigint(20) unsigned NOT NULL,
            subject_id bigint(20) unsigned DEFAULT NULL,
            class_level varchar(50) NOT NULL DEFAULT '',
            position int(11) NOT NULL DEFAULT 0,
            payload longtext DEFAULT NULL,
            validation_errors longtext DEFAULT NULL,
            is_complete tinyint(1) NOT NULL DEFAULT 0,
            status varchar(20) NOT NULL DEFAULT 'draft',
            published_question_id bigint(20) unsigned DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY school_id (school_id),
            KEY author (school_id,author_staff_id,status),
            KEY batch (batch_id,position),
            KEY subject_id (subject_id)
        ) {$collate};";

        // ---------------------------------------------------------------
        // Exams — series and papers replace exams + exam_timetables
        // ---------------------------------------------------------------

        $sql[] = "CREATE TABLE {$t('exam_series')} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            school_id bigint(20) unsigned NOT NULL,
            session_id bigint(20) unsigned NOT NULL,
            term_id bigint(20) unsigned DEFAULT NULL,
            title varchar(191) NOT NULL,
            series_type varchar(30) NOT NULL DEFAULT 'terminal',
            starts_on date DEFAULT NULL,
            ends_on date DEFAULT NULL,
            status varchar(20) NOT NULL DEFAULT 'draft',
            created_by bigint(20) unsigned DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY school_id (school_id),
            KEY session_term (session_id,term_id),
            KEY status (school_id,status)
        ) {$collate};";

        $sql[] = "CREATE TABLE {$t('exam_papers')} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            school_id bigint(20) unsigned NOT NULL,
            series_id bigint(20) unsigned NOT NULL,
            subject_id bigint(20) unsigned NOT NULL,
            class_id bigint(20) unsigned DEFAULT NULL,
            level_id bigint(20) unsigned DEFAULT NULL,
            department_id bigint(20) unsigned DEFAULT NULL,
            scheduled_at datetime DEFAULT NULL,
            duration_seconds int(11) NOT NULL DEFAULT 0,
            question_count int(11) NOT NULL DEFAULT 0,
            total_marks decimal(8,2) NOT NULL DEFAULT 0.00,
            shuffle_questions tinyint(1) NOT NULL DEFAULT 1,
            shuffle_options tinyint(1) NOT NULL DEFAULT 1,
            access_code varchar(20) NOT NULL DEFAULT '',
            requires_access_code tinyint(1) NOT NULL DEFAULT 1,
            allow_review tinyint(1) NOT NULL DEFAULT 0,
            venue varchar(150) NOT NULL DEFAULT '',
            is_practice tinyint(1) NOT NULL DEFAULT 0,
            status varchar(20) NOT NULL DEFAULT 'draft',
            legacy_exam_id bigint(20) unsigned DEFAULT NULL,
            created_by_staff bigint(20) unsigned DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY school_id (school_id),
            KEY series_id (series_id),
            KEY subject_class (subject_id,class_id),
            KEY scheduled_at (school_id,scheduled_at),
            KEY status (school_id,status),
            KEY legacy_exam_id (legacy_exam_id),
            KEY created_by_staff (school_id,created_by_staff)
        ) {$collate};";

        $sql[] = "CREATE TABLE {$t('paper_questions')} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            school_id bigint(20) unsigned NOT NULL,
            paper_id bigint(20) unsigned NOT NULL,
            question_id bigint(20) unsigned NOT NULL,
            sort_order int(11) NOT NULL DEFAULT 0,
            marks decimal(8,2) NOT NULL DEFAULT 1.00,
            PRIMARY KEY  (id),
            UNIQUE KEY paper_question (paper_id,question_id),
            KEY school_id (school_id),
            KEY paper_sort (paper_id,sort_order)
        ) {$collate};";

        $sql[] = "CREATE TABLE {$t('paper_invigilators')} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            school_id bigint(20) unsigned NOT NULL,
            paper_id bigint(20) unsigned NOT NULL,
            staff_id bigint(20) unsigned NOT NULL,
            assigned_mode varchar(20) NOT NULL DEFAULT 'auto',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY paper_staff (paper_id,staff_id),
            KEY school_id (school_id),
            KEY staff_id (staff_id)
        ) {$collate};";

        // ---------------------------------------------------------------
        // CBT runtime
        // ---------------------------------------------------------------

        $sql[] = "CREATE TABLE {$t('attempts')} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            school_id bigint(20) unsigned NOT NULL,
            paper_id bigint(20) unsigned NOT NULL,
            student_id bigint(20) unsigned NOT NULL,
            enrollment_id bigint(20) unsigned DEFAULT NULL,
            session_token varchar(64) NOT NULL,
            question_order longtext DEFAULT NULL,
            started_at datetime DEFAULT NULL,
            submitted_at datetime DEFAULT NULL,
            extension_seconds int(11) NOT NULL DEFAULT 0,
            submit_reason varchar(20) NOT NULL DEFAULT '',
            raw_score decimal(8,2) NOT NULL DEFAULT 0.00,
            max_score decimal(8,2) NOT NULL DEFAULT 0.00,
            percentage decimal(5,2) NOT NULL DEFAULT 0.00,
            graded_at datetime DEFAULT NULL,
            flag_count int(11) NOT NULL DEFAULT 0,
            status varchar(20) NOT NULL DEFAULT 'in_progress',
            legacy_attempt_id bigint(20) unsigned DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY paper_student (paper_id,student_id),
            UNIQUE KEY session_token (session_token),
            KEY school_id (school_id),
            KEY status (school_id,status),
            KEY student_id (student_id),
            KEY legacy_attempt_id (legacy_attempt_id)
        ) {$collate};";

        // One row per answer. Replaces the results.student_responses JSON blob that
        // was rewritten in full on every autosave.
        $sql[] = "CREATE TABLE {$t('attempt_answers')} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            school_id bigint(20) unsigned NOT NULL,
            attempt_id bigint(20) unsigned NOT NULL,
            question_id bigint(20) unsigned NOT NULL,
            option_id bigint(20) unsigned DEFAULT NULL,
            answer_text longtext DEFAULT NULL,
            is_correct tinyint(1) DEFAULT NULL,
            marks_awarded decimal(8,2) NOT NULL DEFAULT 0.00,
            answered_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY attempt_question (attempt_id,question_id),
            KEY school_id (school_id),
            KEY question_id (question_id)
        ) {$collate};";

        $sql[] = "CREATE TABLE {$t('attempt_events')} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            school_id bigint(20) unsigned NOT NULL,
            attempt_id bigint(20) unsigned NOT NULL,
            event_type varchar(30) NOT NULL,
            payload longtext DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY attempt_id (attempt_id),
            KEY school_id (school_id),
            KEY event_type (school_id,event_type)
        ) {$collate};";

        // ---------------------------------------------------------------
        // Results
        // ---------------------------------------------------------------

        $sql[] = "CREATE TABLE {$t('assessment_scores')} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            school_id bigint(20) unsigned NOT NULL,
            student_id bigint(20) unsigned NOT NULL,
            subject_id bigint(20) unsigned NOT NULL,
            class_id bigint(20) unsigned NOT NULL,
            session_id bigint(20) unsigned NOT NULL,
            term_id bigint(20) unsigned NOT NULL,
            component_id bigint(20) unsigned NOT NULL,
            score decimal(6,2) NOT NULL DEFAULT 0.00,
            max_score decimal(6,2) NOT NULL DEFAULT 0.00,
            source varchar(20) NOT NULL DEFAULT 'manual',
            attempt_id bigint(20) unsigned DEFAULT NULL,
            entered_by bigint(20) unsigned DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY score_slot (student_id,subject_id,term_id,component_id),
            KEY school_id (school_id),
            KEY class_term (class_id,term_id),
            KEY subject_term (subject_id,term_id)
        ) {$collate};";

        $sql[] = "CREATE TABLE {$t('subject_results')} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            school_id bigint(20) unsigned NOT NULL,
            student_id bigint(20) unsigned NOT NULL,
            subject_id bigint(20) unsigned NOT NULL,
            class_id bigint(20) unsigned NOT NULL,
            session_id bigint(20) unsigned NOT NULL,
            term_id bigint(20) unsigned NOT NULL,
            ca_total decimal(6,2) NOT NULL DEFAULT 0.00,
            exam_total decimal(6,2) NOT NULL DEFAULT 0.00,
            total decimal(6,2) NOT NULL DEFAULT 0.00,
            grade varchar(10) NOT NULL DEFAULT '',
            remark varchar(100) NOT NULL DEFAULT '',
            subject_position int(11) NOT NULL DEFAULT 0,
            class_average decimal(6,2) NOT NULL DEFAULT 0.00,
            highest_in_class decimal(6,2) NOT NULL DEFAULT 0.00,
            lowest_in_class decimal(6,2) NOT NULL DEFAULT 0.00,
            teacher_remark varchar(255) NOT NULL DEFAULT '',
            status varchar(20) NOT NULL DEFAULT 'draft',
            computed_at datetime DEFAULT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY result_slot (student_id,subject_id,term_id),
            KEY school_id (school_id),
            KEY class_term (class_id,term_id),
            KEY status (school_id,status)
        ) {$collate};";

        $sql[] = "CREATE TABLE {$t('term_results')} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            school_id bigint(20) unsigned NOT NULL,
            student_id bigint(20) unsigned NOT NULL,
            class_id bigint(20) unsigned NOT NULL,
            session_id bigint(20) unsigned NOT NULL,
            term_id bigint(20) unsigned NOT NULL,
            subjects_offered int(11) NOT NULL DEFAULT 0,
            total_score decimal(10,2) NOT NULL DEFAULT 0.00,
            average_score decimal(6,2) NOT NULL DEFAULT 0.00,
            class_position int(11) NOT NULL DEFAULT 0,
            class_size int(11) NOT NULL DEFAULT 0,
            class_teacher_remark text DEFAULT NULL,
            principal_remark text DEFAULT NULL,
            days_present int(11) NOT NULL DEFAULT 0,
            days_total int(11) NOT NULL DEFAULT 0,
            status varchar(20) NOT NULL DEFAULT 'draft',
            submitted_by bigint(20) unsigned DEFAULT NULL,
            submitted_at datetime DEFAULT NULL,
            approved_by bigint(20) unsigned DEFAULT NULL,
            approved_at datetime DEFAULT NULL,
            published_at datetime DEFAULT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY term_slot (student_id,term_id),
            KEY school_id (school_id),
            KEY class_term (class_id,term_id),
            KEY status (school_id,status)
        ) {$collate};";

        $sql[] = "CREATE TABLE {$t('promotion_batches')} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            school_id bigint(20) unsigned NOT NULL,
            from_session_id bigint(20) unsigned NOT NULL,
            to_session_id bigint(20) unsigned NOT NULL,
            level_id bigint(20) unsigned DEFAULT NULL,
            rules longtext DEFAULT NULL,
            total_evaluated int(11) NOT NULL DEFAULT 0,
            total_promoted int(11) NOT NULL DEFAULT 0,
            total_trial int(11) NOT NULL DEFAULT 0,
            total_repeated int(11) NOT NULL DEFAULT 0,
            total_unresolved int(11) NOT NULL DEFAULT 0,
            status varchar(20) NOT NULL DEFAULT 'proposed',
            created_by bigint(20) unsigned DEFAULT NULL,
            committed_by bigint(20) unsigned DEFAULT NULL,
            committed_at datetime DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY school_id (school_id),
            KEY status (school_id,status)
        ) {$collate};";

        $sql[] = "CREATE TABLE {$t('promotion_decisions')} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            school_id bigint(20) unsigned NOT NULL,
            batch_id bigint(20) unsigned NOT NULL,
            student_id bigint(20) unsigned NOT NULL,
            from_class_id bigint(20) unsigned DEFAULT NULL,
            to_class_id bigint(20) unsigned DEFAULT NULL,
            proposed_outcome varchar(20) NOT NULL DEFAULT 'promote',
            final_outcome varchar(20) NOT NULL DEFAULT 'promote',
            average_score decimal(6,2) NOT NULL DEFAULT 0.00,
            subjects_passed int(11) NOT NULL DEFAULT 0,
            override_reason varchar(255) NOT NULL DEFAULT '',
            overridden_by bigint(20) unsigned DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY batch_student (batch_id,student_id),
            KEY school_id (school_id),
            KEY student_id (student_id)
        ) {$collate};";

        // ---------------------------------------------------------------
        // Communication
        // ---------------------------------------------------------------

        $sql[] = "CREATE TABLE {$t('announcements')} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            school_id bigint(20) unsigned NOT NULL,
            title varchar(191) NOT NULL,
            body longtext DEFAULT NULL,
            audience_type varchar(30) NOT NULL DEFAULT 'school',
            audience_ref longtext DEFAULT NULL,
            send_email tinyint(1) NOT NULL DEFAULT 0,
            status varchar(20) NOT NULL DEFAULT 'draft',
            published_at datetime DEFAULT NULL,
            created_by bigint(20) unsigned DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY school_id (school_id),
            KEY status (school_id,status),
            KEY published_at (school_id,published_at)
        ) {$collate};";

        $sql[] = "CREATE TABLE {$t('message_threads')} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            school_id bigint(20) unsigned NOT NULL,
            subject varchar(191) NOT NULL DEFAULT '',
            participants longtext DEFAULT NULL,
            last_message_at datetime DEFAULT NULL,
            created_by bigint(20) unsigned DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY school_id (school_id),
            KEY last_message_at (school_id,last_message_at)
        ) {$collate};";

        $sql[] = "CREATE TABLE {$t('messages')} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            school_id bigint(20) unsigned NOT NULL,
            thread_id bigint(20) unsigned NOT NULL,
            sender_user_id bigint(20) unsigned NOT NULL,
            body longtext DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY thread_id (thread_id),
            KEY school_id (school_id),
            KEY thread_created (thread_id,created_at)
        ) {$collate};";

        $sql[] = "CREATE TABLE {$t('email_queue')} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            school_id bigint(20) unsigned NOT NULL,
            recipient varchar(191) NOT NULL,
            subject varchar(255) NOT NULL DEFAULT '',
            body longtext DEFAULT NULL,
            attempts tinyint(3) unsigned NOT NULL DEFAULT 0,
            status varchar(20) NOT NULL DEFAULT 'queued',
            last_error varchar(255) NOT NULL DEFAULT '',
            scheduled_for datetime DEFAULT CURRENT_TIMESTAMP,
            sent_at datetime DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY school_id (school_id),
            KEY dispatch (status,scheduled_for)
        ) {$collate};";

        // Every transcript ever issued. A document that travels to people who cannot
        // ring the school to check it must be traceable back to who issued it.
        $sql[] = "CREATE TABLE {$t('transcripts')} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            school_id bigint(20) unsigned NOT NULL,
            student_id bigint(20) unsigned NOT NULL,
            serial varchar(60) NOT NULL,
            purpose varchar(255) NOT NULL DEFAULT '',
            checksum varchar(32) NOT NULL DEFAULT '',
            issued_by bigint(20) unsigned DEFAULT NULL,
            issued_at datetime DEFAULT CURRENT_TIMESTAMP,
            status varchar(20) NOT NULL DEFAULT 'issued',
            PRIMARY KEY  (id),
            UNIQUE KEY serial (serial),
            KEY school_student (school_id,student_id),
            KEY issued_at (school_id,issued_at)
        ) {$collate};";

        // ---------------------------------------------------------------
        // Public trial mode
        // ---------------------------------------------------------------

        // A SEPARATE, PLATFORM-OWNED question bank. Trial mode is open to anyone with
        // the URL and no login, so it must never be able to reach a school's real
        // questions — that would publish live exam papers to the internet. There is
        // deliberately no school_id here: these questions belong to nobody's exam.
        $sql[] = "CREATE TABLE {$t('trial_questions')} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            subject_code varchar(30) NOT NULL,
            subject_name varchar(100) NOT NULL,
            level_band varchar(20) NOT NULL DEFAULT 'both',
            topic varchar(150) NOT NULL DEFAULT '',
            difficulty varchar(20) NOT NULL DEFAULT 'medium',
            question_text text NOT NULL,
            options longtext NOT NULL,
            answer_key varchar(5) NOT NULL,
            explanation text DEFAULT NULL,
            seed_ref varchar(60) NOT NULL DEFAULT '',
            status varchar(20) NOT NULL DEFAULT 'active',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY seed_ref (seed_ref),
            KEY subject_band (subject_code,level_band,status)
        ) {$collate};";

        // Ephemeral. No account, no personal data, and purged on a schedule.
        $sql[] = "CREATE TABLE {$t('trial_attempts')} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            token varchar(64) NOT NULL,
            client_hash varchar(64) NOT NULL DEFAULT '',
            display_name varchar(60) NOT NULL DEFAULT '',
            subject_code varchar(30) NOT NULL,
            level_band varchar(20) NOT NULL DEFAULT 'both',
            question_ids longtext NOT NULL,
            answers longtext DEFAULT NULL,
            question_count int(11) NOT NULL DEFAULT 0,
            score int(11) NOT NULL DEFAULT 0,
            duration_seconds int(11) NOT NULL DEFAULT 0,
            started_at datetime DEFAULT CURRENT_TIMESTAMP,
            submitted_at datetime DEFAULT NULL,
            expires_at datetime DEFAULT NULL,
            status varchar(20) NOT NULL DEFAULT 'in_progress',
            PRIMARY KEY  (id),
            UNIQUE KEY token (token),
            KEY client_hash (client_hash,started_at),
            KEY expires_at (expires_at)
        ) {$collate};";

        // ---------------------------------------------------------------
        // Migration bookkeeping
        // ---------------------------------------------------------------

        // ---------------------------------------------------------------
        // Question Sets — the unit of work for teacher question submission.
        // A Question Set groups every question a teacher writes for one
        // subject + class + exam_type in one session/term. The unique key
        // makes resume reliable: selecting the same scope always finds the
        // same set (or creates it on first question).
        // ---------------------------------------------------------------
        $sql[] = "CREATE TABLE {$t('question_sets')} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            school_id bigint(20) unsigned NOT NULL,
            session_id bigint(20) unsigned NOT NULL,
            term_id bigint(20) unsigned DEFAULT NULL,
            subject_id bigint(20) unsigned NOT NULL,
            class_id bigint(20) unsigned NOT NULL,
            exam_type varchar(20) NOT NULL DEFAULT 'objective',
            teacher_id bigint(20) unsigned NOT NULL,
            default_marks decimal(8,2) NOT NULL DEFAULT 1.00,
            status varchar(20) NOT NULL DEFAULT 'draft',
            min_required int(11) NOT NULL DEFAULT 0,
            submitted_at datetime DEFAULT NULL,
            submitted_by bigint(20) unsigned DEFAULT NULL,
            reviewed_at datetime DEFAULT NULL,
            reviewed_by bigint(20) unsigned DEFAULT NULL,
            reviewer_comment longtext DEFAULT NULL,
            revision_history longtext DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY scope (school_id, session_id, term_id, subject_id, class_id, exam_type),
            KEY teacher (school_id, teacher_id, status),
            KEY status (school_id, status)
        ) {$collate};";

        // Theory sub-questions — when a theory question has parts (a, b, c),
        // each part has its own text and marks. The parent question's marks
        // auto-calculate as the sum of sub-question marks.
        $sql[] = "CREATE TABLE {$t('question_sub_items')} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            school_id bigint(20) unsigned NOT NULL,
            question_id bigint(20) unsigned NOT NULL,
            label varchar(10) NOT NULL DEFAULT '',
            text longtext DEFAULT NULL,
            marks decimal(8,2) NOT NULL DEFAULT 0.00,
            sequence int(11) NOT NULL DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY question_id (question_id),
            KEY school_id (school_id),
            KEY question_sort (question_id,sequence)
        ) {$collate};";

        $sql[] = "CREATE TABLE {$t('migration_map')} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            school_id bigint(20) unsigned NOT NULL,
            entity varchar(50) NOT NULL,
            legacy_key varchar(191) NOT NULL,
            new_id bigint(20) unsigned NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY mapping (school_id,entity,legacy_key),
            KEY new_id (new_id)
        ) {$collate};";

        return $sql;
    }

    /**
     * Every v2 table name, unprefixed. Used by tests and the uninstaller.
     *
     * @return array<int,string>
     */
    public static function table_names(): array {
        return [
            'academic_sessions', 'terms', 'departments', 'class_levels', 'classes',
            'subjects_v2', 'class_subjects', 'student_subjects',
            'grading_scales', 'grade_bands', 'assessment_components',
            'staff', 'guardians', 'guardian_student', 'enrollments', 'staff_assignments',
            'question_options', 'question_drafts', 'question_sets', 'question_sub_items', 'passages',
            'exam_series', 'exam_papers', 'paper_questions', 'paper_invigilators',
            'attempts', 'attempt_answers', 'attempt_events',
            'assessment_scores', 'subject_results', 'term_results',
            'promotion_batches', 'promotion_decisions',
            'announcements', 'notifications', 'message_threads', 'messages', 'email_queue',
            'transcripts', 'trial_questions', 'trial_attempts',
            'migration_map',
        ];
    }
}
