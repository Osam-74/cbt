<?php
/**
 * Teacher → Remarks — class teachers set up auto-remark ranges for their own
 * class-teacher role, and can also override individual student remarks from
 * the report-print page.
 *
 * @var array<string,mixed> $educbt
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

global $wpdb;

$school_id      = (int) ( $educbt['school_id'] ?? 0 );
$remark_service = new \EduCBTPro\Services\RemarkService();

$active_role = 'class_teacher';

$ranges = $remark_service->ranges( $school_id, $active_role );

if ( empty( $ranges ) ) {
    $remark_service->seed_defaults( $school_id, $active_role );
    $ranges = $remark_service->ranges( $school_id, $active_role );
}

$educbt_title = 'Remarks';
$educbt_body  = static function() use ( $school_id, $ranges, $active_role ) {
    $flash = \EduCBTPro\Frontend\PortalActions::flash();
    if ( ! empty( $flash['error'] ) ) {
        echo '<div class="educbt-flash educbt-flash--error">' . esc_html( $flash['error'] ) . '</div>';
    }
    if ( ! empty( $flash['result'] ) && ( $flash['result']['type'] ?? '' ) === 'saved' ) {
        echo '<div class="educbt-flash educbt-flash--success">Remark ranges saved (' . esc_html( (string) ( $flash['result']['count'] ?? 0 ) ) . ' ranges).</div>';
    }
    if ( ! empty( $flash['result'] ) && ( $flash['result']['type'] ?? '' ) === 'deleted' ) {
        echo '<div class="educbt-flash educbt-flash--success">Remark range deleted.</div>';
    }
    ?>
    <p class="educbt-help">
        Set up the remarks that are automatically applied to students based on their average score.
        When results are compiled, each student gets the remark matching their average.
        You can override individual ones on the report sheet.
    </p>

    <h3>Class Teacher Remarks</h3>

    <table class="educbt-table" style="margin-bottom:24px;">
        <thead>
            <tr><th>Min %</th><th>Max %</th><th>Remark</th><th>Action</th></tr>
        </thead>
        <tbody>
        <?php foreach ( $ranges as $range ) : ?>
            <tr>
                <td><?php echo esc_html( (string) (float) $range['min_avg'] ); ?></td>
                <td><?php echo esc_html( (string) (float) $range['max_avg'] ); ?></td>
                <td><?php echo esc_html( $range['remark'] ); ?></td>
                <td>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;" onsubmit="return confirm('Delete this remark range?');">
                        <?php wp_nonce_field( 'educbt_delete_remark_range' ); ?>
                        <input type="hidden" name="action" value="educbt_delete_remark_range">
                        <input type="hidden" name="range_id" value="<?php echo esc_attr( $range['id'] ); ?>">
                        <button type="submit" class="educbt-btn educbt-btn--sm educbt-btn--danger">Delete</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if ( empty( $ranges ) ) : ?>
            <tr><td colspan="4" style="text-align:center;color:#94a3b8;">No remark ranges yet.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>

    <h3>Add Remark Ranges</h3>
    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="educbt-form">
        <?php wp_nonce_field( 'educbt_save_remarks' ); ?>
        <input type="hidden" name="action" value="educbt_save_remarks">
        <input type="hidden" name="roles[]" value="<?php echo esc_attr( $active_role ); ?>">

        <div id="remark-rows">
            <div class="educbt-grid educbt-grid--3 remark-row" style="align-items:end;">
                <div class="educbt-field">
                    <label>Min Average %</label>
                    <input type="number" name="min_avgs[]" min="0" max="100" step="1" placeholder="0">
                </div>
                <div class="educbt-field">
                    <label>Max Average %</label>
                    <input type="number" name="max_avgs[]" min="0" max="100" step="1" placeholder="39">
                </div>
                <div class="educbt-field">
                    <label>Remark</label>
                    <textarea name="remarks[]" rows="2" placeholder="A poor result. Work harder."></textarea>
                </div>
            </div>
        </div>

        <button type="button" id="add-remark-row" class="educbt-btn educbt-btn--sm" style="margin:8px 0;">+ Add another range</button>
        <button type="submit" class="educbt-btn educbt-btn--primary">Save Ranges</button>
    </form>

    <script>
    (function() {
        var container = document.getElementById('remark-rows');
        var btn = document.getElementById('add-remark-row');
        if (!btn || !container) return;
        btn.addEventListener('click', function() {
            var firstRow = container.querySelector('.remark-row');
            if (!firstRow) return;
            var clone = firstRow.cloneNode(true);
            clone.querySelectorAll('input, textarea').forEach(function(el) { el.value = ''; });
            container.appendChild(clone);
        });
    })();
    </script>
    <?php
};

require EDUCBT_PRO_PATH . 'templates/portal/shell.php';
