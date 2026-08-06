<?php
/**
 * Question approval — the exam officer's or principal's review screen.
 *
 * Shows every teacher's submission against the quota, so a reviewer can see at a
 * glance who is short and who is waiting, then open one submission and work through
 * it in the question bank. Only approved questions can be drawn into a paper.
 *
 * @var array<string,mixed> $educbt
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

$school_id = (int) $educbt['school_id'];
$flash     = \EduCBTPro\Frontend\PortalActions::flash();

$approvals = new \EduCBTPro\Services\QuestionApprovalService();
$quotas    = $approvals->quotas( $school_id );

$open_subject = (int) ( $_GET['subject'] ?? 0 );
$open_staff   = (int) ( $_GET['staff'] ?? 0 );
$open_level   = (int) ( $_GET['level_id'] ?? 0 );

$submissions = $approvals->submissions( $school_id );

$educbt_title = 'Question Approval';

$educbt_body = static function () use ( $flash, $submissions, $quotas ): void {
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
                <thead>
                    <tr>
                        <th>Teacher</th>
                        <th>Subject</th>
                        <th>Class</th>
                        <th>Objective</th>
                        <th>Theory</th>
                        <th>Status</th>
                        <th>Submitted At</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $submissions as $sub ) :
                    $submitted_at_raw = (string) ( $sub['submitted_at'] ?? '' );
                    if ( ! empty( $submitted_at_raw ) && $submitted_at_raw !== '0000-00-00 00:00:00' ) {
                        $ts = strtotime( $submitted_at_raw );
                        $submitted_display = $ts ? mysql2date( 'M j, g:i A', $submitted_at_raw ) : '—';
                    } else {
                        $submitted_display = '—';
                    }

                    $format_type_status = static function( string $type_label, string $status, int $count ): array {
                        $s = strtolower( trim( $status ) );
                        switch ( $s ) {
                            case 'submitted':
                            case 'under_review':
                            case 'pending':
                                $text  = 'awaiting review';
                                $class = 'educbt-pill--submitted';
                                break;
                            case 'approved':
                                $text  = 'approved';
                                $class = 'educbt-pill--approved';
                                break;
                            case 'returned':
                            case 'revision':
                                $text  = 'sent back';
                                $class = 'educbt-pill--draft';
                                break;
                            case 'draft':
                            default:
                                $text  = ( $count === 0 ) ? 'not started' : 'draft';
                                $class = 'educbt-pill--draft';
                                break;
                        }
                        return [
                            'label' => $type_label . ': ' . $text,
                            'class' => $class,
                        ];
                    };

                    $obj_status = $format_type_status( 'Objective', (string) ( $sub['objective_status'] ?? '' ), (int) ( $sub['objective'] ?? 0 ) );
                    $thy_status = $format_type_status( 'Theory', (string) ( $sub['theory_status'] ?? '' ), (int) ( $sub['theory'] ?? 0 ) );

                    $review_url = add_query_arg(
                        [
                            'subject_id'    => (int) ( $sub['subject_id'] ?? 0 ),
                            'level_id'      => (int) ( $sub['level_id'] ?? 0 ),
                            'department_id' => (int) ( $sub['department_id'] ?? 0 ),
                        ],
                        home_url( '/portal/exams/questions/' )
                    );
                    ?>
                    <tr>
                        <td><?php echo esc_html( (string) ( $sub['teacher_name'] ?? '' ) ); ?></td>
                        <td><?php echo esc_html( (string) ( $sub['subject_name'] ?? '' ) ); ?></td>
                        <td><?php echo esc_html( (string) ( $sub['level_name'] ?? '' ) ); ?></td>
                        <td>
                            <?php echo esc_html( (string) ( $sub['objective'] ?? 0 ) ); ?>
                            <?php if ( ! empty( $sub['short_objective'] ) && (int) $sub['short_objective'] > 0 ) : ?>
                                <span class="educbt-pill educbt-pill--draft"><?php echo esc_html( (string) $sub['short_objective'] ); ?> below minimum</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php echo esc_html( (string) ( $sub['theory'] ?? 0 ) ); ?>
                            <?php if ( ! empty( $sub['short_theory'] ) && (int) $sub['short_theory'] > 0 ) : ?>
                                <span class="educbt-pill educbt-pill--draft"><?php echo esc_html( (string) $sub['short_theory'] ); ?> below minimum</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div style="display:flex;flex-direction:column;gap:4px;align-items:flex-start">
                                <span class="educbt-pill <?php echo esc_attr( $obj_status['class'] ); ?>"><?php echo esc_html( $obj_status['label'] ); ?></span>
                                <span class="educbt-pill <?php echo esc_attr( $thy_status['class'] ); ?>"><?php echo esc_html( $thy_status['label'] ); ?></span>
                            </div>
                        </td>
                        <td><?php echo esc_html( $submitted_display ); ?></td>
                        <td style="white-space:nowrap">
                            <a class="educbt-btn" href="<?php echo esc_url( $review_url ); ?>">Review</a>

                            <?php if ( empty( $sub['complete'] ) ) : ?>
                                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
                                    <input type="hidden" name="action" value="educbt_remind_questions">
                                    <input type="hidden" name="subject_id" value="<?php echo esc_attr( (string) ( $sub['subject_id'] ?? 0 ) ); ?>">
                                    <input type="hidden" name="staff_id" value="<?php echo esc_attr( (string) ( $sub['staff_id'] ?? 0 ) ); ?>">
                                    <?php wp_nonce_field( 'educbt_remind_questions' ); ?>
                                    <button type="submit" class="educbt-btn">Remind</button>
                                </form>
                            <?php endif; ?>

                            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
                                <input type="hidden" name="action" value="educbt_delete_submission">
                                <input type="hidden" name="subject_id" value="<?php echo esc_attr( (string) ( $sub['subject_id'] ?? 0 ) ); ?>">
                                <input type="hidden" name="staff_id" value="<?php echo esc_attr( (string) ( $sub['staff_id'] ?? 0 ) ); ?>">
                                <input type="hidden" name="level_id" value="<?php echo esc_attr( (string) ( $sub['level_id'] ?? 0 ) ); ?>">
                                <?php wp_nonce_field( 'educbt_delete_submission' ); ?>
                                <button type="submit" class="educbt-btn" style="color:var(--edu-danger,#dc2626);font-size:.8rem;padding:2px 8px" onclick="return confirm('Are you sure you want to delete this submission?');">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>
    <?php
};

require EDUCBT_PRO_PATH . 'templates/portal/shell.php';
