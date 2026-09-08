<?php
/**
 * Student dashboard — redesigned to match the approved HTML concept.
 *
 * Forest-green ticket card with stub, quick links, CA tests, examinations,
 * upcoming exams, and published results. All sections are data-driven.
 *
 * @var array<string,mixed> $educbt
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$school_id  = (int) $educbt['school_id'];
$scope      = $educbt['scope'];
$actor      = $scope->actor();
$student_id = (int) $actor['id'];
$user       = wp_get_current_user();

global $wpdb;

// ── Student profile ────────────────────────────────────────────────
$students_table = $wpdb->prefix . 'educbt_students';
$student_row    = $wpdb->get_row(
    $wpdb->prepare(
        "SELECT * FROM {$students_table} WHERE id = %d LIMIT 1",
        $student_id
    ),
    ARRAY_A
);

$student_row = is_array( $student_row ) ? $student_row : [];

$student_name = trim( (string) ( $student_row['full_name'] ?? '' ) );
if ( $student_name === '' ) {
    $first = trim( (string) ( $student_row['first_name'] ?? '' ) );
    $last  = trim( (string) ( $student_row['last_name'] ?? '' ) );
    $student_name = trim( $first . ' ' . $last );
}
if ( $student_name === '' ) {
    $student_name = $user->display_name ?: 'Student';
}
$first_name = trim( (string) ( $student_row['first_name'] ?? '' ) );
if ( $first_name === '' ) {
    $first_name = $student_name;
}

// ── Class label from the enrollments table (v2 path) ────────────────
$class_label = '';
$enrollments_t  = \EduCBTPro\Core\Schema::table( 'enrollments' );
$classes_table  = \EduCBTPro\Core\Schema::table( 'classes' );
$sessions_table = \EduCBTPro\Core\Schema::table( 'academic_sessions' );

$class_row = $wpdb->get_row(
    $wpdb->prepare(
        "SELECT c.display_name
         FROM {$enrollments_t} e
         INNER JOIN {$classes_table} c ON c.id = e.class_id
         INNER JOIN {$sessions_table} ss ON ss.id = e.session_id AND ss.is_current = 1
         WHERE e.student_id = %d AND e.status = 'active'
         LIMIT 1",
        $student_id
    ),
    ARRAY_A
);

if ( $class_row && is_array( $class_row ) ) {
    $class_label = trim( (string) ( $class_row['display_name'] ?? '' ) );
}

if ( $class_label === '' ) {
    $legacy_class = trim( (string) ( $student_row['class'] ?? '' ) );
    $legacy_arm   = trim( (string) ( $student_row['arm'] ?? '' ) );
    $class_label  = $legacy_class . ( $legacy_arm !== '' ? ' ' . $legacy_arm : '' );
    $class_label  = trim( $class_label );
}

// School name
$school_row  = $school_id > 0
    ? $wpdb->get_row( $wpdb->prepare( "SELECT school_name FROM {$wpdb->prefix}educbt_schools WHERE id = %d", $school_id ), ARRAY_A )
    : null;
$school_name = trim( (string) ( is_array( $school_row ) ? ( $school_row['school_name'] ?? '' ) : '' ) );
if ( $school_name === '' ) {
    $school_name = (string) get_bloginfo( 'name' );
}

// ── Active exam ────────────────────────────────────────────────────
$timetable = new \EduCBTPro\Services\TimetableService();
$active    = $timetable->active_for_student( $school_id, $student_id );
$upcoming  = $timetable->upcoming_for_student( $school_id, $student_id, 5 );

// ── Results ────────────────────────────────────────────────────────
$results_svc   = new \EduCBTPro\Services\ResultWorkflowService();
$term_results  = $results_svc->published_for_student( $school_id, $student_id );

// ── Available tests (published AND unpublished papers this student can see) ─────
$papers_table   = \EduCBTPro\Core\Schema::table( 'exam_papers' );
$subjects_table = \EduCBTPro\Core\Schema::table( 'subjects_v2' );
$registered_t   = \EduCBTPro\Core\Schema::table( 'student_subjects' );
$attempts_table = \EduCBTPro\Core\Schema::table( 'attempts' );

// Published papers this student can see. The attempt is fetched via a correlated
// subquery (not a LEFT JOIN) so a student with multiple attempts for the same
// paper does not produce duplicate rows.
$available_tests = (array) $wpdb->get_results(
    $wpdb->prepare(
        "SELECT p.id, p.scheduled_at, p.closes_at, p.duration_seconds, p.question_count, p.is_practice,
                p.requires_access_code, p.access_code, p.status, p.delivery_mode,
                s.name AS subject_name,
                (SELECT a.id FROM {$attempts_table} a
                  WHERE a.paper_id = p.id AND a.student_id = %d
                  ORDER BY a.id DESC LIMIT 1) AS attempt_id,
                (SELECT a2.status FROM {$attempts_table} a2
                  WHERE a2.paper_id = p.id AND a2.student_id = %d
                  ORDER BY a2.id DESC LIMIT 1) AS attempt_status
         FROM {$papers_table} p
         INNER JOIN {$subjects_table} s ON s.id = p.subject_id
         WHERE p.school_id = %d
           AND p.status = 'published'
           AND (
               EXISTS (SELECT 1 FROM {$registered_t} rs WHERE rs.student_id = %d AND rs.subject_id = p.subject_id)
               OR (p.class_id IS NOT NULL AND EXISTS (
                   SELECT 1 FROM {$enrollments_t} e
                   INNER JOIN {$sessions_table} ss ON ss.id = e.session_id AND ss.is_current = 1
                   WHERE e.student_id = %d AND e.class_id = p.class_id AND e.status = 'active'
               ))
               OR (p.class_id IS NULL AND EXISTS (
                   SELECT 1 FROM {$registered_t} rs2 WHERE rs2.student_id = %d AND rs2.subject_id = p.subject_id
               ))
           )
           AND (
               p.is_practice = 0
               OR p.closes_at IS NULL
               OR p.closes_at > %s
           )
         ORDER BY p.is_practice DESC, p.scheduled_at ASC",
        $student_id, $student_id, $school_id, $student_id, $student_id, $student_id, current_time( 'mysql', true )
    ),
    ARRAY_A
);

// ── Stats ──────────────────────────────────────────────────────────
$ca_completed = (int) $wpdb->get_var(
    $wpdb->prepare(
        "SELECT COUNT(*) FROM {$attempts_table} at
         INNER JOIN {$papers_table} p ON p.id = at.paper_id
         WHERE at.student_id = %d AND at.school_id = %d AND p.is_practice = 1
           AND at.status IN ('submitted','graded')",
        $student_id, $school_id
    )
);

$exams_completed = (int) $wpdb->get_var(
    $wpdb->prepare(
        "SELECT COUNT(*) FROM {$attempts_table} at
         INNER JOIN {$papers_table} p ON p.id = at.paper_id
         WHERE at.student_id = %d AND at.school_id = %d AND p.is_practice = 0
           AND at.status IN ('submitted','graded')",
        $student_id, $school_id
    )
);

// CA tests: only count papers visible to THIS student that are still open
$total_ca = (int) $wpdb->get_var(
    $wpdb->prepare(
        "SELECT COUNT(*) FROM {$papers_table} p
         WHERE p.school_id = %d AND p.status = 'published' AND p.is_practice = 1
           AND (p.closes_at IS NULL OR p.closes_at > %s)
           AND (
               EXISTS (SELECT 1 FROM {$registered_t} rs WHERE rs.student_id = %d AND rs.subject_id = p.subject_id)
               OR (p.class_id IS NOT NULL AND EXISTS (
                   SELECT 1 FROM {$enrollments_t} e
                   INNER JOIN {$sessions_table} ss ON ss.id = e.session_id AND ss.is_current = 1
                   WHERE e.student_id = %d AND e.class_id = p.class_id AND e.status = 'active'
               ))
               OR (p.class_id IS NULL AND EXISTS (
                   SELECT 1 FROM {$registered_t} rs2 WHERE rs2.student_id = %d AND rs2.subject_id = p.subject_id
               ))
           )",
        $school_id, current_time( 'mysql', true ), $student_id, $student_id, $student_id
    )
);

// Exams: count ALL published exams visible to this student — no time window filter.
// Once an exam is published it stays visible permanently (there's no unpublish for exams).
$total_exams = (int) $wpdb->get_var(
    $wpdb->prepare(
        "SELECT COUNT(*) FROM {$papers_table} p
         WHERE p.school_id = %d AND p.status = 'published' AND p.is_practice = 0
           AND (
               EXISTS (SELECT 1 FROM {$registered_t} rs WHERE rs.student_id = %d AND rs.subject_id = p.subject_id)
               OR (p.class_id IS NOT NULL AND EXISTS (
                   SELECT 1 FROM {$enrollments_t} e
                   INNER JOIN {$sessions_table} ss ON ss.id = e.session_id AND ss.is_current = 1
                   WHERE e.student_id = %d AND e.class_id = p.class_id AND e.status = 'active'
               ))
               OR (p.class_id IS NULL AND EXISTS (
                   SELECT 1 FROM {$registered_t} rs2 WHERE rs2.student_id = %d AND rs2.subject_id = p.subject_id
               ))
           )",
        $school_id, $student_id, $student_id, $student_id
    )
);

// Next exam
$next_exam_text = 'Not scheduled';
if ( ! empty( $upcoming ) ) {
    $next = $upcoming[0];
    $next_exam_text = $next['subject_name'] . ' — ' . wp_date( 'M j, g:ia', strtotime( (string) $next['scheduled_at'] . ' UTC' ) );
}

// Average score
$avg_score_text = 'No results yet';
if ( ! empty( $term_results ) ) {
    $sum = 0; $count = 0;
    foreach ( $term_results as $tr ) {
        if ( isset( $tr['average_score'] ) && (float) $tr['average_score'] > 0 ) {
            $sum += (float) $tr['average_score'];
            $count++;
        }
    }
    if ( $count > 0 ) {
        $avg_score_text = round( $sum / $count ) . '%';
    }
}

// Greeting based on time
$hour = (int) current_time( 'G' );
if ( $hour < 12 ) {
    $greeting = 'Good morning';
} elseif ( $hour < 17 ) {
    $greeting = 'Good afternoon';
} else {
    $greeting = 'Good evening';
}

$today_label = wp_date( 'l, j F' );

$educbt_title = 'Dashboard';

$educbt_body = static function () use (
    $greeting, $first_name, $school_name, $class_label, $today_label,
    $ca_completed, $exams_completed, $total_ca, $total_exams, $next_exam_text, $avg_score_text,
    $active, $upcoming, $term_results, $available_tests
): void {
    $has_active = ! empty( $active );

    // Separate available tests into CA tests and examinations
    $now_ts = time();
    $ca_tests = [];
    $exam_tests = [];
    foreach ( $available_tests as $test ) {
        $a_status = (string) ( $test['attempt_status'] ?? '' );
        if ( $a_status === 'submitted' || $a_status === 'graded' ) {
            continue;
        }
        if ( (int) $test['is_practice'] === 1 ) {
            $ca_tests[] = $test;
        } else {
            $exam_tests[] = $test;
        }
    }
    $has_ca   = ! empty( $ca_tests );
    $has_exam = ! empty( $exam_tests );
    ?>
    <div class="edu-dash">

        <!-- Greeting -->
        <section class="edu-dash__greeting">
            <h2><?php echo esc_html( $greeting . ', ' . $first_name ); ?></h2>
            <p><?php echo esc_html( $school_name . ( $class_label !== '' ? ' · ' . $class_label : '' ) . ' · ' . $today_label ); ?></p>
        </section>

        <!-- Stats row -->
        <section class="edu-dash__stats">
            <div class="edu-stat">
                <span class="edu-stat__label">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
                    CA tests done
                </span>
                <span class="edu-stat__value"><?php echo esc_html( $ca_completed . ' of ' . max( $total_ca, $ca_completed ) ); ?></span>
            </div>
            <div class="edu-stat">
                <span class="edu-stat__label">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
                    Exams completed
                </span>
                <span class="edu-stat__value"><?php echo esc_html( $exams_completed . ' of ' . max( $total_exams, $exams_completed ) ); ?></span>
            </div>
            <div class="edu-stat">
                <span class="edu-stat__label">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 3"/></svg>
                    Next exam
                </span>
                <span class="edu-stat__value"><?php echo esc_html( $next_exam_text ); ?></span>
            </div>
            <div class="edu-stat">
                <span class="edu-stat__label">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 20V10M12 20V4M20 20v-7"/></svg>
                    Average score
                </span>
                <span class="edu-stat__value"><?php echo esc_html( $avg_score_text ); ?></span>
            </div>
        </section>

        <!-- Main grid: ticket card + quick links -->
        <section class="edu-dash__main">

            <div class="edu-ticket">
                <div class="edu-ticket__main">
                    <?php if ( $has_active ) : ?>
                        <?php
                        $paper = $active[0];
                        $exam_url   = home_url( '/portal/student/exam/' . (int) $paper['id'] );
                        $btn_label  = empty( $paper['attempt_id'] ) ? 'Start exam' : 'Continue exam';
                        $is_written = (string) ( $paper['delivery_mode'] ?? 'cbt' ) === 'written';
                        $q_count    = (int) $paper['question_count'];
                        $duration   = (int) round( (int) $paper['duration_seconds'] / 60 );
                        $remaining  = '';
                        if ( ! empty( $paper['attempt_id'] ) && $paper['attempt_status'] === 'in_progress' ) {
                            $end = strtotime( (string) $paper['scheduled_at'] . ' UTC' ) + (int) $paper['duration_seconds'];
                            $mins_left = max( 0, (int) round( ( $end - time() ) / 60 ) );
                            $remaining = $mins_left . ' minutes remaining';
                        }
                        ?>
                        <div class="edu-ticket__eyebrow"><?php echo $is_written ? 'Written exam' : 'Exam in progress'; ?></div>
                        <h3 class="edu-ticket__title"><?php echo esc_html( (string) $paper['subject_name'] ); ?> is open</h3>
                        <p class="edu-ticket__body"><?php echo $is_written ? esc_html( (string) $q_count . ' questions. This is a paper-based exam — your invigilator will hand out the question paper. Answer on paper and submit it to the invigilator.' ) : esc_html( (string) $q_count . ' questions. Answer at your own pace, flag anything you want to revisit, then submit before time runs out.' ); ?></p>
                        <?php if ( $remaining !== '' ) : ?>
                            <div class="edu-ticket__meta">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 3"/></svg>
                                <?php echo esc_html( $remaining ); ?>
                            </div>
                        <?php endif; ?>
                        <?php if ( $is_written ) : ?>
                            <div style="padding:8px 16px;background:#f3f4f6;color:#374151;border-radius:10px;font-size:.85rem;font-weight:600">
                                Written — paper-based
                            </div>
                        <?php else : ?>
                            <a class="edu-ticket__btn" href="<?php echo esc_url( $exam_url ); ?>">
                                <?php echo esc_html( $btn_label ); ?>
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
                            </a>
                        <?php endif; ?>
                    <?php else : ?>
                        <div class="edu-ticket__eyebrow">Exam pass</div>
                        <h3 class="edu-ticket__title">You don't have an exam open right now</h3>
                        <p class="edu-ticket__body">When your teacher opens an exam, it'll appear here and you'll get a notification. Nothing to do in the meantime.</p>
                        <button type="button" onclick="window.location.reload()" style="display:inline-flex;align-items:center;gap:6px;margin-top:6px;padding:8px 14px;border:1px solid #E5E8E0;border-radius:10px;background:#fff;color:#0F2818;cursor:pointer;font-size:13px;font-weight:600;transition:background .15s;width:fit-content" onmouseover="this.style.background='#F3F5EF'" onmouseout="this.style.background='#fff'">
                            <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 1 1-3-6.7L21 8"/><path d="M21 3v5h-5"/></svg>
                            Refresh
                        </button>
                    <?php endif; ?>
                </div>
                <div class="edu-ticket__stub">
                    <div class="edu-ticket__notch edu-ticket__notch--top"></div>
                    <?php if ( $has_active ) : ?>
                        <span class="edu-ticket__stub-label">Status</span>
                        <span class="edu-ticket__stub-status">
                            <span class="edu-ticket__dot edu-ticket__dot--live"></span>
                            <span class="edu-ticket__stub-word">Live</span>
                        </span>
                    <?php else : ?>
                        <span class="edu-ticket__stub-label">Status</span>
                        <span class="edu-ticket__stub-status">
                            <span class="edu-ticket__dot"></span>
                            <span class="edu-ticket__stub-word">Standby</span>
                        </span>
                    <?php endif; ?>
                    <div class="edu-ticket__notch edu-ticket__notch--bottom"></div>
                </div>
            </div>

            <div class="edu-quick">
                <span class="edu-quick__title">Quick links</span>
                <a class="edu-quick__row" href="<?php echo esc_url( home_url( '/portal/student/timetable/' ) ); ?>">
                    <span class="edu-quick__icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4.5" width="18" height="16" rx="2"/><path d="M3 9.5h18M8 3v3M16 3v3"/></svg>
                    </span>
                    <span class="edu-quick__text">View timetable</span>
                    <svg class="edu-quick__chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 6l6 6-6 6"/></svg>
                </a>
                <a class="edu-quick__row" href="<?php echo esc_url( home_url( '/portal/student/subjects/' ) ); ?>">
                    <span class="edu-quick__icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 5.5C4 4.1 5.1 3 6.5 3H20v16.5H6.5A2.5 2.5 0 0 0 4 22"/><path d="M4 19.5C4 18.1 5.1 17 6.5 17H20"/></svg>
                    </span>
                    <span class="edu-quick__text">Browse subjects</span>
                    <svg class="edu-quick__chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 6l6 6-6 6"/></svg>
                </a>
                <a class="edu-quick__row" href="<?php echo esc_url( home_url( '/portal/student/results/' ) ); ?>">
                    <span class="edu-quick__icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 20V10M12 20V4M20 20v-7"/></svg>
                    </span>
                    <span class="edu-quick__text">Check results</span>
                    <svg class="edu-quick__chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 6l6 6-6 6"/></svg>
                </a>
            </div>

        </section>

        <?php if ( $has_ca || $has_exam ) : ?>
        <!-- Available tests -->
        <section class="edu-dash__secondary" style="grid-template-columns:1fr; margin-bottom:14px">

            <?php if ( $has_ca ) : ?>
            <div class="edu-card">
                <h3 class="edu-card__title">CA Tests</h3>
                <p class="edu-card__sub">Class assessments your teachers have published</p>
                <div class="edu-agenda" style="margin-top:14px">
                    <?php foreach ( $ca_tests as $test ) :
                        $test_id   = (int) $test['id'];
                        $exam_url  = home_url( '/portal/student/exam/' . $test_id );
                        $q_count   = (int) $test['question_count'];
                        $is_continuing = ! empty( $test['attempt_id'] ) && (string) $test['attempt_status'] === 'in_progress';
                        $is_published = (string) $test['status'] === 'published';
                        $label = $is_continuing ? 'Continue' : 'Take test';
                    ?>
                        <div class="edu-agenda__row" style="align-items:center;justify-content:space-between">
                            <span class="edu-agenda__text" style="flex:1">
                                <strong><?php echo esc_html( $test['subject_name'] ); ?></strong>
                                <span style="font-size:.8rem"><?php echo esc_html( (string) $q_count ); ?> questions</span>
                            </span>
                            <?php if ( $is_published ) : ?>
                                <a class="edu-ticket__btn" href="<?php echo esc_url( $exam_url ); ?>" style="padding:6px 16px;font-size:.85rem">
                                    <?php echo esc_html( $label ); ?>
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
                                </a>
                            <?php else : ?>
                                <span class="edu-card__sub" style="font-size:.75rem;font-style:italic;padding:4px 10px;background:#F3F5EF;border-radius:6px">Not yet open</span>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php if ( $has_exam ) : ?>
            <div class="edu-card">
                <h3 class="edu-card__title">Examinations</h3>
                <p class="edu-card__sub">Your published exams</p>
                <div class="edu-agenda" style="margin-top:14px">
                    <?php foreach ( $exam_tests as $test ) :
                        $test_id   = (int) $test['id'];
                        $exam_url  = home_url( '/portal/student/exam/' . $test_id );
                        $q_count   = (int) $test['question_count'];
                        $duration  = (int) round( (int) $test['duration_seconds'] / 60 );
                        $start_ts  = strtotime( (string) $test['scheduled_at'] . ' UTC' );
                        $end_ts    = $start_ts + (int) $test['duration_seconds'];
                        $early     = $start_ts - 900;
                        $is_continuing = ! empty( $test['attempt_id'] ) && (string) $test['attempt_status'] === 'in_progress';
                        $is_submitted  = ! empty( $test['attempt_id'] ) && in_array( (string) $test['attempt_status'], [ 'submitted', 'graded' ], true );
                        $can_open  = ( $now_ts >= $early && $now_ts < $end_ts ) && ! $is_submitted;
                        $test_written = (string) ( $test['delivery_mode'] ?? 'cbt' ) === 'written';
                        $label     = $is_continuing ? 'Continue' : 'Start exam';
                        $status_badge = '';

                        if ( $is_submitted ) {
                            $status_badge = '<span style="font-size:.75rem;padding:3px 10px;background:#dcfce7;color:#166534;border-radius:6px;font-weight:600">Completed</span>';
                        } elseif ( $is_continuing ) {
                            $status_badge = '<span style="font-size:.75rem;padding:3px 10px;background:#dbeafe;color:#1e40af;border-radius:6px;font-weight:600">In progress</span>';
                        } elseif ( $now_ts < $early ) {
                            $status_badge = '<span style="font-size:.75rem;padding:3px 10px;background:#F3F5EF;color:#666;border-radius:6px;font-weight:600">Scheduled</span>';
                        } else {
                            // Window has passed but paper is still published — auto-close
                            // will remove it on the next page load.
                            $status_badge = '<span style="font-size:.75rem;padding:3px 10px;background:#fef3c7;color:#92400e;border-radius:6px;font-weight:600">Closed</span>';
                        }
                    ?>
                        <div class="edu-agenda__row" style="align-items:center;justify-content:space-between">
                            <span class="edu-agenda__text" style="flex:1">
                                <strong><?php echo esc_html( $test['subject_name'] ); ?></strong>
                                <span style="font-size:.8rem"><?php echo esc_html( wp_date( 'M j, g:ia', strtotime( (string) $test['scheduled_at'] . ' UTC' ) ) . ' · ' . $duration . ' min' ); ?></span>
                                <span style="font-size:.8rem"><?php echo esc_html( (string) $q_count ); ?> questions</span>
                            </span>
                            <?php if ( $test_written ) : ?>
                                <span style="font-size:.75rem;padding:3px 10px;background:#f3f4f6;color:#374151;border-radius:6px;font-weight:600">Written</span>
                            <?php elseif ( $can_open ) : ?>
                                <a class="edu-ticket__btn" href="<?php echo esc_url( $exam_url ); ?>" style="padding:6px 16px;font-size:.85rem">
                                    <?php echo esc_html( $label ); ?>
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
                                </a>
                            <?php else :
                                echo $status_badge; // phpcs:ignore
                            ?>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

        </section>
        <?php endif; ?>

        <!-- Secondary grid: coming up + results -->
        <section class="edu-dash__secondary">

            <div class="edu-card">
                <h3 class="edu-card__title">Coming up</h3>
                <p class="edu-card__sub">Your next scheduled exams</p>
                <?php if ( empty( $upcoming ) ) : ?>
                    <div class="edu-empty">
                        <span class="edu-empty__icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4.5" width="18" height="16" rx="2"/><path d="M3 9.5h18M8 3v3M16 3v3"/></svg>
                        </span>
                        <p class="edu-empty__h">Nothing scheduled yet</p>
                        <p class="edu-empty__p">Your exam timetable will show up here once your school publishes it.</p>
                    </div>
                <?php else : ?>
                    <div class="edu-agenda">
                        <?php foreach ( $upcoming as $paper ) : ?>
                            <div class="edu-agenda__row">
                                <span class="edu-agenda__date">
                                    <span class="edu-agenda__d"><?php echo esc_html( wp_date( 'j', strtotime( (string) $paper['scheduled_at'] . ' UTC' ) ) ); ?></span>
                                    <span class="edu-agenda__m"><?php echo esc_html( wp_date( 'M', strtotime( (string) $paper['scheduled_at'] . ' UTC' ) ) ); ?></span>
                                </span>
                                <span class="edu-agenda__text">
                                    <strong><?php echo esc_html( (string) $paper['subject_name'] ); ?></strong>
                                    <span><?php
                                        $time_start = wp_date( 'g:ia', strtotime( (string) $paper['scheduled_at'] . ' UTC' ) );
                                        $closes_raw = (string) ( $paper['closes_at'] ?? '' );
                                        if ( ! empty( $closes_raw ) && $closes_raw !== '0000-00-00 00:00:00' ) {
                                            $time_end = wp_date( 'g:ia', strtotime( $closes_raw . ' UTC' ) );
                                            $time_display = $time_start . ' – ' . $time_end;
                                        } else {
                                            $time_display = $time_start;
                                        }
                                        echo esc_html( $time_display . ( (string) ( $paper['delivery_mode'] ?? 'cbt' ) === 'written' ? ' · Written' : '' ) );
                                    ?></span>
                                </span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="edu-card">
                <h3 class="edu-card__title">Results</h3>
                <p class="edu-card__sub">Published term results</p>
                <?php if ( empty( $term_results ) ) : ?>
                    <div class="edu-empty">
                        <span class="edu-empty__icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 20V10M12 20V4M20 20v-7"/></svg>
                        </span>
                        <p class="edu-empty__h">No results yet</p>
                        <p class="edu-empty__p">Scores appear here as soon as your teachers publish them.</p>
                    </div>
                <?php else : ?>
                    <div class="edu-results">
                        <?php foreach ( $term_results as $row ) : ?>
                            <?php
                            $score     = (float) ( $row['average_score'] ?? 0 );
                            $score_pct = max( 0, min( 100, $score ) );
                            $result_url = home_url( '/portal/student/results/' . (int) $row['term_id'] );
                            ?>
                            <a class="edu-result" href="<?php echo esc_url( $result_url ); ?>">
                                <div class="edu-result__top">
                                    <strong><?php echo esc_html( (string) ( $row['term_name'] ?? 'Term' ) . ', ' . ( $row['session_name'] ?? '' ) ); ?></strong>
                                    <span class="edu-result__score"><?php echo esc_html( (string) round( $score ) ); ?>%</span>
                                </div>
                                <div class="edu-result__bar">
                                    <div class="edu-result__fill" style="width:<?php echo esc_attr( (string) $score_pct ); ?>%"></div>
                                </div>
                                <span class="edu-result__pos">
                                    <?php
                                    echo esc_html(
                                        \EduCBTPro\Services\ReportCardDocument::ordinal( (int) ( $row['class_position'] ?? 0 ) )
                                        . ' of ' . ( $row['class_size'] ?? 0 )
                                    );
                                    ?>
                                </span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

        </section>

    </div>
    <?php
};

require EDUCBT_PRO_PATH . 'templates/portal/shell.php';
