<?php

namespace EduCBTPro\Services;

use EduCBTPro\Core\EventDispatcher;
use EduCBTPro\Core\Schema;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * PHASE 9 — notifications.
 *
 * Three things were conflated in v1, and separating them is most of the work:
 *
 *   NOTIFICATION   system-generated, per-user, in-app. "Your result is published."
 *                  Nobody wrote it; the system emitted it.
 *   ANNOUNCEMENT   human-authored broadcast to an audience. "Resumption is 8 Jan."
 *   MESSAGE        a threaded conversation between two people.
 *
 * They differ in who creates them, who receives them, and whether a reply makes
 * sense. Putting them in one table means every read has to filter by type and every
 * feature grows an `if`.
 *
 * The `educbt_notifications` table was queried in six places in v1 and NEVER
 * CREATED — the whole feature was dead on arrival. Phase 0 created it; this makes
 * it work.
 */
class NotificationService {

    // System notification types. Each one is emitted from a specific place, so a
    // stray string cannot invent a type nobody renders.
    public const RESULT_PUBLISHED   = 'result_published';
    public const EXAM_SCHEDULED     = 'exam_scheduled';
    public const EXAM_STARTING      = 'exam_starting';
    public const SCORE_SUBMITTED    = 'score_submitted';
    public const PROMOTION_APPROVED = 'promotion_approved';
    public const GUARDIAN_INVITE    = 'guardian_invite';
    public const PASSWORD_RESET     = 'password_reset';
    public const ANNOUNCEMENT       = 'announcement';
    public const MESSAGE_RECEIVED   = 'message_received';
    public const EXAM_PREP_OPENED    = 'exam_prep_opened';
    public const QUESTION_SUBMITTED  = 'question_submitted';
    public const QUESTION_WITHDRAWN = 'question_withdrawn';
    public const QUESTION_DELETED    = 'question_set_deleted';
    public const RESULT_APPROVED     = 'result_approved';

    /**
     * @return array<string,string>
     */
    public static function types(): array {
        return [
            self::RESULT_PUBLISHED   => 'Result published',
            self::EXAM_SCHEDULED     => 'Exam scheduled',
            self::EXAM_STARTING      => 'Exam starting soon',
            self::SCORE_SUBMITTED    => 'Scores submitted',
            self::PROMOTION_APPROVED => 'Promotion approved',
            self::GUARDIAN_INVITE    => 'Guardian invitation',
            self::PASSWORD_RESET     => 'Password reset',
            self::ANNOUNCEMENT       => 'Announcement',
            self::MESSAGE_RECEIVED   => 'New message',
            self::EXAM_PREP_OPENED    => 'Exam prep opened',
            self::QUESTION_SUBMITTED  => 'Questions submitted',
            self::QUESTION_WITHDRAWN => 'Submission withdrawn',
            self::QUESTION_DELETED    => 'Draft set deleted',
            self::RESULT_APPROVED     => 'Results approved',
        ];
    }

    /**
     * Notify one user.
     *
     * @return int notification id, or 0
     */
    /**
     * Where a notification should take someone when they open it.
     *
     * Every notice previously landed on the question bank, whatever it was about,
     * because that was the only link ever passed. A notification that does not take
     * you to the thing it is about is barely a notification.
     */
    public static function default_link( string $type ): string {
        $map = [
            self::RESULT_PUBLISHED   => '/portal/student/results/',
            self::EXAM_SCHEDULED     => '/portal/student/timetable/',
            self::EXAM_STARTING      => '/portal/student/',
            self::SCORE_SUBMITTED    => '/portal/exams/questions/',
            self::PROMOTION_APPROVED => '/portal/student/',
            self::MESSAGE_RECEIVED   => '/portal/account/notifications/',
            self::ANNOUNCEMENT       => '/portal/account/notifications/',
            self::EXAM_PREP_OPENED    => '/portal/exams/questions/',
            self::QUESTION_SUBMITTED  => '/portal/exams/questions/',
            self::QUESTION_WITHDRAWN => '/portal/exams/questions/',
            self::QUESTION_DELETED    => '/portal/exams/questions/',
            self::RESULT_APPROVED     => '/portal/teacher/results/',
        ];

        return home_url( $map[ $type ] ?? '/portal/account/notifications/' );
    }

