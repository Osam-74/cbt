<?php

namespace EduCBTPro\Core;

use EduCBTPro\Admin\AdminController;
use EduCBTPro\Api\RestController;
use EduCBTPro\Core\Repository\StudentRepository;
use EduCBTPro\Services\AuditLogService;
use EduCBTPro\Data\TrialQuestionSeed;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Plugin {
    private const SCHEMA_VERSION = '3.0.0';
    private const VERSION        = '2.1.2';

    private static ?Plugin $instance = null;
    private TenantContext $tenant_context;
    private AdminController $admin_controller;
    private RestController $api_controller;

    private function __construct() {
        Autoloader::register();
        $this->tenant_context = new TenantContext();
        $this->admin_controller = new AdminController( $this->tenant_context );
        $this->api_controller = new RestController( $this->tenant_context );
    }

    public static function instance(): Plugin {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function run(): void {
        add_action( 'init', [ $this, 'init' ] );
        add_action( 'admin_menu', [ $this->admin_controller, 'register_admin_pages' ] );
        add_action( 'rest_api_init', [ $this->api_controller, 'register_routes' ] );
        add_filter( 'authenticate', [ $this, 'authenticate_student_identifier' ], 30, 3 );
        add_filter( 'login_redirect', [ $this, 'force_student_frontend_login_redirect' ], 20, 3 );
        add_action( 'admin_init', [ $this, 'handle_student_admin_access' ] );
        add_filter( 'show_admin_bar', [ $this, 'hide_student_admin_bar' ] );
        add_filter( 'educbt_portal_allowed_student_ids', [ $this, 'resolve_portal_student_ids' ], 10, 4 );
        add_action( 'wp_login', [ $this, 'log_successful_login' ], 10, 2 );
        add_action( 'wp_login_failed', [ $this, 'log_failed_login' ], 10, 1 );

    }

    public function init(): void {
        $this->tenant_context->init();
        ( new AttemptSweeper() )->init();
        ( new GradingWorker() )->init();
        ( new \EduCBTPro\Services\EmailQueueService() )->init();
        ( new \EduCBTPro\Services\NotificationService() )->init();
        // Schema catch-up.
        //
        // WordPress fires the activation hook only when a plugin is ACTIVATED. Uploading
        // a new version over a running one does not, so every schema fix shipped since
        // silently never applied — which is why the transcripts and questions errors
        // kept reappearing however many times the fix was released.
        self::maybe_upgrade();
        self::maybe_seed_trial_questions();

        ( new StudentLogin() )->init();
        ( new StaffLogin() )->init();
        ( new ActivityLog() )->init();
        ( new \EduCBTPro\Admin\PlatformAdminController() )->init();
        ( new \EduCBTPro\Admin\RepairTool() )->init();
        ( new \EduCBTPro\Frontend\LegacyThemeBridge() )->init();
        ( new \EduCBTPro\Api\ExamController() )->init();
        ( new \EduCBTPro\Api\QuestionController() )->init();
        ( new \EduCBTPro\Api\TrialApiController() )->init();
        ( new \EduCBTPro\Frontend\PortalActions() )->init();
        ( new \EduCBTPro\Frontend\PortalRouter() )->init();
        ( new \EduCBTPro\Frontend\LandingController() )->init();
        // Defensive: if a stale copy of this class is what actually got loaded — which
        // happens when a second plugin folder still exists and wins the race — the
        // method may not be there. Registering a hook to a missing callback takes the
        // whole site down, so the hook is only attached when the method really exists.
        if ( method_exists( self::class, 'enqueue_portal_assets' ) ) {
            add_action( 'wp_enqueue_scripts', [ self::class, 'enqueue_portal_assets' ], 99 );
        }

        // PHASE 2: front-end-only access for school roles, and host-bound auth.
        ( new AdminLockdown() )->init();
        ( new HostRouter() )->init();

        self::maybe_upgrade_schema();
        self::maybe_restore_roles();
    }

    private static function ensure_autoloader(): void {
        if ( ! class_exists( 'EduCBTPro\Core\Autoloader' ) ) {
            require_once EDUCBT_PRO_DIR . 'includes/Core/Autoloader.php';
        }
        Autoloader::register();
    }

    /** Phases 0-4 applied. */
    public const PHASE_VERSION = '12.0.6';

    /**
     * Portal styling, loaded only on portal pages so a school's public site is not
     * made heavier by CSS it never uses.
     */
    public static function enqueue_portal_assets(): void {
        // Detect a portal page from the URL, exactly as the router does.
        //
        // This previously read get_query_var(), which is only populated when rewrite
        // rules exist. Since the router was changed to parse the path itself — so the
        // portal works without a permalink flush — the query var is empty on a fresh
        // install, and the stylesheet and media picker silently never loaded. The
        // portal rendered unstyled and every image field fell back to a URL box.
        if ( ! \EduCBTPro\Frontend\PortalRouter::is_portal_request() ) {
            return;
        }

        $relative = 'assets/css/educbt-portal.css';
        $path     = EDUCBT_PRO_DIR . $relative;

        if ( ! file_exists( $path ) ) {
            return;
        }

        // Dequeue the legacy theme's portal CSS on portal pages only. Its dashboard
        // styles target the same generic class names and win on specificity, which is
        // why the portal still looked like the old theme.
        foreach ( [ 'educbt-theme', 'educbt-frontend-portal', 'educbt-theme-style' ] as $handle ) {
            wp_dequeue_style( $handle );
        }

        wp_enqueue_style(
            'educbt-portal',
            EDUCBT_PRO_URL . $relative,
            [],
            (string) filemtime( $path )
        );

        // The media library, so image fields on the front end behave exactly as they
        // do in wp-admin. Only for users who can actually upload.
        if ( current_user_can( 'upload_files' ) ) {
            wp_enqueue_media();
        }

        foreach ( [ 'educbt-tags' => 'assets/js/educbt-tags.js' ] as $handle => $rel ) {
            $file = EDUCBT_PRO_DIR . $rel;

            if ( file_exists( $file ) ) {
                wp_enqueue_script( $handle, EDUCBT_PRO_URL . $rel, [], (string) filemtime( $file ), true );
            }
        }

        $media = EDUCBT_PRO_DIR . 'assets/js/educbt-media.js';

        if ( file_exists( $media ) ) {
            wp_enqueue_script(
                'educbt-media',
                EDUCBT_PRO_URL . 'assets/js/educbt-media.js',
                [],
                (string) filemtime( $media ),
                true
            );
        }
    }

    /**
     * Run pending schema work when the plugin version has moved on.
     *
     * Cheap: one option read on a normal request, and the migration itself only when
     * the stored version differs.
     */
    public static function maybe_upgrade(): void {
        $stored = (string) get_option( 'educbt_schema_version', '' );

        if ( $stored === self::PHASE_VERSION ) {
            return;
        }

        global $wpdb;

        // Never print during an ordinary page load, whatever the database says.
        $suppressing = $wpdb->suppress_errors( true );
        $showing     = $wpdb->show_errors( false );

        ob_start();

        try {
            ( new TenantContext() )->create_tables();
            Migrations::run();
            Capabilities::install();
        } catch ( \Throwable $e ) {
            error_log( 'EduCBT upgrade failed: ' . $e->getMessage() );
        } finally {
            ob_end_clean();

            $wpdb->suppress_errors( $suppressing );
            $wpdb->show_errors( $showing );
        }

        update_option( 'educbt_schema_version', self::PHASE_VERSION, false );
    }

    public static function on_activate(): void {
        // Activation must never PRINT. WordPress buffers this callback and warns
        // about "unexpected output" if anything is echoed, and with WP_DEBUG_DISPLAY
        // on, $wpdb echoes every failed query straight to the buffer — which is what
        // produced the "2196 characters of unexpected output" warning.
        //
        // Errors are still recorded to the log; they are just not written to the
        // page, where they would break headers and corrupt the redirect.
        global $wpdb;

        $suppressing = $wpdb->suppress_errors( true );
        $showing     = $wpdb->show_errors( false );

        ob_start();

        try {
            self::run_activation();
        } finally {
            $noise = trim( (string) ob_get_clean() );

            $wpdb->suppress_errors( $suppressing );
            $wpdb->show_errors( $showing );

            if ( $noise !== '' ) {
                // Keep it, so a genuine problem is still discoverable afterwards.
                update_option( 'educbt_activation_notices', substr( $noise, 0, 5000 ), false );
            }
        }
    }

    private static function run_activation(): void {
        self::validate_runtime_requirements();
        self::ensure_autoloader();

        $tenant_context = new TenantContext();
        $tenant_context->create_tables();

        // PHASE 1: versioned migrations. Schema v2 is created alongside v1 and the
        // backfill runs exactly once, tracked by MigrationManager rather than being
        // re-executed blindly on every activation.
        $applied = Migrations::run();
        if ( ! empty( $applied ) ) {
            EventDispatcher::action( 'educbt_migrations_applied', [ 'versions' => $applied ] );
        }

        // PHASE 2: the full capability taxonomy replaces the nine-capability model.
        // RoleCapabilityManager is retained only so an upgrade can revoke what it
        // previously granted to WordPress's built-in roles.
        RoleCapabilityManager::apply_role_capabilities();
        Capabilities::install();

        self::seed_default_questions();
        self::seed_demo_data();

        self::seed_trial_questions();

        // Register the portal rules BEFORE flushing, or /portal/ 404s until
        // someone happens to re-save permalinks.
        ( new \EduCBTPro\Frontend\PortalRouter() )->register_rewrites();

        if ( function_exists( 'flush_rewrite_rules' ) ) {
            flush_rewrite_rules( false );
        }

        update_option( 'educbt_pro_bootstrap_complete', '1' );
        update_option( 'educbt_pro_schema_version', self::SCHEMA_VERSION );
    }

    public static function on_deactivate(): void {
        global $wpdb;
        self::ensure_autoloader();
        RoleCapabilityManager::remove_role_capabilities();
    }

    public static function on_uninstall(): void {
        self::ensure_autoloader();
        global $wpdb;
        $tables = [
            $wpdb->prefix . 'educbt_schools',
            $wpdb->prefix . 'educbt_tenants',
            $wpdb->prefix . 'educbt_users',
            $wpdb->prefix . 'educbt_students',
            $wpdb->prefix . 'educbt_teachers',
            $wpdb->prefix . 'educbt_subjects',
            $wpdb->prefix . 'educbt_questions',
            $wpdb->prefix . 'educbt_exams',
            $wpdb->prefix . 'educbt_results',
            $wpdb->prefix . 'educbt_promotions',
            $wpdb->prefix . 'educbt_transcripts',
            $wpdb->prefix . 'educbt_audit_logs',
            $wpdb->prefix . 'educbt_licenses',
            $wpdb->prefix . 'educbt_exam_timetables',
            $wpdb->prefix . 'educbt_exam_integrity_events',
            $wpdb->prefix . 'educbt_student_profile_updates',
            $wpdb->prefix . 'educbt_exam_questions',
            $wpdb->prefix . 'educbt_exam_attempts',
        ];
        foreach ( $tables as $table ) {
            $wpdb->query( "DROP TABLE IF EXISTS {$table}" );
        }

        // Delete created pages
        foreach ( [ 'educbt_page_id_login', 'educbt_page_id_dashboard', 'educbt_page_id_exam' ] as $opt ) {
            $pid = absint( get_option( $opt, 0 ) );
            if ( $pid > 0 ) {
                wp_delete_post( $pid, true );
            }
            delete_option( $opt );
        }

        delete_option( 'educbt_pro_bootstrap_complete' );
        delete_option( 'educbt_pro_schema_version' );
        delete_option( 'educbt_pro_default_questions_seeded' );
        // Delete all demo data flags (version-specific)
        $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'educbt_pro_demo_data_seeded%'" );
    }

    /**
     * Seed 3 full default questions into the question bank on activation.
     */
    private static function seed_default_questions(): void {
        if ( get_option( 'educbt_pro_default_questions_seeded' ) ) {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'educbt_questions';

        $defaults = [
            [
                'school_id'           => 0,
                'subject'             => 'English Language',
                'section'             => 'Objective',
                'topic'               => 'Comprehension',
                'class'               => 'SS1',
                'difficulty'          => 'Medium',
                'question_type'       => 'objective',
                'estimated_duration'  => 60,
                'marks'               => 1.00,
                'question_text'       => 'Choose the option that best completes the sentence: "The teacher advised the students to desist ___ cheating during examinations."',
                'options'             => wp_json_encode( [ 'from', 'against', 'with', 'at' ] ),
                'answers'             => wp_json_encode( [ 'from' ] ),
                'explanations'        => 'The correct preposition after "desist" is "from". To desist from something means to stop doing it.',
                'status'              => 'published',
            ],
            [
                'school_id'           => 0,
                'subject'             => 'Mathematics',
                'section'             => 'Objective',
                'topic'               => 'Algebra',
                'class'               => 'SS2',
                'difficulty'          => 'Medium',
                'question_type'       => 'objective',
                'estimated_duration'  => 90,
                'marks'               => 1.00,
                'question_text'       => 'If 3x + 7 = 22, find the value of x.',
                'options'             => wp_json_encode( [ '3', '5', '7', '15' ] ),
                'answers'             => wp_json_encode( [ '5' ] ),
                'explanations'        => '3x + 7 = 22 -> 3x = 22 - 7 -> 3x = 15 -> x = 5.',
                'status'              => 'published',
            ],
            [
                'school_id'           => 0,
                'subject'             => 'Economics',
                'section'             => 'Objective',
                'topic'               => 'Demand and Supply',
                'class'               => 'SS3',
                'difficulty'          => 'Hard',
                'question_type'       => 'objective',
                'estimated_duration'  => 120,
                'marks'               => 1.00,
                'question_text'       => 'A simultaneous increase in consumers\' income and production costs will most likely result in which outcome for a normal good?',
                'options'             => wp_json_encode( [
                    'Higher equilibrium price with uncertain quantity',
                    'Lower price and higher quantity',
                    'Lower price and lower quantity',
                    'No change in price',
                ] ),
                'answers'             => wp_json_encode( [ 'Higher equilibrium price with uncertain quantity' ] ),
                'explanations'        => 'Demand rises while supply falls, raising price; the effect on quantity depends on the relative magnitude of the shifts.',
                'status'              => 'published',
            ],
        ];

        $format = [ '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%f', '%s', '%s', '%s', '%s', '%s' ];

        foreach ( $defaults as $q ) {
            $wpdb->insert( $table, $q, $format );
        }

        update_option( 'educbt_pro_default_questions_seeded', '1' );
    }

    /**
     * Seed complete demo data: school, WP user, student, subject, exam,
     * questions, and exam-question assignments.
     */
    private static function seed_demo_data(): void {
        $demo_flag = 'educbt_pro_demo_data_seeded_' . self::VERSION;
        if ( get_option( $demo_flag ) ) {
            return;
        }

        global $wpdb;

        // 1. Create demo school
        $schools_table = $wpdb->prefix . 'educbt_schools';
        $wpdb->insert( $schools_table, [
            'school_name'   => 'Demo Secondary School',
            'school_code'   => 'DSS001',
            'address'        => '123 Education Avenue, Lagos',
            'email'         => 'admin@demosecondary.edu',
            'phone'         => '+2348000000000',
            'created_at'     => current_time( 'mysql' ),
            'updated_at'     => current_time( 'mysql' ),
        ], [ '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ] );
        $school_id = (int) $wpdb->insert_id;

        if ( $school_id <= 0 ) {
            return;
        }

        // 2. Create subject
        $subjects_table = $wpdb->prefix . 'educbt_subjects';
        $wpdb->insert( $subjects_table, [
            'school_id'     => $school_id,
            'subject_name'  => 'General Studies',
            'subject_code'  => 'GST101',
            'subject_type'  => 'core',
            'created_at'    => current_time( 'mysql' ),
            'updated_at'    => current_time( 'mysql' ),
        ], [ '%d', '%s', '%s', '%s', '%s', '%s' ] );

        // 3. Create WP user for the student (password = surname = "Student")
        $username = 'DEMO/2024/001';
        $user_email = 'student@demosecondary.edu';
        $user_id = username_exists( $username );
        if ( ! $user_id ) {
            $user_id = wp_create_user( $username, 'Student', $user_email );
            if ( $user_id && ! is_wp_error( $user_id ) ) {
                $user = new \WP_User( $user_id );
                $user->set_role( 'educbt_student' );
                wp_update_user( [
                    'ID'           => $user_id,
                    'display_name'  => 'Demo Student',
                    'first_name'   => 'Demo',
                    'last_name'     => 'Student',
                ] );
            }
        }
        if ( is_wp_error( $user_id ) ) {
            $user_id = 0;
        }

        // 4. Create student record
        $students_table = $wpdb->prefix . 'educbt_students';
        $wpdb->insert( $students_table, [
            'school_id'            => $school_id,
            'admission_number'     => 'ADM001',
            'registration_number'  => 'DEMO/2024/001',
            'student_id'           => 'STU001',
            'wp_user_id'           => absint( $user_id ),
            'login_username'       => $username,
            'first_name'           => 'Demo',
            'last_name'             => 'Student',
            'full_name'            => 'Demo Student',
            'gender'               => 'Male',
            'class'                => 'SS3',
            'arm'                  => 'A',
            'department'           => 'Science',
            'session_year'         => '2024/2025',
            'status'               => 'active',
            'created_at'           => current_time( 'mysql' ),
            'updated_at'           => current_time( 'mysql' ),
        ], [ '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ] );
        $student_record_id = (int) $wpdb->insert_id;

        // 4b. Link WP user to student record (theme reads this meta to pass studentRecordId to JS)
        if ( $user_id > 0 && $student_record_id > 0 ) {
            update_user_meta( $user_id, 'educbt_student_record_id', $student_record_id );
        }

        // 5. Create demo exam (30 min, published)
        $exams_table = $wpdb->prefix . 'educbt_exams';
        $wpdb->insert( $exams_table, [
            'school_id'         => $school_id,
            'title'             => 'Demo CBT Practice Exam',
            'exam_type'         => 'Practice',
            'description'       => 'A demonstration exam with 5 questions across English, Mathematics, and Economics. Use this to test the CBT interface.',
            'duration_minutes'  => 30,
            'is_published'      => 1,
            'start_time'        => current_time( 'mysql' ),
            'created_at'        => current_time( 'mysql' ),
            'updated_at'        => current_time( 'mysql' ),
        ], [ '%d', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s' ] );
        $exam_id = (int) $wpdb->insert_id;

        // 5b. Create timetable for the demo exam (trial mode, active now)
        $timetable_table = $wpdb->prefix . 'educbt_exam_timetables';
        $wpdb->insert( $timetable_table, [
            'school_id'       => $school_id,
            'exam_id'         => $exam_id,
            'session_year'    => '2024/2025',
            'term'            => 'First Term',
            'class_name'      => 'SS3',
            'arm'             => 'A',
            'department'      => 'Science',
            'subject'         => 'General Studies',
            'exam_date'       => gmdate( 'Y-m-d' ),
            'start_time'      => '00:00:00',
            'end_time'        => '23:59:59',
            'is_trial_mode'   => 1,
            'status'          => 'scheduled',
            'created_at'      => current_time( 'mysql' ),
            'updated_at'      => current_time( 'mysql' ),
        ], [ '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s' ] );

        // 6. Create exam-specific questions (5 total, tied to this school)
        $questions_table = $wpdb->prefix . 'educbt_questions';
        $exam_questions_table = $wpdb->prefix . 'educbt_exam_questions';

        $demo_questions = [
            [
                'subject' => 'English Language', 'topic' => 'Grammar',
                'question_text' => 'Choose the option that best completes the sentence: "The teacher advised the students to desist ___ cheating during examinations."',
                'options' => ['from', 'against', 'with', 'at'],
                'answer'  => 'from',
                'explanation' => 'The correct preposition after "desist" is "from".',
            ],
            [
                'subject' => 'Mathematics', 'topic' => 'Algebra',
                'question_text' => 'If 3x + 7 = 22, find the value of x.',
                'options' => ['3', '5', '7', '15'],
                'answer'  => '5',
                'explanation' => '3x + 7 = 22 -> 3x = 15 -> x = 5.',
            ],
            [
                'subject' => 'Mathematics', 'topic' => 'Geometry',
                'question_text' => 'What is the area of a circle with radius 7 cm? (Use pi = 22/7)',
                'options' => ['154 sq cm', '144 sq cm', '164 sq cm', '49 sq cm'],
                'answer'  => '154 sq cm',
                'explanation' => 'Area = pi * r^2 = 22/7 * 49 = 154 sq cm.',
            ],
            [
                'subject' => 'Economics', 'topic' => 'Demand and Supply',
                'question_text' => 'A simultaneous increase in consumers\' income and production costs will most likely result in which outcome for a normal good?',
                'options' => [
                    'Higher equilibrium price with uncertain quantity',
                    'Lower price and higher quantity',
                    'Lower price and lower quantity',
                    'No change in price',
                ],
                'answer' => 'Higher equilibrium price with uncertain quantity',
                'explanation' => 'Demand rises while supply falls, raising price; quantity depends on the shifts.',
            ],
            [
                'subject' => 'Biology', 'topic' => 'Cell Biology',
                'question_text' => 'Which organelle is known as the "powerhouse of the cell"?',
                'options' => ['Nucleus', 'Mitochondria', 'Ribosome', 'Golgi apparatus'],
                'answer'  => 'Mitochondria',
                'explanation' => 'Mitochondria produce ATP through cellular respiration, earning the nickname "powerhouse".',
            ],
        ];

        $q_format = [ '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%f', '%s', '%s', '%s', '%s', '%s' ];

        foreach ( $demo_questions as $q ) {
            $wpdb->insert( $questions_table, [
                'school_id'           => $school_id,
                'subject'             => $q['subject'],
                'section'             => 'Objective',
                'topic'               => $q['topic'],
                'class'               => 'SS3',
                'difficulty'          => 'Medium',
                'question_type'       => 'objective',
                'estimated_duration'  => 60,
                'marks'               => 1.00,
                'question_text'       => $q['question_text'],
                'options'             => wp_json_encode( $q['options'] ),
                'answers'             => wp_json_encode( [ $q['answer'] ] ),
                'explanations'        => $q['explanation'],
                'status'              => 'published',
            ], $q_format );

            $question_id = (int) $wpdb->insert_id;
            if ( $question_id > 0 && $exam_id > 0 ) {
                $wpdb->insert( $exam_questions_table, [
                    'school_id'    => $school_id,
                    'exam_id'      => $exam_id,
                    'question_id'  => $question_id,
                    'created_at'   => current_time( 'mysql' ),
                ], [ '%d', '%d', '%d', '%s' ] );
            }
        }

        update_option( 'educbt_pro_demo_data_seeded_' . self::VERSION, '1' );
    }


    private static function maybe_seed_trial_questions(): void {
        if ( get_option( "educbt_pro_trial_questions_seeded" ) ) {
            return;
        }
        self::seed_trial_questions();
    }

    private static function seed_trial_questions(): void {
        if ( get_option( "educbt_pro_trial_questions_seeded" ) ) {
            return;
        }

        try {
            $count = TrialQuestionSeed::install();
            update_option( "educbt_pro_trial_questions_seeded", (string) $count, false );
        } catch ( \Throwable $e ) {
            // Non-fatal: trial mode is a convenience feature.
            if ( function_exists( "error_log" ) ) {
                error_log( "EduCBT: trial seed failed: " . $e->getMessage() );
            }
        }
    }

    private static function maybe_upgrade_schema(): void {
        $schema_version = (string) get_option( 'educbt_pro_schema_version', '0' );

        if ( version_compare( $schema_version, self::SCHEMA_VERSION, '<' ) ) {
            $tenant_context = new TenantContext();
            $tenant_context->create_tables();

            // v2.3.0: add is_flagged column to notifications for flag/resolve feature
            global $wpdb;
            $notif_table = $wpdb->prefix . 'educbt_notifications';
            $cols = (array) $wpdb->get_col( "SHOW COLUMNS FROM {$notif_table} LIKE 'is_flagged'" );
            if ( empty( $cols ) ) {
                $wpdb->query( "ALTER TABLE {$notif_table} ADD COLUMN is_flagged tinyint(1) NOT NULL DEFAULT 0 AFTER is_read" );
            }

            update_option( 'educbt_pro_schema_version', self::SCHEMA_VERSION );
            update_option( 'educbt_pro_bootstrap_complete', '1' );
        }
    }

    private static function maybe_restore_roles(): void {
        $bootstrap_complete = (string) get_option( 'educbt_pro_bootstrap_complete', '' );

        foreach ( array_keys( RoleCapabilityManager::get_custom_roles() ) as $role_slug ) {
            if ( ! get_role( $role_slug ) ) {
                RoleCapabilityManager::apply_role_capabilities();
                update_option( 'educbt_pro_bootstrap_complete', '1' );
                return;
            }
        }

        if ( $bootstrap_complete !== '1' ) {
            RoleCapabilityManager::apply_role_capabilities();
            update_option( 'educbt_pro_bootstrap_complete', '1' );
        }
    }

    private static function validate_runtime_requirements(): void {
        global $wp_version;

        if ( version_compare( PHP_VERSION, '8.2.0', '<' ) ) {
            wp_die( esc_html__( 'EduCBT Pro requires PHP 8.2 or newer.', 'educbt-pro' ) );
        }

        if ( ! isset( $wp_version ) || version_compare( (string) $wp_version, '6.8', '<' ) ) {
            wp_die( esc_html__( 'EduCBT Pro requires WordPress 6.8 or newer.', 'educbt-pro' ) );
        }
    }

    public function authenticate_student_identifier( $user, string $username, string $password ) {
        if ( $username === '' || $password === '' ) {
            return $user;
        }

        if ( $user instanceof \WP_User ) {
            return $this->enforce_student_status( $user );
        }

        if ( $user instanceof \WP_Error ) {
            return $user;
        }

        $student_repository = new StudentRepository();
        $student = $student_repository->find_student_for_login( $username );

        if ( ! is_array( $student ) || empty( $student['login_username'] ) ) {
            return $user;
        }

        $mapped_username = sanitize_user( (string) $student['login_username'] );
        if ( $mapped_username === '' ) {
            return $user;
        }

        remove_filter( 'authenticate', [ $this, 'authenticate_student_identifier' ], 30 );
        $authenticated = wp_authenticate_username_password( null, $mapped_username, $password );
        add_filter( 'authenticate', [ $this, 'authenticate_student_identifier' ], 30, 3 );

        if ( $authenticated instanceof \WP_User ) {
            return $this->enforce_student_status( $authenticated );
        }

        return $authenticated;
    }

    public function log_successful_login( string $user_login, \WP_User $user ): void {
        $this->log_auth_event( $user_login, (int) $user->ID, 'login_success' );

        // Sync student record ID to user meta so the theme JS can auto-fill it
        $student_repository = new StudentRepository();
        $student = $student_repository->find_student_by_wp_user_id( (int) $user->ID );
        if ( is_array( $student ) && ! empty( $student['id'] ) ) {
            update_user_meta( (int) $user->ID, 'educbt_student_record_id', absint( $student['id'] ) );
        }
    }

    public function log_failed_login( string $username ): void {
        $this->log_auth_event( $username, 0, 'login_failed' );
    }

    private function enforce_student_status( \WP_User $user ) {
        $student_repository = new StudentRepository();
        $student = $student_repository->find_student_for_login( (string) $user->user_login );

        if ( ! is_array( $student ) ) {
            return $user;
        }

        $status = strtolower( (string) ( $student['status'] ?? 'active' ) );
        if ( in_array( $status, [ 'inactive', 'disabled', 'suspended', 'blocked' ], true ) ) {
            return new \WP_Error( 'educbt_student_inactive', __( 'Student account is inactive. Contact your school administrator.', 'educbt-pro' ) );
        }

        return $user;
    }

    private function log_auth_event( string $username, int $user_id, string $action ): void {
        $student_repository = new StudentRepository();
        $student = $student_repository->find_student_for_login( $username );
        $school_id = absint( $student['school_id'] ?? 0 );

        if ( $school_id <= 0 ) {
            return;
        }

        $audit_log_service = new AuditLogService();
        $audit_log_service->create_log(
            $school_id,
            [
                'user_id' => $user_id,
                'action' => $action,
                'object_type' => 'student_auth',
                'object_id' => absint( $student['id'] ?? 0 ),
                'new_value' => wp_json_encode( [ 'username' => $username ] ),
                'ip_address' => sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) ),
                'device' => sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? '' ) ),
            ]
        );
    }

    public function force_student_frontend_login_redirect( string $redirect_to, string $requested_redirect_to, $user ): string {
        if ( $user instanceof \WP_User && $this->is_student_portal_user( $user ) ) {
            return $this->resolve_student_dashboard_url();
        }

        return $redirect_to;
    }

    public function handle_student_admin_access(): void {
        if ( ! is_user_logged_in() ) {
            return;
        }

        $user = wp_get_current_user();
        if ( ! ( $user instanceof \WP_User ) || ! $this->is_student_portal_user( $user ) ) {
            return;
        }

        if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
            return;
        }

        wp_safe_redirect( $this->resolve_student_dashboard_url() );
        exit;
    }

    public function hide_student_admin_bar( bool $show ): bool {
        if ( ! is_user_logged_in() ) {
            return $show;
        }

        $user = wp_get_current_user();
        if ( $user instanceof \WP_User && $this->is_student_portal_user( $user ) ) {
            return false;
        }

        return $show;
    }


    /**
     * Map the current WP user ID to their student record IDs so the portal
     * scope check compares student-table IDs, not WP user IDs.
     */
    public function resolve_portal_student_ids( array $student_ids, int $user_id, int $school_id, string $context ): array {
        if ( ! empty( $student_ids ) ) {
            return $student_ids;
        }
        if ( $user_id <= 0 ) {
            return [];
        }

        global $wpdb;
        $table = $wpdb->prefix . 'educbt_students';

        $ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT id FROM {$table} WHERE wp_user_id = %d LIMIT 1",
                $user_id
            )
        );

        return array_map( 'absint', $ids ?: [] );
    }

    private function is_student_portal_user( \WP_User $user ): bool {
        $has_student_cap = user_can( $user, 'educbt_student_portal' );
        $is_admin = user_can( $user, 'manage_options' );

        return $has_student_cap && ! $is_admin;
    }

    private function resolve_student_dashboard_url(): string {
        $dashboard_page_id = absint( get_option( 'educbt_page_id_dashboard', 0 ) );
        if ( $dashboard_page_id > 0 ) {
            $url = get_permalink( $dashboard_page_id );
            if ( is_string( $url ) && $url !== '' ) {
                return $url;
            }
        }

        // Use the theme's dashboard page if available, otherwise fallback to home
        $theme_dash_id = absint( get_option( 'educbt_theme_page_id_dashboard', 0 ) );
        if ( $theme_dash_id > 0 ) {
            $url = get_permalink( $theme_dash_id );
            if ( is_string( $url ) && $url !== '' ) {
                return $url;
            }
        }
        return home_url( '/' );
    }
}
