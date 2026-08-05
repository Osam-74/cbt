<?php
/**
 * Forced password change.
 *
 * Reached by the router before any other portal page when a temporary password is
 * still in place. This is what makes surname-derived student passwords acceptable:
 * they are a delivery mechanism, not a credential the student keeps.
 *
 * Rendered as a standalone page (like login.php) so it doesn't depend on the
 * portal shell, sidebar, or any portal state being fully initialized.
 *
 * @var array<string,mixed> $educbt
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$notice  = '';
$user_id = get_current_user_id();

if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && check_admin_referer( 'educbt_change_password' ) ) {
    $new     = (string) ( $_POST['new_password'] ?? '' );
    $confirm = (string) ( $_POST['confirm_password'] ?? '' );
    $user    = wp_get_current_user();

    if ( strlen( $new ) < 8 ) {
        $notice = 'Your new password must be at least 8 characters.';
    } elseif ( $new !== $confirm ) {
        $notice = 'The two passwords do not match.';
    } elseif ( strtolower( $new ) === strtolower( (string) $user->last_name ) ) {
        $notice = 'Please choose something other than your surname.';
    } else {
        wp_set_password( $new, $user_id );
        delete_user_meta( $user_id, '_educbt_must_change_password' );
        wp_set_auth_cookie( $user_id );
        wp_safe_redirect( \EduCBTPro\Core\AdminLockdown::portal_url( $user_id ) );
        exit;
    }
}

$school_id = (int) ( $educbt['school_id'] ?? 0 );
$branding  = class_exists( '\EduCBTPro\Services\DocumentBrandingService' )
    ? ( new \EduCBTPro\Services\DocumentBrandingService() )->letterhead( $school_id )
    : [ 'name' => get_bloginfo( 'name' ), 'logo' => '' ];
$name      = $branding['name'] !== '' ? $branding['name'] : get_bloginfo( 'name' );

?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Choose a new password — <?php echo esc_html( $name ); ?></title>
<?php wp_head(); ?>
</head>
<body class="educbt-portal educbt-signin">
<main class="educbt-signin__wrap">
    <div class="educbt-signin__card">
        <?php if ( ! empty( $branding['logo'] ) ) : ?>
            <img class="educbt-signin__crest" src="<?php echo esc_url( $branding['logo'] ); ?>" alt="">
        <?php endif; ?>

        <h1>Choose a new password</h1>
        <p class="educbt-muted">You are signing in with a temporary password. Please choose your own before continuing.</p>

        <?php if ( $notice !== '' ) : ?>
            <p class="educbt-note educbt-note--warn"><?php echo esc_html( $notice ); ?></p>
        <?php endif; ?>

        <form method="post" class="educbt-form">
            <?php wp_nonce_field( 'educbt_change_password' ); ?>

            <label for="new_password">New password</label>
            <input type="password" id="new_password" name="new_password" autocomplete="new-password" required minlength="8">

            <label for="confirm_password" style="margin-top:12px">Repeat new password</label>
            <input type="password" id="confirm_password" name="confirm_password" autocomplete="new-password" required minlength="8">

            <button type="submit" class="educbt-btn educbt-btn--primary" style="width:100%;margin-top:18px">Save and continue</button>
        </form>

        <p class="educbt-muted" style="margin-top:14px;font-size:13px">Use at least 8 characters. Mix letters, numbers and symbols for a stronger password.</p>
    </div>
</main>
<?php wp_footer(); ?>
</body>
</html>
