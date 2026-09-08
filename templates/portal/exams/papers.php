<?php
/**
 * Exam papers — schedule, compose, publish.
 *
 * A paper is one subject, for one class, at one time, for one duration. Composing
 * happens automatically on creation, because a paper with no questions cannot be
 * published and a second click only creates a state to get stuck in.
 *
 * @var array<string,mixed> $educbt
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

global $wpdb;

$school_id = (int) $educbt['school_id'];
$flash     = \EduCBTPro\Frontend\PortalActions::flash();

$structure = new \EduCBTPro\Services\AcademicStructureService();
$classes   = $structure->list_classes( $school_id );

$subjects_table = \EduCBTPro\Core\Schema::table( 'subjects_v2' );
$papers_table   = \EduCBTPro\Core\Schema::table( 'exam_papers' );
$series_table   = \EduCBTPro\Core\Schema::table( 'exam_series' );
$classes_table  = \EduCBTPro\Core\Schema::table( 'classes' );
$pq_table       = \EduCBTPro\Core\Schema::table( 'paper_questions' );
$inv_table      = \EduCBTPro\Core\Schema::table( 'paper_invigilators' );
$staff_table    = \EduCBTPro\Core\Schema::table( 'staff' );
$questions      = $wpdb->prefix . 'educbt_questions';

$subjects = (array) $wpdb->get_results(
    $wpdb->prepare(
        // Approved only — that is the pool a paper is actually built from, and showing
        // the raw bank total made an unbuildable paper look perfectly possible.
        "SELECT s.id, s.name,
                SUM(CASE WHEN q.approval_status = 'approved' THEN 1 ELSE 0 END) AS bank,
                COUNT(q.id) AS submitted
         FROM {$subjects_table} s
         LEFT JOIN {$questions} q ON q.subject_id = s.id AND q.status = 'active'
         WHERE s.school_id = %d AND s.status = 'active'
         GROUP BY s.id ORDER BY s.name ASC",
        $school_id
    ),
    ARRAY_A
);

$sessions_table = \EduCBTPro\Core\Schema::table( 'academic_sessions' );
$terms_table    = \EduCBTPro\Core\Schema::table( 'terms' );

$ay                 = new \EduCBTPro\Services\AcademicYearService();
$current_session    = $ay->current_session( $school_id );
$current_session_id = absint( $current_session['id'] ?? 0 );
$current_term       = $ay->resolve_current_term( $school_id, $current_session_id );
$current_term_id    = absint( $current_term['id'] ?? 0 );

$sessions = (array) $wpdb->get_results(
    $wpdb->prepare( "SELECT id, title FROM {$sessions_table} WHERE school_id = %d ORDER BY id DESC", $school_id ),
    ARRAY_A
);

$terms = (array) $wpdb->get_results(
    $wpdb->prepare(
        "SELECT id, title FROM {$terms_table} WHERE school_id = %d AND session_id = %d ORDER BY term_order ASC",
        $school_id,
        $current_session_id
    ),
    ARRAY_A
);

$series = (array) $wpdb->get_results(
    $wpdb->prepare(
        // EXAMINATIONS ONLY.
        //
        // This listed every series, so a continuous assessment appeared here as well
        // as in its own table — and twice over in the question-bank dropdown, once
        // labelled assessment and once examination. They are different things with
        // different controls, so they belong in different tables.
        "SELECT se.id, se.title, se.status, se.starts_on, se.series_type,
                a.title AS session_title, t.title AS term_title
         FROM {$series_table} se
         LEFT JOIN {$sessions_table} a ON a.id = se.session_id
         LEFT JOIN {$terms_table} t ON t.id = se.term_id
         WHERE se.school_id = %d
           AND COALESCE(se.series_type, 'examination') <> 'ca_test'
         ORDER BY se.id DESC",
        $school_id
    ),
    ARRAY_A
);

$papers = (array) $wpdb->get_results(
    $wpdb->prepare(
        "SELECT p.*, sub.name AS subject_name, c.display_name AS class_name,
                COALESCE(es.series_type, 'examination') AS series_type,
                (SELECT COUNT(*) FROM {$pq_table} pq WHERE pq.paper_id = p.id) AS composed,
                (SELECT CONCAT(st.first_name,' ',st.last_name) FROM {$inv_table} i
                   INNER JOIN {$staff_table} st ON st.id = i.staff_id
                  WHERE i.paper_id = p.id LIMIT 1) AS invigilator
         FROM {$papers_table} p
         INNER JOIN {$subjects_table} sub ON sub.id = p.subject_id
         LEFT JOIN {$classes_table} c ON c.id = p.class_id
         LEFT JOIN {$series_table} es ON es.id = p.series_id
         WHERE p.school_id = %d AND p.status <> 'cancelled'
         ORDER BY p.is_practice DESC, p.scheduled_at DESC LIMIT 60",
        $school_id
    ),
    ARRAY_A
);

// Lookup CA component names for practice papers
$comp_table = \EduCBTPro\Core\Schema::table( 'assessment_components' );
$paper_components = [];
foreach ( $papers as $p ) {
    if ( (int) $p['is_practice'] === 1 ) {
        $comp_id = (int) get_option( 'educbt_paper_component_' . (int) $p['id'], 0 );
        if ( $comp_id > 0 ) {
            $comp_name = $wpdb->get_var( $wpdb->prepare(
                "SELECT name FROM {$comp_table} WHERE id = %d AND school_id = %d", $comp_id, $school_id
            ) );
            $paper_components[ (int) $p['id'] ] = (string) ( $comp_name ?: 'CA Test' );
        } else {
            $paper_components[ (int) $p['id'] ] = 'CA Test';
        }
    }
}

$educbt_title = 'Exam Papers';

// Continuous assessment windows, alongside terminal examinations. Both live in
// exam_series; what separates them is series_type.
$window_service = new \EduCBTPro\Services\QuestionWindowService();
$open_window    = $window_service->current( $school_id );

$ca_service   = new \EduCBTPro\Services\CaTestWindowService();
$ca_windows   = $ca_service->windows( $school_id, $current_session_id, $current_term_id );
$ca_components = (array) $wpdb->get_results(
    $wpdb->prepare(
        'SELECT id, name, max_score FROM ' . \EduCBTPro\Core\Schema::table( 'assessment_components' ) . "
         WHERE school_id = %d AND status = 'active' AND is_exam = 0
         ORDER BY sort_order ASC, id ASC",
        $school_id
    ),
    ARRAY_A
);

$educbt_body = static function () use ( $flash, $subjects, $classes, $series, $papers, $sessions, $terms, $current_session_id, $current_term_id, $paper_components, $wpdb, $papers_table, $school_id, $ca_windows, $ca_components, $open_window ): void {
    require EDUCBT_PRO_PATH . 'templates/portal/partials/flash.php';

    if ( empty( $classes ) || empty( $subjects ) ) {
        echo '<div class="educbt-card"><p class="educbt-note educbt-note--warn">You need at least one class and one subject before creating an examination.</p></div>';
        return;
    }
    ?>
    <?php
    // An examination is created once for a session and term. Teachers then submit
    // their questions against it, and the timetable is generated from what has been
    // approved. Asking for subject, class, date, time, duration and question count
    // up front made the exam office invent a schedule before anyone had submitted
    // anything — the schedule is an OUTPUT of the process, not its input.
    ?>
    <?php
    // The question bank has exactly one open window. This is where it is set.
    //
    // Two open windows would put the question back to the teacher — "is this for
    // the second CA or the examination?" — which is the ambiguity the whole
    // arrangement exists to remove.
    ?>
    <?php
    // Practice sits outside the window arrangement entirely: never scheduled, never
    // reviewed, always open. So it gets a button rather than a place in the
    // dropdown, which is for the one window teachers are being pointed at.
    $practice = ( new \EduCBTPro\Services\QuestionWindowService() )->practice( $school_id );
    ?>
    <section class="educbt-card">
        <h2>Practice exam</h2>
        <?php if ( $practice ) : ?>
            <p class="educbt-muted" style="margin-bottom:10px">
                <strong>Available to students.</strong> Teachers can add practice questions at any
                time, including while the question bank is closed. Nothing here is scheduled or
                reviewed, and marks do not count towards results.
            </p>
        <?php else : ?>
            <p class="educbt-muted" style="margin-bottom:10px">
                A practice exam students can sit at any time. Not scheduled, not reviewed, and it
                does not count towards results.
            </p>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="educbt_create_practice">
                <?php wp_nonce_field( 'educbt_create_practice' ); ?>
                <button type="submit" class="educbt-btn educbt-btn--primary">Create practice exam</button>
            </form>
        <?php endif; ?>
    </section>

    <section class="educbt-card">
        <h2>Question bank</h2>

        <?php if ( empty( $open_window ) ) : ?>
            <p class="educbt-note educbt-note--warn" style="margin-bottom:12px">
                <strong>Closed.</strong> Teachers cannot set questions for anything at the
                moment. Open it below for whichever examination or assessment is next.
            </p>
        <?php else : ?>
            <p class="educbt-note" style="margin-bottom:12px">
                <strong>Open for <?php echo esc_html( (string) $open_window['title'] ); ?>.</strong>
                Every question a teacher sets now goes to this
                <?php echo ! empty( $open_window['is_ca'] ) ? 'assessment' : 'examination'; ?>.
            </p>
        <?php endif; ?>

        <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
                  style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                <input type="hidden" name="action" value="educbt_set_question_window">
                <?php wp_nonce_field( 'educbt_set_question_window' ); ?>
                <select name="series_id" style="min-width:220px">
                    <option value="0">— Close the question bank —</option>
                    <?php foreach ( $ca_windows as $w ) : ?>
                        <option value="<?php echo esc_attr( (string) (int) $w['id'] ); ?>"
                            <?php selected( (int) ( $open_window['series_id'] ?? 0 ), (int) $w['id'] ); ?>>
                            <?php echo esc_html( (string) $w['title'] ); ?> (assessment)
                        </option>
                    <?php endforeach; ?>
                    <?php foreach ( $series as $se ) : ?>
                        <?php // Examinations only; assessments are listed above. ?>
                        <option value="<?php echo esc_attr( (string) (int) $se['id'] ); ?>"
                            <?php selected( (int) ( $open_window['series_id'] ?? 0 ), (int) $se['id'] ); ?>>
                            <?php echo esc_html( (string) $se['title'] ); ?> (examination)
                        </option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="educbt-btn educbt-btn--primary">Apply</button>
            </form>
        </div>

        <p class="educbt-muted" style="font-size:.8rem;margin-top:10px">
            Creating an examination or an assessment opens the bank for it automatically
            and closes the previous one. Between assessments, closing it means nothing can
            be written into the wrong place.
        </p>
    </section>

    <?php
    // ── Continuous assessment ────────────────────────────────────────────────
    // Opened by the office, written into by teachers, composed and published here.
    // Teachers set no dates: a test each class sits on a different afternoon is not
    // a school assessment, it is thirty separate quizzes that cannot be compared.
    ?>
    <section class="educbt-card">
        <h2>Continuous assessment tests</h2>

        <?php if ( empty( $ca_components ) ) : ?>
            <p class="educbt-note educbt-note--warn">
                No continuous assessment components are defined yet. Set them under
                <strong>School &rarr; Settings &rarr; Assessment</strong> first — a CA test needs to
                know which column its marks belong in.
            </p>
        <?php else : ?>
            <?php if ( empty( $ca_windows ) ) : ?>
                <p class="educbt-muted">None yet. Open one below.</p>
            <?php else : ?>
                <table class="educbt-table" style="margin-bottom:16px">
                    <thead><tr><th>Assessment</th><th>Window</th><th>Per student</th><th>Subjects written</th><th>Papers</th><th>Status</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ( $ca_windows as $w ) : ?>
                        <tr>
                            <td><strong><?php echo esc_html( (string) $w['title'] ); ?></strong>
                                <?php if ( ! empty( $w['component_name'] ) ) : ?>
                                    <br><span class="educbt-muted" style="font-size:.78rem">
                                        counts towards <?php echo esc_html( (string) $w['component_name'] ); ?>
                                        (max <?php echo esc_html( (string) (float) $w['max_score'] ); ?>)
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td style="white-space:nowrap">
                                <?php echo esc_html( mysql2date( 'j M', (string) $w['starts_on'] ) ); ?>
                                &ndash;
                                <?php echo esc_html( mysql2date( 'j M Y', (string) $w['ends_on'] ) ); ?>
                            </td>
                            <td><?php echo esc_html( (string) (int) $w['questions_per_student'] ); ?>
                                <span class="educbt-muted">in <?php echo esc_html( (string) (int) $w['duration_minutes'] ); ?> min</span>
                            </td>
                            <td><?php echo esc_html( (string) (int) $w['set_count'] ); ?></td>
                            <td><?php echo esc_html( (string) (int) $w['paper_count'] ); ?></td>
                            <td><span class="educbt-pill"><?php echo esc_html( ucfirst( (string) $w['status'] ) ); ?></span></td>
                            <td style="white-space:nowrap">
                                <?php if ( (int) $w['set_count'] > 0 ) : ?>
                                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
                                        <input type="hidden" name="action" value="educbt_compose_ca_window">
                                        <input type="hidden" name="series_id" value="<?php echo esc_attr( (string) (int) $w['id'] ); ?>">
                                        <?php wp_nonce_field( 'educbt_compose_ca_window' ); ?>
                                        <button type="submit" class="educbt-btn"><?php echo (int) $w['paper_count'] > 0 ? 'Re-compose' : 'Compose papers'; ?></button>
                                    </form>
                                <?php endif; ?>
                                <?php if ( (int) $w['paper_count'] > 0 ) : ?>
                                    <a class="educbt-btn" href="<?php echo esc_url( home_url( '/portal/exams/timetable/?series=' . (int) $w['id'] ) ); ?>">Build timetable</a>
                                <?php endif; ?>
                                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline"
                                      onsubmit="return confirm('Delete <?php echo esc_js( (string) $w['title'] ); ?>? Its papers and timetable go with it. Questions stay in the bank.');">
                                    <input type="hidden" name="action" value="educbt_delete_series">
                                    <input type="hidden" name="series_id" value="<?php echo esc_attr( (string) (int) $w['id'] ); ?>">
                                    <?php wp_nonce_field( 'educbt_delete_series' ); ?>
                                    <button type="submit" class="educbt-btn" style="color:#b91c1c;border-color:#fca5a5">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

        <?php endif; ?>
    </section>

    <?php // Creating an assessment is a separate act from reviewing the ones that
          // exist, so it gets its own card rather than sitting under the table. ?>
    <section class="educbt-card">
        <h2>Open a new assessment window</h2>

        <?php if ( empty( $ca_components ) ) : ?>
            <p class="educbt-note educbt-note--warn">
                Define your continuous assessment components under
                <strong>School &rarr; Settings &rarr; Assessment</strong> first.
            </p>
        <?php else : ?>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="educbt-form">
                <input type="hidden" name="action" value="educbt_create_ca_window">
                <?php wp_nonce_field( 'educbt_create_ca_window' ); ?>

                <div class="educbt-grid">
                    <div>
                        <label for="ca_component">Counts towards *</label>
                        <select id="ca_component" name="component_id" required>
                            <?php foreach ( $ca_components as $c ) : ?>
                                <option value="<?php echo esc_attr( (string) $c['id'] ); ?>">
                                    <?php echo esc_html( (string) $c['name'] ); ?>
                                    (max <?php echo esc_html( (string) (float) $c['max_score'] ); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="educbt-muted">Taken from your assessment settings, so the mark lands in the right column.</small>
                    </div>
                    <div>
                        <label for="ca_title">Name</label>
                        <input id="ca_title" name="title" type="text" placeholder="Leave blank to name it after the assessment">
                    </div>
                    <div>
                        <label for="ca_opens">Question window opens *</label>
                        <input id="ca_opens" name="starts_on" type="date" required>
                        <small class="educbt-muted">Date teachers can begin submitting questions.</small>
                    </div>
                    <div>
                        <label for="ca_closes">Question window closes *</label>
                        <input id="ca_closes" name="ends_on" type="date" required>
                        <small class="educbt-muted">Deadline for teachers to submit questions.</small>
                    </div>
                    <div>
                        <label for="ca_count">Questions per student *</label>
                        <input id="ca_count" name="questions_per_student" type="number" min="1" max="100" value="20" required>
                        <small class="educbt-muted">Teachers may write more than this; each student answers this many.</small>
                    </div>
                    <div>
                        <label for="ca_duration">Duration (minutes) *</label>
                        <input id="ca_duration" name="duration_minutes" type="number" min="5" max="180" step="5" value="30" required>
                    </div>
                </div>

                <button type="submit" class="educbt-btn educbt-btn--primary" style="margin-top:14px">Open assessment window</button>
            </form>
        <?php endif; ?>
    </section>

    <section class="educbt-card">
        <h2>Create examination</h2>
        <p class="educbt-muted">
            Choose the session and term this examination covers. Teachers submit their
            questions against it, and you build the timetable once questions are in.
        </p>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="educbt-form">
            <input type="hidden" name="action" value="educbt_create_examination">
            <?php wp_nonce_field( 'educbt_create_examination' ); ?>

            <div class="educbt-grid">
                <div>
                    <label for="session_id">Session *</label>
                    <select id="session_id" name="session_id" required>
                        <?php foreach ( $sessions as $sess ) : ?>
                            <option value="<?php echo esc_attr( (string) $sess['id'] ); ?>"
                                <?php selected( (int) $sess['id'], $current_session_id ); ?>>
                                <?php echo esc_html( (string) $sess['title'] ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="term_id">Term *</label>
                    <select id="term_id" name="term_id" required>
                        <?php foreach ( $terms as $t ) : ?>
                            <option value="<?php echo esc_attr( (string) $t['id'] ); ?>"
                                <?php selected( (int) $t['id'], $current_term_id ); ?>>
                                <?php echo esc_html( (string) $t['title'] ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="series_title">Name</label>
                    <input id="series_title" name="title" type="text" placeholder="First Term Examination">
                    <small class="educbt-muted">Leave blank to name it after the term.</small>
                </div>
                <div>
                    <label for="starts_on">Question window opens</label>
                    <input id="starts_on" name="starts_on" type="date">
                    <small class="educbt-muted">Date teachers can begin submitting questions.</small>
                </div>
                <div>
                    <label for="ends_on">Question window closes</label>
                    <input id="ends_on" name="ends_on" type="date">
                    <small class="educbt-muted">Deadline for teachers to submit questions. Exam sitting dates are set when the timetable is created.</small>
                </div>
            </div>

            <button type="submit" class="educbt-btn educbt-btn--primary" style="margin-top:16px">Create examination</button>
        </form>
    </section>

    <section class="educbt-card">
        <h2>Examinations <span class="educbt-muted">(<?php echo esc_html( (string) count( $series ) ); ?>)</span></h2>
        <?php if ( empty( $series ) ) : ?>
            <p class="educbt-muted">None yet. Create one above.</p>
        <?php else : ?>
            <table class="educbt-table">
                <thead><tr><th>Examination</th><th>Session / Term</th><th>Q-Bank Opens</th><th>Q-Bank Closes</th><th>Status</th><th></th></tr></thead>
                <tbody>
                <?php foreach ( $series as $s ) :
                    $has_papers = (int) $wpdb->get_var(
                        $wpdb->prepare(
                            "SELECT COUNT(*) FROM {$papers_table} WHERE series_id = %d AND school_id = %d AND status <> 'cancelled'",
                            (int) $s['id'], $school_id
                        )
                    );
                    $series_status = $has_papers > 0 ? 'Timetable Built' : ucfirst( (string) $s['status'] );
                ?>
                    <tr>
                        <td><strong><?php echo esc_html( (string) $s['title'] ); ?></strong></td>
                        <td><?php echo esc_html( trim( (string) ( $s['session_title'] ?? '' ) . ' · ' . (string) ( $s['term_title'] ?? '' ), ' ·' ) ); ?></td>
                        <td><?php echo esc_html( (string) ( $s['starts_on'] ?: '—' ) ); ?></td>
                        <td><?php echo esc_html( (string) ( $s['ends_on'] ?? '—' ) ?: '—' ); ?></td>
                        <td><span class="educbt-pill <?php echo $has_papers > 0 ? 'educbt-pill--published' : ''; ?>"><?php echo esc_html( $series_status ); ?></span></td>
                        <td style="white-space:nowrap">
                            <?php if ( $has_papers > 0 ) : ?>
                                <a class="educbt-btn" style="font-size:.78rem;padding:4px 10px" href="<?php echo esc_url( home_url( '/portal/exams/timetable/?series=' . (int) $s['id'] ) ); ?>">View timetable</a>
                            <?php else : ?>
                                <a class="educbt-btn" style="font-size:.78rem;padding:4px 10px" href="<?php echo esc_url( home_url( '/portal/exams/timetable/?series=' . (int) $s['id'] ) ); ?>">Build timetable</a>
                                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline"
                                      onsubmit="return confirm('Delete <?php echo esc_js( (string) $s['title'] ); ?>? Its papers and timetable go with it. Questions stay in the bank.');">
                                    <input type="hidden" name="action" value="educbt_delete_series">
                                    <input type="hidden" name="series_id" value="<?php echo esc_attr( (string) (int) $s['id'] ); ?>">
                                    <?php wp_nonce_field( 'educbt_delete_series' ); ?>
                                    <button type="submit" class="educbt-btn" style="font-size:.78rem;padding:4px 10px;color:#b91c1c;border-color:#fca5a5">Delete</button>
                                </form>
                            <?php endif; ?>
                            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;margin-left:4px"
                                  onsubmit="return confirm('Delete this examination and all its papers? This cannot be undone.');">
                                <input type="hidden" name="action" value="educbt_delete_examination">
                                <input type="hidden" name="series_id" value="<?php echo esc_attr( (string) $s['id'] ); ?>">
                                <?php wp_nonce_field( 'educbt_delete_examination' ); ?>
                                <button type="submit" class="educbt-btn" style="color:#b91c1c;border-color:#f3c9c9;font-size:.78rem;padding:4px 10px">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>

    <section class="educbt-card">
        <h2>Papers <span class="educbt-muted">(<?php echo esc_html( (string) count( $papers ) ); ?>)</span></h2>
        <p class="educbt-muted" style="margin-bottom:12px;font-size:.85rem">
            Papers are created when you build the timetable. Once a paper exists, click
            <strong>Compose</strong> to pull all approved questions into the pool. Each
            student sees a random subset — not the full pool. Written papers need no composition.
        </p>

        <?php if ( empty( $papers ) ) : ?>
            <p class="educbt-muted">No papers yet. Build the timetable from an examination above to create papers.</p>
        <?php else : ?>
            <table class="educbt-table">
                <thead><tr><th>Subject</th><th>Class</th><th>Type</th><th>When</th><th>Closes</th><th>Questions</th><th>Invigilator</th><th>Status</th><th></th></tr></thead>
                <tbody>
                <?php foreach ( $papers as $p ) : ?>
                    <tr>
                        <td><?php echo esc_html( (string) $p['subject_name'] ); ?></td>
                        <td><?php echo esc_html( educbt_class_level_name( (string) $p['class_name'] ) ); ?></td>
                        <td>
                            <?php
                            $p_series_type = (string) ( $p['series_type'] ?? 'examination' );
                            $p_is_ca = (int) $p['is_practice'] === 1 || $p_series_type === 'ca_test';
                            ?>
                            <?php if ( $p_is_ca ) : ?>
                                <span class="educbt-pill" style="background:#F3F7DC;color:#3F6B4A;border:1px solid #D3E64B"><?php echo esc_html( $paper_components[ (int) $p['id'] ] ?? 'CA Test' ); ?></span>
                            <?php else : ?>
                                <span class="educbt-pill" style="background:#E8F0E5;color:#173D26;border:1px solid #7C9473">Examination</span>
                            <?php endif; ?>
                            <?php if ( (string) ( $p['delivery_mode'] ?? 'cbt' ) === 'written' ) : ?>
                                <span class="educbt-pill" style="background:#FEF3C7;color:#92400E;border:1px solid #FCD34D;margin-left:4px">Written</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html( wp_date( 'j M, g:ia', strtotime( (string) $p['scheduled_at'] . ' UTC' ) ) ); ?><br>
                            <span class="educbt-muted"><?php echo esc_html( (string) round( (int) $p['duration_seconds'] / 60 ) ); ?> min</span></td>
                        <td><?php
                            $p_closes = (string) ( $p['closes_at'] ?? '' );
                            if ( $p_closes !== '' && $p_closes !== '0000-00-00 00:00:00' ) {
                                echo esc_html( wp_date( 'j M, g:ia', strtotime( (string) $p_closes . ' UTC' ) ) );
                            } else {
                                echo '<span class="educbt-muted">&mdash;</span>';
                            }
                        ?></td>
                        <td><?php
                            if ( (string) ( $p['delivery_mode'] ?? 'cbt' ) === 'written' ) {
                                echo '<span class="educbt-muted">Written paper</span>';
                            } else {
                                $pool = (int) $p['composed'];
                                $per_student = (int) $p['question_count'];
                                if ( $pool === 0 ) {
                                    echo '<span class="educbt-muted">Not composed</span>';
                                } else {
                                    echo esc_html( $pool . ' in pool' );
                                    if ( $pool !== $per_student ) {
                                        echo '<br><span class="educbt-muted" style="font-size:.8rem">' . esc_html( $per_student . ' per student' ) . '</span>';
                                    }
                                }
                            }
                        ?></td>
                        <td><?php echo esc_html( (string) ( $p['invigilator'] ?: '—' ) ); ?></td>
                        <td><span class="educbt-pill educbt-pill--<?php echo esc_attr( (string) $p['status'] ); ?>"><?php echo esc_html( ucfirst( (string) $p['status'] ) ); ?></span></td>
                        <td style="white-space:nowrap">
                            <?php
                            $p_status   = (string) $p['status'];
                            $p_is_ca    = (int) $p['is_practice'] === 1 || (string) ( $p['series_type'] ?? 'examination' ) === 'ca_test';
                            $p_code     = (string) $p['access_code'];
                            ?>
                            <?php if ( $p_status === 'published' ) : ?>
                                <?php if ( ! $p_is_ca && $p_code !== '' ) : ?>
                                    <code style="font-size:13px;font-weight:700;letter-spacing:1px;background:#f0fdf4;padding:2px 8px;border-radius:4px;color:#166534"><?php echo esc_html( $p_code ); ?></code>
                                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;margin-left:4px"
                                          onsubmit="return confirm('Regenerate access code? The old code will stop working immediately.');">
                                        <input type="hidden" name="action" value="educbt_regenerate_access_code">
                                        <input type="hidden" name="paper_id" value="<?php echo esc_attr( (string) $p['id'] ); ?>">
                                        <?php wp_nonce_field( 'educbt_regenerate_access_code' ); ?>
                                        <button type="submit" class="educbt-btn" style="font-size:.8rem;padding:4px 10px;border-color:var(--sage);color:var(--forest)">↻</button>
                                    </form>
                                <?php elseif ( ! $p_is_ca ) : ?>
                                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline"
                                          onsubmit="return confirm('Generate an access code for this paper?');">
                                        <input type="hidden" name="action" value="educbt_regenerate_access_code">
                                        <input type="hidden" name="paper_id" value="<?php echo esc_attr( (string) $p['id'] ); ?>">
                                        <?php wp_nonce_field( 'educbt_regenerate_access_code' ); ?>
                                        <button type="submit" class="educbt-btn" style="font-size:.78rem;padding:4px 10px;border-color:var(--sage);color:var(--forest)">Generate code</button>
                                    </form>
                                <?php endif; ?>
                                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;margin-right:4px"
                                      onsubmit="return confirm('Unpublish this <?php echo $p_is_ca ? 'test' : 'exam'; ?>? Students will no longer see it. You can republish later.');">
                                    <input type="hidden" name="action" value="educbt_unpublish_paper">
                                    <input type="hidden" name="paper_id" value="<?php echo esc_attr( (string) $p['id'] ); ?>">
                                    <?php wp_nonce_field( 'educbt_unpublish_paper' ); ?>
                                    <button type="submit" class="educbt-btn" style="color:#b45309;border-color:#fcd34d">Unpublish</button>
                                </form>
                            <?php elseif ( $p_status === 'closed' ) : ?>
                                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;margin-right:4px">
                                    <input type="hidden" name="action" value="educbt_publish_paper">
                                    <input type="hidden" name="paper_id" value="<?php echo esc_attr( (string) $p['id'] ); ?>">
                                    <?php wp_nonce_field( 'educbt_publish_paper' ); ?>
                                    <button type="submit" class="educbt-btn educbt-btn--primary">Republish</button>
                                </form>
                            <?php else : ?>
                                <?php if ( ! $p_is_ca && $p_code !== '' ) : ?>
                                    <code style="font-size:13px;font-weight:700;letter-spacing:1px;background:#f3f5ef;padding:2px 8px;border-radius:4px;color:#666"><?php echo esc_html( $p_code ); ?></code>
                                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;margin-left:4px"
                                          onsubmit="return confirm('Regenerate access code? The old code will stop working immediately.');">
                                        <input type="hidden" name="action" value="educbt_regenerate_access_code">
                                        <input type="hidden" name="paper_id" value="<?php echo esc_attr( (string) $p['id'] ); ?>">
                                        <?php wp_nonce_field( 'educbt_regenerate_access_code' ); ?>
                                        <button type="submit" class="educbt-btn" style="font-size:.8rem;padding:4px 10px;border-color:var(--sage);color:var(--forest)">↻</button>
                                    </form>
                                <?php elseif ( ! $p_is_ca ) : ?>
                                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline"
                                          onsubmit="return confirm('Generate an access code for this paper?');">
                                        <input type="hidden" name="action" value="educbt_regenerate_access_code">
                                        <input type="hidden" name="paper_id" value="<?php echo esc_attr( (string) $p['id'] ); ?>">
                                        <?php wp_nonce_field( 'educbt_regenerate_access_code' ); ?>
                                        <button type="submit" class="educbt-btn" style="font-size:.78rem;padding:4px 10px;border-color:var(--sage);color:var(--forest)">Generate code</button>
                                    </form>
                                <?php endif; ?>
                                <?php if ( (string) ( $p['delivery_mode'] ?? 'cbt' ) !== 'written' ) : ?>
                                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline"
                                      onsubmit="return confirm('Recompose this paper? This will refresh the question pool from all approved questions.');">
                                    <input type="hidden" name="action" value="educbt_recompose_paper">
                                    <input type="hidden" name="paper_id" value="<?php echo esc_attr( (string) $p['id'] ); ?>">
                                    <?php wp_nonce_field( 'educbt_recompose_paper' ); ?>
                                    <button type="submit" class="educbt-btn" style="font-size:.8rem;padding:4px 10px;border-color:var(--sage);color:var(--forest)"><?php echo (int) $p['composed'] === 0 ? 'Compose' : 'Recompose'; ?></button>
                                </form>
                                <?php endif; ?>
                                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
                                    <input type="hidden" name="action" value="educbt_publish_paper">
                                    <input type="hidden" name="paper_id" value="<?php echo esc_attr( (string) $p['id'] ); ?>">
                                    <?php wp_nonce_field( 'educbt_publish_paper' ); ?>
                                    <button type="submit" class="educbt-btn">Publish</button>
                                </form>
                            <?php endif; ?>

                            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;margin-left:4px"
                                  onsubmit="return confirm('Delete this paper? This cannot be undone.');">
                                <input type="hidden" name="action" value="educbt_delete_paper">
                                <input type="hidden" name="paper_id" value="<?php echo esc_attr( (string) $p['id'] ); ?>">
                                <?php wp_nonce_field( 'educbt_delete_paper' ); ?>
                                <button type="submit" class="educbt-btn" style="color:#b91c1c;border-color:#f3c9c9">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <p class="educbt-muted" style="margin-top:10px">
                The access code is what the invigilator reads out in the exam hall. Students cannot open the paper without it. Click ↻ to generate a new code.
            </p>
        <?php endif; ?>
    </section>
    <?php
};

require EDUCBT_PRO_PATH . 'templates/portal/shell.php';
