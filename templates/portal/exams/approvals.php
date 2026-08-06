<?php
/**
 * Question approval — the exam officer's or principal's review screen.
 *
 * Shows every teacher's submission against the quota, so a reviewer can see at a
 * glance who is short and who is waiting, then expand one submission to review
 * the questions inline — checkboxes, approve or send back, right here.
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
                        $submitted_display = mysql2date( 'M j, g:i A', $submitted_at_raw );
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

                    $row_key = 'sub-' . (int) ( $sub['subject_id'] ?? 0 ) . '-' . (int) ( $sub['staff_id'] ?? 0 ) . '-' . (int) ( $sub['level_id'] ?? 0 );
                    ?>
                    <tr id="row-<?php echo esc_attr( $row_key ); ?>">
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
                            <button type="button"
                                class="educbt-btn educbt-toggle-review"
                                data-subject="<?php echo (int) ( $sub['subject_id'] ?? 0 ); ?>"
                                data-staff="<?php echo (int) ( $sub['staff_id'] ?? 0 ); ?>"
                                data-level="<?php echo (int) ( $sub['level_id'] ?? 0 ); ?>"
                                data-target="review-<?php echo esc_attr( $row_key ); ?>"
                                onclick="toggleReview(this)">Review</button>

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
                    <tr id="review-<?php echo esc_attr( $row_key ); ?>" style="display:none">
                        <td colspan="8" style="padding:0;border-top:none">
                            <div class="educbt-review-inline" style="padding:12px;background:var(--edu-surface-alt,#f9fafb);border-top:2px solid var(--edu-line)">
                                <div class="review-loading" style="text-align:center;padding:20px;color:var(--edu-muted)">Loading questions…</div>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>

    <script>
    (function() {
        var nonce = '<?php echo esc_js( wp_create_nonce( 'wp_rest' ) ); ?>';
        var loaded = {};

        window.toggleReview = function(btn) {
            var targetId = btn.getAttribute('data-target');
            var targetRow = document.getElementById(targetId);
            if (!targetRow) return;

            if (targetRow.style.display === 'none') {
                targetRow.style.display = 'table-row';
                btn.textContent = 'Hide';
                if (!loaded[targetId]) {
                    loadReviewQuestions(btn, targetRow);
                }
            } else {
                targetRow.style.display = 'none';
                btn.textContent = 'Review';
            }
        };

        function loadReviewQuestions(btn, targetRow) {
            var subjectId = btn.getAttribute('data-subject');
            var staffId   = btn.getAttribute('data-staff');
            var levelId   = btn.getAttribute('data-level');
            var container = targetRow.querySelector('.educbt-review-inline');

            var url = '<?php echo esc_url_raw( rest_url( "educbt/v1/review-queue" ) ); ?>'
                + '?subject_id=' + encodeURIComponent(subjectId)
                + '&staff_id=' + encodeURIComponent(staffId)
                + '&level_id=' + encodeURIComponent(levelId);

            fetch(url, {
                headers: { 'X-WP-Nonce': nonce }
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                loaded[btn.getAttribute('data-target')] = true;
                renderReviewQuestions(container, data.questions || [], subjectId, staffId);
            })
            .catch(function() {
                container.innerHTML = '<p style="color:red">Could not load questions. Please try again.</p>';
            });
        }

        function esc(s) {
            var d = document.createElement('div');
            d.textContent = s || '';
            return d.innerHTML;
        }

        function renderReviewQuestions(container, questions, subjectId, staffId) {
            if (!questions.length) {
                container.innerHTML = '<p class="educbt-muted">No questions found for this submission.</p>';
                return;
            }

            var html = '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">'
                + '<h3 style="margin:0;font-size:1rem">Questions (' + questions.length + ')</h3>'
                + '<div style="display:flex;gap:6px">'
                + '<button type="button" class="educbt-btn" onclick="selectAllReview(this)">Select All</button>'
                + '</div></div>';

            html += '<div style="max-height:500px;overflow-y:auto;border:1px solid var(--edu-line);border-radius:8px">';
            questions.forEach(function(q, i) {
                var isObj = q.question_type !== 'theory';
                var statusClass = q.approval_status === 'approved' ? 'educbt-pill--approved'
                    : (q.approval_status === 'revision' ? 'educbt-pill--draft' : 'educbt-pill--submitted');
                var statusText = q.approval_status === 'approved' ? 'approved'
                    : (q.approval_status === 'revision' ? 'sent back' : 'pending');

                html += '<div style="display:flex;gap:8px;padding:10px;border-bottom:1px solid var(--edu-line);align-items:flex-start">';
                html += '<input type="checkbox" class="review-q-check" value="' + q.id + '" style="margin-top:4px">';
                html += '<div style="flex:1">';
                html += '<div style="display:flex;align-items:center;gap:6px;margin-bottom:4px">';
                html += '<span style="font-weight:700;color:var(--edu-muted)">' + (i + 1) + '.</span>';
                html += '<span class="educbt-pill educbt-pill--draft" style="font-size:.7rem">' + esc(q.question_type) + '</span>';
                html += '<span class="educbt-pill ' + statusClass + '" style="font-size:.7rem">' + statusText + '</span>';
                if (!q.has_answer && isObj) {
                    html += '<span class="educbt-pill educbt-pill--draft" style="font-size:.7rem;color:#dc2626">no answer set</span>';
                }
                html += '</div>';
                html += '<p style="margin:0 0 6px">' + esc(q.question_text || '') + '</p>';

                if (isObj && q.options && q.options.length) {
                    html += '<div style="display:flex;flex-direction:column;gap:2px;margin-left:12px">';
                    q.options.forEach(function(opt, oi) {
                        var correct = parseInt(opt.is_correct) === 1;
                        html += '<div style="font-size:.85rem;' + (correct ? 'color:#16a34a;font-weight:600' : '') + '">';
                        html += String.fromCharCode(65 + oi) + '. ' + esc(opt.option_text || '');
                        if (correct) html += ' \u2713';
                        html += '</div>';
                    });
                    html += '</div>';
                }

                if (q.review_note) {
                    html += '<div style="margin-top:4px;padding:4px 8px;background:#fef3c7;border-radius:4px;font-size:.8rem"><strong>Reviewer note:</strong> ' + esc(q.review_note) + '</div>';
                }
                html += '</div>';
                html += '</div>';
            });
            html += '</div>';

            // Decision bar
            html += '<div style="display:flex;flex-wrap:wrap;gap:10px;margin-top:12px;align-items:flex-end">';
            html += '<input type="hidden" class="decide-subject" value="' + subjectId + '">';
            html += '<input type="hidden" class="decide-staff" value="' + staffId + '">';
            html += '<div style="flex:1;min-width:200px"><label class="educbt-muted" style="font-size:.8rem">Reviewer note (required for send back)</label>';
            html += '<textarea class="educbt-input decide-note" style="width:100%;min-height:40px;font-size:.85rem" placeholder="Explain what needs fixing..."></textarea></div>';
            html += '<button type="button" class="educbt-btn educbt-btn--primary" style="background:#16a34a" onclick="decideReview(this,\'approve\')">Approve Selected</button>';
            html += '<button type="button" class="educbt-btn educbt-btn--primary" style="background:#16a34a" onclick="decideReview(this,\'approve_all\')">Approve All</button>';
            html += '<button type="button" class="educbt-btn" style="color:#dc2626;border-color:#dc2626" onclick="decideReview(this,\'revision\')">Send Back</button>';
            html += '</div>';

            container.innerHTML = html;
        }

        window.selectAllReview = function(btn) {
            var container = btn.closest('.educbt-review-inline');
            var checks = container.querySelectorAll('.review-q-check');
            var allChecked = Array.prototype.every.call(checks, function(c) { return c.checked; });
            Array.prototype.forEach.call(checks, function(c) { c.checked = !allChecked; });
            btn.textContent = allChecked ? 'Select All' : 'Deselect All';
        };

        window.decideReview = function(btn, action) {
            var container = btn.closest('.educbt-review-inline');
            var subjectId = container.querySelector('.decide-subject').value;
            var staffId   = container.querySelector('.decide-staff').value;
            var note      = container.querySelector('.decide-note').value || '';
            var questionIds = [];

            if (action === 'approve_all') {
                container.querySelectorAll('.review-q-check').forEach(function(c) { questionIds.push(parseInt(c.value)); });
            } else {
                container.querySelectorAll('.review-q-check:checked').forEach(function(c) { questionIds.push(parseInt(c.value)); });
            }

            if (questionIds.length === 0 && action !== 'approve_all') {
                alert('Select at least one question first.');
                return;
            }

            var decision = action === 'revision' ? 'revision' : 'approve';

            if (decision === 'revision' && !note.trim()) {
                alert('You must write a note explaining what needs fixing before sending back.');
                return;
            }

            btn.disabled = true;
            btn.textContent = 'Working...';

            fetch('<?php echo esc_url_raw( rest_url( "educbt/v1/questions/decide" ) ); ?>', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': nonce
                },
                body: JSON.stringify({
                    subject_id: parseInt(subjectId),
                    staff_id: parseInt(staffId),
                    decision: decision,
                    note: note,
                    question_ids: questionIds
                })
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) {
                    alert('Done - ' + (data.changed || 0) + ' question(s) updated.');
                    window.location.reload();
                } else {
                    alert(data.message || 'Something went wrong.');
                    btn.disabled = false;
                    btn.textContent = action === 'approve' ? 'Approve Selected' : (action === 'approve_all' ? 'Approve All' : 'Send Back');
                }
            })
            .catch(function() {
                alert('Network error. Please try again.');
                btn.disabled = false;
                btn.textContent = action === 'approve' ? 'Approve Selected' : (action === 'approve_all' ? 'Approve All' : 'Send Back');
            });
        };
    })();
    </script>
    <?php
};

require EDUCBT_PRO_PATH . 'templates/portal/shell.php';
