<?php
/**
 * Guardian invite acceptance — a PUBLIC page (no login required).
 *
 * The school links a parent to a student and gives them an invite link. The
 * parent opens it and sets their own password here. The school never holds
 * the password.
 *
 * @var array<string,mixed> $educbt
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

$token  = isset( $_GET['token'] ) ? trim( (string) wp_unslash( $_GET['token'] ) ) : '';
$error = '';
$done  = false;

// Handle the form submission inline so we don't need a separate admin-post
// round-trip for something this simple.
if ( $_SERVER['REQUEST_METHOD'] === 'POST' ) {
    $posted_token = isset( $_POST['invite_token'] ) ? trim( (string) wp_unslash( $_POST['invite_token'] ) ) : '';
    $password     = isset( $_POST['password'] ) ? (string) wp_unslash( $_POST['password'] ) : '';
    $confirm      = isset( $_POST['password_confirm'] ) ? (string) wp_unslash( $_POST['password_confirm'] ) : '';

    if ( $posted_token === '' ) {
        $error = 'This invite link is incomplete. Please use the full link you were given.';
    } elseif ( strlen( $password ) < 8 ) {
        $error = 'Password must be at least 8 characters.';
    } elseif ( $password !== $confirm ) {
        $error = 'The two passwords do not match.';
    } else {
        $result = ( new \EduCBTPro\Services\GuardianService() )->accept_invite( $posted_token, $password );

        if ( ! empty( $result['success'] ) ) {
            $done = true;
        } else {
            $map = [
                'invalid_token'          => 'This invite link is invalid or has expired. Please ask the school for a new one.',
                'password_too_short'      => 'Password must be at least 8 characters.',
                'invalid_or_used_token'   => 'This invite has already been used or is no longer valid.',
                'account_creation_failed' => 'We could not create your account. Please contact the school.',
            ];
            $error = $map[ (string) ( $result['error'] ?? '' ) ] ?? 'Something went wrong. Please contact the school.';
        }
    }

    $token = $posted_token;
}

$educbt_title = 'Set Your Password';
$educbt_body  = static function () use ( $token, $error, $done ): void {
    ?>
    <div class="educbt-card" style="max-width:460px;margin:40px auto">
        <?php if ( $done ) : ?>
            <h2>Password set — you're in</h2>
            <p>Your account is ready. You can now sign in to see your child's results and timetable.</p>
            <p style="margin-top:16px">
                <a class="educbt-btn educbt-btn--primary" href="<?php echo esc_url( home_url( '/portal/login/' ) ); ?>">
                    Go to sign-in
                </a>
            </p>
        <?php else : ?>
            <h2>Set your password</h2>
            <p class="educbt-muted">
                You were invited by your child's school to view their results online.
                Choose a password to activate your account.
            </p>

            <?php if ( $error !== '' ) : ?>
                <p class="educbt-note educbt-note--warn"><?php echo esc_html( $error ); ?></p>
            <?php endif; ?>

            <form method="post" class="educbt-form" style="margin-top:16px">
                <input type="hidden" name="invite_token" value="<?php echo esc_attr( $token ); ?>">

                <label for="password">Password <span class="educbt-muted">(at least 8 characters)</span></label>
                <input id="password" name="password" type="password" required minlength="8" autocomplete="new-password">

                <label for="password_confirm" style="margin-top:10px">Confirm password</label>
                <input id="password_confirm" name="password_confirm" type="password" required minlength="8" autocomplete="new-password">

                <button type="submit" class="educbt-btn educbt-btn--primary" style="margin-top:16px">Activate my account</button>
            </form>
        <?php endif; ?>
    </div>
    <?php
};

require EDUCBT_PRO_PATH . 'templates/portal/shell.php';
