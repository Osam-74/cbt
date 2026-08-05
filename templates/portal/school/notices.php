<?php
/**
 * Send a notice to teaching staff.
 *
 * Pick a template, and the people it is meant for are ticked automatically —
 * "your submission is incomplete" sent to teachers whose submission is complete is
 * noise, and noise is how staff learn to ignore notifications.
 *
 * @var array<string,mixed> $educbt
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

$school_id = (int) $educbt['school_id'];
$flash     = \EduCBTPro\Frontend\PortalActions::flash();

$school_svc     = new \EduCBTPro\Services\SchoolService();
$exam_prep_open = $school_svc->is_exam_prep_enabled( $school_id );

$notices   = new \EduCBTPro\Services\StaffNoticeService();
$teachers  = $notices->teachers( $school_id );
$templates = \EduCBTPro\Services\StaffNoticeService::templates();

$audiences = [];

foreach ( array_keys( $templates ) as $key ) {
    $audiences[ $key ] = $notices->audience_for( $key, $teachers );
}

$educbt_title = 'Notify Staff';

$educbt_body = static function () use ( $flash, $teachers, $templates, $audiences, $exam_prep_open ): void {
    require EDUCBT_PRO_PATH . 'templates/portal/partials/flash.php';
    ?>
    <section class="educbt-card" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px">
        <div>
            <h2 style="margin:0">Exam Preparation</h2>
            <p class="educbt-muted" style="margin:4px 0 0">When open, teachers can submit questions to the question bank.</p>
        </div>
        <div style="display:flex;align-items:center;gap:12px">
            <span class="educbt-pill educbt-pill--<?php echo $exam_prep_open ? 'approved' : 'draft'; ?>"><?php echo $exam_prep_open ? 'Open' : 'Closed'; ?></span>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="educbt_toggle_exam_prep">
                <?php wp_nonce_field( 'educbt_toggle_exam_prep' ); ?>
                <button type="submit" class="educbt-btn <?php echo $exam_prep_open ? '' : 'educbt-btn--primary'; ?>">
                    <?php echo $exam_prep_open ? 'Close submission' : 'Open submission'; ?>
                </button>
            </form>
        </div>
    </section>

    <?php
    if ( empty( $teachers ) ) {
        echo '<div class="educbt-card"><p class="educbt-muted">No staff have portal accounts yet.</p></div>';
        return;
    }
    ?>
    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="educbt-form">
        <input type="hidden" name="action" value="educbt_send_staff_notice">
        <?php wp_nonce_field( 'educbt_send_staff_notice' ); ?>

        <section class="educbt-card">
            <h2>What are you sending?</h2>

            <label for="template">Message</label>
            <select id="template" name="template" onchange="educbtPickTemplate(this.value)">
                <?php foreach ( $templates as $key => $tpl ) : ?>
                    <option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $tpl['label'] ); ?></option>
                <?php endforeach; ?>
            </select>

            <label for="subject" style="margin-top:12px">Subject</label>
            <input id="subject" name="subject" type="text" required>

            <label for="body" style="margin-top:12px">Message</label>
            <textarea id="body" name="body" rows="6" required></textarea>

            <p class="educbt-muted" style="margin-top:8px">
                Edit the wording freely — the template is a starting point, not a rule.
            </p>
        </section>

        <section class="educbt-card">
            <h2>Who gets it <span class="educbt-muted">(<span id="recipient-count">0</span> selected)</span></h2>

            <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px">
                <button type="button" class="educbt-btn" onclick="educbtSelectAll(true)">Select all</button>
                <button type="button" class="educbt-btn" onclick="educbtSelectAll(false)">Clear</button>
                <button type="button" class="educbt-btn" onclick="educbtApplyTemplateAudience()">Reset to who it is for</button>
            </div>

            <table class="educbt-table">
                <thead><tr><th style="width:40px"></th><th>Teacher</th><th>Subjects</th><th>Questions</th><th>Status</th></tr></thead>
                <tbody>
                <?php foreach ( $teachers as $t ) : ?>
                    <tr>
                        <td><input type="checkbox" name="staff_ids[]" value="<?php echo esc_attr( (string) $t['staff_id'] ); ?>"
                                   class="notice-pick" style="width:auto" onchange="educbtCount()"></td>
                        <td><?php echo esc_html( (string) $t['name'] ); ?></td>
                        <td><?php echo esc_html( (string) $t['subjects'] ); ?></td>
                        <td><?php echo esc_html( (string) $t['questions'] ); ?></td>
                        <td>
                            <?php if ( ! empty( $t['nothing'] ) ) : ?>
                                <span class="educbt-pill educbt-pill--draft">nothing submitted</span>
                            <?php elseif ( ! empty( $t['incomplete'] ) ) : ?>
                                <span class="educbt-pill educbt-pill--submitted">below minimum</span>
                            <?php else : ?>
                                <span class="educbt-pill educbt-pill--approved">complete</span>
                            <?php endif; ?>
                            <?php if ( ! empty( $t['marking'] ) ) : ?>
                                <span class="educbt-pill educbt-pill--draft">marking outstanding</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <button type="submit" class="educbt-btn educbt-btn--primary" style="margin-top:14px">Send notice</button>
        </section>
    </form>

    <script>
    var educbtTemplates = <?php echo wp_json_encode( $templates ); ?>;
    var educbtAudiences = <?php echo wp_json_encode( $audiences ); ?>;

    function educbtCount() {
        document.getElementById('recipient-count').textContent =
            document.querySelectorAll('.notice-pick:checked').length;
    }

    function educbtSelectAll(state) {
        document.querySelectorAll('.notice-pick').forEach(function (c) { c.checked = state; });
        educbtCount();
    }

    /* Tick exactly the people the chosen message is aimed at. The sender can still
       change any of it — this is a sensible default, not a lock. */
    function educbtApplyTemplateAudience() {
        var key = document.getElementById('template').value;
        var ids = (educbtAudiences[key] || []).map(String);

        document.querySelectorAll('.notice-pick').forEach(function (c) {
            c.checked = ids.indexOf(c.value) !== -1;
        });

        educbtCount();
    }

    function educbtPickTemplate(key) {
        var tpl = educbtTemplates[key];
        if (!tpl) { return; }

        // Never overwrite wording someone has already typed.
        var subject = document.getElementById('subject');
        var body = document.getElementById('body');

        if (!subject.dataset.touched) { subject.value = tpl.subject; }
        if (!body.dataset.touched) { body.value = tpl.body; }

        educbtApplyTemplateAudience();
    }

    document.getElementById('subject').addEventListener('input', function () { this.dataset.touched = '1'; });
    document.getElementById('body').addEventListener('input', function () { this.dataset.touched = '1'; });

    educbtPickTemplate(document.getElementById('template').value);
    </script>
    <?php
};

require EDUCBT_PRO_PATH . 'templates/portal/shell.php';