    public function notify( int $school_id, int $user_id, string $type, string $title, string $body = '', string $link = '', array $payload = [] ): int {
        if ( $link === '' ) {
            $link = self::default_link( $type );
        }

        if ( $user_id <= 0 || ! array_key_exists( $type, self::types() ) ) {
            return 0;
        }

        global $wpdb;

        $wpdb->insert(
            Schema::table( 'notifications' ),
            [
                'school_id'  => $school_id,
                'user_id'    => $user_id,
                'type'       => $type,
                'title'      => sanitize_text_field( $title ),
                'body'       => wp_kses_post( $body ),
                'link'       => esc_url_raw( $link ),
                'payload'    => (string) wp_json_encode( $payload ),
                'is_read'    => 0,
                'created_at' => current_time( 'mysql', true ),
            ],
            [ '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s' ]
        );

        $id = absint( $wpdb->insert_id );

        if ( $id > 0 ) {
            $this->maybe_queue_email( $school_id, $user_id, $type, $title, $body, $link );
        }

        return $id;
    }

    /**
     * Notify many users with the same content.
     *
     * One multi-row INSERT rather than 500 single ones. Publishing results for a
     * whole school fires several hundred notifications at once, and a per-user
     * insert loop is the difference between a click and a timeout.
     *
     * @param array<int,int> $user_ids
     */
    public function notify_many( int $school_id, array $user_ids, string $type, string $title, string $body = '', string $link = '' ): int {
        if ( $link === '' ) {
            $link = self::default_link( $type );
        }

        $user_ids = array_values( array_unique( array_filter( array_map( 'absint', $user_ids ) ) ) );

        if ( empty( $user_ids ) || ! array_key_exists( $type, self::types() ) ) {
            return 0;
        }

        global $wpdb;

        $table   = Schema::table( 'notifications' );
        $now     = current_time( 'mysql', true );
        $title   = sanitize_text_field( $title );
        $body    = wp_kses_post( $body );
        $link    = esc_url_raw( $link );
        $created = 0;

        // Chunked so a very large audience cannot exceed max_allowed_packet.
        foreach ( array_chunk( $user_ids, 200 ) as $chunk ) {
            $values = [];
            $args   = [];

            foreach ( $chunk as $user_id ) {
                $values[] = '(%d, %d, %s, %s, %s, %s, 0, %s)';
                array_push( $args, $school_id, $user_id, $type, $title, $body, $link, $now );
            }

            $wpdb->query(
                $wpdb->prepare(
                    "INSERT INTO {$table} (school_id, user_id, type, title, body, link, is_read, created_at)
                     VALUES " . implode( ', ', $values ),
                    $args
                )
            );

            $created += count( $chunk );
        }

        foreach ( $user_ids as $user_id ) {
            $this->maybe_queue_email( $school_id, $user_id, $type, $title, $body, $link );
        }

        return $created;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function inbox( int $school_id, int $user_id, bool $unread_only = false, int $limit = 30 ): array {
        global $wpdb;

        $table = Schema::table( 'notifications' );

        $sql    = "SELECT * FROM {$table} WHERE school_id = %d AND user_id = %d";
        $params = [ $school_id, $user_id ];

        if ( $unread_only ) {
            $sql .= ' AND is_read = 0';
        }

        $sql     .= ' ORDER BY created_at DESC LIMIT %d';
        $params[] = max( 1, min( 100, $limit ) );

        return (array) $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
    }

    public function unread_count( int $school_id, int $user_id ): int {
        global $wpdb;

        return absint(
            $wpdb->get_var(
                $wpdb->prepare(
                    'SELECT COUNT(*) FROM ' . Schema::table( 'notifications' ) .
                    ' WHERE school_id = %d AND user_id = %d AND is_read = 0',
                    $school_id,
                    $user_id
                )
            )
        );
    }

    /**
     * Mark read. Scoped to the owning user, so an id from another user's inbox
     * cannot be marked by guessing the number.
     */
    public function mark_read( int $school_id, int $user_id, array $ids ): int {
        $ids = array_values( array_filter( array_map( 'absint', $ids ) ) );

        if ( empty( $ids ) ) {
            return 0;
        }

        global $wpdb;

        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

        return absint(
            $wpdb->query(
                $wpdb->prepare(
                    'UPDATE ' . Schema::table( 'notifications' ) .
                    " SET is_read = 1, read_at = %s
                      WHERE school_id = %d AND user_id = %d AND id IN ({$placeholders})",
                    array_merge( [ current_time( 'mysql', true ), $school_id, $user_id ], $ids )
                )
            )
        );
    }

    public function mark_all_read( int $school_id, int $user_id ): int {
        global $wpdb;

        return absint(
            $wpdb->query(
                $wpdb->prepare(
                    'UPDATE ' . Schema::table( 'notifications' ) .
                    ' SET is_read = 1, read_at = %s WHERE school_id = %d AND user_id = %d AND is_read = 0',
                    current_time( 'mysql', true ),
                    $school_id,
                    $user_id
                )
            )
        );
    }

    /**
     * Delete a single notification. Scoped to the owning user so one person
     * cannot delete another's inbox by guessing the id.
     */
    public function delete( int $school_id, int $user_id, int $notification_id ): bool {
        global $wpdb;

        $deleted = $wpdb->delete(
            Schema::table( 'notifications' ),
            [ 'id' => $notification_id, 'school_id' => $school_id, 'user_id' => $user_id ],
            [ '%d', '%d', '%d' ]
        );

        return (bool) $deleted;
    }

    /**
     * Delete all read notifications for a user — a quick "clear inbox".
     */
    public function delete_all_read( int $school_id, int $user_id ): int {
        global $wpdb;

        return absint(
            $wpdb->query(
                $wpdb->prepare(
                    'DELETE FROM ' . Schema::table( 'notifications' ) .
                    ' WHERE school_id = %d AND user_id = %d AND is_read = 1',
                    $school_id,
                    $user_id
                )
            )
        );
    }

    // ---------------------------------------------------------------
    // Preferences and email
    // ---------------------------------------------------------------

    /**
     * Per-user delivery preference. Defaults to in-app plus email, which is what a
     * parent expects, but a teacher receiving forty score notifications a day will
     * want to turn email off.
     *
     * @return array{in_app:bool,email:bool,muted_types:array<int,string>}
     */
    public function preferences( int $user_id ): array {
        $stored = get_user_meta( $user_id, '_educbt_notification_prefs', true );

        $defaults = [ 'in_app' => true, 'email' => true, 'muted_types' => [] ];

        return is_array( $stored ) ? array_merge( $defaults, $stored ) : $defaults;
    }

    public function set_preferences( int $user_id, array $preferences ): bool {
        return (bool) update_user_meta(
            $user_id,
            '_educbt_notification_prefs',
            [
                'in_app'      => ! empty( $preferences['in_app'] ),
                'email'       => ! empty( $preferences['email'] ),
                'muted_types' => array_values( array_intersect( (array) ( $preferences['muted_types'] ?? [] ), array_keys( self::types() ) ) ),
            ]
        );
    }

    /**
     * Queue an email if the user wants one. QUEUED, never sent inline.
     *
     * Sending to 500 guardians inside the request that published results will time
     * out and lose messages half way through, with no record of which half. The
     * queue makes delivery restartable and auditable.
     */
    private function maybe_queue_email( int $school_id, int $user_id, string $type, string $title, string $body, string $link ): void {
        $prefs = $this->preferences( $user_id );

        if ( empty( $prefs['email'] ) || in_array( $type, (array) $prefs['muted_types'], true ) ) {
            return;
        }

        $user = get_userdata( $user_id );

        if ( ! $user || ! is_email( $user->user_email ) ) {
            return;
        }

        // A generated placeholder address is not a real inbox; students are created
        // with one, and mailing it would bounce for every student in the school.
        if ( str_ends_with( (string) $user->user_email, '.invalid' ) ) {
            return;
        }

        ( new EmailQueueService() )->queue(
            $school_id,
            (string) $user->user_email,
            $title,
            $body . ( $link !== '' ? "\n\n" . $link : '' )
        );
    }

    // ---------------------------------------------------------------
    // Emitters — wired to domain events
    // ---------------------------------------------------------------

    /**
     * Subscribe to the events other phases already fire, so notifications are a
     * consequence of what happened rather than something every service has to
     * remember to call.
     */
    public function init(): void {
        add_action( 'educbt_results_published', [ $this, 'on_results_published' ], 10, 1 );
        add_action( 'educbt_results_transitioned', [ $this, 'on_results_transitioned' ], 10, 1 );
        add_action( 'educbt_paper_published', [ $this, 'on_paper_published' ], 10, 1 );
        add_action( 'educbt_promotion_committed', [ $this, 'on_promotion_committed' ], 10, 1 );
        add_action( 'educbt_student_created', [ $this, 'on_student_created' ], 10, 1 );
        add_action( 'educbt_guardian_linked', [ $this, 'on_guardian_linked' ], 10, 1 );
    }

    /**
     * Results published: tell every affected student and their guardians.
     *
     * @param array<string,mixed> $payload
     */
    public function on_results_published( $payload ): void {
        if ( ! is_array( $payload ) ) {
            return;
        }

        global $wpdb;

        $school_id = absint( $payload['school_id'] ?? 0 );
        $class_id  = absint( $payload['class_id'] ?? 0 );
        $term_id   = absint( $payload['term_id'] ?? 0 );

        if ( $school_id <= 0 || $class_id <= 0 ) {
            return;
        }

        $students    = $wpdb->prefix . 'educbt_students';
        $enrollments = Schema::table( 'enrollments' );
        $link        = Schema::table( 'guardian_student' );
        $guardians   = Schema::table( 'guardians' );

        $student_users = array_map(
            'absint',
            (array) $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT st.wp_user_id FROM {$enrollments} e
                     INNER JOIN {$students} st ON st.id = e.student_id
                     WHERE e.school_id = %d AND e.class_id = %d AND e.status = 'active'
                       AND st.wp_user_id IS NOT NULL",
                    $school_id,
                    $class_id
                )
            )
        );

