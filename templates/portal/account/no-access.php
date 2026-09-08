<?php
/**
 * Shown when a signed-in account has no school role.
 *
 * This is where the redirect loop used to happen. Explaining the situation is worth
 * far more than bouncing the browser until it gives up — the user can at least see
 * which account they are signed in as and act on it.
 *
 * @var array<string,mixed> $educbt
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$user       = wp_get_current_user();
$is_admin   = current_user_can( 'manage_options' );
$roles      = (array) $user->roles;

global $wpdb;

// Check if this is an inactive or withdrawn student
$students_table = $wpdb->prefix . 'educbt_students';
$student_record = $wpdb->get_row(
    $wpdb->prepare(
        "SELECT id, status, first_name, last_name FROM {$students_table} WHERE wp_user_id = %d LIMIT 1",
        get_current_user_id()
    ),
    ARRAY_A
);
$student_status = (string) ( $student_record['status'] ?? '' );
$student_name   = trim( (string) ( $student_record['first_name'] ?? '' ) . ' ' . (string) ( $student_record['last_name'] ?? '' ) );

$educbt_title = $student_status === 'inactive' ? 'Account suspended'
    : ( $student_status === 'withdrawn' ? 'Account withdrawn' : 'No portal for this account' );

$educbt_body = static function () use ( $user, $is_admin, $roles, $student_status, $student_name ): void {
    if ( $student_status === 'inactive' ) :
        ?>
        <section class="educbt-card educbt-card--narrow">
            <div style="text-align:center;padding:10px 0 16px">
                <div style="width:56px;height:56px;margin:0 auto 14px;border-radius:50%;background:#fef3c7;display:flex;align-items:center;justify-content:center">
                    <svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="#92400e" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 8v4M12 16h.01"/></svg>
                </div>
                <h2 style="margin:0 0 8px;font-size:20px">Your account is inactive</h2>
                <p style="color:#666;margin:0"><?php echo esc_html( $student_name ? 'Hi ' . $student_name . ', your' : 'Your' ); ?> portal access has been temporarily suspended. This may be due to disciplinary action, unpaid fees, or other reasons. Please contact your school office to resolve this.</p>
            </div>
            <p style="margin-top:14px">
                <a class="educbt-btn" href="<?php echo esc_url( wp_logout_url( home_url( '/' ) ) ); ?>">Sign out</a>
            </p>
        </section>
    <?php
    elseif ( $student_status === 'withdrawn' ) :
        ?>
        <section class="educbt-card educbt-card--narrow">
            <div style="text-align:center;padding:10px 0 16px">
                <div style="width:56px;height:56px;margin:0 auto 14px;border-radius:50%;background:#fee2e2;display:flex;align-items:center;justify-content:center">
                    <svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="#991b1b" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M15 9l-6 6M9 9l6 6"/></svg>
                </div>
                <h2 style="margin:0 0 8px;font-size:20px">Your account has been withdrawn</h2>
                <p style="color:#666;margin:0">You have been withdrawn from the school. Your results and records are preserved. If you believe this is an error, please contact your school office.</p>
            </div>
            <p style="margin-top:14px">
                <a class="educbt-btn" href="<?php echo esc_url( wp_logout_url( home_url( '/' ) ) ); ?>">Sign out</a>
            </p>
        </section>
    <?php
    else :
        ?>
        <section class="educbt-card educbt-card--narrow">
            <p>
                You are signed in as <strong><?php echo esc_html( $user->user_login ); ?></strong>,
                which is not a student, parent or staff account, so there is no dashboard to show.
            </p>

        <?php if ( $is_admin ) : ?>
            <p class="educbt-note">
                This is the site administrator account. Schools are managed from the
                WordPress admin, not from the portal.
            </p>
            <p><a class="educbt-btn educbt-btn--primary" href="<?php echo esc_url( admin_url( 'admin.php?page=educbt-schools' ) ); ?>">Go to EduCBT Schools</a></p>
            <p class="educbt-muted">
                To see a school portal, sign in with that school&rsquo;s principal account
                (their email address) in a private browsing window.
            </p>
        <?php else : ?>
            <p class="educbt-note educbt-note--warn">
                If you should have access, ask the school office to check that your account
                is linked to the school.
            </p>
            <p class="educbt-muted">
                Roles on this account: <?php echo esc_html( $roles ? implode( ', ', $roles ) : 'none' ); ?>
            </p>
        <?php endif; ?>

        <p style="margin-top:18px">
            <a class="educbt-btn" href="<?php echo esc_url( wp_logout_url( home_url( '/' ) ) ); ?>">Sign out</a>
        </p>
    </section>
    <?php
    endif;
};

require EDUCBT_PRO_PATH . 'templates/portal/shell.php';
