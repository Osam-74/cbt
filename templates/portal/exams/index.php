<?php
/**
 * Examinations overview — redesigned with dark forest/lime design system.
 *
 * Works for both principal (school-wide) and teacher views. Teachers see
 * stats scoped to their assigned subjects/classes.
 *
 * @var array<string,mixed> $educbt
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

global $wpdb;
$school_id = (int) $educbt['school_id'];
$scope     = $educbt['scope'];
$actor     = $scope->actor();
$is_wide   = $scope->is_school_wide();

$papers    = \EduCBTPro\Core\Schema::table( 'exam_papers' );
$subjects  = \EduCBTPro\Core\Schema::table( 'subjects_v2' );
$classes   = \EduCBTPro\Core\Schema::table( 'classes' );
$attempts  = \EduCBTPro\Core\Schema::table( 'attempts' );
$questions = $wpdb->prefix . 'educbt_questions';
$answers   = \EduCBTPro\Core\Schema::table( 'attempt_answers' );

// ── Build scope conditions ────────────────────────────────────────
//
// For teachers, filter papers to their assigned subjects+classes.
// For school-wide users, no extra filtering.
$scope_subjects = [];
$scope_classes   = [];

if ( ! $is_wide && $actor['type'] === \EduCBTPro\Core\Scope::ACTOR_STAFF ) {
    $assignments = $scope->assignments();
    // subject_teacher entries are "subject_id:class_id" pairs
    foreach ( $assignments['subject_teacher'] as $key ) {
        $parts = explode( ':', (string) $key );
        $subj  = (int) ( $parts[0] ?? 0 );
        $cls   = (int) ( $parts[1] ?? 0 );
        if ( $subj > 0 ) {
            $scope_subjects[] = $subj;
        }
        if ( $cls > 0 ) {
            $scope_classes[] = $cls;
        }
    }
    // Also include classes where they're class_teacher
    foreach ( $assignments['class_teacher'] as $cls ) {
        if ( $cls > 0 ) {
            $scope_classes[] = $cls;
        }
    }
    $scope_subjects = array_unique( $scope_subjects );
    $scope_classes   = array_unique( $scope_classes );
}

// Build the WHERE clause for scoping
$scope_where = '';
$scope_args  = [];
if ( ! $is_wide && ( ! empty( $scope_subjects ) || ! empty( $scope_classes ) ) ) {
    $conditions = [];
    if ( ! empty( $scope_subjects ) ) {
        $placeholders = implode( ',', array_fill( 0, count( $scope_subjects ), '%d' ) );
        $conditions[] = "p.subject_id IN ($placeholders)";
        $scope_args = array_merge( $scope_args, $scope_subjects );
    }
    if ( ! empty( $scope_classes ) ) {
        $placeholders = implode( ',', array_fill( 0, count( $scope_classes ), '%d' ) );
        $conditions[] = "p.class_id IN ($placeholders)";
        $scope_args = array_merge( $scope_args, $scope_classes );
    }
    if ( ! empty( $conditions ) ) {
        $scope_where = ' AND (' . implode( ' OR ', $conditions ) . ')';
    }
}

/**
 * Helper: run a scoped count query.
 */
$educbt_exams_scoped_count = static function ( string $base_query, array $base_args, string $scope_where, array $scope_args ): int {
    global $wpdb;
    $sql = $base_query . $scope_where;
    $all_args = array_merge( $base_args, $scope_args );
    return (int) $wpdb->get_var( $wpdb->prepare( $sql, $all_args ) );
};

