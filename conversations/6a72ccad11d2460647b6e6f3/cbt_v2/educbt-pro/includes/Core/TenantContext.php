<?php

namespace EduCBTPro\Core;

use EduCBTPro\Core\EventDispatcher;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class TenantContext {
    private ?int $school_id = null;

    /** Server-side impersonation state, set only via switch_school(). */
    private ?int $impersonated_school_id = null;
    private bool $resolved = false;

    public function init(): void {
        add_action( 'init', [ $this, 'resolve_tenant' ], 1 );
        add_action( 'rest_api_init', [ $this, 'resolve_tenant' ], 1 );
    }

    /**
     * PHASE 0 SECURITY FIX.
     *
     * Tenant identity is derived from the authenticated user's own record, and is
     * cross-checked against the request host. Request parameters, headers and
     * cookies are NEVER accepted as tenant identity — accepting them allowed any
     * visitor to read and write another school's data via ?school_id=N.
     *
     * A platform admin may act on behalf of a school, but only through
     * switch_school(), which is capability-gated, audit-logged and stored
     * server-side in the PHP session/user meta — never in a client-supplied value.
     */
    public function resolve_tenant(): void {
        $this->resolved = true;

        $host_school_id = $this->resolve_from_host();
        $user_school_id = $this->resolve_from_logged_in_user();

        // Platform admins acting on behalf of a school.
        if ( $user_school_id === null && $this->user_is_platform_admin() ) {
            $impersonated = $this->get_stored_impersonation();
            if ( $impersonated !== null ) {
                $this->school_id = $impersonated;
                return;
            }
            // Platform admin on a school subdomain adopts that school's context.
            $this->school_id = $host_school_id;
            return;
        }

        if ( $user_school_id === null ) {
            $this->school_id = null;
            return;
        }

        // A user authenticated on a host belonging to a different school is
        // rejected outright: a School A session must be worthless on School B.
        if ( $host_school_id !== null && $host_school_id !== $user_school_id ) {
            EventDispatcher::action( 'tenant_host_mismatch', [
                'user_school_id' => $user_school_id,
                'host_school_id' => $host_school_id,
                'host'           => $this->get_request_host(),
                'user_id'        => function_exists( 'get_current_user_id' ) ? absint( get_current_user_id() ) : 0,
            ] );

            $this->school_id = null;
            return;
        }

        $this->school_id = $user_school_id;
    }

    /**
     * Resolve a school from the request host (wildcard subdomain or custom domain).
     * Returns null when the host is the platform root or the column is not yet present.
     */
    public function resolve_from_host(): ?int {
        $host = $this->get_request_host();
        if ( $host === '' ) {
            return null;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'educbt_schools';

        // Custom domain takes precedence over subdomain, when the columns exist.
        if ( $this->column_exists( $table, 'custom_domain' ) ) {
            $id = $wpdb->get_var(
                $wpdb->prepare( "SELECT id FROM {$table} WHERE custom_domain = %s LIMIT 1", $host )
            );
            if ( $id ) {
                return absint( $id );
            }
        }

        if ( ! $this->column_exists( $table, 'subdomain' ) ) {
            return null;
        }

        $label = $this->extract_subdomain_label( $host );
        if ( $label === '' ) {
            return null;
        }

        $id = $wpdb->get_var(
            $wpdb->prepare( "SELECT id FROM {$table} WHERE subdomain = %s LIMIT 1", $label )
        );

        return $id ? absint( $id ) : null;
    }

    /**
     * Leftmost label of the host, excluding reserved platform labels.
     */
    private function extract_subdomain_label( string $host ): string {
        $reserved = [ 'www', 'mail', 'admin', 'api', 'cdn', 'app', 'portal', 'static', 'localhost' ];

        $parts = explode( '.', $host );
        if ( count( $parts ) < 3 ) {
            return '';
        }

        $label = strtolower( $parts[0] );
        return in_array( $label, $reserved, true ) ? '' : $label;
    }

    private function get_request_host(): string {
        if ( empty( $_SERVER['HTTP_HOST'] ) ) {
            return '';
        }

        $host = strtolower( (string) wp_unslash( $_SERVER['HTTP_HOST'] ) );
        $host = preg_replace( '/:\d+$/', '', $host );

        // Only characters legal in a hostname survive.
        return (string) preg_replace( '/[^a-z0-9.\-]/', '', (string) $host );
    }

    private function user_is_platform_admin(): bool {
        return function_exists( 'current_user_can' ) && current_user_can( 'manage_options' );
    }

    /**
     * Platform-admin impersonation. Capability-gated and audit-logged.
     * The chosen school is stored in user meta, server-side — never in a cookie
     * or request parameter the client can forge.
     */
    public function switch_school( int $school_id ): bool {
        if ( ! $this->user_is_platform_admin() ) {
            return false;
        }

        $user_id = absint( get_current_user_id() );
        if ( $user_id <= 0 ) {
            return false;
        }

        if ( $school_id > 0 ) {
            update_user_meta( $user_id, '_educbt_acting_school_id', $school_id );
        } else {
            delete_user_meta( $user_id, '_educbt_acting_school_id' );
        }

        EventDispatcher::action( 'tenant_context_switched', [
            'user_id'   => $user_id,
            'school_id' => $school_id,
        ] );

        $this->impersonated_school_id = $school_id > 0 ? $school_id : null;
        $this->school_id              = $this->impersonated_school_id;

        return true;
    }

    private function get_stored_impersonation(): ?int {
        if ( $this->impersonated_school_id !== null ) {
            return $this->impersonated_school_id;
        }

        $user_id = function_exists( 'get_current_user_id' ) ? absint( get_current_user_id() ) : 0;
        if ( $user_id <= 0 ) {
            return null;
        }

        $stored = absint( get_user_meta( $user_id, '_educbt_acting_school_id', true ) );
        return $stored > 0 ? $stored : null;
    }

    /**
     * Resolve school_id from the logged-in user's own database record.
     * This is the only trusted source of tenant identity for a school user.
     */
    private function resolve_from_logged_in_user(): ?int {
        $user_id = function_exists( 'get_current_user_id' ) ? absint( get_current_user_id() ) : 0;
        if ( $user_id <= 0 ) {
            return null;
        }

        global $wpdb;

        $students_table = $wpdb->prefix . 'educbt_students';
        $school_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT school_id FROM {$students_table} WHERE wp_user_id = %d LIMIT 1",
                $user_id
            )
        );

        if ( $school_id ) {
            return absint( $school_id );
        }

        $users_table = $wpdb->prefix . 'educbt_users';
        $school_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT school_id FROM {$users_table} WHERE wp_user_id = %d LIMIT 1",
                $user_id
            )
        );

        if ( $school_id ) {
            return absint( $school_id );
        }

        // The staff table is checked here too: a principal is staff, and if the
        // legacy users table row is missing this is where they are found.
        $staff_id = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT school_id FROM ' . Schema::table( 'staff' ) . ' WHERE wp_user_id = %d LIMIT 1',
                $user_id
            )
        );

        if ( $staff_id ) {
            return absint( $staff_id );
        }

        // Written server-side at account creation; never accepted from a request.
        $meta = absint( get_user_meta( $user_id, '_educbt_school_id', true ) );

        return $meta > 0 ? $meta : null;
    }

    public function get_school_id(): ?int {
        if ( ! $this->resolved ) {
            $this->resolve_tenant();
        }
        return $this->school_id;
    }

    /**
     * Internal/testing setter. Not reachable from a request path.
     * Production context changes must go through switch_school().
     */
    public function set_school_id( int $school_id ): void {
        $this->school_id = $school_id;
        $this->resolved  = true;
    }

    public function create_tables(): void {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        $schools_table = $wpdb->prefix . 'educbt_schools';
        $tenants_table = $wpdb->prefix . 'educbt_tenants';
        $users_table = $wpdb->prefix . 'educbt_users';
        $students_table = $wpdb->prefix . 'educbt_students';
        $teachers_table = $wpdb->prefix . 'educbt_teachers';
        $subjects_table = $wpdb->prefix . 'educbt_subjects';
        $classes_table = $wpdb->prefix . 'educbt_classes';
        $questions_table = $wpdb->prefix . 'educbt_questions';
        $exam_questions_table = $wpdb->prefix . 'educbt_exam_questions';

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $sql = "CREATE TABLE {$schools_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            school_name varchar(255) NOT NULL,
            school_code varchar(100) NOT NULL,
            logo varchar(255) DEFAULT '',
            address text DEFAULT NULL,
            phone varchar(100) DEFAULT '',
            email varchar(255) DEFAULT '',
            website varchar(255) DEFAULT '',
            principal_name varchar(255) DEFAULT '',
            academic_settings longtext DEFAULT NULL,
            report_settings longtext DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY school_code (school_code)
        ) {$charset_collate};

        CREATE TABLE {$tenants_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            school_id bigint(20) unsigned NOT NULL,
            tenant_label varchar(255) NOT NULL,
            tenant_code varchar(100) NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY tenant_code (tenant_code),
            KEY school_id (school_id)
        ) {$charset_collate};

        CREATE TABLE {$users_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            school_id bigint(20) unsigned NOT NULL,
            wp_user_id bigint(20) unsigned NOT NULL,
            role varchar(100) NOT NULL,
            capabilities longtext DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY school_id (school_id),
            KEY wp_user_id (wp_user_id)
        ) {$charset_collate};

        CREATE TABLE {$students_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            school_id bigint(20) unsigned NOT NULL,
            admission_number varchar(100) NOT NULL,
            registration_number varchar(100) NOT NULL,
            student_id varchar(100) NOT NULL,
            wp_user_id bigint(20) unsigned DEFAULT NULL,
            login_username varchar(100) DEFAULT '',
            passport_photo varchar(255) DEFAULT '',
            first_name varchar(150) DEFAULT '',
            last_name varchar(150) DEFAULT '',
            full_name varchar(255) NOT NULL,
            gender varchar(50) DEFAULT '',
            date_of_birth date DEFAULT NULL,
            parent_information longtext DEFAULT NULL,
            parent_phone varchar(100) DEFAULT '',
            parent_email varchar(255) DEFAULT '',
            address text DEFAULT NULL,
            class varchar(100) DEFAULT '',
            arm varchar(50) DEFAULT '',
            department varchar(100) DEFAULT '',
            session_year varchar(50) DEFAULT '',
            subject_bundle longtext DEFAULT NULL,
            status varchar(50) DEFAULT 'active',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY school_id (school_id),
            KEY admission_number (admission_number),
            UNIQUE KEY registration_number (registration_number),
            KEY login_username (login_username),
            KEY wp_user_id (wp_user_id)
        ) {$charset_collate};

        CREATE TABLE {$teachers_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            school_id bigint(20) unsigned NOT NULL,
            teacher_id varchar(100) NOT NULL,
            full_name varchar(255) NOT NULL,
            teacher_group varchar(100) DEFAULT '',
            contact_details longtext DEFAULT NULL,
            subjects longtext DEFAULT NULL,
            assigned_classes longtext DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY school_id (school_id),
            KEY teacher_id (teacher_id)
        ) {$charset_collate};

        CREATE TABLE {$subjects_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            school_id bigint(20) unsigned NOT NULL,
            subject_name varchar(255) NOT NULL,
            subject_code varchar(100) NOT NULL,
            subject_type varchar(100) DEFAULT 'core',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY school_id (school_id),
            KEY subject_code (subject_code)
        ) {$charset_collate};

        CREATE TABLE {$classes_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            school_id bigint(20) unsigned NOT NULL,
            class_name varchar(100) NOT NULL,
            arm varchar(50) DEFAULT '',
            class_level varchar(100) DEFAULT '',
            status varchar(50) DEFAULT 'active',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY school_id (school_id),
            KEY class_name (class_name)
        ) {$charset_collate};

        CREATE TABLE {$questions_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            school_id bigint(20) unsigned NOT NULL,
            subject varchar(255) NOT NULL,
            section varchar(100) DEFAULT '',
            passage_text longtext DEFAULT NULL,
            topic varchar(255) DEFAULT '',
            sub_topic varchar(255) DEFAULT '',
            class varchar(100) DEFAULT '',
            department varchar(100) DEFAULT '',
            difficulty varchar(50) DEFAULT '',
            learning_objective varchar(255) DEFAULT '',
            bloom_level varchar(100) DEFAULT '',
            examination_type varchar(100) DEFAULT '',
            examination_year varchar(10) DEFAULT '',
            question_type varchar(100) DEFAULT '',
            estimated_duration int(11) DEFAULT 0,
            marks decimal(8,2) DEFAULT 0.00,
            image_reference varchar(255) DEFAULT '',
            question_text longtext NOT NULL,
            options longtext DEFAULT NULL,
            answers longtext DEFAULT NULL,
            explanations longtext DEFAULT NULL,
            status varchar(50) DEFAULT 'draft',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY school_id (school_id),
            KEY subject (subject)
        ) {$charset_collate};

        CREATE TABLE {$exam_questions_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            school_id bigint(20) unsigned NOT NULL,
            exam_id bigint(20) unsigned NOT NULL,
            question_id bigint(20) unsigned NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY school_id (school_id),
            KEY exam_id (exam_id),
            KEY question_id (question_id)
        ) {$charset_collate};

        CREATE TABLE {$wpdb->prefix}educbt_exams (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            school_id bigint(20) unsigned NOT NULL,
            title varchar(255) NOT NULL,
            exam_type varchar(100) DEFAULT '',
            description longtext DEFAULT NULL,
            start_time datetime DEFAULT NULL,
            end_time datetime DEFAULT NULL,
            duration_minutes int(11) DEFAULT 0,
            is_published tinyint(1) DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY school_id (school_id)
        ) {$charset_collate};

        CREATE TABLE {$wpdb->prefix}educbt_exam_attempts (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            school_id bigint(20) unsigned NOT NULL,
            exam_id bigint(20) unsigned NOT NULL,
            student_id bigint(20) unsigned NOT NULL,
            session_key varchar(255) NOT NULL,
            question_order longtext DEFAULT NULL,
            randomize_options tinyint(1) DEFAULT 0,
            time_started datetime DEFAULT NULL,
            time_submitted datetime DEFAULT NULL,
            timer_seconds_remaining int(11) DEFAULT 0,
            extension_seconds int(11) NOT NULL DEFAULT 0,
            submit_reason varchar(30) DEFAULT '',
            status varchar(50) DEFAULT 'in_progress',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY school_id (school_id),
            KEY exam_id (exam_id),
            KEY student_id (student_id),
            KEY session_key (session_key),
            UNIQUE KEY session_unique (exam_id, student_id, session_key)
        ) {$charset_collate};

        CREATE TABLE {$wpdb->prefix}educbt_results (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            school_id bigint(20) unsigned NOT NULL,
            exam_id bigint(20) unsigned NOT NULL DEFAULT 0,
            exam_attempt_id bigint(20) unsigned DEFAULT NULL,
            student_id bigint(20) unsigned NOT NULL,
            term varchar(100) DEFAULT '',
            session_year varchar(100) DEFAULT '',
            subject varchar(255) DEFAULT '',
            score decimal(8,2) DEFAULT 0.00,
            grade varchar(10) DEFAULT '',
            remark varchar(255) DEFAULT '',
            student_responses longtext DEFAULT NULL,
            grading_scheme varchar(100) DEFAULT 'percentage',
            status varchar(100) DEFAULT 'draft',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY school_id (school_id),
            KEY exam_id (exam_id),
            KEY exam_attempt_id (exam_attempt_id),
            KEY student_id (student_id)
        ) {$charset_collate};

        CREATE TABLE {$wpdb->prefix}educbt_promotions (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            school_id bigint(20) unsigned NOT NULL,
            student_id bigint(20) unsigned NOT NULL,
            from_class varchar(100) DEFAULT '',
            to_class varchar(100) DEFAULT '',
            session_year varchar(100) DEFAULT '',
            status varchar(100) DEFAULT 'pending',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY school_id (school_id),
            KEY student_id (student_id)
        ) {$charset_collate};

        CREATE TABLE {$wpdb->prefix}educbt_transcripts (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            school_id bigint(20) unsigned NOT NULL,
            student_id bigint(20) unsigned NOT NULL,
            terms longtext DEFAULT NULL,
            sessions longtext DEFAULT NULL,
            summary longtext DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY school_id (school_id),
            KEY student_id (student_id)
        ) {$charset_collate};

        CREATE TABLE {$wpdb->prefix}educbt_audit_logs (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            school_id bigint(20) unsigned NOT NULL,
            user_id bigint(20) unsigned NOT NULL,
            action varchar(255) DEFAULT '',
            object_type varchar(100) DEFAULT '',
            object_id bigint(20) unsigned DEFAULT 0,
            previous_value longtext DEFAULT NULL,
            new_value longtext DEFAULT NULL,
            ip_address varchar(100) DEFAULT '',
            device varchar(255) DEFAULT '',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY school_id (school_id),
            KEY user_id (user_id)
        ) {$charset_collate};

        CREATE TABLE {$wpdb->prefix}educbt_licenses (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            school_id bigint(20) unsigned NOT NULL,
            license_key varchar(255) NOT NULL,
            license_type varchar(100) DEFAULT '',
            status varchar(100) DEFAULT 'active',
            issued_at datetime DEFAULT NULL,
            expires_at datetime DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY school_id (school_id),
            UNIQUE KEY license_key (license_key)
        ) {$charset_collate};

        CREATE TABLE {$wpdb->prefix}educbt_exam_timetables (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            school_id bigint(20) unsigned NOT NULL,
            exam_id bigint(20) unsigned NOT NULL,
            session_year varchar(100) DEFAULT '',
            term varchar(100) DEFAULT '',
            class_name varchar(100) DEFAULT '',
            arm varchar(50) DEFAULT '',
            department varchar(100) DEFAULT '',
            subject varchar(255) DEFAULT '',
            exam_type varchar(100) DEFAULT '',
            exam_date date DEFAULT NULL,
            start_time time DEFAULT NULL,
            end_time time DEFAULT NULL,
            duration_minutes int(11) DEFAULT 0,
            venue varchar(255) DEFAULT '',
            invigilator varchar(255) DEFAULT '',
            is_trial_mode tinyint(1) DEFAULT 0,
            status varchar(100) DEFAULT 'scheduled',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY school_id (school_id),
            KEY exam_id (exam_id),
            KEY class_name (class_name),
            KEY department (department),
            KEY exam_date (exam_date)
        ) {$charset_collate};

        CREATE TABLE {$wpdb->prefix}educbt_notifications (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            school_id bigint(20) unsigned NOT NULL,
            user_id bigint(20) unsigned NOT NULL,
            type varchar(100) DEFAULT '',
            title varchar(255) DEFAULT '',
            body longtext DEFAULT NULL,
            link varchar(255) DEFAULT '',
            payload longtext DEFAULT NULL,
            is_read tinyint(1) NOT NULL DEFAULT 0,
            is_flagged tinyint(1) NOT NULL DEFAULT 0,
            read_at datetime DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY school_id (school_id),
            KEY user_id (user_id),
            KEY is_read (is_read),
            KEY created_at (created_at)
        ) {$charset_collate};

        CREATE TABLE {$wpdb->prefix}educbt_exam_integrity_events (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            school_id bigint(20) unsigned NOT NULL,
            attempt_id bigint(20) unsigned NOT NULL,
            exam_id bigint(20) unsigned NOT NULL,
            student_id bigint(20) unsigned NOT NULL,
            event_type varchar(100) NOT NULL,
            event_payload longtext DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY school_id (school_id),
            KEY attempt_id (attempt_id),
            KEY exam_id (exam_id),
            KEY student_id (student_id),
            KEY event_type (event_type)
        ) {$charset_collate};";

        dbDelta( $sql );

        $attempts_table = $wpdb->prefix . 'educbt_exam_attempts';
        $this->ensure_column( $attempts_table, 'extension_seconds', "ALTER TABLE {$attempts_table} ADD COLUMN extension_seconds int(11) NOT NULL DEFAULT 0 AFTER timer_seconds_remaining" );
        $this->ensure_column( $attempts_table, 'submit_reason', "ALTER TABLE {$attempts_table} ADD COLUMN submit_reason varchar(30) DEFAULT '' AFTER extension_seconds" );
        // Every column the school screens read.
        //
        // A table created by an early version can be missing any of these, and a
        // SELECT naming a column that does not exist returns NOTHING AT ALL — so the
        // schools list appeared empty, the settings form rendered blank, and the
        // portal header fell back to the word "School", all from one absent column.
        foreach ( [
            'logo'           => "ALTER TABLE {$schools_table} ADD COLUMN logo varchar(255) NOT NULL DEFAULT ''",
            'address'        => "ALTER TABLE {$schools_table} ADD COLUMN address text DEFAULT NULL",
            'phone'          => "ALTER TABLE {$schools_table} ADD COLUMN phone varchar(100) NOT NULL DEFAULT ''",
            'email'          => "ALTER TABLE {$schools_table} ADD COLUMN email varchar(255) NOT NULL DEFAULT ''",
            'website'        => "ALTER TABLE {$schools_table} ADD COLUMN website varchar(255) NOT NULL DEFAULT ''",
            'principal_name' => "ALTER TABLE {$schools_table} ADD COLUMN principal_name varchar(255) NOT NULL DEFAULT ''",
            'created_at'     => "ALTER TABLE {$schools_table} ADD COLUMN created_at datetime DEFAULT CURRENT_TIMESTAMP",
        ] as $column => $alter ) {
            $this->ensure_column( $schools_table, $column, $alter );
        }


        $this->ensure_column( $schools_table, 'subdomain', "ALTER TABLE {$schools_table} ADD COLUMN subdomain varchar(63) DEFAULT '' AFTER school_code" );
        $this->ensure_column( $schools_table, 'custom_domain', "ALTER TABLE {$schools_table} ADD COLUMN custom_domain varchar(191) DEFAULT '' AFTER subdomain" );
        $this->ensure_column( $schools_table, 'academic_settings', "ALTER TABLE {$schools_table} ADD COLUMN academic_settings longtext DEFAULT NULL" );
        $this->ensure_column( $schools_table, 'report_settings', "ALTER TABLE {$schools_table} ADD COLUMN report_settings longtext DEFAULT NULL" );

        $this->ensure_column( $teachers_table, 'teacher_group', "ALTER TABLE {$teachers_table} ADD COLUMN teacher_group varchar(100) DEFAULT '' AFTER full_name" );
        $this->ensure_column( $students_table, 'wp_user_id', "ALTER TABLE {$students_table} ADD COLUMN wp_user_id bigint(20) unsigned DEFAULT NULL AFTER student_id" );
        $this->ensure_column( $students_table, 'login_username', "ALTER TABLE {$students_table} ADD COLUMN login_username varchar(100) DEFAULT '' AFTER wp_user_id" );
                // Columns the registration service writes but the v1 table never had. A
        // missing column makes $wpdb->insert() fail as a warning rather than an
        // exception, so the STUDENT row was silently dropped while the enrolment row
        // was still created — which is why a class showed a headcount but the student
        // list and the overview showed nobody.
        $this->ensure_column( $students_table, 'admission_number', "ALTER TABLE {$students_table} ADD COLUMN admission_number varchar(100) NOT NULL DEFAULT ''" );
        $this->ensure_column( $students_table, 'student_id', "ALTER TABLE {$students_table} ADD COLUMN student_id varchar(100) NOT NULL DEFAULT ''" );
        $this->ensure_column( $students_table, 'gender', "ALTER TABLE {$students_table} ADD COLUMN gender varchar(20) NOT NULL DEFAULT ''" );
        $this->ensure_column( $students_table, 'date_of_birth', "ALTER TABLE {$students_table} ADD COLUMN date_of_birth varchar(20) NOT NULL DEFAULT ''" );
        $this->ensure_column( $students_table, 'passport_photo', "ALTER TABLE {$students_table} ADD COLUMN passport_photo varchar(255) NOT NULL DEFAULT ''" );
        $this->ensure_column( $students_table, 'status', "ALTER TABLE {$students_table} ADD COLUMN status varchar(20) NOT NULL DEFAULT 'active'" );

