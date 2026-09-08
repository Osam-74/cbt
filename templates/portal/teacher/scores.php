<?php
/**
 * Score entry — one class, one subject, ALL components on a single screen.
 *
 * Every assessment component (CA1, CA2, … Exam) appears as its own column.
 * CBT-sourced exam marks are shown read-only with a "CBT" badge; manual CA
 * marks are editable input boxes.  One Save button writes the whole grid.
 *
 * @var array<string,mixed> $educbt
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

global $wpdb;

$school_id = (int) $educbt['school_id'];
$flash     = \EduCBTPro\Frontend\PortalActions::flash();

$year       = new \EduCBTPro\Services\AcademicYearService();
$session    = $year->current_session( $school_id );
$term       = $year->current_term( $school_id );
$session_id = (int) ( $session['id'] ?? 0 );
$term_id    = (int) ( $term['id'] ?? 0 );

$assessment = new \EduCBTPro\Services\AssessmentService();

$classes_table  = \EduCBTPro\Core\Schema::table( 'classes' );
$subjects_table = \EduCBTPro\Core\Schema::table( 'subjects_v2' );
$assign_table   = \EduCBTPro\Core\Schema::table( 'staff_assignments' );

$actor = $educbt['scope']->actor();

// Only the (subject, class) pairs this teacher actually holds. School management
// sees everything; a subject teacher sees their own pairs and nothing else.
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

$class_id   = (int) ( $_GET['class'] ?? 0 );
$subject_id = (int) ( $_GET['subject'] ?? 0 );

$sheet = [];

if ( $class_id > 0 && $subject_id > 0 && $term_id > 0 ) {
    $sheet = $assessment->full_entry_sheet(
        $school_id,
        [ 'subject_id' => $subject_id, 'class_id' => $class_id, 'session_id' => $session_id, 'term_id' => $term_id ]
    );
}

// Check if results for this class have been approved/published — lock entry
$entry_locked = false;
$lock_status = '';
if ( $class_id > 0 && $term_id > 0 ) {
    $term_results_t = \EduCBTPro\Core\Schema::table( 'term_results' );
    $lock_status = (string) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT status FROM {$term_results_t} WHERE school_id = %d AND class_id = %d AND term_id = %d LIMIT 1",
            $school_id, $class_id, $term_id
        )
    );
    $entry_locked = in_array( $lock_status, [ 'approved', 'published' ], true );
}

$students   = $sheet['students'] ?? [];
$components = $sheet['components'] ?? [];

// How is this subject being examined? A CBT paper's exam mark arrives from the
// engine and must not be typed over. A WRITTEN paper has no engine behind it, so
// the subject teacher enters the mark exactly as they enter a CA score. Showing
// the same locked "CBT" cell for both left written papers with no way to record a
// mark at all.
$exam_delivery = 'cbt';
$exam_state    = '';

if ( $class_id > 0 && $subject_id > 0 && $term_id > 0 ) {
    $set_row = (array) $wpdb->get_row(
        $wpdb->prepare(
            'SELECT qs.delivery_mode, qs.status
             FROM ' . \EduCBTPro\Core\Schema::table( 'question_sets' ) . ' qs
             INNER JOIN ' . $classes_table . ' c ON c.level_id = qs.level_id
             WHERE qs.school_id = %d AND qs.subject_id = %d AND c.id = %d
               AND qs.session_id = %d AND COALESCE(qs.term_id,0) = %d
             ORDER BY qs.id DESC LIMIT 1',
            $school_id,
            $subject_id,
            $class_id,
            $session_id,
            $term_id
        ),
        ARRAY_A
    );

    if ( $set_row ) {
        $exam_delivery = (string) ( $set_row['delivery_mode'] ?? 'cbt' );
        $exam_state    = (string) ( $set_row['status'] ?? '' );
    }
}

$educbt_title = 'Record Scores';

$educbt_body = static function () use ( $flash, $pairs, $students, $components, $class_id, $subject_id, $session_id, $term_id, $term, $session, $entry_locked, $lock_status, $exam_delivery, $exam_state ): void {
    require EDUCBT_PRO_PATH . 'templates/portal/partials/flash.php';

    if ( $term_id === 0 ) {
        echo '<div class="educbt-card"><p class="educbt-note educbt-note--warn">No current term is set, so scores cannot be recorded.</p></div>';
        return;
    }

    if ( empty( $pairs ) ) {
        echo '<div class="educbt-card"><p class="educbt-note educbt-note--warn">You have not been assigned any subject to teach yet. The school office assigns these under Staff.</p></div>';
        return;
    }
    ?>
    <section class="educbt-card">
        <h2>Record Scores</h2>
        <p class="educbt-muted" style="margin-top:-6px">
            All assessment components are shown below. CBT exam marks appear automatically and are read-only.
        </p>
        <p class="educbt-muted" style="margin-top:-6px">
            <?php echo esc_html( (string) ( $session['title'] ?? '' ) . ' · ' . (string) ( $term['title'] ?? '' ) ); ?>
        </p>

        <form method="get" class="educbt-form">
            <div class="educbt-grid">
                <div>
                    <label for="pair">Class and subject</label>
                    <select id="pair" name="pair" onchange="var v=this.value.split(':');document.getElementById('f_class').value=v[0];document.getElementById('f_subject').value=v[1];this.form.submit();">
                        <option value=":">Choose</option>
                        <?php foreach ( $pairs as $p ) : ?>
                            <option value="<?php echo esc_attr( $p['class_id'] . ':' . $p['subject_id'] ); ?>"
                                <?php selected( (int) $p['class_id'] === $class_id && (int) $p['subject_id'] === $subject_id ); ?>>
                                <?php echo esc_html( $p['display_name'] . ' — ' . $p['subject_name'] ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <input type="hidden" name="class" id="f_class" value="<?php echo esc_attr( (string) $class_id ); ?>">
            <input type="hidden" name="subject" id="f_subject" value="<?php echo esc_attr( (string) $subject_id ); ?>">
            <noscript><button class="educbt-btn" type="submit">Load</button></noscript>
        </form>
    </section>

    <?php if ( $entry_locked ) : ?>
    <section class="educbt-card">
        <p class="educbt-note educbt-note--warn">
            Results for this class have been <?php echo esc_html( $lock_status ); ?>.
            Score entry is now locked. You can no longer record or modify scores.
        </p>
    </section>
    <?php elseif ( ! empty( $students ) && ! empty( $components ) ) : ?>

    <?php
    // Compute totals per student (for display)
    $has_exam = false;
    foreach ( $components as $c ) {
        if ( ! empty( $c['is_exam'] ) ) { $has_exam = true; }
    }
    ?>

    <section class="educbt-card">
        <h2>Score Sheet</h2>

        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="educbt-form">
            <input type="hidden" name="action" value="educbt_save_scores">
            <input type="hidden" name="subject_id" value="<?php echo esc_attr( (string) $subject_id ); ?>">
            <input type="hidden" name="class_id" value="<?php echo esc_attr( (string) $class_id ); ?>">
            <input type="hidden" name="session_id" value="<?php echo esc_attr( (string) $session_id ); ?>">
            <input type="hidden" name="term_id" value="<?php echo esc_attr( (string) $term_id ); ?>">
            <input type="hidden" name="multi_component" value="1">
            <?php wp_nonce_field( 'educbt_save_scores' ); ?>

            <div style="overflow-x:auto">
            <table class="educbt-table">
                <thead>
                    <tr>
                        <th style="white-space:nowrap">Adm. no.</th>
                        <th style="white-space:nowrap">Student</th>
                        <?php foreach ( $components as $c ) : ?>
                            <?php
                            // Two lines, not three. The name and its maximum are what
                            // the marker needs; a third line for a badge pushed the
                            // header onto a second row and squeezed the whole sheet.
                            $head = (string) $c['name'];
                            $head = str_ireplace(
                                [ 'First CA Test', 'Second CA Test', 'Third CA Test', 'Continuous Assessment', ' Test' ],
                                [ 'CA 1', 'CA 2', 'CA 3', 'CA', '' ],
                                $head
                            );
                            ?>
                            <th style="width:96px;text-align:center;white-space:nowrap">
                                <?php echo esc_html( $head ); ?>
                                <span class="educbt-muted" style="font-weight:normal;font-size:.78em">
                                    /<?php echo esc_html( (string) (float) $c['max_score'] ); ?>
                                    <?php if ( ! empty( $c['is_exam'] ) ) : ?>
                                        · <?php echo esc_html( $exam_delivery === 'written' ? 'Written' : 'CBT' ); ?>
                                    <?php endif; ?>
                                </span>
                            </th>
                        <?php endforeach; ?>
                        <th style="text-align:center">Total</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $students as $row ) :
                    $sid = (int) $row['student_id'];
                    $total = 0.0;
                ?>
                    <tr>
                        <td><code><?php echo esc_html( (string) $row['admission_number'] ); ?></code></td>
                        <td style="white-space:nowrap"><?php echo esc_html( $row['last_name'] . ', ' . $row['first_name'] ); ?></td>

                        <?php foreach ( $components as $c ) :
                            $cid   = (int) $c['id'];
                            $entry = $row['components'][ $cid ] ?? null;
                            $score = $entry['score'] ?? null;
                            $src   = (string) ( $entry['source'] ?? '' );
                            // Only a CBT exam column is locked. A written exam mark is
                            // entered by the teacher, like any CA score.
                            $is_cbt = ( $src === 'cbt' )
                                || ( ! empty( $c['is_exam'] ) && $exam_delivery !== 'written' );

                            if ( $score !== null ) {
                                $total += (float) $score;
                            }
                        ?>
                            <td style="text-align:center">
                                <?php if ( $is_cbt ) : ?>
                                    <?php if ( $score !== null ) : ?>
                                        <strong><?php echo esc_html( number_format( (float) $score, 1 ) ); ?></strong>
                                    <?php else : ?>
                                        <?php
                                        // An empty box invited a teacher to type a mark
                                        // the engine owns. Say what is actually happening
                                        // instead of showing a dash or an input.
                                        $waiting = 'Not sat';

                                        if ( $exam_state === 'published' ) {
                                            $waiting = 'Awaiting sitting';
                                        } elseif ( in_array( $exam_state, [ 'approved', 'submitted', 'under_review' ], true ) ) {
                                            $waiting = 'Not yet sat';
                                        }
                                        ?>
                                        <span class="educbt-muted" style="font-size:.78rem"><?php echo esc_html( $waiting ); ?></span>
                                    <?php endif; ?>
                                <?php else : ?>
                                    <input type="number" step="0.5" min="0"
                                           max="<?php echo esc_attr( (string) (float) $c['max_score'] ); ?>"
                                           name="score[<?php echo esc_attr( (string) $sid ); ?>][<?php echo esc_attr( (string) $cid ); ?>]"
                                           value="<?php echo $score === null ? '' : esc_attr( (string) (float) $score ); ?>"
                                           style="width:80px;padding:6px;text-align:center"
                                           placeholder="—">
                                <?php endif; ?>
                            </td>
                        <?php endforeach; ?>

                        <td style="text-align:center;font-weight:600">
                            <?php echo esc_html( number_format( $total, 1 ) ); ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>

            <p class="educbt-muted" style="margin-top:10px">
                Leave a box empty for a student you have not marked yet — that is different from a zero.
                CBT exam marks are shown automatically and cannot be edited here.
            </p>

            <button type="submit" class="educbt-btn educbt-btn--primary" style="margin-top:8px">Save scores</button>
        </form>
    </section>
    <?php elseif ( $class_id > 0 && $subject_id > 0 ) : ?>
        <div class="educbt-card"><p class="educbt-muted">No students are registered for that subject in that class.</p></div>
    <?php endif; ?>
    <?php
};

require EDUCBT_PRO_PATH . 'templates/portal/shell.php';
