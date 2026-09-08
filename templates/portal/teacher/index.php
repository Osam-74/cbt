<?php
/**
 * A teacher's own dashboard: what they teach, CA test recording, marking, and schedule.
 *
 * Redesigned with the dark forest/lime design system (Sora/Inter typography, card
 * containers, stat tiles, pill badges, and prominent Record CA section).
 *
 * @var array<string,mixed> $educbt
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

global $wpdb;

$school_id = (int) $educbt['school_id'];
$actor     = $educbt['scope']->actor();
$staff_id  = (int) $actor['id'];

$year       = new \EduCBTPro\Services\AcademicYearService();
$session    = $year->current_session( $school_id );
$term       = $year->current_term( $school_id );
$session_id = (int) ( $session['id'] ?? 0 );
$term_id    = (int) ( $term['id'] ?? 0 );

$assignments   = \EduCBTPro\Core\Schema::table( 'staff_assignments' );
$classes       = \EduCBTPro\Core\Schema::table( 'classes' );
$subjects      = \EduCBTPro\Core\Schema::table( 'subjects_v2' );
$enrolments    = \EduCBTPro\Core\Schema::table( 'enrollments' );
$student_sub_t = \EduCBTPro\Core\Schema::table( 'student_subjects' );

// ── Teaching assignments ────────────────────────────────────────────────────────
$held = (array) $wpdb->get_results(
    $wpdb->prepare(
        "SELECT a.assignment_type, a.class_id, a.subject_id, c.display_name AS class_name, s.name AS subject_name,
                (SELECT COUNT(*) FROM {$enrolments} e WHERE e.class_id = a.class_id AND e.status = 'active') AS students
         FROM {$assignments} a
         LEFT JOIN {$classes} c ON c.id = a.class_id
         LEFT JOIN {$subjects} s ON s.id = a.subject_id
         WHERE a.school_id = %d AND a.staff_id = %d AND a.status = 'active'
         ORDER BY a.assignment_type ASC, c.display_name ASC",
        $school_id,
        $staff_id
    ),
    ARRAY_A
);

$class_teacher_of   = [];
$subject_teacher_of = [];
foreach ( $held as $h ) {
    if ( (string) $h['assignment_type'] === 'class_teacher' ) {
        $class_teacher_of[] = $h;
    } else {
        $subject_teacher_of[] = $h;
    }
}

$total_my_students = 0;
foreach ( $class_teacher_of as $c ) {
    $total_my_students += (int) $c['students'];
}

// ── Record CA per-subject / per-class assignment query ───────────────────────
$ca_raw_assignments = (array) $wpdb->get_results(
    $wpdb->prepare(
        "SELECT a.class_id, a.subject_id, c.display_name AS class_name, s.name AS subject_name,
                COALESCE(
                    NULLIF((
                        SELECT COUNT(DISTINCT ss.student_id)
                        FROM {$student_sub_t} ss
                        INNER JOIN {$enrolments} e ON e.student_id = ss.student_id
                        WHERE e.class_id = a.class_id AND e.status = 'active' AND ss.subject_id = a.subject_id
                    ), 0),
                    (
                        SELECT COUNT(*)
                        FROM {$enrolments} e2
                        WHERE e2.class_id = a.class_id AND e2.status = 'active'
                    )
                ) AS student_count
         FROM {$assignments} a
         LEFT JOIN {$classes} c ON c.id = a.class_id
         INNER JOIN {$subjects} s ON s.id = a.subject_id
         WHERE a.school_id = %d AND a.staff_id = %d AND a.status = 'active'
           AND a.assignment_type = 'subject_teacher' AND a.subject_id IS NOT NULL
         ORDER BY s.name ASC, c.display_name ASC",
        $school_id,
        $staff_id
    ),
    ARRAY_A
);

// Only real subject-teacher assignments with a resolvable subject reach the CA
// recording card. A class-teacher assignment (which is about heading a class,
// not teaching a subject) must never show up here — that duplicated the
// "Classes I Head" card under a confusing "Unassigned Subject" label.
$grouped_ca = [];
foreach ( $ca_raw_assignments as $ca_row ) {
    if ( empty( $ca_row['subject_name'] ) ) {
        continue;
    }
    $subj_name = (string) $ca_row['subject_name'];
    if ( ! isset( $grouped_ca[ $subj_name ] ) ) {
        $grouped_ca[ $subj_name ] = [];
    }
    $grouped_ca[ $subj_name ][] = $ca_row;
}

// ── Invigilation duties ─────────────────────────────────────────────────────
$papers = \EduCBTPro\Core\Schema::table( 'exam_papers' );
$invig  = \EduCBTPro\Core\Schema::table( 'paper_invigilators' );

$duties = (array) $wpdb->get_results(
    $wpdb->prepare(
        "SELECT p.id, p.scheduled_at, p.status, p.access_code, p.requires_access_code,
                s.name AS subject_name, c.display_name AS class_name
         FROM {$invig} i
         INNER JOIN {$papers} p ON p.id = i.paper_id
         INNER JOIN {$subjects} s ON s.id = p.subject_id
         LEFT JOIN {$classes} c ON c.id = p.class_id
         WHERE i.school_id = %d AND i.staff_id = %d AND p.scheduled_at >= DATE_SUB(%s, INTERVAL 1 DAY)
         ORDER BY p.scheduled_at ASC LIMIT 8",
        $school_id,
        $staff_id,
        current_time( 'mysql', true )
    ),
    ARRAY_A
);

// ── Questions written ────────────────────────────────────────────────────────
$questions_table = $wpdb->prefix . 'educbt_questions';

$my_subject_ids = array_map(
    'absint',
    (array) $wpdb->get_col(
        $wpdb->prepare(
            "SELECT DISTINCT subject_id FROM {$assignments}
             WHERE school_id = %d AND staff_id = %d AND status = 'active' AND subject_id IS NOT NULL",
            $school_id,
            $staff_id
        )
    )
);

$my_questions         = 0;
$my_pending_questions = 0;

if ( ! empty( $my_subject_ids ) ) {
    $holder = implode( ',', array_fill( 0, count( $my_subject_ids ), '%d' ) );

    $my_questions = absint(
        $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$questions_table}
                 WHERE school_id = %d AND status = 'active' AND subject_id IN ({$holder})",
                array_merge( [ $school_id ], $my_subject_ids )
            )
        )
    );

    $my_pending_questions = absint(
        $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$questions_table}
                 WHERE school_id = %d AND status = 'active' AND approval_status = 'pending' AND subject_id IN ({$holder})",
                array_merge( [ $school_id ], $my_subject_ids )
            )
        )
    );
}

// ── CA tests created ────────────────────────────────────────────────────────
$pq_t = \EduCBTPro\Core\Schema::table( 'paper_questions' );

$my_tests = (array) $wpdb->get_results(
    $wpdb->prepare(
        "SELECT p.id, p.scheduled_at, p.status, p.question_count, s.name AS subject_name, c.display_name AS class_name,
                (SELECT COUNT(*) FROM {$enrolments} e2 WHERE e2.class_id = p.class_id AND e2.status = 'active') AS class_size,
                (SELECT COUNT(*) FROM {$pq_t} pq WHERE pq.paper_id = p.id) AS composed
         FROM {$papers} p
         INNER JOIN {$subjects} s ON s.id = p.subject_id
         LEFT JOIN {$classes} c ON c.id = p.class_id
         WHERE p.school_id = %d AND p.is_practice = 1 AND p.status <> 'cancelled'
           AND p.created_by_staff = %d
         ORDER BY p.scheduled_at DESC LIMIT 5",
        $school_id, $staff_id
    ),
    ARRAY_A
);

// ── Marking queue ────────────────────────────────────────────────────────────
$marking = ( new \EduCBTPro\Services\TheoryService() )->papers_awaiting_marking( $school_id, $staff_id );

// ── Exam prep status ─────────────────────────────────────────────────────────
$exam_prep_open = ( new \EduCBTPro\Services\SchoolService() )->is_exam_prep_enabled( $school_id );

// What the question bank is open for, if anything. A teacher should be able to see
// from their dashboard whether there is work to do and what it is for.
$open_window = ( new \EduCBTPro\Services\QuestionWindowService() )->current( $school_id );

// An examination officer and a vice principal both reach this dashboard, because
// they hold teaching capabilities too. Calling it the Teacher Dashboard told them
// they were somebody else. The page is the same work surface; the name follows
// whoever is looking at it.
$educbt_role_label = 'Teacher';

$educbt_actor_role = (string) ( $actor['role'] ?? '' );

$educbt_role_names = [
    \EduCBTPro\Core\Capabilities::ROLE_PRINCIPAL      => 'Principal',
    \EduCBTPro\Core\Capabilities::ROLE_VICE_PRINCIPAL => 'Vice Principal',
    \EduCBTPro\Core\Capabilities::ROLE_EXAM_OFFICER   => 'Examination Officer',
    \EduCBTPro\Core\Capabilities::ROLE_TEACHER        => 'Teacher',
];

if ( isset( $educbt_role_names[ $educbt_actor_role ] ) ) {
    $educbt_role_label = $educbt_role_names[ $educbt_actor_role ];
}

$educbt_title = $educbt_role_label . ' Dashboard';

// Result pipeline for class teacher(s)
$teacher_pipeline = [];
if ( ! empty( $class_teacher_of ) ) {
    $rws = new \EduCBTPro\Services\ResultWorkflowService();
    $teacher_pipeline = $rws->pipeline_overview( $school_id, $term_id );
}

$educbt_body = static function () use (
    $held, $class_teacher_of, $subject_teacher_of, $grouped_ca, $total_my_students,
    $duties, $session, $term, $my_questions, $my_pending_questions, $my_tests,
    $marking, $exam_prep_open, $teacher_pipeline, $educbt_role_label, $open_window
): void {
    $answers_to_mark = (int) array_sum( array_map( static fn( array $m ): int => (int) $m['outstanding'], $marking ) );
    ?>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Sora:wght@500;600;700;800&family=Inter:wght@400;500;600;700&display=swap');

        :root {
            --forest-950: #0B2118;
            --forest-900: #102C20;
            --lime-400: #A6E86B;
            --lime-300: #C9F0A6;
            --lime-100: #E9FBD8;
            --canvas: #F5F6F1;
            --surface: #FFFFFF;
            --ink-900: #152019;
            --ink-700: #37453D;
            --ink-500: #69766C;
            --ink-400: #8B968D;
            --line: #E6E9E1;
            --line-soft: #EFF2EA;
            --success-bg: #E3F6DA;
            --success-text: #2C6B36;
            --live-bg: #FFF4D9;
            --live-text: #8A5B00;
            --danger-bg: #FBE8E3;
            --danger-text: #B14328;
            --r-lg: 18px;
            --shadow-card: 0 1px 2px rgba(16,32,23,.04), 0 8px 24px rgba(16,32,23,.06);
            --shadow-hover: 0 14px 30px rgba(16,32,23,.12);
            --font-display: 'Sora', 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            --font-body: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
        }

        .edu-dash-wrap {
            font-family: var(--font-body);
            color: var(--ink-900);
            display: flex;
            flex-direction: column;
            gap: 22px;
        }

        .edu-dash-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 4px;
        }

        .edu-dash-header__title {
            font-family: var(--font-display);
            font-size: 22px;
            font-weight: 700;
            color: var(--ink-900);
            margin: 0;
        }

        .edu-dash-header__sub {
            font-size: 13px;
            color: var(--ink-500);
            font-weight: 500;
            margin-top: 2px;
        }

        /* Stat Grid — all cards in one row on desktop */
        .edu-stat-grid {
            display: grid;
            grid-template-columns: repeat(6, 1fr);
            gap: 10px;
        }
        @media (max-width: 768px) {
            .edu-stat-grid { grid-template-columns: repeat(3, 1fr); }
        }
        @media (max-width: 480px) {
            .edu-stat-grid { grid-template-columns: repeat(2, 1fr); }
        }

        .edu-stat-card {
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: var(--r-lg);
            padding: 16px 18px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
            gap: 10px;
            box-shadow: var(--shadow-card);
            transition: transform .15s ease, box-shadow .15s ease;
        }

        .edu-stat-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-hover);
        }

        .edu-stat-icon {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            background: var(--lime-100);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #2C6B36;
            flex-shrink: 0;
        }

        .edu-stat-icon svg {
            width: 18px;
            height: 18px;
        }

