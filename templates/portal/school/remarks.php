<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

$school_id = $educbt['school_id'] ?? 0;
$remark_service = new \EduCBTPro\Services\RemarkService();
$ranges = $remark_service->ranges( $school_id, 'principal' );

// Seed the five default bands if this school has no ranges yet
if ( empty( $ranges ) ) {
    $remark_service->seed_defaults( $school_id, 'principal' );
    $ranges = $remark_service->ranges( $school_id, 'principal' );
}

$educbt_title = 'Remarks';
$educbt_body = static function() use ( $ranges ) {
    $flash = \EduCBTPro\Frontend\PortalActions::flash();
    if ( ! empty( $flash['error'] ) ) {
        echo '<div class="educbt-flash educbt-flash--error">' . esc_html( $flash['error'] ) . '</div>';
    }
    if ( ! empty( $flash['result'] ) && ( $flash['result']['type'] ?? '' ) === 'saved' ) {
        echo '<div class="educbt-flash educbt-flash--success">Remark ranges saved.</div>';
    }
    if ( ! empty( $flash['result'] ) && ( $flash['result']['type'] ?? '' ) === 'deleted' ) {
        echo '<div class="educbt-flash educbt-flash--success">Remark range deleted.</div>';
    }
    ?>
    <p class="educbt-help">
        Set up score ranges and their corresponding remarks. When results are compiled,
        empty remarks on report cards are auto-filled based on the student's average score.
        These are the principal's remarks — class teachers manage their own remarks separately.
    </p>

    <h3>Existing Remark Ranges</h3>
    <table class="educbt-table">
        <thead><tr><th>Role</th><th>Min Avg</th><th>Max Avg</th><th>Remark</th><th></th></tr></thead>
        <tbody>
        <?php foreach ( $ranges as $range ) : ?>
            <tr>
                <td><?php echo esc_html( ucfirst( str_replace( '_', ' ', $range['role'] ) ) ); ?></td>
                <td><?php echo esc_html( $range['min_avg'] ); ?></td>
                <td><?php echo esc_html( $range['max_avg'] ); ?></td>
                <td><?php echo esc_html( $range['remark'] ); ?></td>
                <td>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
                        <?php wp_nonce_field( 'educbt_delete_remark_range' ); ?>
                        <input type="hidden" name="action" value="educbt_delete_remark_range">
                        <input type="hidden" name="range_id" value="<?php echo esc_attr( $range['id'] ); ?>">
                        <button type="submit" class="educbt-btn educbt-btn--sm educbt-btn--danger" onclick="return confirm('Delete this range?')">Delete</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if ( empty( $ranges ) ) : ?>
            <tr><td colspan="5" style="text-align:center;color:#94a3b8;">No remark ranges configured yet.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>

    <h3 style="margin-top:24px;">Add Remark Range</h3>
    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="educbt-form">
        <?php wp_nonce_field( 'educbt_save_remarks' ); ?>
        <input type="hidden" name="action" value="educbt_save_remarks">

        <div class="educbt-grid educbt-grid--3">
            <div class="educbt-field">
                <label>Role</label>
                <select name="roles[]" required>
                    <option value="principal">Principal</option>
                </select>
            </div>
            <div class="educbt-field">
                <label>Min Average</label>
                <input type="number" name="min_avgs[]" min="0" max="100" step="0.1" placeholder="0" required>
            </div>
            <div class="educbt-field">
                <label>Max Average</label>
                <input type="number" name="max_avgs[]" min="0" max="100" step="0.1" placeholder="100" required>
            </div>
        </div>
        <div class="educbt-field">
            <label>Remark</label>
            <textarea name="remarks[]" rows="2" placeholder="e.g. A very good result. Keep it up!" required></textarea>
        </div>

        <button type="submit" class="educbt-btn educbt-btn--primary">Add Range</button>
    </form>
    <?php
};

require EDUCBT_PRO_PATH . 'templates/portal/shell.php';
