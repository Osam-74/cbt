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
        "SELECT se.id, se.title, se.status, se.starts_on,
                a.title AS session_title, t.title AS term_title
         FROM {$series_table} se
         LEFT JOIN {$sessions_table} a ON a.id = se.session_id
         LEFT JOIN {$terms_table} t ON t.id = se.term_id
         WHERE se.school_id = %d ORDER BY se.id DESC",
        $school_id
    ),
    ARRAY_A
);

$papers = (array) $wpdb->get_results(
    $wpdb->prepare(
        "SELECT p.*, sub.name AS subject_name, c.display_name AS class_name,
                (SELECT COUNT(*) FROM {$pq_table} pq WHERE pq.paper_id = p.id) AS composed,
                (SELECT CONCAT(st.first_name,' ',st.last_name) FROM {$inv_table} i
                   INNER JOIN {$staff_table} st ON st.id = i.staff_id
                  WHERE i.paper_id = p.id LIMIT 1) AS invigilator
         FROM {$papers_table} p
         INNER JOIN {$subjects_table} sub ON sub.id = p.subject_id
         LEFT JOIN {$classes_table} c ON c.id = p.class_id
         WHERE p.school_id = %d AND p.status <> 'cancelled'
         ORDER BY p.scheduled_at DESC LIMIT 60",
        $school_id
    ),
    ARRAY_A
);

$educbt_title = 'Exam Papers';

$educbt_body = static function () use ( $flash, $subjects, $classes, $series, $papers, $sessions, $terms, $current_session_id, $current_term_id ): void {
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
                    <label for="starts_on">Starts on</label>
                    <input id="starts_on" name="starts_on" type="date">
                    <small class="educbt-muted">Used as the first day when generating the timetable.</small>
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
                <thead><tr><th>Examination</th><th>Session / Term</th><th>Starts</th><th>Status</th><th></th></tr></thead>
                <tbody>
                <?php foreach ( $series as $s ) : ?>
                    <tr>
                        <td><strong><?php echo esc_html( (string) $s['title'] ); ?></strong></td>
                        <td><?php echo esc_html( trim( (string) ( $s['session_title'] ?? '' ) . ' · ' . (string) ( $s['term_title'] ?? '' ), ' ·' ) ); ?></td>
                        <td><?php echo esc_html( (string) ( $s['starts_on'] ?: '—' ) ); ?></td>
                        <td><span class="educbt-pill"><?php echo esc_html( ucfirst( (string) $s['status'] ) ); ?></span></td>
                        <td>
                            <a class="educbt-btn" href="<?php echo esc_url( home_url( '/portal/exams/timetable/?series=' . (int) $s['id'] ) ); ?>">Build timetable</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>

    <section class="educbt-card">
        <h2>Papers <span class="educbt-muted">(<?php echo esc_html( (string) count( $papers ) ); ?>)</span></h2>

        <?php if ( empty( $papers ) ) : ?>
            <p class="educbt-muted">No papers scheduled yet.</p>
        <?php else : ?>
            <table class="educbt-table">
                <thead><tr><th>Subject</th><th>Class</th><th>When</th><th>Questions</th><th>Invigilator</th><th>Status</th><th></th></tr></thead>
                <tbody>
                <?php foreach ( $papers as $p ) : ?>
                    <tr>
                        <td><?php echo esc_html( (string) $p['subject_name'] ); ?></td>
                        <td><?php echo esc_html( (string) $p['class_name'] ); ?></td>
                        <td><?php echo esc_html( mysql2date( 'j M, g:ia', (string) $p['scheduled_at'] ) ); ?><br>
                            <span class="educbt-muted"><?php echo esc_html( (string) round( (int) $p['duration_seconds'] / 60 ) ); ?> min</span></td>
                        <td><?php echo esc_html( $p['composed'] . '/' . $p['question_count'] ); ?></td>
                        <td><?php echo esc_html( (string) ( $p['invigilator'] ?: '—' ) ); ?></td>
                        <td><span class="educbt-pill educbt-pill--<?php echo esc_attr( (string) $p['status'] ); ?>"><?php echo esc_html( ucfirst( (string) $p['status'] ) ); ?></span></td>
                        <td style="white-space:nowrap">
                            <?php if ( (string) $p['status'] !== 'published' ) : ?>
                                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
                                    <input type="hidden" name="action" value="educbt_publish_paper">
                                    <input type="hidden" name="paper_id" value="<?php echo esc_attr( (string) $p['id'] ); ?>">
                                    <?php wp_nonce_field( 'educbt_publish_paper' ); ?>
                                    <button type="submit" class="educbt-btn">Publish</button>
                                </form>
                            <?php else : ?>
                                <code><?php echo esc_html( (string) $p['access_code'] ); ?></code>
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
                The code beside a published paper is what the invigilator reads out. Students cannot open the paper without it.
            </p>
        <?php endif; ?>
    </section>
    <?php
};

require EDUCBT_PRO_PATH . 'templates/portal/shell.php';
