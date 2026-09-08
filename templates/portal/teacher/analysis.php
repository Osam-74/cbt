<?php
/**
 * A subject teacher's view of how their students did.
 *
 * Two modes:
 *  - CA components: reads from assessment_scores (what the teacher entered).
 *  - Exam component: reads from CBT attempts (what the platform auto-marked).
 *
 * Ranked highest first. The per-question breakdown is here too, because a
 * question everybody missed usually means the teaching, not the students.
 *
 * @var array<string,mixed> $educbt
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

global $wpdb;

$school_id = (int) $educbt['school_id'];
$actor     = $educbt['scope']->actor();
$staff_id  = (int) $actor['id'];

$year       = new \EduCBTPro\Services\AcademicYearService();
$session    = $year->current_session( $school_id );
$term       = $year->current_term( $school_id );
$session_id = (int) ( $session['id'] ?? 0 );
$term_id    = (int) ( $term['id'] ?? 0 );

$assessment = new \EduCBTPro\Services\AssessmentService();
$components = $assessment->components( $school_id );

// Non-school-wide teachers cannot see exam component scores here (those come from CBT).
// They CAN see the exam results via the paper dropdown when an exam paper exists.
$assign_table   = \EduCBTPro\Core\Schema::table( 'staff_assignments' );
$classes_table  = \EduCBTPro\Core\Schema::table( 'classes' );
$subjects_table = \EduCBTPro\Core\Schema::table( 'subjects_v2' );
$scores_table   = \EduCBTPro\Core\Schema::table( 'assessment_scores' );
$papers_tb      = \EduCBTPro\Core\Schema::table( 'exam_papers' );
$attempts       = \EduCBTPro\Core\Schema::table( 'attempts' );
$answers        = \EduCBTPro\Core\Schema::table( 'attempt_answers' );
$students_t     = $wpdb->prefix . 'educbt_students';
$questions      = $wpdb->prefix . 'educbt_questions';
$enrol_table    = \EduCBTPro\Core\Schema::table( 'enrollments' );

// (class, subject) pairs this teacher holds — same pattern as scores.php
if ( $educbt['scope']->is_school_wide() ) {
    $pairs = (array) $wpdb->get_results(
        $wpdb->prepare(
            "SELECT DISTINCT c.id AS class_id, c.display_name, s.id AS subject_id, s.name AS subject_name
             FROM {$classes_table} c CROSS JOIN {$subjects_table} s
             WHERE c.school_id = %d AND c.status = 'active' AND s.school_id = %d AND s.status = 'active'
             ORDER BY c.display_name ASC, s.name ASC",
            $school_id,
            $school_id
        ),
        ARRAY_A
    );
} else {
    $pairs = (array) $wpdb->get_results(
        $wpdb->prepare(
            "SELECT DISTINCT c.id AS class_id, c.display_name, s.id AS subject_id, s.name AS subject_name
             FROM {$assign_table} a
             INNER JOIN {$classes_table} c ON c.id = a.class_id
             INNER JOIN {$subjects_table} s ON s.id = a.subject_id
             WHERE a.school_id = %d AND a.staff_id = %d AND a.assignment_type = 'subject_teacher'
               AND a.status = 'active'
             ORDER BY c.display_name ASC, s.name ASC",
            $school_id,
            (int) $actor['id']
        ),
        ARRAY_A
    );
}

// Filters (GET-driven, matching scores.php pattern)
$class_id     = (int) ( $_GET['class'] ?? 0 );
$subject_id   = (int) ( $_GET['subject'] ?? 0 );
$component_id = (int) ( $_GET['component'] ?? ( $components[0]['id'] ?? 0 ) );

// If a pair is selected via the combined dropdown, split it
$pair_val = sanitize_text_field( (string) ( $_GET['pair'] ?? '' ) );
if ( strpos( $pair_val, ':' ) !== false ) {
    list( $class_id, $subject_id ) = array_map( 'absint', explode( ':', $pair_val ) );
}

$component = null;
foreach ( $components as $c ) {
    if ( (int) $c['id'] === $component_id ) { $component = $c; }
}

$is_exam_component = ! empty( $component['is_exam'] );

$ranking  = [];
$per_item = [];
$stats    = [ 'count' => 0, 'average' => 0, 'highest' => 0, 'passed' => 0, 'pass_rate' => 0 ];

if ( $class_id > 0 && $subject_id > 0 && $component_id > 0 && $term_id > 0 ) {

    if ( $is_exam_component ) {
        // Exam mode: pull from CBT attempts for this subject + class
        // Look for an exam paper for this subject. The timetable generator may set
        // class_id to a representative class or null, so match by subject and
        // level (derived from the class) rather than class_id alone.
        $level_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT level_id FROM {$classes_table} WHERE id = %d", $class_id
        ) );

        // Look for an exam paper for this subject. Try in order of specificity:
        // 1. Exact class_id match + current session/term (via series_id)
        // 2. Same level + current session/term
        // 3. Any paper for this subject (fallback — the paper may have been
        //    created by the timetable generator with different class/level)
        $series_table = \EduCBTPro\Core\Schema::table( 'exam_series' );

        $paper = (array) $wpdb->get_row(
            $wpdb->prepare(
                "SELECT p.id FROM {$papers_tb} p
                 LEFT JOIN {$series_table} es ON es.id = p.series_id
                 WHERE p.school_id = %d AND p.subject_id = %d
                   AND p.is_practice = 0
                   AND (p.class_id = %d OR (p.class_id IS NULL AND p.level_id = %d))
                   AND (es.session_id = %d OR p.series_id IS NULL)
                 ORDER BY p.scheduled_at DESC LIMIT 1",
                $school_id, $subject_id, $class_id, $level_id, $session_id
            ),
            ARRAY_A
        );

        if ( empty( $paper['id'] ) ) {
            // Fallback: any non-practice paper for this subject, most recent first.
            $paper = (array) $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT p.id FROM {$papers_tb} p
                     WHERE p.school_id = %d AND p.subject_id = %d
                       AND p.is_practice = 0
                     ORDER BY p.scheduled_at DESC LIMIT 1",
                    $school_id, $subject_id
                ),
                ARRAY_A
            );
        }

        $paper_id = (int) ( $paper['id'] ?? 0 );

        if ( $paper_id > 0 ) {
            // Include both 'graded' and 'submitted' attempts. A submitted attempt
            // has been sat but may still be awaiting auto-grading or manual
            // theory marking — the teacher needs to see it either way.
            $ranking = (array) $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT at.id AS attempt_id, st.admission_number, CONCAT(st.first_name, ' ', st.last_name) AS name,
                            at.raw_score, at.max_score, at.percentage, at.submitted_at, at.status
                     FROM {$attempts} at
                     INNER JOIN {$students_t} st ON st.id = at.student_id
                     WHERE at.paper_id = %d AND at.school_id = %d
                       AND at.status IN ('graded', 'submitted')
                     ORDER BY at.percentage DESC, st.last_name ASC",
                    $paper_id,
                    $school_id
                ),
                ARRAY_A
            );

            // Hardest questions
            $per_item = (array) $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT q.question_text,
                            COUNT(a.id) AS answered,
                            SUM(CASE WHEN a.is_correct = 1 THEN 1 ELSE 0 END) AS correct
                     FROM {$answers} a
                     INNER JOIN {$attempts} at ON at.id = a.attempt_id
                     INNER JOIN {$questions} q ON q.id = a.question_id
                     WHERE at.paper_id = %d AND at.status IN ('graded', 'submitted') AND q.question_type <> 'theory'
                     GROUP BY a.question_id
                     HAVING COUNT(a.id) > 0
                     ORDER BY (SUM(CASE WHEN a.is_correct = 1 THEN 1 ELSE 0 END) / COUNT(a.id)) ASC
                     LIMIT 10",
                    $paper_id
                ),
                ARRAY_A
            );
        }

    } else {
        // CA mode: pull from assessment_scores, and join CBT attempt_id
        // for scores that came from a computer-based test so the teacher can
        // preview individual responses.
        $ranking = (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT st.admission_number, CONCAT(st.first_name, ' ', st.last_name) AS name,
                        sc.score, sc.max_score,
                        CASE WHEN sc.max_score > 0 THEN ROUND(sc.score / sc.max_score * 100, 1) ELSE 0 END AS percentage,
                        sc.source,
                        sc.attempt_id
                 FROM {$scores_table} sc
                 INNER JOIN {$students_t} st ON st.id = sc.student_id
                 WHERE sc.school_id = %d AND sc.subject_id = %d AND sc.class_id = %d
                   AND sc.session_id = %d AND sc.term_id = %d AND sc.component_id = %d
                   AND sc.score IS NOT NULL
                 ORDER BY percentage DESC, st.last_name ASC",
                $school_id, $subject_id, $class_id, $session_id, $term_id, $component_id
            ),
            ARRAY_A
        );

        // For CA tests taken via CBT, pull the attempt_id from the attempts
        // table if assessment_scores doesn't carry it.
        if ( ! empty( $ranking ) ) {
            $ca_paper = (array) $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT p.id FROM {$papers_tb} p
                     WHERE p.school_id = %d AND p.subject_id = %d AND p.class_id = %d
                       AND p.is_practice = 1
                     ORDER BY p.scheduled_at DESC LIMIT 1",
                    $school_id, $subject_id, $class_id
                ),
                ARRAY_A
            );
            $ca_paper_id = (int) ( $ca_paper['id'] ?? 0 );

            if ( $ca_paper_id > 0 ) {
                foreach ( $ranking as &$r ) {
                    if ( empty( $r['attempt_id'] ) ) {
                        $att_id = (int) $wpdb->get_var( $wpdb->prepare(
                            "SELECT id FROM {$attempts} WHERE paper_id = %d AND student_id = (
                                SELECT id FROM {$students_t} WHERE admission_number = %s AND school_id = %d LIMIT 1
                             ) AND status = 'graded' ORDER BY id DESC LIMIT 1",
                            $ca_paper_id, $r['admission_number'], $school_id
                        ) );
                        $r['attempt_id'] = $att_id > 0 ? $att_id : null;
                    }
                }
                unset( $r );
            }
        }
    }

    // Stats
    if ( ! empty( $ranking ) ) {
        $marks   = array_map( static fn( array $r ): float => (float) $r['percentage'], $ranking );
        $stats['count']   = count( $marks );
        $stats['average'] = round( array_sum( $marks ) / count( $marks ), 1 );
        $stats['highest'] = round( max( $marks ), 1 );
        $stats['passed']  = count( array_filter( $marks, static fn( float $m ): bool => $m >= 40 ) );
        $stats['pass_rate'] = round( ( $stats['passed'] / count( $marks ) ) * 100 );
    }
}

$educbt_title = 'Subject Results';

$educbt_body = static function () use (
    $pairs, $components, $component, $class_id, $subject_id, $component_id, $pair_val,
    $ranking, $per_item, $stats, $is_exam_component, $session, $term
): void {
    if ( empty( $pairs ) ) {
        echo '<div class="educbt-card"><p class="educbt-muted">You have not been assigned any subject to teach yet.</p></div>';
        return;
    }
    ?>
    <section class="educbt-card no-print">
        <p class="educbt-muted" style="margin-top:-6px">
            <?php echo esc_html( (string) ( $session['title'] ?? '' ) . ' · ' . (string) ( $term['title'] ?? '' ) ); ?>
        </p>
        <form method="get" class="educbt-form">
            <input type="hidden" name="p" value="educbt_portal">
            <input type="hidden" name="section" value="analysis">
            <div class="educbt-grid">
                <div>
                    <label for="pair">Class and subject</label>
                    <select id="pair" name="pair" onchange="var v=this.value.split(':');document.getElementById('f_class').value=v[0];document.getElementById('f_subject').value=v[1];this.form.submit()">
                        <option value=":">Choose</option>
                        <?php foreach ( $pairs as $p ) : ?>
                            <option value="<?php echo esc_attr( $p['class_id'] . ':' . $p['subject_id'] ); ?>"
                                <?php selected( (int) $p['class_id'] === $class_id && (int) $p['subject_id'] === $subject_id ); ?>>
                                <?php echo esc_html( $p['display_name'] . ' — ' . $p['subject_name'] ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="component">Assessment</label>
                    <select id="component" name="component" onchange="this.form.submit()">
                        <?php foreach ( $components as $c ) : ?>
                            <option value="<?php echo esc_attr( (string) $c['id'] ); ?>" <?php selected( (int) $c['id'], $component_id ); ?>>
                                <?php echo esc_html( $c['name'] . ( ! empty( $c['is_exam'] ) ? ' (exam)' : ' (max ' . (float) $c['max_score'] . ')' ) ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <input type="hidden" name="class" id="f_class" value="<?php echo esc_attr( (string) $class_id ); ?>">
            <input type="hidden" name="subject" id="f_subject" value="<?php echo esc_attr( (string) $subject_id ); ?>">
            <button type="submit" class="educbt-btn" style="margin-top:12px">View</button>
        </form>
    </section>

    <div class="educbt-card" style="display:flex;justify-content:space-between;align-items:center;">
        <h2 style="margin:0;">
            <?php echo esc_html( (string) ( $component['name'] ?? 'Results' ) ); ?>
            <?php if ( $class_id > 0 ) : ?>
                <span class="educbt-muted" style="font-weight:normal;font-size:.85em;">
                    — <?php echo esc_html( (string) ( $pairs[array_search($component_id, array_column($pairs, 'subject_id'))]['subject_name'] ?? '' ) ); ?>
                </span>
            <?php endif; ?>
        </h2>
        <?php if ( ! empty( $ranking ) ) : ?>
            <button type="button" class="educbt-btn" id="educbt-analysis-download">Download PDF</button>
<script>
            (function(){
                var btn = document.getElementById("educbt-analysis-download");
                if (!btn) return;
                btn.addEventListener("click", function(){
                    
                    btn.disabled = true; var o = btn.textContent; btn.textContent = "Generating PDF\u2026";
                    var el = document.querySelector(".educbt-card") || document.body;
                    window.print(); btn.disabled = false; btn.textContent = o;
                });
            })();
            </script>
        <?php endif; ?>
    </div>

    <?php if ( $class_id === 0 || $subject_id === 0 ) : ?>
        <div class="educbt-card"><p class="educbt-muted">Choose a class and subject to see results.</p></div>
        <?php return; ?>
    <?php endif; ?>

    <?php if ( empty( $ranking ) ) : ?>
        <div class="educbt-card">
            <p class="educbt-muted">
                <?php if ( $is_exam_component ) : ?>
                    No exam has been sat for this subject and class yet.
                <?php else : ?>
                    No scores recorded for <?php echo esc_html( (string) ( $component['name'] ?? '' ) ); ?> yet.
                <?php endif; ?>
            </p>
        </div>
        <?php return; ?>
    <?php endif; ?>

    <section class="educbt-stats">
        <div class="educbt-stat"><b><?php echo esc_html( (string) $stats['count'] ); ?></b><span>Sat / recorded</span></div>
        <div class="educbt-stat"><b><?php echo esc_html( (string) $stats['average'] ); ?>%</b><span>Average</span></div>
        <div class="educbt-stat"><b><?php echo esc_html( (string) $stats['highest'] ); ?>%</b><span>Highest</span></div>
        <div class="educbt-stat"><b><?php echo esc_html( (string) $stats['pass_rate'] ); ?>%</b><span>Passed (≥40%)</span></div>
    </section>

    <section class="educbt-card">
        <h2>Results, highest first</h2>
        <table class="educbt-table">
            <thead><tr><th style="width:50px">#</th><th>Student</th><th>Score</th><th>Percentage</th><th>Source</th><th></th></tr></thead>
            <tbody>
            <?php $position = 0; $previous = null; $seen = 0; ?>
            <?php foreach ( $ranking as $row ) :
                $seen++;
                $pct = (float) $row['percentage'];
                if ( $previous === null || abs( $pct - $previous ) > 0.0001 ) { $position = $seen; $previous = $pct; } ?>
                <tr>
                    <td><?php echo esc_html( (string) $position ); ?></td>
                    <td><?php echo esc_html( (string) $row['name'] ); ?><br>
                        <span class="educbt-muted"><?php echo esc_html( (string) $row['admission_number'] ); ?></span></td>
                    <td><?php echo esc_html( (float) $row['score'] . ' / ' . (float) $row['max_score'] ); ?></td>
                    <td><strong><?php echo esc_html( (string) round( $pct, 1 ) ); ?>%</strong>
                        <?php if ( $is_exam_component && (float) $row['score'] === 0.0 && empty( $row['attempt_id'] ) ) : ?>
                            <br><span class="educbt-muted" style="font-size:.8em;color:#dc2626;">No exam attempted</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ( (string) ( $row['source'] ?? '' ) === 'cbt' || ! empty( $row['attempt_id'] ) ) : ?>
                            <span class="educbt-pill">CBT</span>
                        <?php else : ?>
                            <span class="educbt-muted">entered</span>
                        <?php endif; ?>
                    </td>
                    <td style="white-space:nowrap">
                        <?php if ( ! empty( $row['attempt_id'] ) ) : ?>
                            <a class="educbt-btn" style="font-size:.78rem;padding:4px 10px" href="<?php echo esc_url( home_url( '/portal/teacher/responses/' . (int) $row['attempt_id'] ) ); ?>">Preview →</a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <p class="educbt-muted" style="margin-top:8px">
            Equal marks share a position, so two students on 78% are both second and the next is fourth.
        </p>
    </section>

    <?php if ( ! empty( $per_item ) ) : ?>
    <section class="educbt-card">
        <h2>Hardest questions</h2>
        <p class="educbt-muted" style="margin-top:-6px">
            A question almost everyone missed usually says more about the teaching than
            the students — or the answer key is wrong.
        </p>
        <table class="educbt-table">
            <thead><tr><th>Question</th><th style="width:130px">Got it right</th></tr></thead>
            <tbody>
            <?php foreach ( $per_item as $item ) :
                $rate = (int) $item['answered'] > 0 ? round( ( (int) $item['correct'] / (int) $item['answered'] ) * 100 ) : 0; ?>
                <tr>
                    <td><?php echo esc_html( wp_trim_words( wp_strip_all_tags( (string) $item['question_text'] ), 22 ) ); ?></td>
                    <td>
                        <strong style="<?php echo $rate < 40 ? 'color:#b91c1c' : ''; ?>"><?php echo esc_html( (string) $rate ); ?>%</strong>
                        <span class="educbt-muted">(<?php echo esc_html( $item['correct'] . '/' . $item['answered'] ); ?>)</span>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </section>
    <?php endif; ?>
    <?php
};

require EDUCBT_PRO_PATH . 'templates/portal/shell.php';
