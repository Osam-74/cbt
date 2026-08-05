<?php

namespace EduCBTPro\Frontend;

use EduCBTPro\Core\Repository\StudentRepository;
use EduCBTPro\Services\ExamService;
use EduCBTPro\Services\ExamAttemptService;
use EduCBTPro\Services\ExamTimetableService;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Student Portal — frontend login + dashboard + exam start.
 *
 * Shortcodes:
 *   [educbt_login]      — Registration number + password (surname) login form
 *   [educbt_dashboard]  — Student dashboard with available exams
 */
class StudentPortal {

    public static function register(): void {
        add_shortcode( 'educbt_login', [ __CLASS__, 'render_login' ] );
        add_shortcode( 'educbt_dashboard', [ __CLASS__, 'render_dashboard' ] );
        add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
        add_action( 'template_redirect', [ __CLASS__, 'handle_login_submit' ] );
        add_action( 'template_redirect', [ __CLASS__, 'handle_logout' ] );
    }

    public static function enqueue_assets(): void {
        wp_enqueue_style(
            'educbt-student-portal',
            plugins_url( 'assets/css/student-portal.css', EDUCBT_PRO_FILE ),
            [],
            '2.1.0'
        );
    }

    // ===== LOGIN =====

    public static function render_login( $atts = [] ): string {
        // If already logged in, redirect to dashboard
        if ( is_user_logged_in() && self::is_student() ) {
            $dashboard_url = self::get_dashboard_url();
            if ( $dashboard_url ) {
                return '<script>window.location.href="' . esc_js( $dashboard_url ) . '";</script>';
            }
        }

        $err_key = 'educbt_login_err_' . md5( (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ) . (string) ( $_POST['reg_number'] ?? '' ) );
        $error = get_transient( $err_key );
        if ( $error ) {
            delete_transient( $err_key );
        }

        ob_start();
        ?>
        <div class="educbt-login-wrap">
            <div class="educbt-login-card">
                <div class="educbt-login-header">
                    <h2>CBT Exam Portal</h2>
                    <p>Enter your admission number and password to begin</p>
                </div>

                <?php if ( $error ): ?>
                    <div class="educbt-login-error"><?php echo esc_html( $error ); ?></div>
                <?php endif; ?>

                <form method="post" action="" class="educbt-login-form">
                    <?php wp_nonce_field( 'educbt_student_login', 'educbt_login_nonce' ); ?>
                    <input type="hidden" name="educbt_action" value="student_login">

                    <div class="educbt-form-group">
                        <label for="reg_number">Admission Number</label>
                        <input type="text" id="reg_number" name="reg_number"
                               placeholder="e.g. DEMO/2024/001" required autocomplete="username">
                    </div>

                    <div class="educbt-form-group">
                        <label for="password">Password</label>
                        <input type="password" id="password" name="password"
                               placeholder="Your surname" required autocomplete="current-password">
                    </div>

                    <button type="submit" class="educbt-login-btn">Sign In</button>
                </form>

                <div class="educbt-login-hint">
                    <p><strong>Demo credentials:</strong></p>
                    <p>Admission No: <code>DEMO/2024/001</code> &nbsp; Password: <code>student</code> (your surname, lowercase, no spaces)</p>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    public static function handle_login_submit(): void {
        if ( ! isset( $_POST['educbt_action'] ) || $_POST['educbt_action'] !== 'student_login' ) {
            return;
        }
        if ( ! isset( $_POST['educbt_login_nonce'] ) || ! wp_verify_nonce( $_POST['educbt_login_nonce'], 'educbt_student_login' ) ) {
            return;
        }

        $reg_number = sanitize_text_field( wp_unslash( $_POST['reg_number'] ?? '' ) );
        $password = wp_unslash( $_POST['password'] ?? '' );

        // Use a session-stable key for error transients — get_current_user_id()
        // returns 0 for all logged-out visitors, so all students shared the same
        // error slot and could see each other's messages.
        $err_key = 'educbt_login_err_' . md5( (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ) . (string) ( $_POST['reg_number'] ?? '' ) );

        if ( empty( $reg_number ) || empty( $password ) ) {
            set_transient( $err_key, 'Please fill in all fields.', 60 );
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'educbt_students';

        // Match by admission_number OR registration_number, case-insensitive.
        // Both fields are set to the same value at registration, but older records
        // imported from v1 may only have admission_number populated.
        $student = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table}
                 WHERE admission_number = %s OR registration_number = %s
                 LIMIT 1",
                $reg_number, $reg_number
            ),
            ARRAY_A
        );

        if ( ! $student ) {
            set_transient( $err_key, 'Invalid admission number. Check your slip and try again.', 60 );
            return;
        }

