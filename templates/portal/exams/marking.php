<?php
/**
 * Marking — teachers mark written answers here; school-wide roles see marking progress across all exams.
 *
 * For the principal/exam officer, this is a dashboard, not a marking interface.
 * Teachers who need to mark written answers still access it per-paper, but the
 * overview answers "what is holding up results" at a glance.
 *
 * @var array<string,mixed> $educbt
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

global $wpdb;

$school_id = (int) $educbt['school_id'];
$paper_id  = (int) $educbt['id'];
$flash     = \EduCBTPro\Frontend\PortalActions::flash();

$theory    = new \EduCBTPro\Services\TheoryService();
$actor     = $educbt['scope']->actor();
$is_wide   = $educbt['scope']->is_school_wide();

$pending = $theory->papers_awaiting_marking(
    $school_id,
    $is_wide ? 0 : (int) $actor['id']
);

// Also get all completed exams with marking status
$papers_table = \EduCBTPro\Core\Schema::table( 'exam_papers' );
$attempts     = \EduCBTPro\Core\Schema::table( 'attempts' );
$answers      = \EduCBTPro\Core\Schema::table( 'attempt_answers' );
$questions    = $wpdb->prefix . 'educbt_questions';
$subjects     = \EduCBTPro\Core\Schema::table( 'subjects_v2' );
$classes      = \EduCBTPro\Core\Schema::table( 'classes' );

// For school-wide users (principal, exam officer) show all completed exams.
// For subject teachers, filter to only papers whose (subject, class) match
// their active staff assignments — otherwise they see every exam in the
// school and can't find their own students' submissions.
$_staff_assign_t = \EduCBTPro\Core\Schema::table( 'staff_assignments' );
$_actor = $educbt['scope']->actor();
$_staff_id = $is_wide ? 0 : (int) $_actor['id'];

$_where = "p.school_id = %d\n         AND EXISTS (SELECT 1 FROM {$attempts} at4 WHERE at4.paper_id = p.id AND at4.status IN ('graded', 'submitted'))";
$_params = [ $school_id ];

if ( ! $is_wide && $_staff_id > 0 ) {
    $_where .= " AND EXISTS (
            SELECT 1 FROM {$_staff_assign_t} sa
            WHERE sa.staff_id = %d AND sa.school_id = p.school_id
              AND sa.subject_id = p.subject_id AND sa.class_id = p.class_id
              AND sa.status = 'active'
        )";
    $_params[] = $_staff_id;
}

$_params[] = 100; // LIMIT

$completed_exams = (array) $wpdb->get_results(
    $wpdb->prepare(
        "SELECT p.id, p.status, p.scheduled_at, s.name AS subject_name, c.display_name AS class_name,
                (SELECT COUNT(DISTINCT at2.student_id) FROM {$attempts} at2 WHERE at2.paper_id = p.id AND at2.status IN ('graded', 'submitted')) AS students_sat,
                (SELECT COUNT(*) FROM {$answers} a
                 INNER JOIN {$attempts} at3 ON at3.id = a.attempt_id
                 INNER JOIN {$questions} q2 ON q2.id = a.question_id
                 WHERE at3.paper_id = p.id AND q2.question_type = 'theory') AS theory_total,
                (SELECT COUNT(*) FROM {$answers} a
                 INNER JOIN {$attempts} at3 ON at3.id = a.attempt_id
                 INNER JOIN {$questions} q2 ON q2.id = a.question_id
                 WHERE at3.paper_id = p.id AND q2.question_type = 'theory' AND a.is_correct IS NOT NULL) AS theory_marked
         FROM {$papers_table} p
         INNER JOIN {$subjects} s ON s.id = p.subject_id
         LEFT JOIN {$classes} c ON c.id = p.class_id
         WHERE {$_where}
         ORDER BY p.scheduled_at DESC
         LIMIT %d",
        $_params
    ),
    ARRAY_A
);

// Per-paper marking queue (only if a specific paper is selected AND user has marking rights)
$queue    = ( $paper_id > 0 && ! $is_wide ) ? $theory->marking_queue_by_student( $school_id, $paper_id ) : [];
$progress = $paper_id > 0 ? $theory->marking_progress( $school_id, $paper_id ) : null;

// Is this a WAEC paper? If so, determine which WAEC paper it is so the raw
// score can be shown alongside its scaled weight toward the 100% total.
$waec_info = null;
if ( $paper_id > 0 ) {
    $paper_row = (array) $wpdb->get_row(
        $wpdb->prepare(
            'SELECT p.subject_id, p.delivery_mode, p.total_marks
             FROM ' . \EduCBTPro\Core\Schema::table( 'exam_papers' ) . ' p
             WHERE p.id = %d AND p.school_id = %d',
            $paper_id, $school_id
        ),
        ARRAY_A
    );

    if ( ! empty( $paper_row ) ) {
        $qs_row = (array) $wpdb->get_row(
            $wpdb->prepare(
                'SELECT qs.exam_type, qs.waec_mode
                 FROM ' . \EduCBTPro\Core\Schema::table( 'question_sets' ) . ' qs
                 WHERE qs.school_id = %d AND qs.subject_id = %d AND qs.waec_mode = 1
                 ORDER BY qs.id DESC LIMIT 1',
                $school_id, absint( $paper_row['subject_id'] )
            ),
            ARRAY_A
        );

        if ( ! empty( $qs_row['waec_mode'] ) ) {
            $waec_info = [
                'exam_type'  => (string) ( $qs_row['exam_type'] ?? '' ),
                'raw_max'    => (float) ( $paper_row['total_marks'] ?? 0 ),
                'paper_key'  => '',
                'weight'     => 0.0,
            ];

            // Theory = Paper 2 (50%). Objective = Paper 1 + Paper 3 combined (50%).
            if ( $waec_info['exam_type'] === 'theory' ) {
                $waec_info['paper_key'] = 'paper2';
                $waec_info['weight']   = 50.0;
            } else {
                $waec_info['paper_key'] = 'paper1+3';
                $waec_info['weight']   = 50.0;
            }
        }
    }
}

// Per-teacher marking status — the reviewer's actual question is "who is holding
// up results", which a paper-by-paper table cannot answer.
$staff_marking = [];

if ( $is_wide ) {
    $staff_table       = \EduCBTPro\Core\Schema::table( 'staff' );
    $staff_assignments = \EduCBTPro\Core\Schema::table( 'staff_assignments' );

    $staff_marking = (array) $wpdb->get_results(
        $wpdb->prepare(
            "SELECT st.id AS staff_id, st.wp_user_id,
                    CONCAT(st.first_name, ' ', st.last_name) AS staff_name,
                    COUNT(DISTINCT p.id) AS papers,
                    SUM(CASE WHEN a.is_correct IS NULL THEN 1 ELSE 0 END) AS outstanding,
                    COUNT(a.id) AS theory_answers
             FROM {$staff_table} st
             INNER JOIN {$staff_assignments} sa
                     ON sa.staff_id = st.id AND sa.school_id = st.school_id AND sa.status = 'active'
             INNER JOIN {$papers_table} p
                     ON p.school_id = st.school_id AND p.subject_id = sa.subject_id AND p.class_id = sa.class_id
             INNER JOIN {$attempts} at ON at.paper_id = p.id AND at.status IN ('graded', 'submitted')
             INNER JOIN {$answers} a ON a.attempt_id = at.id
             INNER JOIN {$questions} q ON q.id = a.question_id AND q.question_type = 'theory'
             WHERE st.school_id = %d AND st.status = 'active'
             GROUP BY st.id, st.wp_user_id, staff_name
             ORDER BY outstanding DESC, staff_name ASC",
            $school_id
        ),
        ARRAY_A
    );
}

// ── Assessment completion progress per teacher ─────────────────────
//
// The principal's question: "has each teacher recorded all their CA
// components for this term?" We check each active assessment component
// against the assessment_scores table for the current term/session.
$assessment_progress = [];

if ( $is_wide ) {
    $components_table = \EduCBTPro\Core\Schema::table( 'assessment_components' );
    $scores_table     = \EduCBTPro\Core\Schema::table( 'assessment_scores' );
    $staff_table      = \EduCBTPro\Core\Schema::table( 'staff' );
    $staff_assignments = \EduCBTPro\Core\Schema::table( 'staff_assignments' );
    $subjects_table   = \EduCBTPro\Core\Schema::table( 'subjects_v2' );
    $classes_table     = \EduCBTPro\Core\Schema::table( 'classes' );

    // Get current term/session
    $year_svc = new \EduCBTPro\Services\AcademicYearService();
    $session  = $year_svc->current_session( $school_id );
    $term     = $year_svc->current_term( $school_id );
    $session_id = absint( $session['id'] ?? 0 );
    $term_id    = absint( $term['id'] ?? 0 );

    // Get all active assessment components for this school
    $components = (array) $wpdb->get_results(
        $wpdb->prepare(
            "SELECT id, name, code, is_exam FROM {$components_table}
             WHERE school_id = %d AND status = 'active' ORDER BY sort_order ASC",
            $school_id
        ),
        ARRAY_A
    );

    if ( ! empty( $components ) && $session_id > 0 && $term_id > 0 ) {
        $total_components = count( $components );

        // For each teacher, check which components have at least one score recorded
        // GROUP BY eliminates duplicate staff_assignment rows for the same
        // subject+class (which would otherwise multiply via the CROSS JOIN
        // and produce duplicate ✓/✗ entries per component).
        $assessment_progress = (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT st.id AS staff_id,
                        CONCAT(st.first_name, ' ', st.last_name) AS staff_name,
                        sa.subject_id, sa.class_id,
                        s.name AS subject_name,
                        c.display_name AS class_name,
                        comp.id AS component_id,
                        comp.name AS component_name,
                        comp.is_exam,
                        EXISTS(
                            SELECT 1 FROM {$scores_table} sc
                            WHERE sc.school_id = %d
                              AND sc.subject_id = sa.subject_id
                              AND sc.class_id = sa.class_id
                              AND sc.session_id = %d
                              AND sc.term_id = %d
                              AND sc.component_id = comp.id
                        ) AS has_scores
                 FROM {$staff_table} st
                 INNER JOIN (
                     SELECT DISTINCT staff_id, school_id, subject_id, class_id
                     FROM {$staff_assignments}
                     WHERE status = 'active' AND assignment_type = 'subject_teacher'
                 ) sa ON sa.staff_id = st.id AND sa.school_id = st.school_id
                 INNER JOIN {$subjects_table} s ON s.id = sa.subject_id
                 INNER JOIN {$classes_table} c ON c.id = sa.class_id
                 CROSS JOIN {$components_table} comp
                 WHERE st.school_id = %d AND st.status = 'active'
                 ORDER BY staff_name ASC, s.name ASC, c.display_name ASC, comp.sort_order ASC",
                $school_id, $session_id, $term_id, $school_id
            ),
            ARRAY_A
        );

        // Reshape: group by teacher+subject+class, then list component status.
        // Deduplicate by component name — if the assessment_components table has
        // multiple rows with the same name (different IDs), we keep one and
        // treat it as done if ANY of the duplicates has scores.
        $assessment_progress = (function() use ( $assessment_progress, $total_components, $components ) {
            $grouped = [];
            foreach ( $assessment_progress as $row ) {
                $key = $row['staff_id'] . '_' . $row['subject_id'] . '_' . $row['class_id'];
                if ( ! isset( $grouped[ $key ] ) ) {
                    $grouped[ $key ] = [
                        'staff_name'    => $row['staff_name'],
                        'subject_name'  => $row['subject_name'],
                        'class_name'    => $row['class_name'],
                        'components'    => [],
                        '_comp_seen'    => [],
                        'completed'     => 0,
                        'total'         => $total_components,
                    ];
                }
                $comp_name = (string) $row['component_name'];
                $done = (int) $row['has_scores'] === 1;
                if ( isset( $grouped[ $key ]['_comp_seen'][ $comp_name ] ) ) {
                    // Already have this component — update done status if this
                    // duplicate has scores but the existing one doesn't.
                    if ( $done && ! $grouped[ $key ]['components'][ $grouped[ $key ]['_comp_seen'][ $comp_name ] ]['has_scores'] ) {
                        $grouped[ $key ]['components'][ $grouped[ $key ]['_comp_seen'][ $comp_name ] ]['has_scores'] = true;
                        $grouped[ $key ]['completed']++;
                    }
                    continue; // Skip duplicate
                }
                $idx = count( $grouped[ $key ]['components'] );
                $grouped[ $key ]['_comp_seen'][ $comp_name ] = $idx;
                $grouped[ $key ]['components'][] = [
                    'name'      => $row['component_name'],
                    'is_exam'   => (int) $row['is_exam'] === 1,
                    'has_scores' => $done,
                ];
                if ( $done ) {
                    $grouped[ $key ]['completed']++;
                }
            }
            // Clean up internal tracking key
            foreach ( $grouped as &$g ) {
                unset( $g['_comp_seen'] );
            }
            return array_values( $grouped );
        })();
    }
}


$educbt_title = $is_wide ? 'Marking Status' : 'Marking';

$educbt_body = static function () use ( $flash, $pending, $completed_exams, $queue, $progress, $paper_id, $is_wide, $staff_marking, $assessment_progress, $waec_info ): void {
    require EDUCBT_PRO_PATH . 'templates/portal/partials/flash.php';
    ?>

    <?php if ( ! empty( $pending ) ) : ?>
    <section class="educbt-card educbt-card--live">
        <h2>Papers awaiting marking</h2>
        <table class="educbt-table">
            <thead><tr><th>Subject</th><th>Class</th><th>Outstanding</th><th></th></tr></thead>
            <tbody>
            <?php foreach ( $pending as $p ) : ?>
                <tr>
                    <td><strong><?php echo esc_html( (string) $p['subject_name'] ); ?></strong></td>
                    <td><?php echo esc_html( (string) $p['class_name'] ); ?></td>
                    <td><span class="educbt-pill educbt-pill--draft"><?php echo esc_html( (string) $p['outstanding'] ); ?> to mark</span></td>
                    <td>
                        <?php if ( ! $is_wide ) : ?>
                        <a class="educbt-btn educbt-btn--primary" href="<?php echo esc_url( home_url( '/portal/exams/marking/' . (int) $p['id'] ) ); ?>">Mark now</a>
                        <?php else : ?>
                        <span class="educbt-muted">Assign to subject teacher</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </section>
    <?php endif; ?>

    <?php if ( $paper_id > 0 && ! empty( $queue ) && ! $is_wide ) : ?>
    <?php if ( $waec_info ) : ?>
    <section class="educbt-card" style="padding:12px 16px">
        <p style="margin:0;font-size:.85rem">
            <strong>WAEC scaling:</strong>
            This paper is <?php echo esc_html( (string) (float) $waec_info['raw_max'] ); ?> raw marks,
            weighted to <strong><?php echo esc_html( (string) (float) $waec_info['weight'] ); ?>%</strong>
            of the 100% WAEC total (170 marks \u2192 100%).
            Paper 1 = 40%, Paper 2 = 50%, Paper 3 = 10%.
        </p>
    </section>
    <?php endif; ?>
    <section class="educbt-card">
        <h2>Student submissions — mark one student at a time</h2>
        <p class="educbt-muted" style="margin-bottom:16px">
            Click "View submission" to reveal a student's written answers. Mark all their
            questions, then save before moving to the next student. You can come back and
            change any mark later.
        </p>

        <table class="educbt-table" id="educbt-marking-students">
            <thead>
                <tr>
                    <th style="width:30px"></th>
                    <th>Student</th>
                    <th>Adm. No.</th>
                    <th>Questions</th>
                    <th>Marks so far</th>
                    <?php if ( $waec_info ) : ?>
                    <th>WAEC %</th>
                    <?php endif; ?>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ( $queue as $i => $student ) :
                $all_marked  = $student['marked'] >= $student['total'] && $student['total'] > 0;
                $some_marked = $student['marked'] > 0 && ! $all_marked;
                $row_id = 'student-row-' . (int) $student['student_id'];
                $panel_id = 'student-panel-' . (int) $student['student_id'];
                ?>
                <tr id="<?php echo esc_attr( $row_id ); ?>">
                    <td style="text-align:center">
                        <span class="educbt-marking-dot <?php echo $all_marked ? 'educbt-marking-dot--done' : ( $some_marked ? 'educbt-marking-dot--partial' : '' ); ?>"></span>
                    </td>
                    <td><strong><?php echo esc_html( (string) $student['student_name'] ); ?></strong></td>
                    <td><?php echo esc_html( (string) $student['admission_number'] ); ?></td>
                    <td><?php echo esc_html( (string) $student['marked'] . ' / ' . (string) $student['total'] ); ?></td>
                    <td>
                        <?php if ( $student['marked'] > 0 ) : ?>
                            <?php echo esc_html( (string) (float) $student['total_awarded'] ); ?> / <?php echo esc_html( (string) (float) $student['total_max'] ); ?>
                        <?php else : ?>
                            <span class="educbt-muted">&mdash;</span>
                        <?php endif; ?>
                    </td>
                    <?php if ( $waec_info ) : ?>
                    <td>
                        <?php if ( $student['marked'] > 0 && $waec_info['raw_max'] > 0 ) : ?>
                            <?php
                            $scaled = round( ( (float) $student['total_awarded'] / $waec_info['raw_max'] ) * $waec_info['weight'], 2 );
                            ?>
                            <strong style="color:var(--edu-accent)"><?php echo esc_html( (string) $scaled ); ?>%</strong>
                        <?php else : ?>
                            <span class="educbt-muted">&mdash;</span>
                        <?php endif; ?>
                    </td>
                    <?php endif; ?>
                    <td>
                        <?php if ( $all_marked ) : ?>
                            <span class="educbt-pill educbt-pill--approved">Complete</span>
                        <?php elseif ( $some_marked ) : ?>
                            <span class="educbt-pill educbt-pill--pending">In progress</span>
                        <?php else : ?>
                            <span class="educbt-pill educbt-pill--draft">Not marked</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <button type="button" class="educbt-btn educbt-btn--primary educbt-toggle-marking"
                                data-target="<?php echo esc_attr( $panel_id ); ?>"
                                data-row="<?php echo esc_attr( $row_id ); ?>">
                            View submission
                        </button>
                    </td>
                </tr>
                <tr class="educbt-marking-panel-row" id="<?php echo esc_attr( $panel_id ); ?>-wrapper" style="display:none">
                    <td colspan="<?php echo $waec_info ? 8 : 7; ?>" style="padding:0;border-top:0">
                        <div class="educbt-marking-panel" id="<?php echo esc_attr( $panel_id ); ?>">
                            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="educbt-form">
                                <input type="hidden" name="action" value="educbt_mark_theory">
                                <input type="hidden" name="paper_id" value="<?php echo esc_attr( (string) $paper_id ); ?>">
                                <?php wp_nonce_field( 'educbt_mark_theory' ); ?>

                                <div class="educbt-marking-answers">
                                    <?php foreach ( $student['answers'] as $answer ) : ?>
                                        <div class="educbt-marking-answer-item">
                                            <div class="educbt-marking-q-header">
                                                <span class="educbt-marking-q-label">
                                                    <?php
                                                    $label = (string) $answer['part_label'];
                                                    if ( $label !== '' ) {
                                                        echo esc_html( $label );
                                                    } else {
                                                        echo esc_html( 'Q' . (string) (int) $answer['question_id'] );
                                                    }
                                                    ?>
                                                </span>
                                                <span class="educbt-marking-marks">
                                                    <?php echo esc_html( (string) (float) $answer['max_marks'] ); ?> mark<?php echo $answer['max_marks'] != 1 ? 's' : ''; ?>
                                                </span>
                                            </div>

                                            <div class="educbt-marking-q-text">
                                                <?php echo wp_kses_post( wpautop( (string) $answer['question_text'] ) ); ?>
                                            </div>

                                            <?php if ( trim( (string) $answer['marking_guide'] ) !== '' ) : ?>
                                                <details style="margin-bottom:10px">
                                                    <summary style="cursor:pointer;font-weight:600;font-size:13px">Marking guide</summary>
                                                    <div class="educbt-muted" style="margin-top:6px;font-size:13px"><?php echo wp_kses_post( wpautop( (string) $answer['marking_guide'] ) ); ?></div>
                                                </details>
                                            <?php endif; ?>

                                            <div class="educbt-marking-answer-text">
                                                <?php if ( trim( (string) $answer['answer_text'] ) === '' ) : ?>
                                                    <p class="educbt-muted"><em>No answer written.</em></p>
                                                <?php else : ?>
                                                    <div style="white-space:pre-wrap"><?php echo esc_html( (string) $answer['answer_text'] ); ?></div>
                                                <?php endif; ?>
                                            </div>

                                            <div class="educbt-marking-score-row">
                                                <label class="educbt-marking-score-label">Marks</label>
                                                <input type="number" step="0.5" min="0"
                                                       max="<?php echo esc_attr( (string) (float) $answer['max_marks'] ); ?>"
                                                       name="marks[<?php echo esc_attr( (string) $answer['answer_id'] ); ?>]"
                                                       value="<?php echo $answer['marked'] ? esc_attr( (string) (float) $answer['marks_awarded'] ) : ''; ?>"
                                                       class="educbt-marking-input"
                                                       data-max="<?php echo esc_attr( (string) (float) $answer['max_marks'] ); ?>"
                                                       data-answer-id="<?php echo esc_attr( (string) (int) $answer['answer_id'] ); ?>">
                                                <span class="educbt-muted">/ <?php echo esc_html( (string) (float) $answer['max_marks'] ); ?></span>
                                                <?php if ( $answer['marked'] ) : ?>
                                                    <span class="educbt-pill educbt-pill--approved" style="margin-left:6px">marked</span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>

                                <div class="educbt-marking-actions">
                                    <p class="educbt-muted">Leave a box empty to come back to it — that is different from a zero.</p>
                                    <button type="submit" class="educbt-btn educbt-btn--primary">Save marks</button>
                                </div>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </section>

    <script>
    (function() {
        document.querySelectorAll('.educbt-toggle-marking').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var panelId = btn.getAttribute('data-target');
                var wrapper = document.getElementById(panelId + '-wrapper');
                if (wrapper.style.display === 'none') {
                    wrapper.style.display = '';
                    btn.textContent = 'Hide submission';
                    btn.classList.add('educbt-btn--secondary');
                    btn.classList.remove('educbt-btn--primary');
                } else {
                    wrapper.style.display = 'none';
                    btn.textContent = 'View submission';
                    btn.classList.add('educbt-btn--primary');
                    btn.classList.remove('educbt-btn--secondary');
                }
            });
        });
    })();
    </script>

    <style>
    .educbt-marking-dot {
        display: inline-block;
        width: 12px;
        height: 12px;
        border-radius: 50%;
        background: #d1d5db;
    }
    .educbt-marking-dot--done { background: #14532d; }
    .educbt-marking-dot--partial { background: #ca8a04; }

    .educbt-marking-panel {
        padding: 16px 20px;
        background: #f9fafb;
        border-bottom: 1px solid #e5e7eb;
    }
    .educbt-marking-answers {
        display: flex;
        flex-direction: column;
        gap: 14px;
    }
    .educbt-marking-answer-item {
        border: 1px solid #e2e8e4;
        border-radius: 9px;
        padding: 14px;
        background: #fff;
    }
    .educbt-marking-q-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 8px;
    }
    .educbt-marking-q-label {
        font-weight: 700;
        font-size: 15px;
        color: #14532d;
    }
    .educbt-marking-marks {
        font-size: 13px;
        color: #6b7280;
        background: #f0f4f1;
        padding: 2px 10px;
        border-radius: 12px;
    }
    .educbt-marking-q-text {
        background: #f5f7f6;
        border-radius: 9px;
        padding: 10px;
        margin-bottom: 10px;
        font-size: 14px;
    }
    .educbt-marking-answer-text {
        border: 1px solid #e5e7eb;
        border-radius: 8px;
        padding: 10px 12px;
        margin-bottom: 10px;
        font-size: 14.5px;
        max-height: 300px;
        overflow-y: auto;
    }
    .educbt-marking-score-row {
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .educbt-marking-score-label {
        font-weight: 600;
        font-size: 14px;
        margin: 0;
    }
    .educbt-marking-input {
        width: 80px;
        padding: 6px 8px;
        border: 1px solid #d1d5db;
        border-radius: 6px;
        font-size: 14px;
    }
    .educbt-marking-actions {
        margin-top: 14px;
        display: flex;
        align-items: center;
        gap: 14px;
    }
    </style>
    <?php endif; ?>

    <?php if ( $is_wide ) : ?>
    <section class="educbt-card">
        <h2>Marking status by teacher</h2>
        <?php if ( empty( $staff_marking ) ) : ?>
            <p class="educbt-muted">No written answers are waiting to be marked.</p>
        <?php else : ?>
            <table class="educbt-table">
                <thead><tr><th>Teacher</th><th>Papers</th><th>Theory answers</th><th>Outstanding</th><th>Status</th><th></th></tr></thead>
                <tbody>
                <?php foreach ( $staff_marking as $row ) :
                    $outstanding = (int) $row['outstanding'];
                    $done        = (int) $row['theory_answers'] - $outstanding;
                    ?>
                    <tr>
                        <td><strong><?php echo esc_html( (string) $row['staff_name'] ); ?></strong></td>
                        <td><?php echo esc_html( (string) (int) $row['papers'] ); ?></td>
                        <td><?php echo esc_html( $done . ' / ' . (int) $row['theory_answers'] ); ?></td>
                        <td><?php echo esc_html( (string) $outstanding ); ?></td>
                        <td>
                            <?php if ( $outstanding === 0 ) : ?>
                                <span class="educbt-pill educbt-pill--approved">Complete</span>
                            <?php else : ?>
                                <span class="educbt-pill educbt-pill--pending">Outstanding</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ( $outstanding > 0 && (int) $row['wp_user_id'] > 0 ) : ?>
                                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
                                    <input type="hidden" name="action" value="educbt_remind_marking">
                                    <input type="hidden" name="staff_id" value="<?php echo esc_attr( (string) (int) $row['staff_id'] ); ?>">
                                    <input type="hidden" name="outstanding" value="<?php echo esc_attr( (string) $outstanding ); ?>">
                                    <?php wp_nonce_field( 'educbt_remind_marking' ); ?>
                                    <button type="submit" class="educbt-btn">Notify teacher</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>
    <?php endif; ?>

    <?php if ( $is_wide && ! empty( $assessment_progress ) ) : ?>
    <?php
    // Build unique filter lists
    $filter_teachers = array_unique( array_map( fn( $r ) => (string) $r['staff_name'], $assessment_progress ) );
    $filter_subjects = array_unique( array_map( fn( $r ) => (string) $r['subject_name'], $assessment_progress ) );
    sort( $filter_teachers ); sort( $filter_subjects );
    ?>
    <section class="educbt-card">
        <h2>CA assessment progress by teacher</h2>
        <p class="educbt-muted" style="margin-bottom:14px">
            How many assessment components each teacher has recorded for the current term.
            <?php
            $comp_names = array_map( fn( $c ) => (string) $c['name'], $assessment_progress[0]['components'] ?? [] );
            if ( ! empty( $comp_names ) ) {
                echo esc_html( 'Components: ' . implode( ', ', $comp_names ) . '.' );
            }
            ?>
        </p>
        <div style="display:flex;gap:10px;margin-bottom:12px;flex-wrap:wrap">
            <select id="filter-teacher" class="educbt-input" style="width:auto;min-width:160px" onchange="filterAssessmentTable()">
                <option value="">All teachers…</option>
                <?php foreach ( $filter_teachers as $t ) : ?>
                    <option value="<?php echo esc_attr( $t ); ?>"><?php echo esc_html( $t ); ?></option>
                <?php endforeach; ?>
            </select>
            <select id="filter-subject" class="educbt-input" style="width:auto;min-width:160px" onchange="filterAssessmentTable()">
                <option value="">All subjects…</option>
                <?php foreach ( $filter_subjects as $s ) : ?>
                    <option value="<?php echo esc_attr( $s ); ?>"><?php echo esc_html( $s ); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <table class="educbt-table" id="assessment-progress-table">
            <thead><tr><th>Teacher</th><th>Subject</th><th>Class</th><th>Components recorded</th><th>Progress</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ( $assessment_progress as $row ) :
                $completed = (int) $row['completed'];
                $total     = (int) $row['total'];
                $pct       = $total > 0 ? round( $completed / $total * 100 ) : 0;
                ?>
                <tr data-teacher="<?php echo esc_attr( (string) $row['staff_name'] ); ?>" data-subject="<?php echo esc_attr( (string) $row['subject_name'] ); ?>">
                    <td><strong><?php echo esc_html( (string) $row['staff_name'] ); ?></strong></td>
                    <td><?php echo esc_html( (string) $row['subject_name'] ); ?></td>
                    <td><?php echo esc_html( (string) $row['class_name'] ); ?></td>
                    <td>
                        <?php echo esc_html( $completed . ' / ' . $total ); ?>
                        <span class="educbt-muted" style="font-size:.8rem">
                            (<?php
                            $parts = [];
                            foreach ( $row['components'] as $c ) {
                                $parts[] = ( $c['has_scores'] ? '✓' : '✗' ) . ' ' . $c['name'];
                            }
                            echo esc_html( implode( ' · ', $parts ) );
                            ?>)
                        </span>
                    </td>
                    <td>
                        <div style="background:#f0f4f1;border-radius:6px;height:8px;overflow:hidden;width:120px;display:inline-block;vertical-align:middle">
                            <div style="background:#14532d;height:100%;width:<?php echo esc_attr( (string) $pct ); ?>%"></div>
                        </div>
                        <span style="font-size:.8rem;margin-left:6px"><?php echo esc_html( (string) $pct ); ?>%</span>
                    </td>
                    <td>
                        <?php if ( $completed >= $total ) : ?>
                            <span class="educbt-pill educbt-pill--approved">All recorded</span>
                        <?php elseif ( $completed > 0 ) : ?>
                            <span class="educbt-pill educbt-pill--pending">In progress</span>
                        <?php else : ?>
                            <span class="educbt-pill educbt-pill--draft">Not started</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </section>
    <script>
    function filterAssessmentTable() {
        var tFilter = document.getElementById('filter-teacher').value;
        var sFilter = document.getElementById('filter-subject').value;
        var rows = document.querySelectorAll('#assessment-progress-table tbody tr');
        rows.forEach(function(r) {
            var show = true;
            if (tFilter && r.getAttribute('data-teacher') !== tFilter) show = false;
            if (sFilter && r.getAttribute('data-subject') !== sFilter) show = false;
            r.style.display = show ? '' : 'none';
        });
    }
    </script>
    <?php endif; ?>


    <section class="educbt-card">
        <h2><?php echo $is_wide ? 'Completed exams — marking status' : 'Mark written answers'; ?></h2>
        <?php if ( empty( $completed_exams ) ) : ?>
            <p class="educbt-muted">No exams have been sat yet.</p>
        <?php else : ?>
            <table class="educbt-table">
                <thead><tr><th>Subject</th><th>Class</th><th>Students</th><th>Theory answers</th><th>Marking progress</th><th>Status</th></tr></thead>
                <tbody>
                <?php foreach ( $completed_exams as $exam ) :
                    $theory_total  = (int) $exam['theory_total'];
                    $theory_marked = (int) $exam['theory_marked'];
                    $has_theory   = $theory_total > 0;
                    $all_marked   = $has_theory && $theory_marked >= $theory_total;
                    $no_theory    = ! $has_theory;
                    ?>
                    <tr>
                        <td><strong><?php echo esc_html( (string) $exam['subject_name'] ); ?></strong></td>
                        <td><?php echo esc_html( (string) $exam['class_name'] ); ?></td>
                        <td><?php echo esc_html( (string) $exam['students_sat'] ); ?></td>
                        <td>
                            <?php if ( $no_theory ) : ?>
                                <span class="educbt-muted">Objectives only</span>
                            <?php else : ?>
                                <?php echo esc_html( (string) $theory_marked . ' / ' . (string) $theory_total ); ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ( $no_theory ) : ?>
                                <span class="educbt-pill educbt-pill--approved">Auto-marked</span>
                            <?php elseif ( $all_marked ) : ?>
                                <span class="educbt-pill educbt-pill--approved">Complete</span>
                            <?php else : ?>
                                <div style="background:#f0f4f1;border-radius:6px;height:8px;overflow:hidden;width:120px">
                                    <div style="background:#14532d;height:100%;width:<?php echo esc_attr( (string) ( $theory_total > 0 ? round( $theory_marked / $theory_total * 100 ) : 0 ) ); ?>%"></div>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ( $no_theory || $all_marked ) : ?>
                                <span class="educbt-pill educbt-pill--completed">Ready for results</span>
                            <?php else : ?>
                                <span class="educbt-pill educbt-pill--pending">Marking in progress</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>


    <?php if ( $progress && $progress['total'] > 0 ) : ?>
        <section class="educbt-card">
            <p class="educbt-muted" style="margin:0">
                <?php echo esc_html( sprintf( '%d of %d answers marked.', (int) $progress['marked'], (int) $progress['total'] ) ); ?>
                <?php if ( (int) $progress['outstanding'] > 0 ) : ?>
                    <strong>Results must not be compiled until this reaches zero</strong> — unmarked
                    answers would count as nought and fail the class silently.
                <?php endif; ?>
            </p>
        </section>
    <?php endif; ?>

    <?php if ( $paper_id > 0 && empty( $queue ) && ! $is_wide ) : ?>
        <div class="educbt-card"><p class="educbt-muted">This paper has no written answers to mark.</p></div>
    <?php endif; ?>
    <?php
};

require EDUCBT_PRO_PATH . 'templates/portal/shell.php';
