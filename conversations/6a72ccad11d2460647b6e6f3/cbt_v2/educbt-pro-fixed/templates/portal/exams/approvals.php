<?php
/**
 * Question approval — the exam officer's or principal's review screen.
 *
 * Shows every teacher's submission against the quota, so a reviewer can see at a
 * glance who is short and who is waiting, then open one submission and work through
 * it. Only approved questions can be drawn into a paper.
 *
 * @var array<string,mixed> $educbt
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

global $wpdb;
$questions_table = $wpdb->prefix . 'educbt_questions';

$school_id = (int) $educbt['school_id'];
$flash     = \EduCBTPro\Frontend\PortalActions::flash();

$approvals = new \EduCBTPro\Services\QuestionApprovalService();
$quotas    = $approvals->quotas( $school_id );

$open_subject = (int) ( $_GET['subject'] ?? 0 );
$open_staff   = (int) ( $_GET['staff'] ?? 0 );

$submissions = $approvals->submissions( $school_id );
$queue       = ( $open_subject > 0 && $open_staff > 0 )
    ? $approvals->review_queue( $school_id, $open_subject, $open_staff )
    : [];

$educbt_title = 'Question Approval';

$educbt_body = static function () use ( $flash, $submissions, $queue, $quotas, $open_subject, $open_staff, $wpdb, $questions_table, $school_id ): void {
    require EDUCBT_PRO_PATH . 'templates/portal/partials/flash.php';
    ?>
    <section class="educbt-card">
        <h2>Minimum required per subject</h2>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="educbt-form">
            <input type="hidden" name="action" value="educbt_save_quotas">
            <?php wp_nonce_field( 'educbt_save_quotas' ); ?>
            <div class="educbt-grid">
                <div><label for="objective">Minimum objective questions</label>
                    <input id="objective" name="objective" type="number" min="0" max="500" value="<?php echo esc_attr( (string) $quotas['objective'] ); ?>"></div>
                <div><label for="theory">Minimum written questions</label>
                    <input id="theory" name="theory" type="number" min="0" max="100" value="<?php echo esc_attr( (string) $quotas['theory'] ); ?>"></div>
            </div>
            <p class="educbt-muted" style="margin-top:8px">
                These are minimums, not caps — a teacher may submit as many as they
                like. A forty-question paper drawn from a bank of exactly forty is not a
                paper, it is the whole bank in order, so ask for comfortably more than a
                paper needs.
            </p>
            <button type="submit" class="educbt-btn" style="margin-top:8px">Save requirement</button>
        </form>
    </section>

    <section class="educbt-card">
        <h2>Submissions</h2>

        <?php if ( empty( $submissions ) ) : ?>
            <p class="educbt-muted">No questions have been submitted yet.</p>
        <?php else : ?>
            <table class="educbt-table">
                <thead><tr><th>Teacher</th><th>Subject</th><th>Class</th><th>Objective</th><th>Written</th><th>Status</th><th></th></tr></thead>
                <tbody>
                <?php foreach ( $submissions as $sub ) :
                    $class_levels = (array) $wpdb->get_col(
                        $wpdb->prepare(
                            "SELECT DISTINCT class_level FROM {$questions_table} WHERE school_id = %d AND subject_id = %d AND created_by_staff = %d AND status = 'active' AND class_level IS NOT NULL AND class_level <> '' ORDER BY class_level ASC",
                            $school_id, (int) $sub['subject_id'], (int) $sub['staff_id']
                        )
                    );
                    $class_display = implode( ', ', $class_levels );
                    ?>
                    <tr>
                        <td><?php echo esc_html( (string) $sub['teacher_name'] ); ?></td>
                        <td><?php echo esc_html( (string) $sub['subject_name'] ); ?></td>
                        <td><?php echo esc_html( $class_display ); ?></td>
                        <td>
                            <?php echo esc_html( (string) $sub['objective'] ); ?>
                            <?php if ( $sub['short_objective'] > 0 ) : ?>
                                <span class="educbt-pill educbt-pill--draft"><?php echo esc_html( (string) $sub['short_objective'] ); ?> below minimum</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php echo esc_html( (string) $sub['theory'] ); ?>
                            <?php if ( $sub['short_theory'] > 0 ) : ?>
                                <span class="educbt-pill educbt-pill--draft"><?php echo esc_html( (string) $sub['short_theory'] ); ?> below minimum</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ( (int) $sub['pending'] > 0 ) : ?>
                                <span class="educbt-pill educbt-pill--submitted"><?php echo esc_html( (string) $sub['pending'] ); ?> awaiting review</span>
                            <?php elseif ( (int) $sub['revision'] > 0 ) : ?>
                                <span class="educbt-pill educbt-pill--draft"><?php echo esc_html( (string) $sub['revision'] ); ?> sent back</span>
                            <?php else : ?>
                                <span class="educbt-pill educbt-pill--approved">all approved</span>
                            <?php endif; ?>
                        </td>
                        <td style="white-space:nowrap">
                            <a class="educbt-btn" href="<?php echo esc_url( add_query_arg( [ 'subject' => (int) $sub['subject_id'], 'staff' => (int) $sub['staff_id'] ] ) ); ?>">Review</a>

                            <?php if ( ! $sub['complete'] ) : ?>
                                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
                                    <input type="hidden" name="action" value="educbt_remind_questions">
                                    <input type="hidden" name="subject_id" value="<?php echo esc_attr( (string) $sub['subject_id'] ); ?>">
                                    <input type="hidden" name="staff_id" value="<?php echo esc_attr( (string) $sub['staff_id'] ); ?>">
                                    <?php wp_nonce_field( 'educbt_remind_questions' ); ?>
                                    <button type="submit" class="educbt-btn">Remind</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>

    <?php if ( ! empty( $queue ) ) : ?>
        <section class="educbt-card">
            <h2>Reviewing <?php echo esc_html( (string) count( $queue ) ); ?> question(s)</h2>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="educbt-form">
                <input type="hidden" name="action" value="educbt_decide_questions">
                <input type="hidden" name="subject_id" value="<?php echo esc_attr( (string) $open_subject ); ?>">
                <input type="hidden" name="staff_id" value="<?php echo esc_attr( (string) $open_staff ); ?>">
                <?php wp_nonce_field( 'educbt_decide_questions' ); ?>

                <?php foreach ( $queue as $i => $q ) : ?>
                    <div style="border:1px solid var(--edu-line);border-radius:9px;padding:13px;margin-bottom:10px">
                        <p style="margin:0 0 6px;font-size:12px;color:var(--edu-muted)">
                            <?php echo esc_html( (string) ( $i + 1 ) ); ?>.
                            <?php echo esc_html( (string) $q['question_type'] === 'theory' ? 'Written' : 'Objective' ); ?> ·
                            <?php echo esc_html( (string) (float) $q['marks'] ); ?> mark(s) ·
                            <?php if ( ! empty( $q["class_level"] ) ) : ?><?php echo esc_html( (string) $q["class_level"] ); ?> · <?php endif; ?>
                            <span class="educbt-pill educbt-pill--<?php echo esc_attr( (string) ( $q['approval_status'] ?: 'pending' ) ); ?>">
                                <?php echo esc_html( (string) ( $q['approval_status'] ?: 'pending' ) ); ?>
                            </span>
                            <?php if ( empty( $q['has_answer'] ) ) : ?>
                                · <span class="educbt-pill educbt-pill--draft">no correct answer marked</span>
                            <?php endif; ?>
                        </p>

                        <div><?php echo wp_kses_post( wpautop( (string) $q['question_text'] ) ); ?></div>

                        <?php if ( ! empty( $q['options'] ) ) : ?>
                            <ul style="list-style:none;padding:0;margin:6px 0 0;font-size:13.5px">
                            <?php foreach ( $q['options'] as $opt ) : ?>
                                <li style="<?php echo ! empty( $opt['is_correct'] ) ? 'color:var(--edu-accent);font-weight:600' : 'color:var(--edu-muted)'; ?>">
                                    <?php echo esc_html( $opt['option_key'] . '. ' . $opt['option_text'] ); ?><?php echo ! empty( $opt['is_correct'] ) ? ' ✓' : ''; ?>
                                </li>
                            <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>

                        <?php if ( ! empty( $q['review_note'] ) ) : ?>
                            <p class="educbt-note educbt-note--warn" style="margin-top:8px"><?php echo esc_html( (string) $q['review_note'] ); ?></p>
                        <?php endif; ?>

                        <label style="display:flex;align-items:center;gap:8px;margin-top:10px;font-weight:400;font-size:13px">
                            <input type="checkbox" name="question_ids[]" value="<?php echo esc_attr( (string) $q['id'] ); ?>" style="width:auto">
                            Include this one in the decision below
                        </label>
                    </div>
                <?php endforeach; ?>

                <label for="note" style="margin-top:12px">Note to the teacher <span class="educbt-muted">(required when sending back)</span></label>
                <textarea id="note" name="note" rows="3" placeholder="Question 4 has no correct option marked; question 9 duplicates question 2."></textarea>

                <p class="educbt-muted" style="margin-top:10px">
                    Leave every box unticked to apply the decision to the whole submission.
                </p>

                <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:8px">
                    <button type="submit" name="decision" value="approved" class="educbt-btn educbt-btn--primary">Approve</button>
                    <button type="submit" name="decision" value="revision" class="educbt-btn">Send back for revision</button>
                </div>
            </form>
        </section>
    <?php endif; ?>
    <?php
};

require EDUCBT_PRO_PATH . 'templates/portal/shell.php';
