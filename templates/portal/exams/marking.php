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

$completed_exams = (array) $wpdb->get_results(
    $wpdb->prepare(
        "SELECT p.id, p.status, p.scheduled_at, s.name AS subject_name, c.display_name AS class_name,
                (SELECT COUNT(DISTINCT at2.student_id) FROM {$attempts} at2 WHERE at2.paper_id = p.id AND at2.status = 'completed') AS students_sat,
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
         WHERE p.school_id = %d
         AND EXISTS (SELECT 1 FROM {$attempts} at4 WHERE at4.paper_id = p.id AND at4.status = 'completed')
         ORDER BY p.scheduled_at DESC
         LIMIT 100",
        $school_id
    ),
    ARRAY_A
);

// Per-paper marking queue (only if a specific paper is selected AND user has marking rights)
$queue    = ( $paper_id > 0 && ! $is_wide ) ? $theory->marking_queue( $school_id, $paper_id ) : [];
$progress = $paper_id > 0 ? $theory->marking_progress( $school_id, $paper_id ) : null;

$educbt_title = 'Marking';

$educbt_body = static function () use ( $flash, $pending, $completed_exams, $queue, $progress, $paper_id, $is_wide ): void {
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

    <?php foreach ( $queue as $group ) : ?>
        <section class="educbt-card">
            <h2>Question <span class="educbt-muted">(<?php echo esc_html( (string) $group['marked'] ); ?>/<?php echo esc_html( (string) $group['total'] ); ?> marked, max <?php echo esc_html( (string) (float) $group['max_marks'] ); ?>)</span></h2>
            <div style="background:#f5f7f6;border-radius:9px;padding:12px;margin-bottom:14px">
                <?php echo wp_kses_post( wpautop( (string) $group['question_text'] ) ); ?>
            </div>

            <?php if ( trim( (string) $group['marking_guide'] ) !== '' ) : ?>
                <details style="margin-bottom:14px">
                    <summary style="cursor:pointer;font-weight:600;font-size:14px">Marking guide</summary>
                    <div class="educbt-muted" style="margin-top:8px"><?php echo wp_kses_post( wpautop( (string) $group['marking_guide'] ) ); ?></div>
                </details>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="educbt-form">
                <input type="hidden" name="action" value="educbt_mark_theory">
                <input type="hidden" name="paper_id" value="<?php echo esc_attr( (string) $paper_id ); ?>">
                <input type="hidden" name="max_marks" value="<?php echo esc_attr( (string) (float) $group['max_marks'] ); ?>">
                <?php wp_nonce_field( 'educbt_mark_theory' ); ?>

                <?php foreach ( $group['answers'] as $answer ) : ?>
                    <div style="border:1px solid #e2e8e4;border-radius:9px;padding:13px;margin-bottom:11px">
                        <p style="margin:0 0 8px;font-size:13.5px">
                            <strong><?php echo esc_html( (string) $answer['student_name'] ); ?></strong>
                            <span class="educbt-muted"><?php echo esc_html( (string) $answer['admission_number'] ); ?></span>
                        </p>

                        <?php if ( trim( (string) $answer['answer_text'] ) === '' ) : ?>
                            <p class="educbt-muted"><em>No answer written.</em></p>
                        <?php else : ?>
                            <div style="white-space:pre-wrap;font-size:14.5px"><?php echo esc_html( (string) $answer['answer_text'] ); ?></div>
                        <?php endif; ?>

                        <div style="display:flex;align-items:center;gap:10px;margin-top:10px">
                            <label style="margin:0">Mark</label>
                            <input type="number" step="0.5" min="0" max="<?php echo esc_attr( (string) (float) $group['max_marks'] ); ?>"
                                   name="marks[<?php echo esc_attr( (string) $answer['answer_id'] ); ?>]"
                                   value="<?php echo $answer['marked'] ? esc_attr( (string) (float) $answer['marks_awarded'] ) : ''; ?>"
                                   style="width:90px;padding:7px">
                            <span class="educbt-muted">of <?php echo esc_html( (string) (float) $group['max_marks'] ); ?></span>
                            <?php if ( $answer['marked'] ) : ?>
                                <span class="educbt-pill educbt-pill--approved">marked</span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>

                <p class="educbt-muted">Leave a box empty to come back to it — that is different from a zero.</p>
                <button type="submit" class="educbt-btn educbt-btn--primary">Save marks</button>
            </form>
        </section>
    <?php endforeach; ?>

    <?php if ( $paper_id > 0 && empty( $queue ) && ! $is_wide ) : ?>
        <div class="educbt-card"><p class="educbt-muted">This paper has no written answers to mark.</p></div>
    <?php endif; ?>
    <?php
};

require EDUCBT_PRO_PATH . 'templates/portal/shell.php';
