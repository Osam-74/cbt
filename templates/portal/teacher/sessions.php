<?php
/**
 * Test Sessions — a log of every CA test and exam attempt.
 *
 * Teachers can search for a student by name, see what happened during their
 * test sessions (start time, end time, status, any integrity flags), and grant
 * a reattempt if a session ended unfairly.
 *
 * Exam officers see all sessions school-wide; subject teachers see only their
 * own subjects' sessions.
 *
 * @var array<string,mixed> $educbt
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

global $wpdb;

$school_id = (int) ( $educbt['school_id'] ?? 0 );
$scope     = $educbt['scope'] ?? new \EduCBTPro\Core\Scope();
$area      = $educbt['area'] ?? 'teacher';

// Search & filters
$search   = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
$subject  = isset( $_GET['subject'] ) ? absint( $_GET['subject'] ) : 0;
$status   = isset( $_GET['status'] ) ? sanitize_text_field( wp_unslash( $_GET['status'] ) ) : '';
$class_f  = isset( $_GET['class'] ) ? absint( $_GET['class'] ) : 0;
$session_f = isset( $_GET['session'] ) ? absint( $_GET['session'] ) : 0;
$term_f   = isset( $_GET['term'] ) ? absint( $_GET['term'] ) : 0;
$reset    = isset( $_GET['reset'] ) ? absint( $_GET['reset'] ) : 0;

// Tables
$attempts_t = \EduCBTPro\Core\Schema::table( 'attempts' );
$papers_t   = \EduCBTPro\Core\Schema::table( 'exam_papers' );
$series_t   = \EduCBTPro\Core\Schema::table( 'exam_series' );
$students_t = $wpdb->prefix . 'educbt_students';
$subjects_t = \EduCBTPro\Core\Schema::table( 'subjects_v2' );
$classes_t  = $wpdb->prefix . 'educbt_classes';
$events_t   = \EduCBTPro\Core\Schema::table( 'attempt_events' );
$sessions_t = \EduCBTPro\Core\Schema::table( 'academic_sessions' );
$terms_t    = \EduCBTPro\Core\Schema::table( 'terms' );
$series_t2  = \EduCBTPro\Core\Schema::table( 'exam_series' );

// Build the query
$where  = [ 'a.school_id = %d' ];
$params = [ $school_id ];

// Subject teachers only see their own subjects. VIEW_EXAMS is granted to every
// teacher role (it just means "can look at exam data at all"), so it cannot be
// what decides "sees everything" — that is a school-wide scope question.
$actor        = $scope->actor();
$can_view_all = $scope->is_school_wide();
if ( ! $can_view_all ) {
    $my_subject_ids = (array) $wpdb->get_col(
        $wpdb->prepare(
            'SELECT DISTINCT subject_id FROM ' . \EduCBTPro\Core\Schema::table( 'staff_assignments' ) . "
             WHERE school_id = %d AND staff_id = %d AND assignment_type = 'subject_teacher' AND status = 'active'
               AND subject_id IS NOT NULL",
            $school_id,
            absint( $actor['id'] ?? 0 )
        )
    );
    if ( empty( $my_subject_ids ) ) {
        $my_subject_ids = [ 0 ];
    }
    $where[] = 'p.subject_id IN (' . implode( ',', array_map( 'absint', $my_subject_ids ) ) . ')';
}

if ( $search !== '' ) {
    $where[]  = '(st.first_name LIKE %s OR st.last_name LIKE %s OR CONCAT(st.first_name, " ", st.last_name) LIKE %s)';
    $like     = '%' . $wpdb->esc_like( $search ) . '%';
    $params[] = $like; $params[] = $like; $params[] = $like;
}

if ( $subject > 0 ) {
    $where[]  = 'p.subject_id = %d';
    $params[] = $subject;
}

if ( $status !== '' ) {
    $where[]  = 'a.status = %s';
    $params[] = $status;
}

if ( $class_f > 0 ) {
    $where[]  = 'p.class_id = %d';
    $params[] = $class_f;
}

// Filter by academic session (via exam_series.session_id)
if ( $session_f > 0 ) {
    $where[]  = 'se.session_id = %d';
    $params[] = $session_f;
}

// Filter by term (via exam_series.term_id)
if ( $term_f > 0 ) {
    $where[]  = 'se.term_id = %d';
    $params[] = $term_f;
}

$where_sql = implode( ' AND ', $where );

// Get attempts with student, paper, subject, class info
$query = $wpdb->prepare(
    "SELECT a.id AS attempt_id, a.status, a.started_at, a.submitted_at, a.submit_reason,
            a.flag_count, COALESCE(a.integrity_count,0) AS integrity_count, a.extension_seconds, a.max_score,
            a.student_id, a.paper_id,
            st.first_name, st.last_name, st.admission_number,
            se.title AS paper_title, p.scheduled_at, p.closes_at, p.is_practice, p.duration_seconds, p.question_count,
            s.name AS subject_name, c.display_name AS class_name
     FROM {$attempts_t} a
     INNER JOIN {$papers_t} p ON p.id = a.paper_id
     LEFT JOIN {$series_t} se ON se.id = p.series_id
     INNER JOIN {$students_t} st ON st.id = a.student_id
     LEFT JOIN {$subjects_t} s ON s.id = p.subject_id
     LEFT JOIN {$classes_t} c ON c.id = p.class_id
     WHERE {$where_sql}
     ORDER BY a.started_at DESC
     LIMIT 80",
    ...$params
);

$sessions = (array) $wpdb->get_results( $query, ARRAY_A );

// Get subjects for filter dropdown
$subjects_q = $wpdb->prepare(
    "SELECT id, name FROM {$subjects_t} WHERE school_id = %d ORDER BY name ASC",
    $school_id
);
$all_subjects = (array) $wpdb->get_results( $subjects_q, ARRAY_A );

// Classes for filter dropdown
$all_classes = (array) $wpdb->get_results(
    $wpdb->prepare(
        "SELECT id, display_name FROM {$classes_t} WHERE school_id = %d ORDER BY display_name ASC",
        $school_id
    ),
    ARRAY_A
);

// Academic sessions for filter dropdown
$all_sessions = (array) $wpdb->get_results(
    $wpdb->prepare(
        "SELECT id, title FROM {$sessions_t} WHERE school_id = %d ORDER BY title DESC",
        $school_id
    ),
    ARRAY_A
);

// Terms for filter dropdown (filtered by session if selected)
$all_terms = [];
if ( $session_f > 0 ) {
    $all_terms = (array) $wpdb->get_results(
        $wpdb->prepare(
            "SELECT id, title FROM {$terms_t} WHERE school_id = %d AND session_id = %d ORDER BY term_order ASC",
            $school_id, $session_f
        ),
        ARRAY_A
    );
} else {
    $all_terms = (array) $wpdb->get_results(
        $wpdb->prepare(
            "SELECT id, title FROM {$terms_t} WHERE school_id = %d ORDER BY term_order ASC",
            $school_id
        ),
        ARRAY_A
    );
}

// Get events for any expanded session
$expanded_id = isset( $_GET['expand'] ) ? absint( $_GET['expand'] ) : 0;
$events      = [];
if ( $expanded_id > 0 ) {
    $events = (array) $wpdb->get_results(
        $wpdb->prepare(
            "SELECT event_type, payload, created_at FROM {$events_t} WHERE attempt_id = %d ORDER BY created_at ASC",
            $expanded_id
        ),
        ARRAY_A
    );
}

// Helpers — guarded so this template can safely be included more than once.
if ( ! function_exists( 'educbt_sessions_student_name' ) ) {
    function educbt_sessions_student_name( array $s ): string {
        $name = trim( ( $s['first_name'] ?? '' ) . ' ' . ( $s['last_name'] ?? '' ) );
        return $name !== '' ? $name : '—';
    }
}

if ( ! function_exists( 'educbt_sessions_status_label' ) ) {
    function educbt_sessions_status_label( string $status ): string {
        $labels = [
            'in_progress' => 'In Progress',
            'submitted'   => 'Submitted',
            'graded'      => 'Graded',
        ];
        return $labels[ $status ] ?? ucfirst( $status );
    }
}

if ( ! function_exists( 'educbt_sessions_status_class' ) ) {
    function educbt_sessions_status_class( string $status ): string {
        $classes = [
            'in_progress' => 'educbt-badge educbt-badge--info',
            'submitted'   => 'educbt-badge educbt-badge--warn',
            'graded'      => 'educbt-badge educbt-badge--ok',
        ];
        return $classes[ $status ] ?? 'educbt-badge';
    }
}

if ( ! function_exists( 'educbt_sessions_reason_label' ) ) {
    function educbt_sessions_reason_label( string $reason ): string {
        $labels = [
            'manual'  => 'Student submitted',
            'timeout' => 'Time expired',
            'forced'  => 'Force-submitted',
            ''        => '—',
        ];
        return $labels[ $reason ] ?? $reason;
    }
}

$educbt_title = 'Test Sessions';

$educbt_body = static function () use (
    $sessions, $all_subjects, $all_classes, $all_sessions, $all_terms,
    $events, $expanded_id,
    $search, $subject, $status, $class_f, $session_f, $term_f, $reset, $can_view_all, $area
): void {
    ?>
    <p class="educbt-muted" style="margin-top:-8px">
        Search for a student to see their test and exam session history. If a session ended unfairly, you can grant a reattempt.
    </p>

    <?php if ( $reset ): ?>
        <div class="educbt-note educbt-note--ok">
            ✓ Attempt has been reset. The student can now retake the test.
        </div>
    <?php endif; ?>

    <section class="educbt-card">
        <form method="get" action="" class="educbt-filters">
            <div class="educbt-field">
                <label>Search student</label>
                <input type="text" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="Name or admission number">
            </div>
            <?php if ( $can_view_all ): ?>
            <div class="educbt-field">
                <label>Subject</label>
                <select name="subject">
                    <option value="0">All subjects</option>
                    <?php foreach ( $all_subjects as $sub ): ?>
                        <option value="<?php echo esc_attr( $sub['id'] ); ?>" <?php selected( $subject, (int) $sub['id'] ); ?>><?php echo esc_html( $sub['name'] ); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <div class="educbt-field">
                <label>Status</label>
                <select name="status">
                    <option value="">All</option>
                    <option value="in_progress" <?php selected( $status, 'in_progress' ); ?>>In Progress</option>
                    <option value="submitted" <?php selected( $status, 'submitted' ); ?>>Submitted</option>
                    <option value="graded" <?php selected( $status, 'graded' ); ?>>Graded</option>
                </select>
            </div>
            <div class="educbt-field">
                <label>Class</label>
                <select name="class">
                    <option value="0">All classes</option>
                    <?php foreach ( $all_classes as $cl ): ?>
                        <option value="<?php echo esc_attr( $cl['id'] ); ?>" <?php selected( $class_f, (int) $cl['id'] ); ?>><?php echo esc_html( $cl['display_name'] ); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if ( $can_view_all ): ?>
            <div class="educbt-field">
                <label>Session</label>
                <select name="session" id="educbt-session-filter">
                    <option value="0">All sessions</option>
                    <?php foreach ( $all_sessions as $sess ): ?>
                        <option value="<?php echo esc_attr( $sess['id'] ); ?>" <?php selected( $session_f, (int) $sess['id'] ); ?>><?php echo esc_html( $sess['title'] ); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="educbt-field">
                <label>Term</label>
                <select name="term" id="educbt-term-filter">
                    <option value="0">All terms</option>
                    <?php foreach ( $all_terms as $trm ): ?>
                        <option value="<?php echo esc_attr( $trm['id'] ); ?>" <?php selected( $term_f, (int) $trm['id'] ); ?>><?php echo esc_html( $trm['title'] ); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <button type="submit" class="educbt-btn educbt-btn--primary">Filter</button>
            <?php if ( $search || $subject || $status || $class_f || $session_f || $term_f ): ?>
                <a class="educbt-btn educbt-btn--ghost" href="<?php echo esc_url( home_url( '/portal/' . $area . '/sessions/' ) ); ?>">Clear</a>
            <?php endif; ?>
        </form>

        <?php if ( empty( $sessions ) ): ?>
            <p class="educbt-muted" style="padding:30px 0;text-align:center">No sessions found. Try searching for a student by name or admission number.</p>
        <?php else: ?>
        <div class="educbt-table-wrap">
        <table class="educbt-table">
            <thead>
                <tr>
                    <th>Student</th>
                    <th>Subject</th>
                    <th>Class</th>
                    <th>Type</th>
                    <th>Started</th>
                    <th>Submitted</th>
                    <th>Reason</th>
                    <th>Status</th>
                    <th title="Integrity incidents: leaving the exam window, right-clicking, switching tabs.">Incidents</th>
                    <th title="Questions the student marked to revisit. Not a warning.">Bookmarked</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $sessions as $row ):
                    $student_name = educbt_sessions_student_name( $row );
                    $started      = (string) ( $row['started_at'] ?? '' );
                    $submitted    = (string) ( $row['submitted_at'] ?? '' );
                    $is_practice  = ! empty( $row['is_practice'] );
                    $type_label   = $is_practice ? 'CA Test' : 'Examination';
                    $is_expanded  = ( $expanded_id === (int) $row['attempt_id'] );
                ?>
                <tr>
                    <td>
                        <strong><?php echo esc_html( $student_name ); ?></strong>
                        <?php if ( ! empty( $row['admission_number'] ) ): ?>
                            <br><small class="educbt-muted"><?php echo esc_html( $row['admission_number'] ); ?></small>
                        <?php endif; ?>
                    </td>
                    <td><?php echo esc_html( $row['subject_name'] ?? '—' ); ?></td>
                    <td><?php echo esc_html( $row['class_name'] ?? '—' ); ?></td>
                    <td><span class="educbt-badge <?php echo $is_practice ? 'educbt-badge--info' : ''; ?>"><?php echo esc_html( $type_label ); ?></span></td>
                    <td class="educbt-muted" style="font-size:12px"><?php echo esc_html( $started ? wp_date( 'M j, Y g:i A', strtotime( $started . ' UTC' ) ) : '—' ); ?></td>
                    <td class="educbt-muted" style="font-size:12px"><?php echo esc_html( $submitted ? wp_date( 'M j, Y g:i A', strtotime( $submitted . ' UTC' ) ) : '—' ); ?></td>
                    <td style="font-size:12px"><?php echo esc_html( educbt_sessions_reason_label( (string) $row['submit_reason'] ) ); ?></td>
                    <td><span class="<?php echo esc_attr( educbt_sessions_status_class( (string) $row['status'] ) ); ?>"><?php echo esc_html( educbt_sessions_status_label( (string) $row['status'] ) ); ?></span></td>
                    <td>
                        <?php $flags = (int) ( $row['flag_count'] ?? 0 ); ?>
                        <?php // A bookmark is the student's own "come back to this".
                              // It was shown with a warning triangle, which made a
                              // careful candidate look like a suspected cheat. ?>
                        <?php $incidents = (int) ( $row['integrity_count'] ?? 0 ); ?>
                        <?php if ( $incidents > 0 ): ?>
                            <span class="educbt-flag-count"><?php echo esc_html( (string) $incidents ); ?> &#9888;</span>
                        <?php else: ?>
                            <span style="color:#16a34a">&#10003;</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ( $flags > 0 ): ?>
                            <span class="educbt-muted"><?php echo esc_html( (string) $flags ); ?></span>
                        <?php else: ?>
                            <span class="educbt-muted">&mdash;</span>
                        <?php endif; ?>
                    </td>
                    <td class="educbt-actions-cell">
                        <?php if ( (string) $row['status'] !== 'in_progress' ): ?>
                            <button type="button" class="educbt-link-muted educbt-expand-btn" data-attempt="<?php echo esc_attr( (string) $row['attempt_id'] ); ?>">
                                ▼ Details
                            </button>
                            <?php if ( (string) $row['status'] === 'submitted' || (string) $row['status'] === 'graded' ): ?>
                            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline" onsubmit="return confirm('This will delete this student\'s attempt and allow them to retake the test. Continue?')">
                                <?php wp_nonce_field( 'educbt_reset_attempt' ); ?>
                                <input type="hidden" name="action" value="educbt_reset_attempt">
                                <input type="hidden" name="attempt_id" value="<?php echo esc_attr( (string) $row['attempt_id'] ); ?>">
                                <input type="hidden" name="student_id" value="<?php echo esc_attr( (string) $row['student_id'] ); ?>">
                                <button type="submit" class="educbt-btn educbt-btn--danger-ghost">Reattempt</button>
                            </form>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="educbt-muted" style="font-size:12px">In progress</span>
                            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline" onsubmit="return confirm('This student has an attempt in progress but the exam window has ended. This will delete the attempt and allow them to retake. Continue?')">
                                <?php wp_nonce_field( 'educbt_reset_attempt' ); ?>
                                <input type="hidden" name="action" value="educbt_reset_attempt">
                                <input type="hidden" name="attempt_id" value="<?php echo esc_attr( (string) $row['attempt_id'] ); ?>">
                                <input type="hidden" name="student_id" value="<?php echo esc_attr( (string) $row['student_id'] ); ?>">
                                <button type="submit" class="educbt-btn educbt-btn--danger-ghost">Force Reset</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
<?php
                        $duration_sec = (int) ( $row['duration_seconds'] ?? 0 );
                        $elapsed      = 0;
                        if ( $started && $submitted ) {
                            $elapsed = (int) strtotime( (string) $submitted . ' UTC' ) - (int) strtotime( $started . ' UTC' );
                        }
                    ?>
                <tr class="educbt-details-row educbt-ajax-detail" data-attempt="<?php echo esc_attr( (string) $row['attempt_id'] ); ?>" hidden>
                    <td colspan="12">
                        <div class="educbt-detail-content">
                            <p class="educbt-muted" style="font-size:12px">Loading…</p>
                        </div>
                        <div class="educbt-muted" style="margin-top:8px;font-size:12px">
                            Duration allowed: <?php echo esc_html( (string) round( $duration_sec / 60 ) ); ?> min ·
                            Actual elapsed: <?php echo $elapsed > 0 ? esc_html( (string) round( $elapsed / 60 ) ) . ' min' : '—'; ?>
                            <?php if ( (int) ( $row['extension_seconds'] ?? 0 ) > 0 ): ?>
                                · Extension: <?php echo esc_html( (string) round( (int) $row['extension_seconds'] / 60 ) ); ?> min
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </section>

    <style>
    .educbt-filters { display:flex; flex-wrap:wrap; gap:12px; margin:0 0 20px; align-items:flex-end; }
    .educbt-field label { display:block; font-size:12px; font-weight:600; margin-bottom:4px; color:var(--muted); }
    .educbt-field input, .educbt-field select { padding:8px 12px; border:1px solid var(--line); border-radius:8px; font-size:14px; background:#fff; }
    .educbt-field input { width:200px; }
    .educbt-btn--ghost { background:transparent; color:var(--muted); border:1px solid var(--line); }
    .educbt-btn--danger-ghost { font-size:12px; padding:4px 10px; border:1px solid var(--danger); border-radius:6px; background:transparent; color:var(--danger); cursor:pointer; font-weight:600; }
    .educbt-table-wrap { overflow-x:auto; }
    .educbt-actions-cell { white-space:nowrap; }
    .educbt-link-muted { font-size:12px; text-decoration:none; color:var(--muted); margin-right:8px; }
    .educbt-flag-count { color:var(--danger); font-weight:600; }
    .educbt-details-row { background:#fafafa; }
    .educbt-details-row td { padding:12px 20px; }
    .educbt-events-list { margin:8px 0 0; padding-left:20px; font-size:12px; color:var(--muted); }
    .educbt-badge { display:inline-block; padding:2px 10px; border-radius:999px; font-size:11px; font-weight:600; background:#e5e7eb; color:#374151; }
    .educbt-badge--ok { background:#d1fae5; color:#065f46; }
    .educbt-badge--warn { background:#fef3c7; color:#92400e; }
    .educbt-badge--info { background:#dbeafe; color:#1e40af; }
    .educbt-expand-btn { background:none; border:none; cursor:pointer; font-size:12px; color:var(--muted); padding:0; margin-right:8px; font-family:inherit; }
    .educbt-expand-btn:hover { color:var(--forest-dark); }
    .educbt-ajax-detail td { padding:12px 20px; }
    .educbt-events-list { margin:8px 0 0; padding-left:20px; font-size:12px; color:var(--muted); }
    .educbt-event-item { padding:4px 0; }
    .educbt-event-time { font-weight:600; }
    .educbt-event-type { color:var(--forest-dark); }
    </style>
    <script>
    (function() {
        var nonce = '<?php echo wp_create_nonce( "wp_rest" ); ?>';
        var root = '<?php echo esc_url( rest_url( "educbt-pro/v1" ) ); ?>';

        document.querySelectorAll('.educbt-expand-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var attemptId = btn.getAttribute('data-attempt');
                var row = btn.closest('tr');
                var detailRow = document.querySelector('.educbt-ajax-detail[data-attempt="' + attemptId + '"]');
                if (!detailRow) return;

                if (detailRow.hasAttribute('hidden')) {
                    // Expand
                    detailRow.removeAttribute('hidden');
                    btn.textContent = '▲ Hide';
                    var content = detailRow.querySelector('.educbt-detail-content');

                    if (!detailRow.dataset.loaded) {
                        // Load via AJAX
                        content.innerHTML = '<p class="educbt-muted" style="font-size:12px">Loading session details…</p>';
                        fetch(root + '/attempt/' + attemptId + '/events', {
                            headers: { 'X-WP-Nonce': nonce }
                        })
                        .then(function(r) { return r.json(); })
                        .then(function(data) {
                            var html = '';

                            // Integrity events first: the paper leaving the screen,
                            // a second session, a resumed attempt. An empty list is
                            // GOOD NEWS, so say so rather than reporting an absence
                            // of records as though something failed.
                            if (data.events && data.events.length > 0) {
                                html += '<strong style="font-size:13px">Integrity events</strong>';
                                html += '<ul class="educbt-events-list">';
                                data.events.forEach(function(ev) {
                                    html += '<li class="educbt-event-item">';
                                    html += '<span class="educbt-event-time">' + ev.created_at + '</span> — ';
                                    html += '<span class="educbt-event-type">' + ev.event_type + '</span>';
                                    html += '</li>';
                                });
                                html += '</ul>';
                            } else {
                                html += '<p style="font-size:12px;color:#16a34a;margin:0 0 6px">'
                                     +  'No integrity events \u2014 this paper was sat without the exam window '
                                     +  'being left, reopened, or resumed.</p>';
                            }

                            // Bookmarks are the student's own "come back to this",
                            // not a concern. Listed separately and without alarm.
                            if (data.flags && data.flags.length > 0) {
                                html += '<strong style="font-size:13px">Questions the student bookmarked</strong>';
                                html += '<p class="educbt-muted" style="font-size:11.5px;margin:2px 0 4px">'
                                     +  'A bookmark is the student marking a question to revisit. It is a working '
                                     +  'habit, not a warning.</p>';
                                html += '<ul class="educbt-events-list">';
                                data.flags.forEach(function(f) {
                                    html += '<li class="educbt-event-item">';
                                    html += '<span class="educbt-event-time">' + f.created_at + '</span> — ';
                                    html += 'Question #' + f.question_id;
                                    html += '</li>';
                                });
                                html += '</ul>';
                            }

                            // Add attempt summary
                            if (data.summary) {
                                html += '<div class="educbt-muted" style="margin-top:8px;font-size:12px">' + data.summary + '</div>';
                            }
                            content.innerHTML = html;
                            detailRow.dataset.loaded = '1';
                        })
                        .catch(function() {
                            content.innerHTML = '<p class="educbt-muted" style="font-size:12px">Could not load session details. Please try again.</p>';
                        });
                    }
                } else {
                    // Collapse
                    detailRow.setAttribute('hidden', '');
                    btn.textContent = '▼ Details';
                }
            });
        });
    })();
    </script>
    <?php
};

require EDUCBT_PRO_PATH . 'templates/portal/shell.php';