$this->ensure_column( $students_table, 'registration_number', "ALTER TABLE {$students_table} ADD COLUMN registration_number varchar(100) NOT NULL DEFAULT '' AFTER admission_number" );
        $this->ensure_column( $students_table, 'first_name', "ALTER TABLE {$students_table} ADD COLUMN first_name varchar(150) DEFAULT '' AFTER passport_photo" );
        $this->ensure_column( $students_table, 'last_name', "ALTER TABLE {$students_table} ADD COLUMN last_name varchar(150) DEFAULT '' AFTER first_name" );
        $this->ensure_column( $students_table, 'parent_phone', "ALTER TABLE {$students_table} ADD COLUMN parent_phone varchar(100) DEFAULT '' AFTER parent_information" );
        $this->ensure_column( $students_table, 'parent_email', "ALTER TABLE {$students_table} ADD COLUMN parent_email varchar(255) DEFAULT '' AFTER parent_phone" );
        $this->ensure_column( $students_table, 'department', "ALTER TABLE {$students_table} ADD COLUMN department varchar(100) DEFAULT '' AFTER arm" );
        $this->ensure_column( $students_table, 'subject_bundle', "ALTER TABLE {$students_table} ADD COLUMN subject_bundle longtext DEFAULT NULL AFTER session_year" );
        $this->ensure_column( $questions_table, 'section', "ALTER TABLE {$questions_table} ADD COLUMN section varchar(100) DEFAULT '' AFTER subject" );
        $this->ensure_column( $questions_table, 'passage_text', "ALTER TABLE {$questions_table} ADD COLUMN passage_text longtext DEFAULT NULL AFTER section" );
        $this->ensure_column( $questions_table, 'sub_topic', "ALTER TABLE {$questions_table} ADD COLUMN sub_topic varchar(255) DEFAULT '' AFTER topic" );
        $this->ensure_column( $questions_table, 'department', "ALTER TABLE {$questions_table} ADD COLUMN department varchar(100) DEFAULT '' AFTER class" );
        $this->ensure_column( $questions_table, 'learning_objective', "ALTER TABLE {$questions_table} ADD COLUMN learning_objective varchar(255) DEFAULT '' AFTER difficulty" );
        $this->ensure_column( $questions_table, 'bloom_level', "ALTER TABLE {$questions_table} ADD COLUMN bloom_level varchar(100) DEFAULT '' AFTER learning_objective" );
        $this->ensure_column( $questions_table, 'examination_type', "ALTER TABLE {$questions_table} ADD COLUMN examination_type varchar(100) DEFAULT '' AFTER bloom_level" );
        $this->ensure_column( $questions_table, 'examination_year', "ALTER TABLE {$questions_table} ADD COLUMN examination_year varchar(10) DEFAULT '' AFTER examination_type" );
        $this->ensure_column( $questions_table, 'estimated_duration', "ALTER TABLE {$questions_table} ADD COLUMN estimated_duration int(11) DEFAULT 0 AFTER question_type" );
        $this->ensure_column( $questions_table, 'marks', "ALTER TABLE {$questions_table} ADD COLUMN marks decimal(8,2) DEFAULT 0.00 AFTER estimated_duration" );
        // The v1 questions table keys the subject by NAME, not by id. Phase 1 moved
        // to real ids, so the column has to be added before anything references it.
        // The v1 transcripts table stored a JSON blob (terms/sessions/summary) and had
        // no serial, purpose, checksum or status. Two conflicting CREATE TABLE
        // definitions then ran against the same table name, and the v2 columns were
        // never added — so the transcripts screen queried a column that did not exist.
        $transcripts = $wpdb->prefix . 'educbt_transcripts';

        foreach ( [
            'serial'    => "ALTER TABLE {$transcripts} ADD COLUMN serial varchar(60) NOT NULL DEFAULT ''",
            'purpose'   => "ALTER TABLE {$transcripts} ADD COLUMN purpose varchar(255) NOT NULL DEFAULT ''",
            'checksum'  => "ALTER TABLE {$transcripts} ADD COLUMN checksum varchar(32) NOT NULL DEFAULT ''",
            'issued_by' => "ALTER TABLE {$transcripts} ADD COLUMN issued_by bigint(20) unsigned DEFAULT NULL",
            'issued_at' => "ALTER TABLE {$transcripts} ADD COLUMN issued_at datetime DEFAULT NULL",
            'status'    => "ALTER TABLE {$transcripts} ADD COLUMN status varchar(20) NOT NULL DEFAULT 'issued'",
        ] as $column => $alter ) {
            $this->ensure_column( $transcripts, $column, $alter );
        }

        // A blank serial on legacy rows would collide under a UNIQUE index, so the
        // index is only added once those rows carry something distinct.
        $wpdb->query(
            "UPDATE {$transcripts} SET serial = CONCAT('LEGACY-', id) WHERE serial = '' OR serial IS NULL"
        );

        $this->ensure_index( $transcripts, 'serial', "ALTER TABLE {$transcripts} ADD UNIQUE KEY serial (serial)" );

        // The v1 table calls it `class`; the service writes `class_level`. Every
        // question insert failed with "Unknown column 'class_level'" — and because the
        // failure was a database warning rather than an exception, the import reported
        // success while saving nothing.
        // Existing senior classes were created before the department was part of the
        // display name, so SS1 Science and SS1 Commercial both read "SS1 A".
        $classes_tbl = Schema::table( 'classes' );
        $depts_tbl   = Schema::table( 'departments' );

        $wpdb->query(
            "UPDATE {$classes_tbl} c
             INNER JOIN {$depts_tbl} d ON d.id = c.department_id
             SET c.display_name = CONCAT(c.display_name, ' ', d.name)
             WHERE c.department_id IS NOT NULL
               AND c.display_name NOT LIKE CONCAT('%', d.name)"
        );

        // Approval workflow. Existing questions default to 'approved' so a school
        // that already has a bank is not suddenly unable to set a paper from it —
        // the gate applies to new work, not retrospectively.
        $this->ensure_column( $questions_table, 'approval_status', "ALTER TABLE {$questions_table} ADD COLUMN approval_status varchar(20) NOT NULL DEFAULT 'approved'" );
        $this->ensure_column( $questions_table, 'review_note', "ALTER TABLE {$questions_table} ADD COLUMN review_note text DEFAULT NULL" );
        $this->ensure_column( $questions_table, 'reviewed_by', "ALTER TABLE {$questions_table} ADD COLUMN reviewed_by bigint(20) unsigned DEFAULT NULL" );
        $this->ensure_column( $questions_table, 'reviewed_at', "ALTER TABLE {$questions_table} ADD COLUMN reviewed_at datetime DEFAULT NULL" );
        $this->ensure_column( $questions_table, 'created_by_staff', "ALTER TABLE {$questions_table} ADD COLUMN created_by_staff bigint(20) unsigned DEFAULT NULL" );
        $this->ensure_column( $questions_table, 'created_at', "ALTER TABLE {$questions_table} ADD COLUMN created_at datetime DEFAULT CURRENT_TIMESTAMP" );

        $this->ensure_column( $questions_table, 'class_level', "ALTER TABLE {$questions_table} ADD COLUMN class_level varchar(100) NOT NULL DEFAULT ''" );
        $this->ensure_column( $questions_table, 'topic', "ALTER TABLE {$questions_table} ADD COLUMN topic varchar(191) NOT NULL DEFAULT ''" );
        $this->ensure_column( $questions_table, 'difficulty', "ALTER TABLE {$questions_table} ADD COLUMN difficulty varchar(30) NOT NULL DEFAULT 'medium'" );
        $this->ensure_column( $questions_table, 'question_type', "ALTER TABLE {$questions_table} ADD COLUMN question_type varchar(30) NOT NULL DEFAULT 'single_choice'" );
        $this->ensure_column( $questions_table, 'subject', "ALTER TABLE {$questions_table} ADD COLUMN subject varchar(191) NOT NULL DEFAULT ''" );
        $this->ensure_column( $questions_table, 'explanations', "ALTER TABLE {$questions_table} ADD COLUMN explanations longtext DEFAULT NULL" );

        $this->ensure_column( $questions_table, 'subject_id', "ALTER TABLE {$questions_table} ADD COLUMN subject_id bigint(20) unsigned DEFAULT NULL" );

        // Deliberately no AFTER clause. Column order is cosmetic, and naming a
        // neighbour couples this migration to a column that may not exist on an
        // older install — which is exactly what broke here.
        $this->ensure_column( $questions_table, 'passage_id', "ALTER TABLE {$questions_table} ADD COLUMN passage_id bigint(20) unsigned DEFAULT NULL" );
        $this->ensure_column( $questions_table, 'passage_position', "ALTER TABLE {$questions_table} ADD COLUMN passage_position int(11) NOT NULL DEFAULT 0" );

        $this->ensure_column( $questions_table, 'image_reference', "ALTER TABLE {$questions_table} ADD COLUMN image_reference varchar(255) DEFAULT '' AFTER marks" );
        $this->ensure_column( $questions_table, 'status', "ALTER TABLE {$questions_table} ADD COLUMN status varchar(50) DEFAULT 'draft' AFTER explanations" );

        // Deliberately last. This index names subject_id, so it can only be created
        // once that column exists — it used to run further up and failed silently on
        // every install, leaving the approval queue doing a full table scan.
        $this->ensure_index( $questions_table, 'approval', "ALTER TABLE {$questions_table} ADD KEY approval (school_id,subject_id,approval_status)" );
    }

    public function apply_tenant_filter( $query ) {
        if ( is_admin() || defined( 'REST_REQUEST' ) ) {
            return $query;
        }

        return $query;
    }

    /**
     * Return a prepared WHERE fragment for tenant-scoped queries.
     * Repositories should use this when building WHERE clauses.
     */
    public function tenant_where( string $alias = '' ): string {
        global $wpdb;

        $school_id = $this->get_school_id() ?? 0;
        if ( empty( $school_id ) ) {
            return '1=1';
        }

        $prefix = $alias !== '' ? esc_sql( $alias ) . '.' : '';
        return $wpdb->prepare( $prefix . 'school_id = %d', $school_id );
    }

    private function column_exists( string $table, string $column ): bool {
        global $wpdb;

        $cache_key = $table . ':' . $column;
        if ( isset( self::$column_cache[ $cache_key ] ) ) {
            return self::$column_cache[ $cache_key ];
        }

        $found = $wpdb->get_var(
            $wpdb->prepare( 'SHOW COLUMNS FROM `' . esc_sql( $table ) . '` LIKE %s', $column )
        );

        self::$column_cache[ $cache_key ] = ( $found !== null );
        return self::$column_cache[ $cache_key ];
    }

    /** @var array<string,bool> */
    private static array $column_cache = [];

    /**
     * Add an index only when it is missing. Re-running ALTER ADD KEY errors, and an
     * error during activation is printed to the page.
     */
    private function ensure_index( string $table, string $index, string $alter ): void {
        global $wpdb;

        $exists = $wpdb->get_var(
            $wpdb->prepare( "SHOW INDEX FROM `{$table}` WHERE Key_name = %s", $index )
        );

        if ( $exists === null ) {
            $wpdb->query( $alter );
        }
    }

    private function ensure_column( string $table, string $column, string $alter_sql ): void {
        global $wpdb;

        $exists = $wpdb->get_var(
            $wpdb->prepare(
                'SHOW COLUMNS FROM ' . $table . ' LIKE %s',
                $column
            )
        );

        if ( $exists === null ) {
            $wpdb->query( $alter_sql );
        }
    }
}
