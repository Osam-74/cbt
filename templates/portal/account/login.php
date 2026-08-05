<?php
/**
 * The portal sign-in screen.
 *
 * Owned by the plugin rather than delegated to wp-login.php. wp-login redirects on
 * its own terms, themes filter it, and the result was users being bounced to the
 * WordPress admin instead of their dashboard. Handling it here means one form, one
 * destination, and nothing else in the way.
 *
 * @var array<string,mixed> $educbt
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$error = '';

if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && isset( $_POST['educbt_login_nonce'] ) ) {
    if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['educbt_login_nonce'] ) ), 'educbt_login' ) ) {
        $error = 'Your session expired. Please try again.';
    } else {
        $user = wp_signon(
            [
                'user_login'    => trim( (string) wp_unslash( $_POST['username'] ?? '' ) ),
                'user_password' => (string) ( $_POST['password'] ?? '' ),
                'remember'      => ! empty( $_POST['remember'] ),
            ],
            is_ssl()
        );

        if ( is_wp_error( $user ) ) {
            // Deliberately vague: saying which of the two was wrong tells an attacker
            // whether an account exists.
            $error = 'That username or password was not recognised.';
        } else {
            wp_set_current_user( $user->ID );

            $destination = get_user_meta( $user->ID, '_educbt_must_change_password', true )
                ? home_url( '/portal/account/password/' )
                : \EduCBTPro\Core\AdminLockdown::portal_url( (int) $user->ID );

            wp_safe_redirect( $destination );
            exit;
        }
    }
}

$school_id = (int) $educbt['school_id'];
if ( $school_id <= 0 ) {
    global $wpdb;
    $uid = get_current_user_id();
    if ( $uid > 0 ) {
        $sid = (int) $wpdb->get_var( $wpdb->prepare( "SELECT school_id FROM {$wpdb->prefix}educbt_users WHERE wp_user_id = %d LIMIT 1", $uid ) );
        if ( $sid <= 0 ) $sid = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT school_id FROM ' . \EduCBTPro\Core\Schema::table( 'staff' ) . ' WHERE wp_user_id = %d LIMIT 1', $uid ) );
        if ( $sid <= 0 ) $sid = (int) $wpdb->get_var( $wpdb->prepare( "SELECT school_id FROM {$wpdb->prefix}educbt_students WHERE wp_user_id = %d LIMIT 1", $uid ) );
        if ( $sid <= 0 ) $sid = (int) get_user_meta( $uid, '_educbt_school_id', true );
        if ( $sid > 0 ) $school_id = $sid;
    }
}
// Resolve school name from the host if still no school_id
if ( $school_id <= 0 ) {
    $school_id = absint( ( new \EduCBTPro\Core\TenantContext() )->resolve_from_host() ?? 0 );
}
$school_row = $school_id > 0 ? $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . 'educbt_schools WHERE id = %d', $school_id ), ARRAY_A ) : null;
$name = trim( (string) ( $school_row['school_name'] ?? '' ) );
$logo = trim( (string) ( $school_row['logo'] ?? '' ) );
if ( $name === '' ) { $name = get_bloginfo( 'name' ); }
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo esc_html( 'Sign in — ' . $name ); ?></title>
<?php wp_head(); ?>
</head>
<body class="educbt-portal educbt-signin">
<main class="educbt-signin__wrap">
    <div class="educbt-signin__card">
        <?php if ( $logo !== '' ) : ?>
            <img class="educbt-signin__crest" src="<?php echo esc_url( $logo ); ?>" alt="">
        <?php endif; ?>

        <h1><?php echo esc_html( $name ); ?></h1>
        <p class="educbt-muted">Sign in to the school portal</p>

        <?php if ( $error !== '' ) : ?>
            <p class="educbt-note educbt-note--warn"><?php echo esc_html( $error ); ?></p>
        <?php endif; ?>

        <form method="post" class="educbt-form">
            <?php wp_nonce_field( 'educbt_login', 'educbt_login_nonce' ); ?>

            <label for="username">Username</label>
            <input id="username" name="username" type="text" autocomplete="username" required autofocus
                   value="<?php echo esc_attr( (string) wp_unslash( $_POST['username'] ?? '' ) ); ?>">

            <label for="password" style="margin-top:12px">Password</label>
            <input id="password" name="password" type="password" autocomplete="current-password" required>

            <label style="display:flex;align-items:center;gap:8px;margin-top:12px;font-weight:400">
                <input type="checkbox" name="remember" value="1" style="width:auto"> Keep me signed in
            </label>

            <button type="submit" class="educbt-btn educbt-btn--primary" style="width:100%;margin-top:18px">Sign in</button>
        </form>

        <p class="educbt-signin__hint">
            <strong>Students:</strong> use your admission number.<br>
            <strong>Staff:</strong> use your staff number or email.<br>
            <strong>Parents:</strong> use your email address.
        </p>

        <p class="educbt-muted" style="margin-top:14px">
            <a href="<?php echo esc_url( wp_lostpassword_url( home_url( '/portal/login/' ) ) ); ?>">Forgotten your password?</a>
        </p>
    </div>
</main>
<?php wp_footer(); ?>
</body>
</html>