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

$series = (array) $wpdb->get_results(
    $wpdb->prepare( "SELECT id, title FROM {$series_table} WHERE school_id = %d ORDER BY id DESC", $school_id ),
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

$educbt_body = static function () use ( $flash, $subjects, $classes, $series, $papers ): void {
    require EDUCBT_PRO_PATH . 'templates/portal/partials/flash.php';

    if ( empty( $classes ) || empty( $subjects ) ) {
        echo '<div class="educbt-card"><p class="educbt-note educbt-note--warn">You need at least one class and one subject before scheduling a paper.</p></div>';
        return;
    }
    ?>
    <section class="educbt-card">
        <h2>Schedule a paper</h2>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="educbt-form">
            <input type="hidden" name="action" value="educbt_create_paper">
            <?php wp_nonce_field( 'educbt_create_paper' ); ?>

            <div class="educbt-grid">
                <div>
                    <label for="series_id">Examination</label>
                    <select id="series_id" name="series_id">
                        <option value="0">— create a new one —</option>
                        <?php foreach ( $series as $s ) : ?>
                            <option value="<?php echo esc_attr( (string) $s['id'] ); ?>"><?php echo esc_html( (string) $s['title'] ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="series_title">…or name a new one</label>
                    <input id="series_title" name="series_title" type="text" placeholder="First Term Examination">
                </div>
                <div>
                    <label for="subject_id">Subject *</label>
                    <select id="subject_id" name="subject_id" required>
                        <option value="">Choose</option>
                        <?php foreach ( $subjects as $s ) : ?>
                            <option value="<?php echo esc_attr( (string) $s['id'] ); ?>" <?php disabled( (int) $s['bank'] === 0 ); ?>>
                                <?php
                                echo esc_html(
                                    $s['name'] . ' — ' . (int) $s['bank'] . ' approved'
                                    . ( (int) $s['submitted'] > (int) $s['bank']
                                        ? ', ' . ( (int) $s['submitted'] - (int) $s['bank'] ) . ' awaiting approval'
                                        : '' )
                                );
                                ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="class_id">Class *</label>
                    <select id="class_id" name="class_id" required>
                        <option value="">Choose</option>
                        <?php foreach ( $classes as $c ) : ?>
                            <option value="<?php echo esc_attr( (string) $c['id'] ); ?>"><?php echo esc_html( (string) $c['display_name'] ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="scheduled_at">Date and time *</label>
                    <input id="scheduled_at" name="scheduled_at" type="datetime-local" required>
                </div>
                <div>
                    <label for="duration_minutes">Duration (minutes) *</label>
                    <input id="duration_minutes" name="duration_minutes" type="number" min="5" max="300" value="60" required>
                </div>
                <div>
                    <label for="question_count">Number of questions *</label>
                    <p class="educbt-muted" style="margin-top:4px;font-size:12.5px;order:2">
                        Cannot exceed the approved count shown against the subject.
                    </p>
                    <input id="question_count" name="question_count" type="number" min="1" max="200" value="40" required>
                </div>
            </div>

            <label style="display:flex;align-items:center;gap:8px;margin-top:12px;font-weight:400">
                <input type="checkbox" name="is_practice" value="1" style="width:auto"> Practice paper (no access code)
            </label>

            <button type="submit" class="educbt-btn educbt-btn--primary" style="margin-top:16px">Schedule and compose</button>
        </form>
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