        // Password = normalised surname, the same way provision_login() built it.
        // The old code compared against the raw surname, so "O'Brien" (login) did
        // not match "obrien" (stored hash) — every student with an apostrophe,
        // hyphen or space in their surname was locked out.
        $expected = self::normalise_password( (string) ( $student['last_name'] ?? '' ) );
        $given    = self::normalise_password( $password );

        if ( $given !== $expected ) {
            set_transient( $err_key, 'Invalid password. Your password is your surname in lowercase, with no spaces.', 60 );
            return;
        }

        // Check student status
        $status = strtolower( trim( $student['status'] ?? 'active' ) );
        if ( in_array( $status, [ 'inactive', 'disabled', 'suspended', 'blocked' ], true ) ) {
            set_transient( $err_key, 'Account inactive. Contact your administrator.', 60 );
            return;
        }

        $wp_user_id = absint( $student['wp_user_id'] ?? 0 );
        if ( $wp_user_id <= 0 ) {
            set_transient( $err_key, 'Account not linked to a login user. Contact admin.', 60 );
            return;
        }

        // PHASE 0 SECURITY FIX: no tenant cookie is written. The school is derived
        // server-side from this student's own record on every request, so a forged
        // or copied cookie cannot move a session into another school.

        // Create WordPress session
        wp_set_current_user( $wp_user_id );
        wp_set_auth_cookie( $wp_user_id, true );
        do_action( 'wp_login', $student['login_username'] ?? $reg_number, get_user_by( 'ID', $wp_user_id ) );

