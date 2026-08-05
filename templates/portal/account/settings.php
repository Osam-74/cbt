<?php
/**
 * My account — change my own password.
 *
 * Available to every role. A student on a shared terminal, a teacher who suspects
 * someone watched them type, a principal after handing a laptop to IT: all need to
 * change their own password without waiting for anyone.
 *
 * @var array<string,mixed> $educbt
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

$flash = \EduCBTPro\Frontend\PortalActions::flash();
$user  = wp_get_current_user();

$educbt_title = 'My Account';

$educbt_body = static function () use ( $flash, $user ): void {
    require EDUCBT_PRO_PATH . 'templates/portal/partials/flash.php';
    ?>
    <section class="educbt-card educbt-card--narrow">
        <h2>Change my password</h2>
        <p class="educbt-muted" style="margin-top:-6px">
            Signed in as <strong><?php echo esc_html( $user->user_login ); ?></strong>
        </p>

        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="educbt-form">
            <input type="hidden" name="action" value="educbt_change_own_password">
            <?php wp_nonce_field( 'educbt_change_own_password' ); ?>

            <label for="current_password">Current password</label>
            <input id="current_password" name="current_password" type="password" autocomplete="current-password" required>

            <label for="new_password" style="margin-top:12px">New password</label>
            <input id="new_password" name="new_password" type="password" autocomplete="new-password" required minlength="8">

            <label for="confirm_password" style="margin-top:12px">Repeat new password</label>
            <input id="confirm_password" name="confirm_password" type="password" autocomplete="new-password" required minlength="8">

            <button type="submit" class="educbt-btn educbt-btn--primary" style="margin-top:16px">Change password</button>
        </form>

        <p class="educbt-muted" style="margin-top:14px">
            Your current password is asked for even though you are signed in — a session
            left open on a shared terminal should not let the next person take the account.
        </p>
    </section>
    <?php
};

require EDUCBT_PRO_PATH . 'templates/portal/shell.php';