.edu-stat-card > div {
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            gap: 4px;
        }

        .edu-stat-value {
            font-family: var(--font-display);
            font-size: 22px;
            font-weight: 700;
            color: var(--ink-900);
            line-height: 1.1;
        }

        .edu-stat-label {
            font-size: 11px;
            font-weight: 600;
            letter-spacing: .03em;
            color: var(--ink-500);
            text-transform: uppercase;
        }

        /* Layout Grid */
        .edu-grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }

        @media (max-width: 920px) {
            .edu-grid-2 {
                grid-template-columns: 1fr;
            }
        }

        /* Card Container */
        .edu-card {
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: var(--r-lg);
            box-shadow: var(--shadow-card);
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }

        .edu-card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 18px 22px;
            border-bottom: 1px solid var(--line);
            background: var(--surface);
        }

        .edu-card-title {
            font-family: var(--font-display);
            font-size: 16px;
            font-weight: 700;
            color: var(--ink-900);
            margin: 0;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .edu-card-body {
            padding: 20px 22px;
            flex: 1;
        }

        .edu-card-body--no-pad {
            padding: 0;
        }

        /* Subject Group & Record CA Table */
        .edu-subject-group {
            margin-bottom: 22px;
        }

        .edu-subject-group:last-child {
            margin-bottom: 0;
        }

        .edu-subject-heading {
            display: flex;
            align-items: center;
            gap: 8px;
            font-family: var(--font-display);
            font-size: 14px;
            font-weight: 700;
            color: var(--forest-900);
            padding: 10px 14px;
            background: var(--line-soft);
            border-radius: 10px;
            margin-bottom: 8px;
        }

        .edu-subject-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: var(--lime-400);
            display: inline-block;
        }

        .edu-ca-tbl {
            width: 100%;
            border-collapse: collapse;
        }

        .edu-ca-tbl tr {
            border-bottom: 1px solid var(--line-soft);
            transition: background .12s;
        }

        .edu-ca-tbl tr:last-child {
            border-bottom: none;
        }

        .edu-ca-tbl tr:hover {
            background: #FAFBF8;
        }

        .edu-ca-tbl td {
            padding: 12px 14px;
            vertical-align: middle;
        }

        /* Table General */
        .edu-tbl {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
        }

        .edu-tbl thead th {
            font-size: 11px;
            letter-spacing: .05em;
            text-transform: uppercase;
            color: var(--ink-400);
            font-weight: 700;
            padding: 12px 18px;
            border-bottom: 1px solid var(--line);
            background: #FAFBF8;
        }

        .edu-tbl tbody td {
            padding: 13px 18px;
            font-size: 13.5px;
            color: var(--ink-900);
            border-bottom: 1px solid var(--line-soft);
        }

        .edu-tbl tbody tr:last-child td {
            border-bottom: none;
        }

        .edu-tbl tbody tr:hover {
            background: #FAFBF8;
        }

        /* Pills */
        .edu-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 11px;
            border-radius: 999px;
            font-size: 11.5px;
            font-weight: 600;
        }

        .edu-pill--published, .edu-pill--approved, .edu-pill--completed {
            background: var(--success-bg);
            color: var(--success-text);
        }

        .edu-pill--pending, .edu-pill--draft, .edu-pill--in_progress {
            background: var(--live-bg);
            color: var(--live-text);
        }

        .edu-pill--class {
            background: var(--lime-100);
            color: #0B2118;
            border: 1px solid var(--lime-300);
        }

        /* Lists */
        .edu-list {
            list-style: none;
            margin: 0;
            padding: 0;
        }

        .edu-list-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 12px 18px;
            border-bottom: 1px solid var(--line-soft);
        }

        .edu-list-item:last-child {
            border-bottom: none;
        }

        /* Warning Notice */
        .edu-notice-warn {
            background: #FEF3C7;
            border: 1px solid #FCD34D;
            color: #92400E;
            border-radius: 12px;
            padding: 14px 18px;
            font-size: 13.5px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        /* Buttons */
        .edu-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 13px;
            border-radius: 9px;
            font-family: var(--font-body);
            font-size: 12.5px;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            transition: all .15s ease;
            border: none;
        }

        .edu-btn--lime {
            background: var(--lime-400);
            color: #0B2118;
        }

        .edu-btn--lime:hover {
            background: #96DC5B;
            color: #0B2118;
        }

        .edu-btn--ghost {
            background: transparent;
            color: var(--ink-700);
            border: 1px solid var(--line);
        }

        .edu-btn--ghost:hover {
            background: var(--line-soft);
            color: var(--ink-900);
        }

        .edu-empty {
            padding: 30px 20px;
            text-align: center;
            color: var(--ink-500);
            font-size: 13.5px;
        }
    </style>

    <div class="edu-dash-wrap">

        <!-- Top Greeting Header -->
        <div class="edu-dash-header">
            <div>
                <h1 class="edu-dash-header__title"><?php echo esc_html( $educbt_role_label ); ?> Dashboard</h1>
                <div class="edu-dash-header__sub">
                    <?php echo esc_html( ( $session['title'] ?? 'Current Session' ) . ' · ' . ( $term['title'] ?? 'No active term' ) ); ?>
                </div>
            </div>
        </div>

        <?php if ( ! empty( $open_window ) ) : ?>
            <?php // What to do now, and one click to it. ?>
            <a href="<?php echo esc_url( home_url( '/portal/exams/questions/' ) ); ?>"
               style="display:flex;align-items:center;gap:12px;background:#0F2818;color:#CBEB6E;border-radius:12px;
                      padding:14px 18px;margin-bottom:16px;text-decoration:none">
                <span style="flex:1">
                    <strong style="display:block;font-size:.96rem">
                        <?php echo ! empty( $open_window['is_ca'] ) ? 'Set your test questions' : 'Set your examination questions'; ?>
                    </strong>
                    <span style="font-size:.83rem;color:rgba(203,235,110,.8)">
                        <?php echo esc_html( (string) $open_window['title'] ); ?>
                        <?php if ( (string) ( $open_window['starts_on'] ?? '' ) !== '' ) : ?>
                            &middot; <?php echo esc_html( mysql2date( 'j M', (string) $open_window['starts_on'] ) ); ?>
                        <?php endif; ?>
                        <?php if ( (string) $open_window['closes_on'] !== '' ) : ?>
                            &ndash; <?php echo esc_html( mysql2date( 'j M Y', (string) $open_window['closes_on'] ) ); ?>
                        <?php endif; ?>
                    </span>
                </span>
                <span style="font-size:1.2rem">&rarr;</span>
            </a>
        <?php endif; ?>

        <?php if ( ! $exam_prep_open ) : ?>
            <div class="edu-notice-warn">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                <span><strong>Exam preparation is closed.</strong> You cannot submit new questions until the school office opens it.</span>
            </div>
        <?php endif; ?>

        <!-- Quick Stats Grid -->
        <div class="edu-stat-grid">
            <div class="edu-stat-card">
                <div class="edu-stat-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="12" rx="1.6"/><path d="M9 20h6M12 16v4"/></svg>
                </div>
                <div>
                    <div class="edu-stat-value"><?php echo esc_html( (string) count( $held ) ); ?></div>
                    <div class="edu-stat-label">Assignments</div>
                </div>
            </div>

            <div class="edu-stat-card">
                <div class="edu-stat-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3 3 8l9 5 9-5-9-5Z"/><path d="M3 12l9 5 9-5"/></svg>
                </div>
                <div>
                    <div class="edu-stat-value"><?php echo esc_html( (string) count( $class_teacher_of ) ); ?></div>
                    <div class="edu-stat-label">Classes Headed</div>
                </div>
            </div>

            <div class="edu-stat-card">
                <div class="edu-stat-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 4 2 9l10 5 10-5-10-5Z"/><path d="M6 11.4V17c0 1.4 2.7 3 6 3s6-1.6 6-3v-5.6"/></svg>
                </div>
                <div>
                    <div class="edu-stat-value"><?php echo esc_html( (string) $total_my_students ); ?></div>
                    <div class="edu-stat-label">My Students</div>
                </div>
            </div>

            <div class="edu-stat-card">
                <div class="edu-stat-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M9.3 9.2a2.7 2.7 0 0 1 5 1.4c0 1.9-2.2 1.8-2.7 3.4"/><path d="M12 17h.01"/></svg>
                </div>
                <div>
                    <div class="edu-stat-value"><?php echo esc_html( (string) $my_questions ); ?></div>
                    <div class="edu-stat-label">Questions Written</div>
                </div>
            </div>

            <div class="edu-stat-card">
                <div class="edu-stat-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3.6-7.2 10-7.2 10 7.2 10 7.2-3.6 7.2-10 7.2-10-7.2-10-7.2Z"/><circle cx="12" cy="12" r="3"/></svg>
                </div>
                <div>
                    <div class="edu-stat-value"><?php echo esc_html( (string) count( $duties ) ); ?></div>
                    <div class="edu-stat-label">Invigilations</div>
                </div>
            </div>

            <div class="edu-stat-card">
                <div class="edu-stat-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5Z"/></svg>
                </div>
                <div>
                    <div class="edu-stat-value"><?php echo esc_html( (string) $answers_to_mark ); ?></div>
                    <div class="edu-stat-label">To Mark</div>
                </div>
            </div>
        </div>

        <?php if ( ! empty( $marking ) ) : ?>
            <!-- Marking Queue Banner -->
            <div class="edu-card" style="border-color: var(--lime-400);">
                <div class="edu-card-header">
                    <h2 class="edu-card-title">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5Z"/></svg>
                        Written Answers Waiting
                    </h2>
                    <span class="edu-pill edu-pill--pending"><?php echo esc_html( (string) $answers_to_mark ); ?> Pending</span>
                </div>
                <div class="edu-card-body edu-card-body--no-pad">
                    <div class="edu-list">
                    <?php foreach ( $marking as $m ) : ?>
                        <div class="edu-list-item">
                            <div>
                                <strong style="font-size:13.5px; color:var(--ink-900)"><?php echo esc_html( $m['subject_name'] . ' — ' . $m['class_name'] ); ?></strong>
                                <span style="font-size:12px; color:var(--ink-500); margin-left:8px"><?php echo esc_html( (string) $m['outstanding'] ); ?> to mark</span>
                            </div>
                            <a class="edu-btn edu-btn--lime" href="<?php echo esc_url( home_url( '/portal/exams/marking/' . (int) $m['id'] ) ); ?>">Mark Now</a>
                        </div>
                    <?php endforeach; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if ( ! empty( $teacher_pipeline ) ) : ?>
        <!-- Result Pipeline -->
        <div class="edu-card">
            <div class="edu-card-header">
                <h2 class="edu-card-title">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="M18.7 4.3v9.4"/><path d="M13.7 9.7v9.4"/><path d="M8.7 14.7v9.4"/></svg>
                    Result Pipeline
                </h2>
                <span class="edu-pill edu-pill--published"><?php echo esc_html( (string) ( $term['title'] ?? 'This Term' ) ); ?></span>
            </div>
            <div class="edu-card-body">
                <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px;">
                    <?php
                    $statuses = [ 'draft' => '#94a3b8', 'submitted' => '#f59e0b', 'approved' => '#3b82f6', 'published' => '#16a34a' ];
                    foreach ( $statuses as $status => $color ) :
                        $count = 0;
                        foreach ( $teacher_pipeline as $p ) {
                            if ( ( $p['status'] ?? '' ) === $status ) { $count++; }
                        }
                    ?>
                    <div style="text-align:center;padding:12px;border-radius:8px;background:<?php echo esc_attr( $color ); ?>11;border:1px solid <?php echo esc_attr( $color ); ?>33;">
                        <div style="font-size:24px;font-weight:700;color:<?php echo esc_attr( $color ); ?>;"><?php echo esc_html( (string) $count ); ?></div>
                        <div style="font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--ink-500);"><?php echo esc_html( ucfirst( $status ) ); ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div style="margin-top:12px;display:flex;gap:8px;">
                    <a class="edu-btn" href="<?php echo esc_url( home_url( '/portal/teacher/results/' ) ); ?>">View Class Results</a>
                    <a class="edu-btn edu-btn--lime" href="<?php echo esc_url( home_url( '/portal/school/review/' ) ); ?>">Review Results</a>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- PROMINENT RECORD CA CARD (Primary Teacher Action) -->
        <div class="edu-card">
            <div class="edu-card-header">
                <h2 class="edu-card-title">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19V9M10 19V5M16 19v-7"/><path d="M2 19h20"/></svg>
                    My Classes &amp; CA Recording
                </h2>
                <span class="edu-pill edu-pill--published">Teaching Overview</span>
            </div>
            <div class="edu-card-body">
                <?php if ( empty( $grouped_ca ) ) : ?>
                    <div class="edu-empty">You have not been assigned as a subject teacher for any class yet.</div>
                <?php else : ?>
                    <?php foreach ( $grouped_ca as $subj_name => $rows ) : ?>
                        <div class="edu-subject-group">
                            <div class="edu-subject-heading">
                                <span class="edu-subject-dot"></span>
                                <span><?php echo esc_html( $subj_name ); ?></span>
                            </div>
                            <table class="edu-ca-tbl">
                                <tbody>
                                <?php foreach ( $rows as $r ) :
                                    $score_url = add_query_arg(
                                        [ 'class' => (int) $r['class_id'], 'subject' => (int) $r['subject_id'] ],
                                        home_url( '/portal/teacher/scores/' )
                                    );
                                    $st_count = (int) $r['student_count'];
                                ?>
                                    <tr>
                                        <td style="width:25%">
                                            <span class="edu-pill edu-pill--class">
                                                <?php echo esc_html( (string) $r['class_name'] ); ?>
                                            </span>
                                        </td>
                                        <td style="width:45%; font-size:13.5px; color:var(--ink-700)">
                                            <strong><?php echo esc_html( (string) $st_count ); ?></strong>
                                            <?php echo esc_html( $st_count === 1 ? 'Student' : 'Students' ); ?>
                                        </td>
                                        <td style="width:30%; text-align:right">
                                            <a href="<?php echo esc_url( $score_url ); ?>" class="edu-btn edu-btn--lime">
                                                Record CA
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Secondary Section Grid: Class Teacher Headed + Invigilation Schedule -->
        <div class="edu-grid-2">

            <!-- Class Teacher Overview -->
            <?php if ( ! empty( $class_teacher_of ) ) : ?>
                <div class="edu-card">
                    <div class="edu-card-header">
                        <h2 class="edu-card-title">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3 3 8l9 5 9-5-9-5Z"/><path d="M3 12l9 5 9-5"/></svg>
                            Classes I Head
                        </h2>
                        <span class="edu-pill edu-pill--class">Class Teacher</span>
                    </div>
                    <div class="edu-card-body edu-card-body--no-pad">
                        <div class="edu-list">
                        <?php foreach ( $class_teacher_of as $h ) : ?>
                            <div class="edu-list-item">
                                <div>
                                    <strong style="font-size:13.5px; color:var(--ink-900)"><?php echo esc_html( (string) $h['class_name'] ); ?></strong>
                                    <span style="font-size:12px; color:var(--ink-500); margin-left:8px"><?php echo esc_html( (string) $h['students'] ); ?> students</span>
                                </div>
                                <a class="edu-btn edu-btn--ghost" href="<?php echo esc_url( add_query_arg( [ 'class' => (int) $h['class_id'] ], home_url( '/portal/teacher/students/' ) ) ); ?>">View Students</a>
                            </div>
                        <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Invigilation Schedule -->
            <div class="edu-card">
                <div class="edu-card-header">
                    <h2 class="edu-card-title">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3.6-7.2 10-7.2 10 7.2 10 7.2-3.6 7.2-10 7.2-10-7.2-10-7.2Z"/><circle cx="12" cy="12" r="3"/></svg>
                        Invigilation Schedule
                    </h2>
                </div>
                <div class="edu-card-body edu-card-body--no-pad">
                    <?php if ( empty( $duties ) ) : ?>
                        <div class="edu-empty">No upcoming invigilation duties assigned.</div>
                    <?php else : ?>
                        <div class="edu-list">
                        <?php foreach ( $duties as $d ) : ?>
                            <div class="edu-list-item" style="flex-wrap:wrap;gap:6px">
                                <strong style="font-size:13.5px; color:var(--ink-900)"><?php echo esc_html( $d['subject_name'] . ' — ' . $d['class_name'] ); ?></strong>
                                <span style="font-size:12px; color:var(--ink-500); flex-shrink:0"><?php echo esc_html( wp_date( 'D j M, g:ia', strtotime( (string) $d['scheduled_at'] . ' UTC' ) ) ); ?></span>
                                <?php if ( ! empty( $d['access_code'] ) && (string) $d['status'] === 'published' ) : ?>
                                    <span style="font-size:12px;font-weight:700;letter-spacing:1px;background:#f0fdf4;padding:2px 8px;border-radius:4px;color:#166534;flex-shrink:0">Code: <?php echo esc_html( (string) $d['access_code'] ); ?></span>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        </div>

        <!-- Recent CA Tests Created -->
        <?php if ( ! empty( $my_tests ) ) : ?>
            <div class="edu-card">
                <div class="edu-card-header">
                    <h2 class="edu-card-title">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H7.5A2 2 0 0 0 5.5 4v16a2 2 0 0 0 2 2H17a2 2 0 0 0 2-2V8l-5-6Z"/><path d="M14 2v6h5"/></svg>
                        Recent CA Tests
                    </h2>
                </div>
                <div class="edu-card-body edu-card-body--no-pad">
                    <table class="edu-tbl">
                        <thead>
                            <tr>
                                <th>Subject</th>
                                <th>Class</th>
                                <th>Scheduled</th>
                                <th>Questions</th>
                                <th>Status</th>
                                <th style="text-align:right">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ( $my_tests as $t ) : ?>
                            <tr>
                                <td><strong><?php echo esc_html( (string) $t['subject_name'] ); ?></strong></td>
                                <td><span class="edu-pill edu-pill--class"><?php echo esc_html( (string) $t['class_name'] ); ?></span></td>
                                <td style="font-size:12.5px; color:var(--ink-700)"><?php echo esc_html( wp_date( 'j M, g:ia', strtotime( (string) $t['scheduled_at'] . ' UTC' ) ) ); ?></td>
                                <td><?php echo esc_html( (string) (int) $t['question_count'] ); ?></td>
                                <td><span class="edu-pill edu-pill--<?php echo esc_attr( (string) $t['status'] ); ?>"><?php echo esc_html( ucfirst( (string) $t['status'] ) ); ?></span></td>
                                <td style="text-align:right">
                                    <?php if ( (string) $t['status'] !== 'published' ) : ?>
                                        <?php if ( (int) $t['composed'] === 0 ) : ?>
                                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;margin-right:4px">
                                            <input type="hidden" name="action" value="educbt_recompose_paper">
                                            <input type="hidden" name="paper_id" value="<?php echo esc_attr( (string) $t['id'] ); ?>">
                                            <?php wp_nonce_field( 'educbt_recompose_paper' ); ?>
                                            <button type="submit" class="edu-btn edu-btn--ghost">Recompose</button>
                                        </form>
                                        <?php endif; ?>
                                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
                                            <input type="hidden" name="action" value="educbt_publish_paper">
                                            <input type="hidden" name="paper_id" value="<?php echo esc_attr( (string) $t['id'] ); ?>">
                                            <?php wp_nonce_field( 'educbt_publish_paper' ); ?>
                                            <button type="submit" class="edu-btn edu-btn--lime">Publish</button>
                                        </form>
                                    <?php else : ?>
                                        <span class="edu-pill edu-pill--published">Live</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        <!-- Question Bank Pending Notice -->
        <?php if ( $my_pending_questions > 0 ) : ?>
            <div class="edu-card">
                <div class="edu-card-header">
                    <h2 class="edu-card-title">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M9.3 9.2a2.7 2.7 0 0 1 5 1.4c0 1.9-2.2 1.8-2.7 3.4"/><path d="M12 17h.01"/></svg>
                        Question Bank
                    </h2>
                </div>
                <div class="edu-card-body">
                    <p style="margin-top:0; font-size:13.5px; color:var(--ink-700)">
                        <strong><?php echo esc_html( (string) $my_pending_questions ); ?></strong> of your questions are awaiting approval by the exam officer.
                    </p>
                    <a class="edu-btn edu-btn--ghost" href="<?php echo esc_url( home_url( '/portal/exams/questions/' ) ); ?>">Go to Question Bank</a>
                </div>
            </div>
        <?php endif; ?>

    </div>
    <?php
};

require EDUCBT_PRO_PATH . 'templates/portal/shell.php';