        // Redirect to dashboard
        $dashboard_url = self::get_dashboard_url();
        if ( $dashboard_url ) {
            wp_safe_redirect( $dashboard_url );
            exit;
        }
    }

    /**
     * Normalise a password the same way StudentRegistrationService::initial_password()
     * does: lowercase, trim, strip everything that is not a-z0-9. This must stay
     * in sync with that method or the login check will never match the stored hash.
     */
    private static function normalise_password( string $value ): string {
        $value = strtolower( trim( $value ) );
        $value = (string) preg_replace( '/[^a-z0-9]/', '', $value );

        if ( strlen( $value ) < 4 ) {
            $value = str_pad( $value, 4, '0' );
        }

        return $value;
    }

    public static function handle_logout(): void {
        if ( ! isset( $_GET['educbt_action'] ) || $_GET['educbt_action'] !== 'logout' ) {
            return;
        }
        wp_logout();
        $login_url = self::get_login_url();
        if ( $login_url ) {
            wp_safe_redirect( $login_url );
            exit;
        }
        wp_safe_redirect( home_url() );
        exit;
    }

    // ===== DASHBOARD =====

    public static function render_dashboard( $atts = [] ): string {
        if ( ! is_user_logged_in() || ! self::is_student() ) {
            $login_url = self::get_login_url();
            if ( $login_url ) {
                return '<script>window.location.href="' . esc_js( $login_url ) . '";</script>';
            }
            return '<p>Please <a href="' . esc_url( $login_url ?: home_url() ) . '">log in</a> to access your dashboard.</p>';
        }

        $current_user = wp_get_current_user();
        $student = self::get_current_student();
        if ( ! $student ) {
            return '<p>Student record not found. Contact your administrator.</p>';
        }

        $school_id = absint( $student['school_id'] ?? 0 );

        // Handle exam start
        if ( isset( $_GET['start_exam'] ) ) {
            $exam_id = absint( $_GET['start_exam'] );
            return self::start_exam( $school_id, $exam_id, $student );
        }

        // Get available exams
        $exam_service = new ExamService();
        $exams = $exam_service->list_exams( $school_id );

        // Get active attempts
        $attempt_service = new ExamAttemptService();
        $active_attempts = [];
        $student_id = absint( $student['id'] );

        foreach ( $exams as $exam ) {
            $exam_id = absint( $exam['id'] ?? 0 );
            if ( $exam_id <= 0 ) continue;
            $active = $attempt_service->get_active_attempt( $school_id, $exam_id, $student_id );
            if ( $active ) {
                $active_attempts[ $exam_id ] = $active;
            }
        }

        ob_start();
        ?>
        <div class="educbt-dashboard-wrap">
            <div class="educbt-dashboard-header">
                <div>
                    <h2>Welcome, <?php echo esc_html( $student['first_name'] ?? 'Student' ); ?>!</h2>
                    <p><?php echo esc_html( $student['full_name'] ?? '' ); ?> &middot; <?php echo esc_html( $student['registration_number'] ?? '' ); ?></p>
                </div>
                <a href="?educbt_action=logout" class="educbt-logout-btn">Logout</a>
            </div>

            <div class="educbt-dashboard-section">
                <h3>Available Exams</h3>

                <?php if ( empty( $exams ) ): ?>
                    <div class="educbt-no-exams">
                        <p>No exams available at this time. Check back later.</p>
                    </div>
                <?php else: ?>
                    <div class="educbt-exam-list">
                        <?php foreach ( $exams as $exam ):
                            $exam_id = absint( $exam['id'] ?? 0 );
                            $is_published = ! empty( $exam['is_published'] );
                            $active = $active_attempts[ $exam_id ] ?? null;
                            $duration = absint( $exam['duration_minutes'] ?? 0 );

                            if ( ! $is_published ) continue;
                        ?>
                            <div class="educbt-exam-card <?php echo $active ? 'has-active-attempt' : ''; ?>">
                                <div class="educbt-exam-card-info">
                                    <h4><?php echo esc_html( $exam['title'] ?? 'Untitled Exam' ); ?></h4>
                                    <div class="educbt-exam-meta">
                                        <span>⏱ <?php echo esc_html( (string) $duration ); ?> minutes</span>
                                        <?php if ( $exam['exam_type'] ): ?>
                                            <span><?php echo esc_html( $exam['exam_type'] ); ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ( $exam['description'] ): ?>
                                        <p class="educbt-exam-desc"><?php echo esc_html( $exam['description'] ); ?></p>
                                    <?php endif; ?>
                                </div>
                                <div class="educbt-exam-card-action">
                                    <?php if ( $active ): ?>
                                        <a href="<?php echo esc_url( self::get_exam_portal_url( absint( $active['id'] ) ) ); ?>"
                                           class="educbt-btn-primary">Resume Exam</a>
                                    <?php else: ?>
                                        <a href="?start_exam=<?php echo esc_attr( (string) $exam_id ); ?>"
                                           class="educbt-btn-primary">Start Exam</a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    private static function start_exam( int $school_id, int $exam_id, array $student ): string {
        $student_id = absint( $student['id'] ?? 0 );
        if ( $school_id <= 0 || $exam_id <= 0 || $student_id <= 0 ) {
            return '<p>Invalid exam or student. Please contact your administrator.</p>';
        }

        $attempt_service = new ExamAttemptService();

        // Check for existing active attempt
        $existing = $attempt_service->get_active_attempt( $school_id, $exam_id, $student_id );
        if ( $existing ) {
            // Resume existing attempt
            $url = self::get_exam_portal_url( absint( $existing['id'] ) );
            if ( $url ) {
                return '<script>window.location.href="' . esc_js( $url ) . '";</script>';
            }
        }

        // Create new attempt
        $attempt_id = $attempt_service->create_attempt( $school_id, $exam_id, $student_id, [
            'randomize_questions' => false,
            'randomize_options'   => 0,
        ] );

        if ( $attempt_id > 0 ) {
            $url = self::get_exam_portal_url( $attempt_id );
            if ( $url ) {
                return '<script>window.location.href="' . esc_js( $url ) . '";</script>';
            }
            return '<p>Exam started! Attempt ID: ' . esc_html( (string) $attempt_id ) . '</p>';
        }

        return '<p>Failed to start exam. You may have an active session already, or the exam has no questions assigned. Please contact your administrator.</p>';
    }

    // ===== HELPERS =====

    private static function is_student(): bool {
        return current_user_can( 'educbt_student_portal' ) && ! current_user_can( 'manage_options' );
    }

    private static function get_current_student(): ?array {
        if ( ! is_user_logged_in() ) {
            return null;
        }

        $user_id = get_current_user_id();
        global $wpdb;
        $table = $wpdb->prefix . 'educbt_students';
        $student = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE wp_user_id = %d LIMIT 1",
                $user_id
            ),
            ARRAY_A
        );
        return $student ?: null;
    }

    private static function get_login_url(): string {
        $page_id = absint( get_option( 'educbt_page_id_login', 0 ) );
        if ( $page_id > 0 ) {
            $url = get_permalink( $page_id );
            if ( $url && ! is_wp_error( $url ) ) {
                return $url;
            }
        }
        return home_url( '/cbt-login/' );
    }

    private static function get_dashboard_url(): string {
        $page_id = absint( get_option( 'educbt_page_id_dashboard', 0 ) );
        if ( $page_id > 0 ) {
            $url = get_permalink( $page_id );
            if ( $url && ! is_wp_error( $url ) ) {
                return $url;
            }
        }
        return home_url( '/cbt-dashboard/' );
    }

    private static function get_exam_portal_url( int $attempt_id ): string {
        $page_id = absint( get_option( 'educbt_page_id_exam', 0 ) );
        if ( $page_id > 0 ) {
            $url = get_permalink( $page_id );
            if ( $url && ! is_wp_error( $url ) ) {
                return add_query_arg( 'attempt_id', $attempt_id, $url );
            }
        }
        return add_query_arg( 'attempt_id', $attempt_id, home_url( '/cbt-exam/' ) );
    }
}
