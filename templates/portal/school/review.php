<?php
/**
 * Result moderation — the principal reviews and adjusts compiled results before
 * approving them.
 *
 * Shows every student in the class with their per-subject component scores
 * (First CA, Second CA, Assignment, Exam) in editable inputs. When a score is
 * changed the subject total and the student's grand total recalculate live. A
 * "Save & Recompile" button writes the moderated scores back to assessment_scores,
 * recompiles the class (which recalculates totals, grades and positions), and
 * leaves the principal on the approval step.
 *
 * @var array<string,mixed> $educbt
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

global $wpdb;

$school_id = (int) $educbt['school_id'];
$class_id  = (int) ( $educbt['id'] ?? 0 );
$flash     = \EduCBTPro\Frontend\PortalActions::flash();

$year       = new \EduCBTPro\Services\AcademicYearService();
$session    = $year->current_session( $school_id );
$term       = $year->current_term( $school_id );
$session_id = (int) ( $session['id'] ?? 0 );
$term_id    = (int) ( $term['id'] ?? 0 );

// Resolve class
if ( $class_id === 0 ) {
    $structure = new \EduCBTPro\Services\AcademicStructureService();
    $classes   = $structure->list_classes( $school_id );
    $class_id  = (int) ( $classes[0]['id'] ?? 0 );
}

$class_name = '';
$structure  = new \EduCBTPro\Services\AcademicStructureService();
foreach ( $structure->list_classes( $school_id ) as $c ) {
    if ( (int) $c['id'] === $class_id ) { $class_name = (string) $c['display_name']; }
}

// Components (First CA, Second CA, Assignment, Exam, etc.)
$components_table = \EduCBTPro\Core\Schema::table( 'assessment_components' );
$components = (array) $wpdb->get_results(
    $wpdb->prepare(
        "SELECT id, name, code, max_score, is_exam, sort_order
         FROM {$components_table} WHERE school_id = %d AND status = 'active' ORDER BY sort_order ASC",
        $school_id
    ),
    ARRAY_A
);

// All assessment scores for this class/term, keyed by (student_id, subject_id, component_id)
$scores_table = \EduCBTPro\Core\Schema::table( 'assessment_scores' );
$score_rows = (array) $wpdb->get_results(
    $wpdb->prepare(
        "SELECT student_id, subject_id, component_id, score, max_score, id
         FROM {$scores_table}
         WHERE school_id = %d AND class_id = %d AND session_id = %d AND term_id = %d",
        $school_id, $class_id, $session_id, $term_id
    ),
    ARRAY_A
);

$scores_map = [];
foreach ( $score_rows as $sr ) {
    $scores_map[ (int) $sr['student_id'] ][ (int) $sr['subject_id'] ][ (int) $sr['component_id'] ] = [
        'id'       => (int) $sr['id'],
        'score'    => (float) $sr['score'],
        'max'      => (float) $sr['max_score'],
    ];
}

// Subjects offered in this class
$enrollments = \EduCBTPro\Core\Schema::table( 'enrollments' );
$registered  = \EduCBTPro\Core\Schema::table( 'student_subjects' );
$subs_table  = \EduCBTPro\Core\Schema::table( 'subjects_v2' );
$students_t  = $wpdb->prefix . 'educbt_students';

$subjects = (array) $wpdb->get_results(
    $wpdb->prepare(
        "SELECT DISTINCT s.id, s.name, s.code FROM {$registered} rs
         INNER JOIN {$subs_table} s ON s.id = rs.subject_id
         INNER JOIN {$enrollments} e ON e.student_id = rs.student_id AND e.session_id = rs.session_id
         WHERE e.class_id = %d AND e.session_id = %d
         ORDER BY s.name ASC",
        $class_id, $session_id
    ),
    ARRAY_A
);

// Students enrolled in this class
$students = (array) $wpdb->get_results(
    $wpdb->prepare(
        "SELECT st.id, st.admission_number, st.first_name, st.last_name
         FROM {$enrollments} e
         INNER JOIN {$students_t} st ON st.id = e.student_id
         WHERE e.school_id = %d AND e.class_id = %d AND e.session_id = %d AND e.status = 'active'
         ORDER BY st.last_name ASC, st.first_name ASC",
        $school_id, $class_id, $session_id
    ),
    ARRAY_A
);

// Current workflow status
$term_results = \EduCBTPro\Core\Schema::table( 'term_results' );
$current_status = (string) $wpdb->get_var(
    $wpdb->prepare(
        "SELECT status FROM {$term_results} WHERE school_id = %d AND class_id = %d AND term_id = %d LIMIT 1",
        $school_id, $class_id, $term_id
    )
);

$educbt_title = 'Review & Moderate — ' . $class_name;

$educbt_body = static function () use ( $flash, $class_id, $class_name, $session, $term, $components, $scores_map, $subjects, $students, $current_status, $session_id, $term_id, $school_id ): void {
    require EDUCBT_PRO_PATH . 'templates/portal/partials/flash.php';

    if ( empty( $students ) ) : ?>
        <div class="educbt-card"><p class="educbt-muted">No students enrolled in this class.</p></div>
        <?php return;
    endif;

    if ( empty( $subjects ) ) : ?>
        <div class="educbt-card"><p class="educbt-muted">No subjects registered for this class.</p></div>
        <?php return;
    endif;
    ?>

    <p class="educbt-muted" style="margin-top:-8px">
        <?php echo esc_html( (string) ( $session['title'] ?? '' ) . ' · ' . (string) ( $term['title'] ?? '' ) ); ?>
        <?php if ( $current_status !== '' ) : ?>
            · <span class="educbt-pill educbt-pill--<?php echo esc_attr( $current_status ); ?>"><?php echo esc_html( ucfirst( $current_status ) ); ?></span>
        <?php endif; ?>
    </p>

    <section class="educbt-card">
        <h2>Result Moderation — <?php echo esc_html( $class_name ); ?></h2>
        <p class="educbt-muted" style="margin-top:-6px">
            Adjust any score below. Subject totals and student grand totals recalculate
            automatically. When you are satisfied, click "Save &amp; Recompile" to write the
            changes, recompute totals/grades/positions, and move to the approval step.
        </p>

        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="educbt-review-form">
            <input type="hidden" name="action" value="educbt_moderate_results">
            <input type="hidden" name="class_id" value="<?php echo esc_attr( (string) $class_id ); ?>">
            <input type="hidden" name="session_id" value="<?php echo esc_attr( (string) $session_id ); ?>">
            <input type="hidden" name="term_id" value="<?php echo esc_attr( (string) $term_id ); ?>">
            <?php wp_nonce_field( 'educbt_moderate_results' ); ?>

            <div style="overflow-x:auto">
                <table class="educbt-table educbt-review-table" id="educbt-review-grid" style="min-width:760px">
                    <thead>
                        <tr>
                            <th style="position:sticky;left:0;z-index:2;background:inherit">Student</th>
                            <?php foreach ( $subjects as $subject ) : ?>
                                <th class="educbt-review-subject" title="<?php echo esc_attr( (string) $subject['name'] ); ?>">
                                    <?php echo esc_html( (string) ( $subject['code'] ?: $subject['name'] ) ); ?>
                                    <span class="educbt-review-subject-name"><?php echo esc_html( (string) $subject['name'] ); ?></span>
                                </th>
                            <?php endforeach; ?>
                            <th>Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $component_json = [];
                        foreach ( $components as $c ) {
                            $component_json[] = [
                                'id'   => (int) $c['id'],
                                'max'  => (float) $c['max_score'],
                                'name' => (string) $c['name'],
                                'is_exam' => (int) $c['is_exam'] === 1,
                            ];
                        }
                        ?>
                        <?php foreach ( $students as $student ) :
                            $sid = (int) $student['id']; ?>
                            <tr>
                                <td style="position:sticky;left:0;z-index:2;background:inherit;white-space:nowrap">
                                    <strong><?php echo esc_html( $student['last_name'] . ', ' . $student['first_name'] ); ?></strong>
                                    <span class="educbt-muted"><?php echo esc_html( (string) $student['admission_number'] ); ?></span>
                                </td>
                                <?php foreach ( $subjects as $subject ) :
                                    $sub_id = (int) $subject['id'];
                                    $student_scores = $scores_map[ $sid ][ $sub_id ] ?? []; ?>
                                    <td class="educbt-review-cell" data-student="<?php echo esc_attr( (string) $sid ); ?>" data-subject="<?php echo esc_attr( (string) $sub_id ); ?>">
                                        <div class="educbt-review-components">
                                            <?php foreach ( $components as $c ) :
                                                $comp_id = (int) $c['id'];
                                                $sc = $student_scores[ $comp_id ] ?? null; ?>
                                                <div class="educbt-review-comp">
                                                    <label class="educbt-review-comp-label"><?php echo esc_html( (string) $c['code'] ); ?></label>
                                                    <input type="number"
                                                           step="0.5"
                                                           min="0"
                                                           max="<?php echo esc_attr( (string) (float) $c['max_score'] ); ?>"
                                                           name="scores[<?php echo esc_attr( (string) $sid ); ?>][<?php echo esc_attr( (string) $sub_id ); ?>][<?php echo esc_attr( (string) $comp_id ); ?>]"
                                                           value="<?php echo $sc !== null ? esc_attr( (string) (float) $sc['score'] ) : ''; ?>"
                                                           class="educbt-review-input"
                                                           data-comp-max="<?php echo esc_attr( (string) (float) $c['max_score'] ); ?>">
                                                    <span class="educbt-review-max">/<?php echo esc_html( (string) (float) $c['max_score'] ); ?></span>
                                                </div>
                                            <?php endforeach; ?>
                                            <div class="educbt-review-subject-total">
                                                <span class="educbt-muted">Subject total: </span>
                                                <strong class="educbt-subject-total" data-subject-total="<?php echo esc_attr( (string) $sid . '_' . (string) $sub_id ); ?>">—</strong>
                                            </div>
                                        </div>
                                    </td>
                                <?php endforeach; ?>
                                <td>
                                    <strong class="educbt-student-total" data-student-total="<?php echo esc_attr( (string) $sid ); ?>">—</strong>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div style="display:flex;gap:12px;align-items:center;margin-top:18px">
                <button type="submit" class="educbt-btn educbt-btn--primary">Save &amp; Recompile</button>
                <a class="educbt-btn" href="<?php echo esc_url( home_url( '/portal/school/results/' ) ); ?>">Cancel</a>
            </div>
        </form>
    </section>

    <script>
    (function() {
        function calcSubjectTotal(cell) {
            var inputs = cell.querySelectorAll('.educbt-review-input');
            var total = 0;
            inputs.forEach(function(inp) {
                var v = parseFloat(inp.value);
                if (!isNaN(v)) total += v;
            });
            var totalEl = cell.querySelector('.educbt-subject-total');
            if (totalEl) totalEl.textContent = total.toFixed(1);
            return total;
        }

        function calcStudentTotal(row) {
            var cells = row.querySelectorAll('.educbt-review-cell');
            var grand = 0;
            cells.forEach(function(cell) {
                grand += calcSubjectTotal(cell);
            });
            var totalEl = row.querySelector('.educbt-student-total');
            if (totalEl) totalEl.textContent = grand.toFixed(1);
        }

        var grid = document.getElementById('educbt-review-grid');
        if (!grid) return;

        grid.querySelectorAll('tbody tr').forEach(function(row) {
            row.querySelectorAll('.educbt-review-input').forEach(function(inp) {
                inp.addEventListener('input', function() {
                    calcStudentTotal(row);
                });
            });
            calcStudentTotal(row);
        });
    })();
    </script>

    <style>
    .educbt-review-table th.educbt-review-subject {
        text-align: center;
        font-size: 13px;
    }
    .educbt-review-subject-name {
        display: block;
        font-size: 10px;
        font-weight: 400;
        color: #6b7280;
        margin-top: 2px;
    }
    .educbt-review-cell {
        vertical-align: top;
    }
    .educbt-review-components {
        display: flex;
        flex-direction: column;
        gap: 5px;
        min-width: 100px;
    }
    .educbt-review-comp {
        display: flex;
        align-items: center;
        gap: 4px;
        font-size: 12px;
    }
    .educbt-review-comp-label {
        width: 28px;
        font-weight: 600;
        color: #6b7280;
    }
    .educbt-review-input {
        width: 52px;
        padding: 3px 5px;
        border: 1px solid #d1d5db;
        border-radius: 4px;
        font-size: 13px;
        text-align: center;
    }
    .educbt-review-max {
        font-size: 11px;
        color: #9ca3af;
    }
    .educbt-review-subject-total {
        font-size: 12px;
        margin-top: 4px;
        padding-top: 4px;
        border-top: 1px solid #e5e7eb;
    }
    .educbt-subject-total {
        color: #14532d;
    }
    .educbt-student-total {
        font-size: 16px;
        color: #14532d;
    }
    </style>
    <?php
};

require EDUCBT_PRO_PATH . 'templates/portal/shell.php';