// ── Stats ──────────────────────────────────────────────────────────
if ( $is_wide ) {
    $stats = [
        'questions'   => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$questions} WHERE school_id = %d AND status = 'active'", $school_id ) ),
        'papers'      => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$papers} p WHERE p.school_id = %d AND p.status <> 'cancelled'", $school_id ) ),
        'published'   => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$papers} p WHERE p.school_id = %d AND p.status = 'published'", $school_id ) ),
        'unpublished' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$papers} p WHERE p.school_id = %d AND p.status = 'unpublished'", $school_id ) ),
        'sat'         => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$attempts} WHERE school_id = %d AND status = 'graded'", $school_id ) ),
        'in_progress' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$attempts} WHERE school_id = %d AND status = 'in_progress'", $school_id ) ),
    ];
} else {
    // Teacher view — scope to their subjects/classes
    $stats = [
        'questions'   => 0,
        'papers'      => 0,
        'published'   => 0,
        'unpublished' => 0,
        'sat'         => 0,
        'in_progress' => 0,
    ];

    if ( ! empty( $scope_subjects ) ) {
        $placeholders = implode( ',', array_fill( 0, count( $scope_subjects ), '%d' ) );
        $stats['questions'] = (int) $wpdb->get_var(
            $wpdb->prepare( "SELECT COUNT(*) FROM {$questions} WHERE school_id = %d AND status = 'active' AND subject_id IN ($placeholders)", array_merge( [ $school_id ], $scope_subjects ) )
        );
    }

    if ( $scope_where !== '' ) {
        $stats['papers'] = $educbt_exams_scoped_count(
            "SELECT COUNT(*) FROM {$papers} p WHERE p.school_id = %d AND p.status <> 'cancelled'",
            [ $school_id ],
            $scope_where,
            $scope_args
        );
        $stats['published'] = $educbt_exams_scoped_count(
            "SELECT COUNT(*) FROM {$papers} p WHERE p.school_id = %d AND p.status = 'published'",
            [ $school_id ],
            $scope_where,
            $scope_args
        );
        $stats['unpublished'] = $educbt_exams_scoped_count(
            "SELECT COUNT(*) FROM {$papers} p WHERE p.school_id = %d AND p.status = 'unpublished'",
            [ $school_id ],
            $scope_where,
            $scope_args
        );
    }

    // Attempts — scope by paper IDs the teacher can see
    if ( $scope_where !== '' ) {
        $paper_ids_sql = "SELECT p.id FROM {$papers} p WHERE p.school_id = %d AND p.status <> 'cancelled'" . $scope_where;
        $all_args = array_merge( [ $school_id ], $scope_args );
        $visible_paper_ids = $wpdb->get_col( $wpdb->prepare( $paper_ids_sql, $all_args ) );
        if ( ! empty( $visible_paper_ids ) ) {
            $id_placeholders = implode( ',', array_fill( 0, count( $visible_paper_ids ), '%d' ) );
            $stats['sat'] = (int) $wpdb->get_var(
                $wpdb->prepare( "SELECT COUNT(*) FROM {$attempts} WHERE paper_id IN ($id_placeholders) AND status = 'graded'", $visible_paper_ids )
            );
            $stats['in_progress'] = (int) $wpdb->get_var(
                $wpdb->prepare( "SELECT COUNT(*) FROM {$attempts} WHERE paper_id IN ($id_placeholders) AND status = 'in_progress'", $visible_paper_ids )
            );
        }
    }
}

// Theory marking progress
$theory_total  = 0;
$theory_marked = 0;
if ( $is_wide ) {
    $theory_total = (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM {$answers} a
             INNER JOIN {$attempts} at ON at.id = a.attempt_id
             INNER JOIN {$questions} q ON q.id = a.question_id
             WHERE at.school_id = %d AND q.question_type = 'theory'",
            $school_id
        )
    );
    $theory_marked = (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM {$answers} a
             INNER JOIN {$attempts} at ON at.id = a.attempt_id
             INNER JOIN {$questions} q ON q.id = a.question_id
             WHERE at.school_id = %d AND q.question_type = 'theory' AND a.is_correct IS NOT NULL",
            $school_id
        )
    );
} elseif ( $scope_where !== '' ) {
    $paper_ids_sql = "SELECT p.id FROM {$papers} p WHERE p.school_id = %d AND p.status <> 'cancelled'" . $scope_where;
    $all_args = array_merge( [ $school_id ], $scope_args );
    $visible_paper_ids = $wpdb->get_col( $wpdb->prepare( $paper_ids_sql, $all_args ) );
    if ( ! empty( $visible_paper_ids ) ) {
        $id_placeholders = implode( ',', array_fill( 0, count( $visible_paper_ids ), '%d' ) );
        $theory_total = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$answers} a
                 INNER JOIN {$attempts} at ON at.id = a.attempt_id
                 INNER JOIN {$questions} q ON q.id = a.question_id
                 WHERE at.paper_id IN ($id_placeholders) AND q.question_type = 'theory'",
                $visible_paper_ids
            )
        );
        $theory_marked = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$answers} a
                 INNER JOIN {$attempts} at ON at.id = a.attempt_id
                 INNER JOIN {$questions} q ON q.id = a.question_id
                 WHERE at.paper_id IN ($id_placeholders) AND q.question_type = 'theory' AND a.is_correct IS NOT NULL",
                $visible_paper_ids
            )
        );
    }
}
$stats['theory_pending'] = $theory_total - $theory_marked;