        // Only guardians permitted to see results are told they exist.
        $guardian_users = array_map(
            'absint',
            (array) $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT DISTINCT g.wp_user_id FROM {$enrollments} e
                     INNER JOIN {$link} gs ON gs.student_id = e.student_id AND gs.can_view_results = 1
                     INNER JOIN {$guardians} g ON g.id = gs.guardian_id
                     WHERE e.school_id = %d AND e.class_id = %d AND e.status = 'active'
                       AND g.wp_user_id IS NOT NULL",
                    $school_id,
                    $class_id
                )
            )
        );

        $this->notify_many(
            $school_id,
            $student_users,
            self::RESULT_PUBLISHED,
            'Your result is available',
            'Your result for this term has been published. You can now view and print it from your portal.',
            home_url( '/portal/student/results/' )
        );

        $this->notify_many(
            $school_id,
            $guardian_users,
            self::RESULT_PUBLISHED,
            'Your child&rsquo;s result is available',
            'The result for this term has been published and can be viewed in the parent portal.',
            home_url( '/portal/guardian/results/' )
        );

        EventDispatcher::action( 'educbt_result_notifications_sent', [
            'school_id' => $school_id,
            'students'  => count( $student_users ),
            'guardians' => count( $guardian_users ),
            'term_id'   => $term_id,
        ] );

        // --- Auto-email to guardians with real email addresses ---
        $this->email_guardians_results( $school_id, $class_id, $term_id, $guardian_users );
    }

    /**
     * When a class's results transition to APPROVED, notify every class teacher
     * assigned to that class so they know the sheet is ready for remarks / printing.
     *
     * @param array<string,mixed> $event
     */
    public function on_results_transitioned( array $event ): void {
        global $wpdb;

        $to = (string) ( $event['to'] ?? '' );

        if ( $to !== 'approved' ) {
            return;
        }

        $school_id = absint( $event['school_id'] ?? 0 );
        $class_id  = absint( $event['class_id'] ?? 0 );
        $term_id   = absint( $event['term_id'] ?? 0 );

        if ( $school_id <= 0 || $class_id <= 0 ) {
            return;
        }

        $staff_assignments = Schema::table( 'staff_assignments' );
        $teachers = (array) $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT staff_id FROM {$staff_assignments}
                 WHERE school_id = %d AND class_id = %d AND assignment_type = 'class_teacher' AND status = 'active'",
                $school_id,
                $class_id
            )
        );

        foreach ( $teachers as $staff_id ) {
            $staff_id = absint( $staff_id );
            $user_id  = absint(
                $wpdb->get_var(
                    $wpdb->prepare(
                        'SELECT user_id FROM ' . $wpdb->prefix . 'educbt_staff WHERE id = %d',
                        $staff_id
                    )
                )
            );

            if ( $user_id <= 0 ) {
                continue;
            }

            $this->notify(
                $school_id,
                $user_id,
                self::RESULT_APPROVED,
                'Results approved',
                'Results for your class have been approved. You can now add remarks and print report sheets.',
                home_url( '/portal/teacher/results/' )
            );
        }
    }

    /**
     * Queue welcome emails when a student is created.
     *
     * @param array<string,mixed> $payload
     */
    public function on_student_created( $payload ): void {
        if ( ! is_array( $payload ) ) {
            return;
        }

        $school_id = absint( $payload['school_id'] ?? 0 );
        $data      = (array) ( $payload['data'] ?? [] );

        if ( $school_id <= 0 ) {
            return;
        }

        $parent_email = sanitize_email( $data['parent_email'] ?? '' );

        if ( $parent_email === '' ) {
            return;
        }

        $student_name = sanitize_text_field( $data['full_name'] ?? '' );
        $username     = sanitize_text_field( $data['login_username'] ?? '' );
        $login_url    = home_url( '/portal/login/' );

        $body = sprintf(
            '<h2>Welcome to EduCBT!</h2>
<p>Dear Parent/Guardian,</p>
<p>Your child, <strong>%s</strong>, has been registered on our school management platform.</p>
<p>Here are the login details for the student portal:</p>
<table style="background:#f8fafc;padding:16px;border-radius:8px;margin:16px 0;">
<tr><td style="font-weight:bold;padding:4px 8px;">Username:</td><td style="padding:4px 8px;">%s</td></tr>
<tr><td style="font-weight:bold;padding:4px 8px;">Login URL:</td><td style="padding:4px 8px;"><a href="%s">%s</a></td></tr>
</table>
<p>Please log in and change the temporary password at your earliest convenience.</p>
<p>If you have any questions, please contact the school.</p>
<p style="color:#94a3b8;font-size:12px;margin-top:24px;">This is an automated message from EduCBT.</p>',
            esc_html( $student_name ),
            esc_html( $username ),
            esc_url( $login_url ),
            esc_html( $login_url )
        );

        ( new EmailQueueService() )->queue(
            $school_id,
            $parent_email,
            'Welcome to EduCBT — Student Registration',
            $body
        );
    }

    /**
     * Queue welcome emails when a guardian is linked to a student.
     *
     * @param array<string,mixed> $payload
     */
    public function on_guardian_linked( $payload ): void {
        if ( ! is_array( $payload ) ) {
            return;
        }

        $school_id   = absint( $payload['school_id'] ?? 0 );
        $guardian_id = absint( $payload['guardian_id'] ?? 0 );
        $student_id  = absint( $payload['student_id'] ?? 0 );
        $created     = ! empty( $payload['created'] );

        if ( $school_id <= 0 || $guardian_id <= 0 || ! $created ) {
            return;
        }

        global $wpdb;

        $guardians = Schema::table( 'guardians' );
        $students  = $wpdb->prefix . 'educbt_students';

        $guardian = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT email, first_name, last_name, invite_token FROM {$guardians} WHERE id = %d",
                $guardian_id
            ),
            ARRAY_A
        );

        if ( ! $guardian || empty( $guardian['email'] ) ) {
            return;
        }

        $student_name = (string) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT CONCAT(first_name, ' ', last_name) FROM {$students} WHERE id = %d",
                $student_id
            )
        );

        $accept_url = add_query_arg(
            [ 'token' => $guardian['invite_token'] ],
            home_url( '/portal/guardian/accept/' )
        );

        $body = sprintf(
            '<h2>You have been invited to EduCBT</h2>
<p>Dear %s,</p>
<p>You have been linked as a guardian to <strong>%s</strong> on our school management platform.</p>
<p>You can view your child&rsquo;s results, attendance, and announcements through the parent portal.</p>
<p style="margin:16px 0;"><a href="%s" style="background:#4f46e5;color:#fff;padding:12px 24px;border-radius:8px;text-decoration:none;display:inline-block;">Set up your account</a></p>
<p style="color:#94a3b8;font-size:12px;margin-top:24px;">This is an automated message from EduCBT.</p>',
            esc_html( trim( $guardian['first_name'] . ' ' . $guardian['last_name'] ) ),
            esc_html( $student_name ),
            esc_url( $accept_url )
        );

        ( new EmailQueueService() )->queue_and_send(
            $school_id,
            $guardian['email'],
            'You have been invited to EduCBT — Guardian Portal',
            $body
        );
    }

    /**
     * Queue result-published emails to guardians.
     */
    private function email_guardians_results( int $school_id, int $class_id, int $term_id, array $guardian_user_ids ): void {
        if ( empty( $guardian_user_ids ) ) {
            return;
        }

        global $wpdb;

        $guardians  = Schema::table( 'guardians' );
        $link       = Schema::table( 'guardian_student' );
        $enrollments = Schema::table( 'enrollments' );
        $students   = $wpdb->prefix . 'educbt_students';
        $terms      = Schema::table( 'terms' );

        $term_name = (string) $wpdb->get_var(
            $wpdb->prepare( "SELECT title FROM {$terms} WHERE id = %d", $term_id )
        );

        // Get guardian emails for this class
        $guardian_emails = (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT DISTINCT g.email, g.first_name, g.last_name
                 FROM {$enrollments} e
                 INNER JOIN {$link} gs ON gs.student_id = e.student_id AND gs.can_view_results = 1
                 INNER JOIN {$guardians} g ON g.id = gs.guardian_id
                 WHERE e.school_id = %d AND e.class_id = %d AND e.status = 'active'
                   AND g.email != ''",
                $school_id,
                $class_id
            ),
            ARRAY_A
        );

        if ( empty( $guardian_emails ) ) {
            return;
        }

        $portal_url = home_url( '/portal/guardian/' );

        foreach ( $guardian_emails as $g ) {
            $email = trim( (string) $g['email'] );

            if ( ! is_email( $email ) || str_ends_with( strtolower( $email ), '@local.educbt' ) ) {
                continue;
            }

            $body = sprintf(
                '<h2>Results Published</h2>
<p>Dear %s,</p>
<p>The results for <strong>%s</strong> have been published and are now available for viewing.</p>
<p style="margin:16px 0;"><a href="%s" style="background:#4f46e5;color:#fff;padding:12px 24px;border-radius:8px;text-decoration:none;display:inline-block;">View Results</a></p>
<p style="color:#94a3b8;font-size:12px;margin-top:24px;">This is an automated message from EduCBT.</p>',
                esc_html( trim( $g['first_name'] . ' ' . $g['last_name'] ) ),
                esc_html( $term_name ),
                esc_url( $portal_url )
            );

            ( new EmailQueueService() )->queue_and_send(
                $school_id,
                $email,
                'Result Published — ' . $term_name,
                $body
            );
        }
    }

    /** @param array<string,mixed> $payload */
    public function on_paper_published( $payload ): void {
        if ( ! is_array( $payload ) ) {
            return;
        }

        global $wpdb;

        $school_id = absint( $payload['school_id'] ?? 0 );
        $paper_id  = absint( $payload['paper_id'] ?? 0 );

        if ( $school_id <= 0 || $paper_id <= 0 ) {
            return;
        }

        $papers      = Schema::table( 'exam_papers' );
        $subjects    = Schema::table( 'subjects_v2' );
        $enrollments = Schema::table( 'enrollments' );
        $registered  = Schema::table( 'student_subjects' );
        $students    = $wpdb->prefix . 'educbt_students';

        $paper = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT p.scheduled_at, s.name AS subject_name FROM {$papers} p
                 INNER JOIN {$subjects} s ON s.id = p.subject_id
                 WHERE p.id = %d AND p.school_id = %d",
                $paper_id,
                $school_id
            ),
            ARRAY_A
        );

        if ( ! $paper ) {
            return;
        }

        // Only students who actually offer the subject — the same rule that governs
        // whether they can open the paper.
        $user_ids = array_map(
            'absint',
            (array) $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT st.wp_user_id FROM {$papers} p
                     INNER JOIN {$enrollments} e ON e.class_id = p.class_id AND e.status = 'active'
                     INNER JOIN {$registered} rs ON rs.student_id = e.student_id
                            AND rs.subject_id = p.subject_id AND rs.session_id = e.session_id
                     INNER JOIN {$students} st ON st.id = e.student_id
                     WHERE p.id = %d AND st.wp_user_id IS NOT NULL",
                    $paper_id
                )
            )
        );

        $this->notify_many(
            $school_id,
            $user_ids,
            self::EXAM_SCHEDULED,
            $paper['subject_name'] . ' examination scheduled',
            sprintf( 'Your %s paper is scheduled for %s.', $paper['subject_name'], (string) $paper['scheduled_at'] ),
            home_url( '/portal/student/timetable/' )
        );
    }

    /** @param array<string,mixed> $payload */
    public function on_promotion_committed( $payload ): void {
        if ( ! is_array( $payload ) ) {
            return;
        }

        // Deliberately no student notification here. A promotion outcome is news a
        // school delivers itself, in the way it chooses — an automated "you are
        // repeating SS1" email is not how a child should learn that.
        EventDispatcher::action( 'educbt_promotion_notice_skipped', [
            'school_id' => absint( $payload['school_id'] ?? 0 ),
            'reason'    => 'delivered_by_the_school_not_the_system',
        ] );
    }

    /**
     * Purge read notifications older than a retention window.
     */
    public function purge( int $days = 120 ): int {
        global $wpdb;

        return absint(
            $wpdb->query(
                $wpdb->prepare(
                    'DELETE FROM ' . Schema::table( 'notifications' ) .
                    ' WHERE is_read = 1 AND created_at < DATE_SUB(%s, INTERVAL %d DAY)',
                    current_time( 'mysql', true ),
                    $days
                )
            )
        );
    }
}
