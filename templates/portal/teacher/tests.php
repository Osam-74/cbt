<?php
/**
 * Class tests set by the subject teacher.
 *
 * A CA test is the same machinery as an examination paper, pointed at a different
 * component. That matters: the mark is SCALED into the component's weight, so a
 * 20-question test scored 15/20 lands as 7.5 in a component worth 10 — not as 15.
 * Marking out of the raw total is how CA scores get quietly inflated.
 *
 * The teacher owns this one: they set it, they publish it, and only students
 * registered for the subject can open it.
 *
 * @var array<string,mixed> $educbt
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

global $wpdb;

$school_id = (int) $educbt['school_id'];
$flash     = \EduCBTPro\Frontend\PortalActions::flash();
$actor     = $educbt['scope']->actor();
$staff_id  = (int) $actor['id'];

$year       = new \EduCBTPro\Services\AcademicYearService();
$session    = $year->current_session( $school_id );
$term       = $year->current_term( $school_id );
$session_id = (int) ( $session['id'] ?? 0 );
$term_id    = (int) ( $term['id'] ?? 0 );

$assessment = new \EduCBTPro\Services\AssessmentService();

// The exam component is the school's to run, not the teacher's.
$components = array_values(
    array_filter(
        $assessment->components( $school_id ),
        static fn( array $c ): bool => empty( $c['is_exam'] )
    )
);

$assign     = \EduCBTPro\Core\Schema::table( 'staff_assignments' );
$classes_tb = \EduCBTPro\Core\Schema::table( 'classes' );
$subjects_t = \EduCBTPro\Core\Schema::table( 'subjects_v2' );
$papers_tb  = \EduCBTPro\Core\Schema::table( 'exam_papers' );
$attempts   = \EduCBTPro\Core\Schema::table( 'attempts' );

$pairs = $educbt['scope']->is_school_wide()
    ? (array) $wpdb->get_results(
        $wpdb->prepare(
            "SELECT DISTINCT c.id AS class_id, c.display_name, s.id AS subject_id, s.name AS subject_name
             FROM {$classes_tb} c CROSS JOIN {$subjects_t} s
             WHERE c.school_id = %d AND c.status = 'active' AND s.school_id = %d AND s.status = 'active'
             ORDER BY c.display_name ASC, s.name ASC",
            $school_id, $school_id
        ),
        ARRAY_A
    )
    : (array) $wpdb->get_results(
        $wpdb->prepare(
            "SELECT DISTINCT c.id AS class_id, c.display_name, s.id AS subject_id, s.name AS subject_name
             FROM {$assign} a
             INNER JOIN {$classes_tb} c ON c.id = a.class_id
             INNER JOIN {$subjects_t} s ON s.id = a.subject_id
             WHERE a.school_id = %d AND a.staff_id = %d AND a.assignment_type = 'subject_teacher' AND a.status = 'active'
             ORDER BY c.display_name ASC, s.name ASC",
            $school_id, $staff_id
        ),
        ARRAY_A
    );

$mine = (array) $wpdb->get_results(
    $wpdb->prepare(
        "SELECT p.id, p.scheduled_at, p.duration_seconds, p.question_count, p.status, p.access_code,
                s.name AS subject_name, c.display_name AS class_name,
                (SELECT COUNT(*) FROM {$attempts} at WHERE at.paper_id = p.id) AS started,
                (SELECT COUNT(*) FROM {$attempts} at WHERE at.paper_id = p.id AND at.status = 'graded') AS finished
         FROM {$papers_tb} p
         INNER JOIN {$subjects_t} s ON s.id = p.subject_id
         LEFT JOIN {$classes_tb} c ON c.id = p.class_id
         WHERE p.school_id = %d AND p.is_practice = 1 AND p.status <> 'cancelled'
         ORDER BY p.scheduled_at DESC LIMIT 40",
        $school_id
    ),
    ARRAY_A
);

$educbt_title = 'Class Tests';

$educbt_body = static function () use ( $flash, $pairs, $components, $mine, $term_id, $term ): void {
    require EDUCBT_PRO_PATH . 'templates/portal/partials/flash.php';

    if ( empty( $components ) ) {
        echo '<div class="educbt-card"><p class="educbt-note educbt-note--warn">No continuous-assessment components are set up. The principal defines these under Settings.</p></div>';
        return;
    }

    if ( empty( $pairs ) ) {
        echo '<div class="educbt-card"><p class="educbt-note educbt-note--warn">You have not been assigned a subject to teach yet.</p></div>';
        return;
    }
    ?>
    <section class="educbt-card">
        <h2>Set a class test</h2>
        <p class="educbt-muted" style="margin-top:-6px">
            Drawn from your approved questions. The mark is scaled into the assessment
            you choose, so a 20-question test counts for whatever that assessment is
            worth — not for 20.
        </p>

        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="educbt-form">
            <input type="hidden" name="action" value="educbt_create_ca_test">
            <input type="hidden" name="term_id" value="<?php echo esc_attr( (string) $term_id ); ?>">
            <?php wp_nonce_field( 'educbt_create_ca_test' ); ?>

            <div class="educbt-grid">
                <div>
                    <label for="pair">Class and subject *</label>
                    <select id="pair" name="pair" required onchange="var v=this.value.split(':');document.getElementById('t_class').value=v[0];document.getElementById('t_subject').value=v[1];">
                        <option value="">Choose</option>
                        <?php foreach ( $pairs as $p ) : ?>
                            <option value="<?php echo esc_attr( $p['class_id'] . ':' . $p['subject_id'] ); ?>">
                                <?php echo esc_html( $p['display_name'] . ' — ' . $p['subject_name'] ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <input type="hidden" name="class_id" id="t_class">
                    <input type="hidden" name="subject_id" id="t_subject">
                </div>
                <div>
                    <label for="component_id">Counts towards *</label>
                    <select id="component_id" name="component_id" required>
                        <?php foreach ( $components as $c ) : ?>
                            <option value="<?php echo esc_attr( (string) $c['id'] ); ?>">
                                <?php echo esc_html( $c['name'] . ' (worth ' . (float) $c['max_score'] . ')' ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="scheduled_at">Opens *</label>
                    <input id="scheduled_at" name="scheduled_at" type="datetime-local" required>
                </div>
                <div>
                    <label for="duration_minutes">Minutes *</label>
                    <input id="duration_minutes" name="duration_minutes" type="number" min="5" max="180" value="20" required>
                </div>
                <div>
                    <label for="question_count">Questions *</label>
                    <input id="question_count" name="question_count" type="number" min="1" max="100" value="20" required>
                </div>
            </div>

            <label style="display:flex;align-items:center;gap:8px;margin-top:12px;font-weight:400">
                <input type="checkbox" name="requires_access_code" value="1" style="width:auto">
                Require a code to open it <span class="educbt-muted">(leave off for a test students take in their own time)</span>
            </label>

            <button type="submit" class="educbt-btn educbt-btn--primary" style="margin-top:16px">Create and compose</button>
        </form>
    </section>

    <section class="educbt-card">
        <h2>Class Tests</h2>
        <?php if ( empty( $mine ) ) : ?>
            <p class="educbt-muted">None yet.</p>
        <?php else : ?>
            <table class="educbt-table">
                <thead><tr><th>Subject</th><th>Class</th><th>Opens</th><th>Taken</th><th>Status</th><th></th></tr></thead>
                <tbody>
                <?php foreach ( $mine as $t ) : ?>
                    <tr>
                        <td><?php echo esc_html( (string) $t['subject_name'] ); ?></td>
                        <td><?php echo esc_html( (string) $t['class_name'] ); ?></td>
                        <td><?php echo esc_html( mysql2date( 'j M, g:ia', (string) $t['scheduled_at'] ) ); ?></td>
                        <td><?php echo esc_html( $t['finished'] . ' of ' . $t['started'] . ' started' ); ?></td>
                        <td><span class="educbt-pill educbt-pill--<?php echo esc_attr( (string) $t['status'] ); ?>"><?php echo esc_html( ucfirst( (string) $t['status'] ) ); ?></span></td>
                        <td>
                            <?php if ( (string) $t['status'] !== 'published' ) : ?>
                                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                                    <input type="hidden" name="action" value="educbt_publish_paper">
                                    <input type="hidden" name="paper_id" value="<?php echo esc_attr( (string) $t['id'] ); ?>">
                                    <?php wp_nonce_field( 'educbt_publish_paper' ); ?>
                                    <button type="submit" class="educbt-btn">Publish to students</button>
                                </form>
                            <?php elseif ( ! empty( $t['access_code'] ) ) : ?>
                                <code><?php echo esc_html( (string) $t['access_code'] ); ?></code>
                            <?php else : ?>
                                <span class="educbt-muted">open</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <p class="educbt-muted" style="margin-top:10px">
                Once published, the test appears on the dashboard of every student
                registered for that subject in that class — and nobody else.
            </p>
        <?php endif; ?>
    </section>
    <?php
};

require EDUCBT_PRO_PATH . 'templates/portal/shell.php';