// ── Upcoming papers ────────────────────────────────────────────────
if ( $is_wide ) {
    $upcoming = (array) $wpdb->get_results(
        $wpdb->prepare(
            "SELECT p.id, p.scheduled_at, p.duration_seconds, p.status, p.is_practice,
                    p.question_count, p.requires_access_code,
                    s.name AS subject_name, c.display_name AS class_name
             FROM {$papers} p
             INNER JOIN {$subjects} s ON s.id = p.subject_id
             LEFT JOIN {$classes} c ON c.id = p.class_id
             WHERE p.school_id = %d AND p.status <> 'cancelled' AND p.scheduled_at >= DATE_SUB(%s, INTERVAL 1 DAY)
             ORDER BY p.scheduled_at ASC LIMIT 12",
            $school_id,
            current_time( 'mysql', true )
        ),
        ARRAY_A
    );
} else {
    $upcoming = [];
    if ( $scope_where !== '' ) {
        $sql = "SELECT p.id, p.scheduled_at, p.duration_seconds, p.status, p.is_practice,
                       p.question_count, p.requires_access_code,
                       s.name AS subject_name, c.display_name AS class_name
                FROM {$papers} p
                INNER JOIN {$subjects} s ON s.id = p.subject_id
                LEFT JOIN {$classes} c ON c.id = p.class_id
                WHERE p.school_id = %d AND p.status <> 'cancelled'
                  AND p.scheduled_at >= DATE_SUB(%s, INTERVAL 1 DAY)"
              . $scope_where .
              " ORDER BY p.scheduled_at ASC LIMIT 12";
        $all_args = array_merge( [ $school_id, current_time( 'mysql', true ) ], $scope_args );
        $upcoming = (array) $wpdb->get_results( $wpdb->prepare( $sql, $all_args ), ARRAY_A );
    }
}

// ── Recent sittings ────────────────────────────────────────────────
if ( $is_wide ) {
    $recent_sittings = (array) $wpdb->get_results(
        $wpdb->prepare(
            "SELECT p.id, p.scheduled_at, p.is_practice, s.name AS subject_name,
                    c.display_name AS class_name,
                    COUNT(at.id) AS sat_count,
                    SUM(CASE WHEN at.status = 'graded' THEN 1 ELSE 0 END) AS graded_count
             FROM {$papers} p
             INNER JOIN {$subjects} s ON s.id = p.subject_id
             LEFT JOIN {$classes} c ON c.id = p.class_id
             LEFT JOIN {$attempts} at ON at.paper_id = p.id
             WHERE p.school_id = %d AND p.status <> 'cancelled'
             GROUP BY p.id
             HAVING sat_count > 0
             ORDER BY p.scheduled_at DESC LIMIT 8",
            $school_id
        ),
        ARRAY_A
    );
} else {
    $recent_sittings = [];
    if ( $scope_where !== '' ) {
        $sql = "SELECT p.id, p.scheduled_at, p.is_practice, s.name AS subject_name,
                       c.display_name AS class_name,
                       COUNT(at.id) AS sat_count,
                       SUM(CASE WHEN at.status = 'graded' THEN 1 ELSE 0 END) AS graded_count
                FROM {$papers} p
                INNER JOIN {$subjects} s ON s.id = p.subject_id
                LEFT JOIN {$classes} c ON c.id = p.class_id
                LEFT JOIN {$attempts} at ON at.paper_id = p.id
                WHERE p.school_id = %d AND p.status <> 'cancelled'"
              . $scope_where .
              " GROUP BY p.id
                HAVING sat_count > 0
                ORDER BY p.scheduled_at DESC LIMIT 8";
        $all_args = array_merge( [ $school_id ], $scope_args );
        $recent_sittings = (array) $wpdb->get_results( $wpdb->prepare( $sql, $all_args ), ARRAY_A );
    }
}


// ── The examination officer's own pipeline ────────────────────────────────
//
// The tiles above report volume: how many questions, how many papers. That is
// the right summary for a principal, but it does not tell the person running
// the examination what to do next.
//
// This does. Each stage reports whether it is finished, and links to the screen
// where the outstanding work is done. The stages are in the order they must
// happen, because each one depends on the one before it.
$pipeline = [];

