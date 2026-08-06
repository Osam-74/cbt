<?php
/**
 * A subject teacher's view of how their students did.
 *
 * Ranked highest first. Two things a teacher asks after a paper: who did well, and
 * where the class as a whole struggled — so the per-question breakdown is here too,
 * because a question everybody missed usually means the teaching, not the students.
 *
 * @var array<string,mixed> $educbt
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

global $wpdb;

$school_id = (int) $educbt['school_id'];
$actor     = $educbt['scope']->actor();
$staff_id  = (int) $actor['id'];

$assign     = \EduCBTPro\Core\Schema::table( 'staff_assignments' );
$classes_tb = \EduCBTPro\Core\Schema::table( 'classes' );
$subjects_t = \EduCBTPro\Core\Schema::table( 'subjects_v2' );
$papers_tb  = \EduCBTPro\Core\Schema::table( 'exam_papers' );
$attempts   = \EduCBTPro\Core\Schema::table( 'attempts' );
$answers    = \EduCBTPro\Core\Schema::table( 'attempt_answers' );
$students_t = $wpdb->prefix . 'educbt_students';
$questions  = $wpdb->prefix . 'educbt_questions';

// Papers for subjects this teacher actually takes. A teacher must not read another
// subject's results just because they know a paper id.
$scope_clause = $educbt['scope']->is_school_wide()
    ? '1=1'
    : $wpdb->prepare(
        "EXISTS (SELECT 1 FROM {$assign} a WHERE a.staff_id = %d AND a.status = 'active'
                 AND a.subject_id = p.subject_id AND a.class_id = p.class_id)",
        $staff_id
    );

$papers = (array) $wpdb->get_results(
    $wpdb->prepare(
        "SELECT p.id, p.scheduled_at, p.is_practice, s.name AS subject_name, c.display_name AS class_name,
                (SELECT COUNT(*) FROM {$attempts} at WHERE at.paper_id = p.id AND at.status = 'graded') AS sat
         FROM {$papers_tb} p
         INNER JOIN {$subjects_t} s ON s.id = p.subject_id
         LEFT JOIN {$classes_tb} c ON c.id = p.class_id
         WHERE p.school_id = %d AND {$scope_clause}
         HAVING sat > 0
         ORDER BY p.scheduled_at DESC LIMIT 40",
        $school_id
    ),
    ARRAY_A
);

$paper_id = (int) ( $educbt['id'] ?: ( $_GET['paper'] ?? 0 ) );

if ( $paper_id === 0 && ! empty( $papers ) ) {
    $paper_id = (int) $papers[0]['id'];
}

$allowed = array_map( static fn( array $p ): int => (int) $p['id'], $papers );

if ( $paper_id > 0 && ! in_array( $paper_id, $allowed, true ) ) {
    $paper_id = (int) ( $papers[0]['id'] ?? 0 );
}

$ranking  = [];
$per_item = [];

if ( $paper_id > 0 ) {
    $ranking = (array) $wpdb->get_results(
        $wpdb->prepare(
            "SELECT st.admission_number, CONCAT(st.first_name, ' ', st.last_name) AS name,
                    at.raw_score, at.max_score, at.percentage, at.submitted_at
             FROM {$attempts} at
             INNER JOIN {$students_t} st ON st.id = at.student_id
             WHERE at.paper_id = %d AND at.school_id = %d AND at.status = 'graded'
             ORDER BY at.percentage DESC, st.last_name ASC",
            $paper_id,
            $school_id
        ),
        ARRAY_A
    );

    // Which questions the class found hardest.
    $per_item = (array) $wpdb->get_results(
        $wpdb->prepare(
            "SELECT q.question_text,
                    COUNT(a.id) AS answered,
                    SUM(CASE WHEN a.is_correct = 1 THEN 1 ELSE 0 END) AS correct
             FROM {$answers} a
             INNER JOIN {$attempts} at ON at.id = a.attempt_id
             INNER JOIN {$questions} q ON q.id = a.question_id
             WHERE at.paper_id = %d AND at.status = 'graded' AND q.question_type <> 'theory'
             GROUP BY a.question_id
             HAVING answered > 0
             ORDER BY (correct / answered) ASC
             LIMIT 10",
            $paper_id
        ),
        ARRAY_A
    );
}

$educbt_title = 'Subject Results';

$educbt_body = static function () use ( $papers, $paper_id, $ranking, $per_item ): void {
    if ( empty( $papers ) ) {
        echo '<div class="educbt-card"><p class="educbt-muted">None of your papers have been sat yet.</p></div>';
        return;
    }
    ?>
    <section class="educbt-card no-print">
        <form method="get" class="educbt-form" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap">
            <div style="flex:1 1 300px">
                <label for="paper">Paper</label>
                <select id="paper" name="paper" onchange="this.form.submit()">
                    <?php foreach ( $papers as $p ) : ?>
                        <option value="<?php echo esc_attr( (string) $p['id'] ); ?>" <?php selected( (int) $p['id'], $paper_id ); ?>>
                            <?php echo esc_html( $p['subject_name'] . ' — ' . $p['class_name'] . ' · ' . mysql2date( 'j M', (string) $p['scheduled_at'] ) ); ?>
                            <?php echo ! empty( $p['is_practice'] ) ? ' (class test)' : ''; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="button" class="educbt-btn" onclick="window.print()">Print</button>
        </form>
    </section>

    <?php if ( empty( $ranking ) ) : ?>
        <div class="educbt-card"><p class="educbt-muted">Nobody has completed this paper yet.</p></div>
        <?php return; ?>
    <?php endif; ?>

    <?php
    $marks   = array_map( static fn( array $r ): float => (float) $r['percentage'], $ranking );
    $average = round( array_sum( $marks ) / count( $marks ), 1 );
    $passed  = count( array_filter( $marks, static fn( float $m ): bool => $m >= 40 ) );
    ?>

    <section class="educbt-stats">
        <div class="educbt-stat"><b><?php echo esc_html( (string) count( $ranking ) ); ?></b><span>Sat</span></div>
        <div class="educbt-stat"><b><?php echo esc_html( (string) $average ); ?>%</b><span>Average</span></div>
        <div class="educbt-stat"><b><?php echo esc_html( (string) max( $marks ) ); ?>%</b><span>Highest</span></div>
        <div class="educbt-stat"><b><?php echo esc_html( (string) round( ( $passed / count( $marks ) ) * 100 ) ); ?>%</b><span>Passed</span></div>
    </section>

    <section class="educbt-card">
        <h2>Results, highest first</h2>
        <table class="educbt-table">
            <thead><tr><th style="width:50px">#</th><th>Student</th><th>Score</th><th>Percentage</th></tr></thead>
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
                    <td><?php echo esc_html( (float) $row['raw_score'] . ' / ' . (float) $row['max_score'] ); ?></td>
                    <td><strong><?php echo esc_html( (string) round( $pct, 1 ) ); ?>%</strong></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <p class="educbt-muted" style="margin-top:8px">
            Equal marks share a position, so two students on 78% are both second and the
            next is fourth.
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
