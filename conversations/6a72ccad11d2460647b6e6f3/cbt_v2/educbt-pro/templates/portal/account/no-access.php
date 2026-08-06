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

$educbt_title = 'No portal for this account';

$educbt_body = static function () use ( $user, $is_admin, $roles ): void {
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
};

require EDUCBT_PRO_PATH . 'templates/portal/shell.php';