if ( $is_wide ) {
    $sets_table = \EduCBTPro\Core\Schema::table( 'question_sets' );
    $series_tbl = \EduCBTPro\Core\Schema::table( 'exam_series' );

    $ay              = new \EduCBTPro\Services\AcademicYearService();
    $cur_session     = $ay->current_session( $school_id );
    $cur_session_id  = absint( $cur_session['id'] ?? 0 );
    $cur_term        = $ay->resolve_current_term( $school_id, $cur_session_id );
    $cur_term_id     = absint( $cur_term['id'] ?? 0 );

    $set_counts = (array) $wpdb->get_row(
        $wpdb->prepare(
            "SELECT
                SUM(CASE WHEN status = 'draft' THEN 1 ELSE 0 END)                 AS drafting,
                SUM(CASE WHEN status IN ('submitted','under_review') THEN 1 ELSE 0 END) AS awaiting,
                SUM(CASE WHEN status = 'returned' THEN 1 ELSE 0 END)              AS returned,
                SUM(CASE WHEN status IN ('approved','published') THEN 1 ELSE 0 END) AS approved,
                COUNT(*) AS total
             FROM {$sets_table}
             WHERE school_id = %d AND session_id = %d AND COALESCE(term_id,0) = %d",
            $school_id,
            $cur_session_id,
            $cur_term_id
        ),
        ARRAY_A
    );

    $awaiting  = absint( $set_counts['awaiting'] ?? 0 );
    $approved  = absint( $set_counts['approved'] ?? 0 );
    $drafting  = absint( $set_counts['drafting'] ?? 0 );
    $returned  = absint( $set_counts['returned'] ?? 0 );

    // The examination itself, and whether its timetable has been released.
    $series = (array) $wpdb->get_row(
        $wpdb->prepare(
            "SELECT id, title, status FROM {$series_tbl}
             WHERE school_id = %d AND session_id = %d AND COALESCE(term_id,0) = %d
             ORDER BY id DESC LIMIT 1",
            $school_id,
            $cur_session_id,
            $cur_term_id
        ),
        ARRAY_A
    );

    $series_id     = absint( $series['id'] ?? 0 );
    $scheduled     = $series_id > 0
        ? absint( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$papers} WHERE school_id = %d AND series_id = %d AND status <> 'cancelled'", $school_id, $series_id ) ) )
        : 0;
    $released      = $series_id > 0 && (string) ( $series['status'] ?? '' ) === 'published';
    $unpublished   = $series_id > 0
        ? absint( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$papers} WHERE school_id = %d AND series_id = %d AND status = 'draft'", $school_id, $series_id ) ) )
        : 0;

    $theory_left = absint( $stats['theory_pending'] ?? 0 );
    $sat_count   = absint( $stats['sat'] ?? 0 );

    $pipeline = [
        [
            'label'  => 'Questions submitted',
            'state'  => $drafting > 0 ? 'wait' : ( ( $set_counts['total'] ?? 0 ) > 0 ? 'done' : 'idle' ),
            'note'   => $drafting > 0
                ? $drafting . ' subject(s) still being written by teachers'
                : ( ( $set_counts['total'] ?? 0 ) > 0 ? 'Every started subject has been handed in' : 'No subject has been started yet' ),
            'link'   => home_url( '/portal/exams/approvals/' ),
            'action' => 'View queue',
        ],
        [
            'label'  => 'Reviewed and approved',
            'state'  => $awaiting > 0 ? 'act' : ( $approved > 0 ? 'done' : 'idle' ),
            'note'   => $awaiting > 0
                ? $awaiting . ' subject(s) waiting on your decision'
                : ( $returned > 0
                    ? $approved . ' approved · ' . $returned . ' sent back and not yet resubmitted'
                    : ( $approved > 0 ? $approved . ' subject(s) approved' : 'Nothing approved yet' ) ),
            'link'   => home_url( '/portal/exams/approvals/' ),
            'action' => $awaiting > 0 ? 'Review now' : 'Open approvals',
        ],
        [
            'label'  => 'Examination created',
            'state'  => $series_id > 0 ? 'done' : 'act',
            'note'   => $series_id > 0
                ? (string) $series['title']
                : 'No examination exists for this term yet',
            'link'   => home_url( '/portal/exams/papers/' ),
            'action' => $series_id > 0 ? 'Open' : 'Create examination',
        ],
        [
            'label'  => 'Timetable built',
            'state'  => $scheduled > 0 ? 'done' : ( $approved > 0 ? 'act' : 'idle' ),
            'note'   => $scheduled > 0
                ? $scheduled . ' paper(s) scheduled'
                : ( $approved > 0 ? 'Approved questions are ready to be scheduled' : 'Nothing approved to schedule yet' ),
            'link'   => home_url( '/portal/exams/timetable/' . ( $series_id > 0 ? '?series=' . $series_id : '' ) ),
            'action' => $scheduled > 0 ? 'Adjust' : 'Generate schedule',
        ],
        [
            'label'  => 'Released to class teachers',
            'state'  => $released ? 'done' : ( $scheduled > 0 ? 'act' : 'idle' ),
            'note'   => $released
                ? 'Class teachers can see their own schedule'
                : ( $scheduled > 0 ? 'Built but not yet sent — teachers cannot see it' : 'Nothing to release yet' ),
            'link'   => home_url( '/portal/exams/timetable/' . ( $series_id > 0 ? '?series=' . $series_id : '' ) ),
            'action' => 'Open timetable',
        ],
        [
            'label'  => 'Papers published',
            'state'  => ( $scheduled > 0 && $unpublished === 0 ) ? 'done' : ( $scheduled > 0 ? 'act' : 'idle' ),
            'note'   => $scheduled > 0
                ? ( $unpublished === 0
                    ? 'All papers are live for students'
                    : $unpublished . ' paper(s) still in draft — students cannot open them' )
                : 'No papers yet',
            'link'   => home_url( '/portal/exams/papers/' ),
            'action' => 'Open papers',
        ],
        [
            'label'  => 'Marking complete',
            // Nothing sat yet is not the same as nothing left to mark. Reporting
            // this as done before a single paper has been written would have told
            // the officer the term was finished on the day it began.
            'state'  => $sat_count > 0
                ? ( $theory_left > 0 ? 'act' : 'done' )
                : 'idle',
            'note'   => $sat_count > 0
                ? ( $theory_left > 0
                    ? $theory_left . ' written answer(s) still to be marked'
                    : 'Every written answer has been marked' )
                : 'No paper has been sat yet',
            'link'   => home_url( '/portal/exams/marking/' ),
            'action' => 'Marking status',
        ],
    ];
}

