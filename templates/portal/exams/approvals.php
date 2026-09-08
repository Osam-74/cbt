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

$educbt_body = static function () use ( $flash, $submissions, $quotas, $school_id ): void {
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

    <?php
    // Questions cannot be recomputed from anything else, so the school gets an
    // explicit way to protect them rather than relying on the automatic snapshots
    // taken at submission and approval.
    $vault_inventory = ( new \EduCBTPro\Services\QuestionVaultService() )->inventory( $school_id );
    $vault_missing   = count( array_filter( $vault_inventory, static fn( array $e ): bool => ! $e['present'] ) );
    $vault_questions = array_sum( array_column( $vault_inventory, 'questions' ) );
    ?>
    <section class="educbt-card">
        <h2>Question bank safekeeping</h2>

        <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:12px">
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="educbt_vault_backup">
                <?php wp_nonce_field( 'educbt_vault_backup' ); ?>
                <button type="submit" class="educbt-btn educbt-btn--primary">Back up question bank now</button>
            </form>

            <?php if ( $vault_missing > 0 ) : ?>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
                      onsubmit="return confirm('Restore missing questions from safe storage? Questions already in the bank are left untouched.');">
                    <input type="hidden" name="action" value="educbt_vault_restore">
                    <?php wp_nonce_field( 'educbt_vault_restore' ); ?>
                    <button type="submit" class="educbt-btn">Restore missing questions</button>
                </form>
            <?php endif; ?>
        </div>

    </section>

    <section class="educbt-card">
        <h2>Submissions</h2>

        <?php if ( empty( $submissions ) ) : ?>
            <p class="educbt-muted">No questions have been submitted yet.</p>
        <?php else : ?>
        <div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:12px;align-items:flex-end">
            <div>
                <label class="educbt-muted" style="font-size:.8rem;display:block;margin-bottom:3px">Filter by Type</label>
                <select id="filter-exam-type" class="educbt-input" style="width:auto" onchange="filterSubmissions()">
                    <option value="">All Types</option>
                    <option value="Examination">Examination</option>
                    <option value="CA Test">CA Test</option>
                    <option value="Practice Exam">Practice Exam</option>
                </select>
            </div>
            <div>
                <label class="educbt-muted" style="font-size:.8rem;display:block;margin-bottom:3px">Filter by Delivery</label>
                <select id="filter-delivery" class="educbt-input" style="width:auto" onchange="filterSubmissions()">
                    <option value="">All Modes</option>
                    <option value="cbt">CBT</option>
                    <option value="written">Written</option>
                </select>
            </div>
            <div>
                <label class="educbt-muted" style="font-size:.8rem;display:block;margin-bottom:3px">Filter by Class</label>
                <select id="filter-class" class="educbt-input" style="width:auto" onchange="filterSubmissions()">
                    <option value="">All Classes</option>
                    <?php
                    $filter_classes = array_unique( array_filter( array_map( fn($s) => (string) ( $s['level_name'] ?? '' ), $submissions ) ) );
                    sort( $filter_classes );
                    foreach ( $filter_classes as $fc ) :
                    ?>
                        <option value="<?php echo esc_attr( $fc ); ?>"><?php echo esc_html( $fc ); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <script>
        function filterSubmissions() {
            var typeFilter = document.getElementById('filter-exam-type').value.toLowerCase();
            var deliveryFilter = document.getElementById('filter-delivery').value.toLowerCase();
            var classFilter = document.getElementById('filter-class').value;
            var rows = document.querySelectorAll('#submissions-table tbody tr');
            rows.forEach(function(row) {
                var typeCell = row.cells[3] ? row.cells[3].textContent.trim().toLowerCase() : '';
                var deliveryCell = row.cells[4] ? row.cells[4].textContent.trim().toLowerCase() : '';
                var classCell = row.cells[2] ? row.cells[2].textContent.trim() : '';
                var typeMatch = !typeFilter || typeCell.indexOf(typeFilter) !== -1;
                var deliveryMatch = !deliveryFilter || deliveryCell.indexOf(deliveryFilter) !== -1;
                var classMatch = !classFilter || classCell === classFilter || classCell.indexOf(classFilter) !== -1;
                row.style.display = (typeMatch && deliveryMatch && classMatch) ? '' : 'none';
            });
        }
        </script>
            <table class="educbt-table" id="submissions-table">
                <thead>
                    <tr>
                        <th>Teacher</th>
                        <th>Subject</th>
                        <th>Class</th>
                        <th>Type</th>
                        <th>Delivery</th>
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
                    $set_ids_csv = implode( ',', array_map( 'absint', (array) ( $sub['set_ids'] ?? [] ) ) );
                    ?>
                    <tr id="row-<?php echo esc_attr( $row_key ); ?>">
                        <td>
                            <?php echo esc_html( (string) ( $sub['teacher_name'] ?? '' ) ); ?>
                            <?php if ( ! empty( $sub['creator_name'] ) && $sub['creator_name'] !== ( $sub['teacher_name'] ?? '' ) ) : ?>
                                <span class="educbt-muted" style="font-size:.7rem;display:block" title="Started by <?php echo esc_attr( $sub['creator_name'] ); ?>">
                                    (started by <?php echo esc_html( $sub['creator_name'] ); ?>)
                                </span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html( (string) ( $sub['subject_name'] ?? '' ) ); ?></td>
                        <td><?php echo esc_html( (string) ( $sub['level_name'] ?? '' ) ); ?></td>
                        <td>
                            <?php
                            $exam_type_label = (string) ( $sub['exam_type_label'] ?? 'Examination' );
                            $type_pill_class = $exam_type_label === 'CA Test' ? 'educbt-pill--submitted'
                                : ( $exam_type_label === 'Practice Exam' ? 'educbt-pill--draft' : 'educbt-pill--approved' );
                            ?>
                            <span class="educbt-pill <?php echo esc_attr( $type_pill_class ); ?>" style="font-size:.72rem"><?php echo esc_html( $exam_type_label ); ?></span>
                        </td>
                        <td>
                            <?php if ( (string) ( $sub['delivery_mode'] ?? 'cbt' ) === 'written' ) : ?>
                                <span class="educbt-pill" style="background:#FEF3C7;color:#92400E;border:1px solid #FCD34D">Written</span>
                            <?php else : ?>
                                <span class="educbt-muted">CBT</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ( (string) ( $sub['delivery_mode'] ?? 'cbt' ) === 'written' ) : ?>
                                <span class="educbt-muted">—</span>
                            <?php else : ?>
                                <?php echo esc_html( (string) ( $sub['objective'] ?? 0 ) ); ?>
                                <?php if ( ! empty( $sub['short_objective'] ) && (int) $sub['short_objective'] > 0 ) : ?>
                                    <span class="educbt-pill educbt-pill--draft"><?php echo esc_html( (string) $sub['short_objective'] ); ?> below minimum</span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ( (string) ( $sub['delivery_mode'] ?? 'cbt' ) === 'written' ) : ?>
                                <span class="educbt-muted">—</span>
                            <?php else : ?>
                                <?php echo esc_html( (string) ( $sub['theory'] ?? 0 ) ); ?>
                                <?php if ( ! empty( $sub['short_theory'] ) && (int) $sub['short_theory'] > 0 ) : ?>
                                    <span class="educbt-pill educbt-pill--draft"><?php echo esc_html( (string) $sub['short_theory'] ); ?> below minimum</span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ( (string) ( $sub['delivery_mode'] ?? 'cbt' ) === 'written' ) : ?>
                                <?php
                                $written_status = strtolower( trim( (string) ( $sub['objective_status'] ?? $sub['theory_status'] ?? '' ) ) );
                                switch ( $written_status ) {
                                    case 'submitted':
                                    case 'under_review':
                                    case 'pending':
                                        $w_text = 'awaiting review';
                                        $w_cls  = 'educbt-pill--submitted';
                                        break;
                                    case 'approved':
                                        $w_text = 'approved';
                                        $w_cls  = 'educbt-pill--approved';
                                        break;
                                    case 'returned':
                                    case 'revision':
                                        $w_text = 'sent back';
                                        $w_cls  = 'educbt-pill--draft';
                                        break;
                                    default:
                                        $w_text = 'not started';
                                        $w_cls  = 'educbt-pill--draft';
                                        break;
                                }
                                ?>
                                <span class="educbt-pill <?php echo esc_attr( $w_cls ); ?>">Written: <?php echo esc_html( $w_text ); ?></span>
                            <?php else : ?>
                                <div style="display:flex;flex-direction:column;gap:4px;align-items:flex-start">
                                    <span class="educbt-pill <?php echo esc_attr( $obj_status['class'] ); ?>"><?php echo esc_html( $obj_status['label'] ); ?></span>
                                    <span class="educbt-pill <?php echo esc_attr( $thy_status['class'] ); ?>"><?php echo esc_html( $thy_status['label'] ); ?></span>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html( $submitted_display ); ?></td>
                        <td style="white-space:nowrap">
                            <button type="button"
                                class="educbt-btn educbt-toggle-review"
                                data-subject="<?php echo (int) ( $sub['subject_id'] ?? 0 ); ?>"
                                data-staff="<?php echo (int) ( $sub['staff_id'] ?? 0 ); ?>"
                                data-level="<?php echo (int) ( $sub['level_id'] ?? 0 ); ?>"
                                data-sets="<?php echo esc_attr( $set_ids_csv ); ?>"
                                data-delivery="<?php echo esc_attr( (string) ( $sub['delivery_mode'] ?? 'cbt' ) ); ?>"
                                data-target="review-<?php echo esc_attr( $row_key ); ?>"
                                onclick="toggleReview(this)">Review</button>

                            <?php
                            // The Remind button nudges a teacher who still has
                            // questions to submit or fix. It must NOT appear once
                            // the submission is approved — there is nothing to
                            // remind them about.
                            $show_remind = empty( $sub['complete'] ) && empty( $sub['all_approved'] );

                            // For written (non-CBT) papers, also check the written
                            // status directly — the all_approved flag counts
                            // individual question records, but a written paper
                            // may carry its approval at the set level.
                            if ( $show_remind && (string) ( $sub['delivery_mode'] ?? 'cbt' ) === 'written' ) {
                                $written_status = strtolower( trim( (string) ( $sub['objective_status'] ?? $sub['theory_status'] ?? '' ) ) );
                                if ( $written_status === 'approved' ) {
                                    $show_remind = false;
                                }
                            }

                            // For CBT papers, also hide the button when BOTH
                            // objective and theory are individually approved.
                            if ( $show_remind && (string) ( $sub['delivery_mode'] ?? 'cbt' ) !== 'written' ) {
                                $obj_ok = strtolower( trim( (string) ( $sub['objective_status'] ?? '' ) ) ) === 'approved';
                                $thy_ok = strtolower( trim( (string) ( $sub['theory_status'] ?? '' ) ) ) === 'approved';
                                // If both parts are approved, or if the only
                                // applicable part is approved, no reminder needed.
                                $has_obj = (int) ( $sub['objective'] ?? 0 ) > 0;
                                $has_thy = (int) ( $sub['theory'] ?? 0 ) > 0;
                                if ( $has_obj && $has_thy && $obj_ok && $thy_ok ) {
                                    $show_remind = false;
                                } elseif ( $has_obj && ! $has_thy && $obj_ok ) {
                                    $show_remind = false;
                                } elseif ( $has_thy && ! $has_obj && $thy_ok ) {
                                    $show_remind = false;
                                }
                            }

                            if ( $show_remind ) : ?>
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

    <!-- Review panel — renders BELOW the table, not inside it -->
    <section class="educbt-card" id="review-panel" style="display:none">
        <div id="review-panel-content">
            <div class="review-loading" style="text-align:center;padding:20px;color:var(--edu-muted)">Loading questions…</div>
        </div>
    </section>

    <script>
    (function() {
        var nonce = '<?php echo esc_js( wp_create_nonce( 'wp_rest' ) ); ?>';
        var loaded = {};
        var activeBtn = null;

        window.toggleReview = function(btn) {
            var targetId = btn.getAttribute('data-target');
            var panel = document.getElementById('review-panel');
            var panelContent = document.getElementById('review-panel-content');

            // If clicking the same button that's already open, close it.
            if (activeBtn === btn && panel.style.display !== 'none') {
                panel.style.display = 'none';
                btn.textContent = 'Review';
                activeBtn = null;
                return;
            }

            // Show the panel below the table.
            activeBtn = btn;
            panel.style.display = 'block';
            btn.textContent = 'Hide';

            // Scroll the panel into view.
            panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });

            if (!loaded[targetId]) {
                loadReviewQuestions(btn, panelContent);
            }
        };

        function loadReviewQuestions(btn, container) {
            var subjectId = btn.getAttribute('data-subject');
            var staffId   = btn.getAttribute('data-staff');
            var levelId   = btn.getAttribute('data-level');
            var setIds    = btn.getAttribute('data-sets');
            var delivery  = btn.getAttribute('data-delivery') || 'cbt';

            // Written submissions have no questions to review — just an intent
            // that needs to be approved so the paper can move to the timetable.
            if (delivery === 'written') {
                loaded[btn.getAttribute('data-target')] = true;
                renderWrittenIntent(container, subjectId, staffId, setIds);
                return;
            }

            var url = '<?php echo esc_url_raw( rest_url( "educbt/v1/review-queue" ) ); ?>'
                + '?subject_id=' + encodeURIComponent(subjectId)
                + '&staff_id=' + encodeURIComponent(staffId)
                + '&level_id=' + encodeURIComponent(levelId)
                + '&set_ids=' + encodeURIComponent(setIds);

            fetch(url, {
                headers: { 'X-WP-Nonce': nonce }
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                loaded[btn.getAttribute('data-target')] = true;
                renderReviewQuestions(container, data.questions || [], subjectId, staffId, setIds);
            })
            .catch(function() {
                container.innerHTML = '<p style="color:red">Could not load questions. Please try again.</p>';
            });
        }

        function renderWrittenIntent(container, subjectId, staffId, setIds) {
            var html = '<div style="padding:10px 0">'
                + '<h3 style="margin:0 0 8px;font-size:1rem">Written Examination Intent</h3>'
                + '<p class="educbt-muted" style="margin-bottom:12px">This subject will be examined on paper. There are no questions to review \u2014 approve the intent to allow the paper to be scheduled on the timetable.</p>'
                + '<div style="display:flex;flex-wrap:wrap;gap:10px;margin-top:12px;align-items:flex-end">'
                + '<input type="hidden" class="decide-subject" value="' + subjectId + '">'
                + '<input type="hidden" class="decide-staff" value="' + staffId + '">'
                + '<input type="hidden" class="decide-sets" value="' + setIds + '">'
                + '<div style="flex:1;min-width:200px"><label class="educbt-muted" style="font-size:.8rem">Reviewer note (required for send back)</label>'
                + '<textarea class="educbt-input decide-note" style="width:100%;min-height:40px;font-size:.85rem" placeholder="Explain what needs fixing..."></textarea></div>'
                + '<button type="button" class="educbt-btn educbt-btn--primary" style="background:#16a34a" onclick="decideReview(this,\'approve_all\')">Approve Written Intent</button>'
                + '<button type="button" class="educbt-btn" style="color:#dc2626;border-color:#dc2626" onclick="decideReview(this,\'revision\')">Send Back</button>'
                + '</div></div>';
            container.innerHTML = html;
        }

        function esc(s) {
            var d = document.createElement('div');
            d.textContent = s || '';
            return d.innerHTML;
        }

        function renderReviewQuestions(container, questions, subjectId, staffId, setIds) {
            if (!questions.length) {
                container.innerHTML = '<p class="educbt-muted">No questions found for this submission.</p>';
                return;
            }

            // Objective and theory are two different papers and are marked
            // differently. Split them, and give each its own approval box so
            // the reviewer can approve objective and theory independently.
            var groups = [
                { key: 'objective', label: 'Objective', items: questions.filter(function(q) { return q.question_type !== 'theory'; }) },
                { key: 'theory',    label: 'Theory',    items: questions.filter(function(q) { return q.question_type === 'theory'; }) }
            ];

            var pendingCount = questions.filter(function(q) {
                return q.approval_status !== 'approved' && q.approval_status !== 'revision';
            }).length;

            var html = '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;flex-wrap:wrap;gap:8px">'
                + '<h3 style="margin:0;font-size:1rem">Questions (' + questions.length + ') \u2014 '
                + pendingCount + ' awaiting your decision</h3>'
                + '</div>';

            // Hidden inputs shared by both groups
            html += '<input type="hidden" class="decide-subject" value="' + subjectId + '">';
            html += '<input type="hidden" class="decide-staff" value="' + staffId + '">';
            html += '<input type="hidden" class="decide-sets" value="' + setIds + '">';

            var counter = 0;

            groups.forEach(function(group) {
                html += '<div class="review-group" data-group="' + group.key + '" style="margin-top:12px;border:1px solid var(--edu-line);border-radius:10px;padding:12px">';

                if (!group.items.length) {
                    html += '<div style="padding:8px 10px">'
                        + '<strong>' + group.label + '</strong> '
                        + '<span class="educbt-muted">\u2014 none submitted</span></div>';
                    html += '</div>';
                    return;
                }

                var groupPending = group.items.filter(function(q) {
                    return q.approval_status !== 'approved' && q.approval_status !== 'revision';
                }).length;

                html += '<div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:8px">'
                    + '<h4 style="margin:0;font-size:.95rem">' + group.label + '</h4>'
                    + '<span class="educbt-pill educbt-pill--draft" style="font-size:.7rem">' + group.items.length + ' question(s)</span>'
                    + (groupPending ? '<span class="educbt-pill educbt-pill--submitted" style="font-size:.7rem">' + groupPending + ' pending</span>' : '')
                    + '<button type="button" class="educbt-btn" style="font-size:.8rem;padding:2px 8px" onclick="selectAllReview(this)">Select All</button>'
                    + '</div>';

                html += '<div style="max-height:400px;overflow-y:auto;border:1px solid var(--edu-line);border-radius:8px">';

                group.items.forEach(function(q) {
                    counter++;
                    var isObj = q.question_type !== 'theory';
                    var statusClass = q.approval_status === 'approved' ? 'educbt-pill--approved'
                        : (q.approval_status === 'revision' ? 'educbt-pill--draft' : 'educbt-pill--submitted');
                    var statusText = q.approval_status === 'approved' ? 'approved'
                        : (q.approval_status === 'revision' ? 'sent back' : 'awaiting decision');

                    html += '<div style="display:flex;gap:8px;padding:10px;border-bottom:1px solid var(--edu-line);align-items:flex-start">';
                    html += '<input type="checkbox" class="review-q-check" value="' + q.id + '" style="margin-top:4px">';
                    html += '<div style="flex:1">';
                    html += '<div style="display:flex;align-items:center;gap:6px;margin-bottom:4px;flex-wrap:wrap">';
                    html += '<span style="font-weight:700;color:var(--edu-muted)">' + counter + '.</span>';
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

                    if (q.sub_items && q.sub_items.length) {
                        html += '<div style="display:flex;flex-direction:column;gap:4px;margin-left:12px;margin-top:4px">';
                        q.sub_items.forEach(function(sub, si) {
                            var label = sub.label ? esc(sub.label) + ' ' : '(' + String.fromCharCode(97 + si) + ') ';
                            html += '<div style="font-size:.85rem;padding:4px 8px;background:rgba(0,0,0,.03);border-radius:4px">'
                                + '<strong>' + label + '</strong>'
                                + esc(sub.text || '')
                                + ' <span class="educbt-muted" style="font-size:.8rem">[' + (sub.marks || 0) + ' marks]</span>'
                                + '</div>';
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

                // Per-group decision bar
                html += '<div style="display:flex;flex-wrap:wrap;gap:10px;margin-top:10px;align-items:flex-end">';
                html += '<div style="flex:1;min-width:200px"><label class="educbt-muted" style="font-size:.8rem">' + group.label + ' reviewer note (required for send back)</label>';
                html += '<textarea class="educbt-input decide-note" style="width:100%;min-height:36px;font-size:.85rem" placeholder="Explain what needs fixing..."></textarea></div>';
                html += '<button type="button" class="educbt-btn educbt-btn--primary" style="background:#16a34a" onclick="decideReview(this,\'approve\')">Approve Selected</button>';
                html += '<button type="button" class="educbt-btn educbt-btn--primary" style="background:#16a34a" onclick="decideReview(this,\'approve_all\')">Approve All ' + group.label + '</button>';
                html += '<button type="button" class="educbt-btn" style="color:#dc2626;border-color:#dc2626" onclick="decideReview(this,\'revision\')">Send Back</button>';
                html += '</div>';

                html += '</div>';
            });

            container.innerHTML = html;
        }

        window.selectAllReview = function(btn) {
            var group = btn.closest('.review-group');
            if (!group) return;
            var checks = group.querySelectorAll('.review-q-check');
            if (!checks.length) return;
            var allChecked = Array.prototype.every.call(checks, function(c) { return c.checked; });
            Array.prototype.forEach.call(checks, function(c) { c.checked = !allChecked; });
            btn.textContent = allChecked ? 'Select All' : 'Deselect All';
        };

        window.decideReview = function(btn, action) {
            var container = document.getElementById('review-panel-content');
            var group     = btn.closest('.review-group');
            var subjectId = container.querySelector('.decide-subject').value;
            var staffId   = container.querySelector('.decide-staff').value;
            var setIds    = container.querySelector('.decide-sets') ? container.querySelector('.decide-sets').value : '';
            var note      = group && group.querySelector('.decide-note') ? group.querySelector('.decide-note').value : '';
            var questionIds = [];

            if (action === 'approve_all') {
                if (group) {
                    group.querySelectorAll('.review-q-check').forEach(function(c) { questionIds.push(parseInt(c.value)); });
                }
            } else {
                if (group) {
                    group.querySelectorAll('.review-q-check:checked').forEach(function(c) { questionIds.push(parseInt(c.value)); });
                }
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

            var origText = btn.textContent;
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
                    question_ids: questionIds,
                    set_ids: setIds
                })
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) {
                    var msg = (data.sets || 0) > 0
                        ? 'Approved \u2014 ' + (data.sets) + ' set(s) updated.'
                        : 'Done \u2014 ' + (data.changed || 0) + ' question(s) updated.';
                    alert(msg);
                    window.location.reload();
                } else {
                    alert(data.message || 'Something went wrong.');
                    btn.disabled = false;
                    btn.textContent = origText;
                }
            })
            .catch(function() {
                alert('Network error. Please try again.');
                btn.disabled = false;
                btn.textContent = origText;
            });
        };
    })();
    </script>
    <?php
};

require EDUCBT_PRO_PATH . 'templates/portal/shell.php';