$flash = \EduCBTPro\Frontend\PortalActions::flash();

// An examination officer lands here. Naming the page after their job, rather
// than after the records it contains, is the difference between a dashboard and
// a filing cabinet.
$educbt_role = (string) ( $actor['role'] ?? '' );

$educbt_heading = 'Examinations';

if ( $educbt_role === \EduCBTPro\Core\Capabilities::ROLE_EXAM_OFFICER ) {
    $educbt_heading = 'Examination Officer Dashboard';
} elseif ( $educbt_role === \EduCBTPro\Core\Capabilities::ROLE_VICE_PRINCIPAL ) {
    $educbt_heading = 'Vice Principal — Examinations';
} elseif ( ! $is_wide ) {
    $educbt_heading = 'My Examinations';
}

$educbt_title = $educbt_heading;

$educbt_body = static function () use ( $stats, $upcoming, $recent_sittings, $is_wide, $flash, $pipeline, $educbt_heading ): void {
    require EDUCBT_PRO_PATH . 'templates/portal/partials/flash.php';
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
        /* Examination pipeline */
        .edu-pipeline { list-style:none; margin:0; padding:0; }
        .edu-pipe { display:flex; align-items:center; gap:14px; padding:13px 22px; border-bottom:1px solid var(--line); }
        .edu-pipe:last-child { border-bottom:none; }
        .edu-pipe__num { width:26px; height:26px; border-radius:50%; display:flex; align-items:center;
            justify-content:center; font-size:12px; font-weight:700; flex-shrink:0;
            background:var(--canvas); color:var(--ink-500); border:1px solid var(--line); }
        .edu-pipe__text { flex:1; min-width:0; }
        .edu-pipe__text strong { display:block; font-size:13.5px; font-weight:600; color:var(--ink-900); }
        .edu-pipe__text span { font-size:12.5px; color:var(--ink-500); }
        .edu-pipe__go { font-size:12.5px; font-weight:600; text-decoration:none; color:var(--ink-500); white-space:nowrap; }
        .edu-pipe--done .edu-pipe__num { background:var(--lime-100); color:#2C6B36; border-color:var(--lime-300); }
        .edu-pipe--act .edu-pipe__num { background:#0F2818; color:var(--lime-400); border-color:#0F2818; }
        .edu-pipe--act { background:#FCFEF8; }
        .edu-pipe--act .edu-pipe__go { color:#2C6B36; }
        .edu-pipe--idle .edu-pipe__text strong,
        .edu-pipe--idle .edu-pipe__go { color:var(--ink-400); }
        @media (max-width:640px) {
            .edu-pipe { flex-wrap:wrap; }
            .edu-pipe__go { width:100%; padding-left:40px; }
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

        .edu-stat-grid {
            display: grid;
            grid-template-columns: repeat(6, 1fr);
            gap: 14px;
        }
        @media (max-width: 1024px) {
            .edu-stat-grid { grid-template-columns: repeat(3, 1fr); }
        }
        @media (max-width: 600px) {
            .edu-stat-grid { grid-template-columns: repeat(2, 1fr); }
        }

        .edu-stat-card {
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: var(--r-lg);
            padding: 18px 20px;
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            gap: 8px;
            box-shadow: var(--shadow-card);
            transition: transform .15s ease, box-shadow .15s ease;
        }

        .edu-stat-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-hover);
        }

        .edu-stat-icon {
            width: 38px;
            height: 38px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .edu-stat-icon svg {
            width: 19px;
            height: 19px;
        }

        .edu-stat-value {
            font-family: var(--font-display);
            font-size: 28px;
            font-weight: 700;
            color: var(--ink-900);
            line-height: 1.1;
        }

        .edu-stat-label {
            font-size: 11.5px;
            font-weight: 600;
            letter-spacing: .03em;
            color: var(--ink-500);
            text-transform: uppercase;
        }

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

        .edu-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13.5px;
        }

        .edu-table th {
            text-align: left;
            font-weight: 600;
            font-size: 11px;
            letter-spacing: .04em;
            text-transform: uppercase;
            color: var(--ink-500);
            padding: 10px 16px;
            border-bottom: 1px solid var(--line);
            background: var(--canvas);
        }

        .edu-table td {
            padding: 12px 16px;
            border-bottom: 1px solid var(--line-soft);
            color: var(--ink-900);
            vertical-align: middle;
        }

        .edu-table tr:last-child td {
            border-bottom: none;
        }

        .edu-table tr:hover td {
            background: var(--lime-100);
        }

        .edu-pill {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 3px 10px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 600;
            line-height: 1.4;
        }
        .edu-pill--published, .edu-pill--approved, .edu-pill--success {
            background: var(--success-bg);
            color: var(--success-text);
        }
        .edu-pill--unpublished, .edu-pill--draft, .edu-pill--pending {
            background: #EEF0EB;
            color: var(--ink-500);
        }
        .edu-pill--live {
            background: var(--live-bg);
            color: var(--live-text);
        }
        .edu-pill--danger {
            background: var(--danger-bg);
            color: var(--danger-text);
        }

        .edu-progress {
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .edu-progress__bar {
            background: var(--line-soft);
            border-radius: 6px;
            height: 7px;
            overflow: hidden;
            width: 100px;
        }
        .edu-progress__fill {
            background: var(--forest-900);
            height: 100%;
            border-radius: 6px;
        }
        .edu-progress__text {
            font-size: 11px;
            font-weight: 600;
            color: var(--ink-500);
        }

        .edu-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 16px;
            border-radius: 10px;
            font-family: var(--font-body);
            font-size: 13px;
            font-weight: 600;
            text-decoration: none;
            border: none;
            cursor: pointer;
            transition: background .15s ease, transform .15s ease;
        }
        .edu-btn--primary {
            background: var(--forest-900);
            color: var(--lime-300);
        }
        .edu-btn--primary:hover {
            background: var(--forest-950);
            transform: translateY(-1px);
        }
        .edu-btn--ghost {
            background: transparent;
            color: var(--ink-700);
            border: 1px solid var(--line);
        }
        .edu-btn--ghost:hover {
            background: var(--canvas);
        }

        .edu-muted {
            color: var(--ink-500);
            font-size: 13px;
        }

        .edu-section-link {
            font-size: 12px;
            font-weight: 600;
            color: var(--forest-900);
            text-decoration: none;
        }
        .edu-section-link:hover {
            text-decoration: underline;
        }
    </style>

    <div class="edu-dash-wrap">
        <!-- Header -->
        <div class="edu-dash-header">
            <div>
                <h2 class="edu-dash-header__title"><?php echo esc_html( $educbt_heading ); ?></h2>
                <p class="edu-dash-header__sub">
                    <?php echo $is_wide
                        ? esc_html__( 'Exam papers, CA tests, marking progress, and upcoming schedule', 'educbt-pro' )
                        : esc_html__( 'Your exam papers, CA tests, and marking queue', 'educbt-pro' );
                    ?>
                </p>
            </div>
            <?php if ( $is_wide ) : ?>
                <a class="edu-btn edu-btn--primary" href="<?php echo esc_url( home_url( '/portal/exams/papers/' ) ); ?>">Create examination</a>
            <?php endif; ?>
        </div>

        <!-- Stat tiles -->
        <div class="edu-stat-grid">
            <div class="edu-stat-card">
                <div class="edu-stat-icon" style="background:var(--lime-100);color:#2C6B36">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
                </div>
                <div class="edu-stat-value"><?php echo esc_html( (string) $stats['questions'] ); ?></div>
                <div class="edu-stat-label">Questions</div>
            </div>
            <div class="edu-stat-card">
                <div class="edu-stat-icon" style="background:#E9F0FF;color:#2C4B8A">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                </div>
                <div class="edu-stat-value"><?php echo esc_html( (string) $stats['papers'] ); ?></div>
                <div class="edu-stat-label">Total papers</div>
            </div>
            <div class="edu-stat-card">
                <div class="edu-stat-icon" style="background:var(--success-bg);color:var(--success-text)">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                </div>
                <div class="edu-stat-value"><?php echo esc_html( (string) $stats['published'] ); ?></div>
                <div class="edu-stat-label">Published</div>
            </div>
            <div class="edu-stat-card">
                <div class="edu-stat-icon" style="background:var(--live-bg);color:var(--live-text)">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>
                </div>
                <div class="edu-stat-value"><?php echo esc_html( (string) $stats['sat'] ); ?></div>
                <div class="edu-stat-label">Sat &amp; graded</div>
            </div>
            <?php if ( $stats['theory_pending'] > 0 ) : ?>
            <div class="edu-stat-card">
                <div class="edu-stat-icon" style="background:var(--danger-bg);color:var(--danger-text)">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 9v2m0 4h.01"/><circle cx="12" cy="12" r="10"/></svg>
                </div>
                <div class="edu-stat-value"><?php echo esc_html( (string) $stats['theory_pending'] ); ?></div>
                <div class="edu-stat-label">Answers to mark</div>
            </div>
            <?php endif; ?>
        </div>

        <?php if ( ! empty( $pipeline ) ) : ?>
        <?php
        // Stages in the order they must happen. `act` means the next move is
        // yours; `wait` means somebody else owes work; `done` is finished.
        $done_count = count( array_filter( $pipeline, static fn( array $p ): bool => $p['state'] === 'done' ) );
        ?>
        <div class="edu-card" style="margin-bottom:20px">
            <div class="edu-card-header">
                <h3 class="edu-card-title">This term&rsquo;s examination</h3>
                <span class="edu-muted" style="font-size:12.5px">
                    <?php echo esc_html( $done_count . ' of ' . count( $pipeline ) . ' stages complete' ); ?>
                </span>
            </div>
            <div class="edu-card-body" style="padding:0">
                <ol class="edu-pipeline">
                    <?php foreach ( $pipeline as $i => $stage ) : ?>
                        <li class="edu-pipe edu-pipe--<?php echo esc_attr( $stage['state'] ); ?>">
                            <span class="edu-pipe__num"><?php echo esc_html( (string) ( $i + 1 ) ); ?></span>
                            <span class="edu-pipe__text">
                                <strong><?php echo esc_html( $stage['label'] ); ?></strong>
                                <span><?php echo esc_html( $stage['note'] ); ?></span>
                            </span>
                            <a class="edu-pipe__go" href="<?php echo esc_url( $stage['link'] ); ?>">
                                <?php echo esc_html( $stage['action'] ); ?> &rarr;
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ol>
            </div>
        </div>
        <?php endif; ?>

        <!-- Two-column grid -->
        <div class="edu-grid-2">
            <!-- Upcoming papers -->
            <div class="edu-card">
                <div class="edu-card-header">
                    <h3 class="edu-card-title">Next papers</h3>
                    <a class="edu-section-link" href="<?php echo esc_url( home_url( '/portal/exams/timetable/' ) ); ?>">Timetable →</a>
                </div>
                <div class="edu-card-body" style="padding:0">
                    <?php if ( empty( $upcoming ) ) : ?>
                        <div style="padding:20px 22px">
                            <p class="edu-muted">Nothing scheduled.</p>
                            <?php if ( $is_wide ) : ?>
                                <p class="edu-muted" style="margin-top:8px">Create the examination for a term, then teachers submit their questions against it.</p>
                            <?php else : ?>
                                <p class="edu-muted" style="margin-top:8px">Set up a class test for your students.</p>
                            <?php endif; ?>
                        </div>
                    <?php else : ?>
                        <table class="edu-table">
                            <thead><tr><th>Subject</th><th>Class</th><th>When</th><th>Type</th><th>Status</th></tr></thead>
                            <tbody>
                            <?php foreach ( $upcoming as $p ) : ?>
                                <tr>
                                    <td><strong><?php echo esc_html( (string) $p['subject_name'] ); ?></strong></td>
                                    <td><?php echo esc_html( $p['class_name'] ? educbt_class_level_name( (string) $p['class_name'] ) : '—' ); ?></td>
                                    <td><?php echo esc_html( wp_date( 'D j M, g:ia', strtotime( (string) $p['scheduled_at'] . ' UTC' ) ) ); ?></td>
                                    <td>
                                        <?php if ( (int) $p['is_practice'] === 1 ) : ?>
                                            <span class="edu-pill edu-pill--draft">CA</span>
                                        <?php else : ?>
                                            <span class="edu-pill edu-pill--published">Exam</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="edu-pill edu-pill--<?php echo esc_attr( (string) $p['status'] ); ?>">
                                            <?php echo esc_html( ucfirst( (string) $p['status'] ) ); ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Recent sittings -->
            <div class="edu-card">
                <div class="edu-card-header">
                    <h3 class="edu-card-title">Recent sittings</h3>
                    <a class="edu-section-link" href="<?php echo esc_url( home_url( '/portal/exams/marking/' ) ); ?>">Marking →</a>
                </div>
                <div class="edu-card-body" style="padding:0">
                    <?php if ( empty( $recent_sittings ) ) : ?>
                        <div style="padding:20px 22px">
                            <p class="edu-muted">No exams have been sat yet.</p>
                        </div>
                    <?php else : ?>
                        <table class="edu-table">
                            <thead><tr><th>Subject</th><th>Type</th><th>Class</th><th>Sat</th><th>Graded</th><th>Progress</th></tr></thead>
                            <tbody>
                            <?php foreach ( $recent_sittings as $s ) :
                                $sat    = (int) $s['sat_count'];
                                $graded = (int) $s['graded_count'];
                                $pct    = $sat > 0 ? round( $graded / $sat * 100 ) : 0;
                                ?>
                                <tr>
                                    <td><strong><?php echo esc_html( (string) $s['subject_name'] ); ?></strong></td>
                                    <td><?php if ( (int) ( $s['is_practice'] ?? 0 ) === 1 ) : ?><span class="educbt-pill" style="background:#F3F7DC;color:#3F6B4A;border:1px solid #D3E64B">CA</span><?php else : ?><span class="educbt-pill" style="background:#E8F0E5;color:#173D26;border:1px solid #7C9473">Exam</span><?php endif; ?></td>
                                    <td><?php echo esc_html( $s['class_name'] ? educbt_class_level_name( (string) $s['class_name'] ) : '—' ); ?></td>
                                    <td><?php echo esc_html( (string) $sat ); ?></td>
                                    <td><?php echo esc_html( (string) $graded ); ?></td>
                                    <td>
                                        <div class="edu-progress">
                                            <div class="edu-progress__bar">
                                                <div class="edu-progress__fill" style="width:<?php echo esc_attr( (string) $pct ); ?>%"></div>
                                            </div>
                                            <span class="edu-progress__text"><?php echo esc_html( (string) $pct ); ?>%</span>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Quick links -->
        <?php if ( $is_wide ) : ?>
        <div class="edu-card">
            <div class="edu-card-header">
                <h3 class="edu-card-title">Examination management</h3>
            </div>
            <div class="edu-card-body" style="display:flex;flex-wrap:wrap;gap:10px">
                <a class="edu-btn edu-btn--ghost" href="<?php echo esc_url( home_url( '/portal/exams/papers/' ) ); ?>">Exam papers</a>
                <a class="edu-btn edu-btn--ghost" href="<?php echo esc_url( home_url( '/portal/exams/sessions/' ) ); ?>">Sessions</a>
                <a class="edu-btn edu-btn--ghost" href="<?php echo esc_url( home_url( '/portal/exams/timetable/' ) ); ?>">Timetable</a>
                <a class="edu-btn edu-btn--ghost" href="<?php echo esc_url( home_url( '/portal/exams/marking/' ) ); ?>">Marking status</a>
                <a class="edu-btn edu-btn--ghost" href="<?php echo esc_url( home_url( '/portal/exams/approvals/' ) ); ?>">Approvals</a>
                <a class="edu-btn edu-btn--ghost" href="<?php echo esc_url( home_url( '/portal/exams/broadsheet/' ) ); ?>">Broadsheet</a>
                <a class="edu-btn edu-btn--ghost" href="<?php echo esc_url( home_url( '/portal/exams/invigilation/' ) ); ?>">Invigilation</a>
            </div>
        </div>
        <?php else : ?>
        <div class="edu-card">
            <div class="edu-card-header">
                <h3 class="edu-card-title">Quick links</h3>
            </div>
            <div class="edu-card-body" style="display:flex;flex-wrap:wrap;gap:10px">
                <a class="edu-btn edu-btn--ghost" href="<?php echo esc_url( home_url( '/portal/exams/marking/' ) ); ?>">Mark answers</a>
                <a class="edu-btn edu-btn--ghost" href="<?php echo esc_url( home_url( '/portal/teacher/scores/' ) ); ?>">Record scores</a>
                <a class="edu-btn edu-btn--ghost" href="<?php echo esc_url( home_url( '/portal/exams/timetable/' ) ); ?>">View timetable</a>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <?php
};

require EDUCBT_PRO_PATH . 'templates/portal/shell.php';
