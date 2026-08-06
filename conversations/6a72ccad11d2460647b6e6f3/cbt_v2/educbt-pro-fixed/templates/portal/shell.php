<?php
/**
 * EduCBT portal shell — v2.1.1
 *
 * Full-height dark sidebar on the left, content on the right. The topbar
 * shares the sidebar's dark green so there is no visible seam. Role + school
 * name sit in the header's left column (desktop), same row as the bell.
 *
 * Styling lives entirely in assets/css/educbt-portal.css — enqueued in <head>
 * by Plugin::enqueue_portal_assets(), so it blocks rendering and there is no
 * flash of unstyled content.
 *
 * @var array<string,mixed> $educbt
 * @var string   $educbt_title
 * @var callable $educbt_body
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

global $wpdb;

// ── Resolve school identity ─────────────────────────────────────────
// TenantContext::get_school_id() is the primary source, but it can return
// null/0 for a platform admin who hasn't impersonated a school. In that case
// fall back to the staff or users table — every school user has a row that
// links their wp_user_id to a school_id.
$school_id = (int) ( $educbt['school_id'] ?? 0 );

if ( $school_id <= 0 ) {
    $uid = get_current_user_id();
    if ( $uid > 0 ) {
        // Try the users table first
        $sid = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT school_id FROM {$wpdb->prefix}educbt_users WHERE wp_user_id = %d LIMIT 1",
                $uid
            )
        );
        // Then the staff table
        if ( $sid <= 0 ) {
            $sid = (int) $wpdb->get_var(
                $wpdb->prepare(
                    'SELECT school_id FROM ' . \EduCBTPro\Core\Schema::table( 'staff' ) . ' WHERE wp_user_id = %d LIMIT 1',
                    $uid
                )
            );
        }
        // Then the students table
        if ( $sid <= 0 ) {
            $sid = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT school_id FROM {$wpdb->prefix}educbt_students WHERE wp_user_id = %d LIMIT 1",
                    $uid
                )
            );
        }
        // Then user meta
        if ( $sid <= 0 ) {
            $sid = (int) get_user_meta( $uid, '_educbt_school_id', true );
        }
        if ( $sid > 0 ) {
            $school_id = $sid;
        }
    }
}

// ── Fetch school record directly ───────────────────────────────────
// Query the school row ourselves rather than going through
// DocumentBrandingService::letterhead(), which only selects a subset of
// columns. We want school_name, logo, and principal_name in one shot.
$school_row = $school_id > 0
    ? $wpdb->get_row(
        $wpdb->prepare(
            // SELECT * so a missing column cannot blank the school name in the header.
            'SELECT * FROM ' . $wpdb->prefix . 'educbt_schools WHERE id = %d',
            $school_id
        ),
        ARRAY_A
    )
    : null;

$school_name = trim( (string) ( $school_row['school_name'] ?? '' ) );
$school_logo = trim( (string) ( $school_row['logo'] ?? '' ) );
if ( $school_name === '' ) {
    // Falling back to the literal word "School" hid a real problem: it meant the
    // school row could not be read, and every document that prints the school name
    // was about to be wrong too. Show the site name so at least something true
    // appears, and leave the settings page to explain it.
    $school_name = (string) get_bloginfo( 'name' );
}

$notifier = new \EduCBTPro\Services\NotificationService();
$unread   = $notifier->unread_count( $school_id, get_current_user_id() );
$user     = wp_get_current_user();
$areas    = \EduCBTPro\Frontend\PortalRouter::areas();
$area     = (string) $educbt['area'];

$reachable_areas = \EduCBTPro\Frontend\PortalRouter::areas_for_user();

$role_label = '';
foreach ( \EduCBTPro\Core\Capabilities::roles() as $slug => $label ) {
    if ( in_array( $slug, (array) $user->roles, true ) ) {
        $role_label = $label;
        break;
    }
}

$name_parts = array_filter( explode( ' ', trim( (string) $user->display_name ) ) );
$initials   = '';
foreach ( array_slice( $name_parts, 0, 2 ) as $part ) {
    $initials .= mb_strtoupper( mb_substr( $part, 0, 1 ) );
}
if ( $initials === '' ) {
    $initials = 'U';
}

$educbt_icon_paths = [
    'area:school'   => '<path d="M4 21V9.5l8-5 8 5V21"/><path d="M9 21v-6h6v6"/>',
    'area:exams'    => '<path d="M8 3h6l4 4v14H8a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2Z"/><path d="M14 3v4h4"/>',
    'area:teacher'  => '<rect x="3" y="4" width="18" height="12" rx="1.6"/><path d="M9 20h6M12 16v4"/>',
    'area:student'  => '<path d="M12 4 2 9l10 5 10-5-10-5Z"/><path d="M6 11.4V17c0 1.4 2.7 3 6 3s6-1.6 6-3v-5.6"/>',
    'area:guardian' => '<path d="M12 20s-7.4-4.4-9.7-8.9C.7 8 2.5 4.8 6 4.8c2 0 3.4 1.2 6 3.6 2.6-2.4 4-3.6 6-3.6 3.5 0 5.3 3.2 3.7 6.3C19.4 15.6 12 20 12 20Z"/>',
    ''             => '<rect x="3" y="3" width="7.5" height="7.5" rx="1.6"/><rect x="13.5" y="3" width="7.5" height="7.5" rx="1.6"/><rect x="13.5" y="13.5" width="7.5" height="7.5" rx="1.6"/><rect x="3" y="13.5" width="7.5" height="7.5" rx="1.6"/>',
    'staff'        => '<circle cx="9" cy="8" r="3.2"/><path d="M3 20c0-3.6 2.7-6.4 6-6.4s6 2.8 6 6.4"/>',
    'students'     => '<path d="M12 4 2 9l10 5 10-5-10-5Z"/><path d="M6 11.4V17c0 1.4 2.7 3 6 3s6-1.6 6-3v-5.6"/>',
    'classes'      => '<path d="M12 3 3 8l9 5 9-5-9-5Z"/><path d="M3 12l9 5 9-5"/>',
    'subjects'     => '<path d="M4 4.6A2.6 2.6 0 0 1 6.6 2H20v17H6.6A2.6 2.6 0 0 0 4 21.6v-17Z"/>',
    'results'      => '<path d="M4 20V10M12 20V4M20 20v-7"/><path d="M2 20h20"/>',
    'promotion'    => '<path d="M3 17l6-6 4 4 7-8"/><path d="M15 6.5h5.5V12"/>',
    'transcripts'  => '<path d="M14 2H7.5A2 2 0 0 0 5.5 4v16a2 2 0 0 0 2 2H17a2 2 0 0 0 2-2V8l-5-6Z"/><path d="M14 2v6h5"/>',
    'notices'      => '<path d="M3 10.2v3.6h3l4.3 4.3V5.9L6 10.2H3Z"/><path d="M14.3 8.3a4.3 4.3 0 0 1 0 7.4"/>',
    'activity'     => '<path d="M3 12h4l2-7 4 14 2-7h6"/>',
    'settings'     => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.9-.3 1.7 1.7 0 0 0-1 1.6V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1-1.6 1.7 1.7 0 0 0-1.9.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.9 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.6-1 1.7 1.7 0 0 0-.3-1.9l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.9.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.9-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.9V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1Z"/>',
    'papers'       => '<path d="M14 2H7.5A2 2 0 0 0 5.5 4v16a2 2 0 0 0 2 2H17a2 2 0 0 0 2-2V8l-5-6Z"/><path d="M14 2v6h5"/>',
    'timetable'    => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18"/>',
    'questions'    => '<circle cx="12" cy="12" r="9"/><path d="M9.3 9.2a2.7 2.7 0 0 1 5 1.4c0 1.9-2.2 1.8-2.7 3.4"/><path d="M12 17h.01"/>',
    'approvals'    => '<circle cx="12" cy="12" r="9"/><path d="M8 12.3l2.6 2.6L16 9.3"/>',
    'invigilate'   => '<path d="M2 12s3.6-7.2 10-7.2 10 7.2 10 7.2-3.6 7.2-10 7.2-10-7.2-10-7.2Z"/><circle cx="12" cy="12" r="3"/>',
    'invigilation' => '<path d="M2 12s3.6-7.2 10-7.2 10 7.2 10 7.2-3.6 7.2-10 7.2-10-7.2-10-7.2Z"/><circle cx="12" cy="12" r="3"/>',
    'marking'      => '<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5Z"/>',
    'broadsheet'   => '<rect x="3" y="4" width="18" height="16" rx="1.6"/><path d="M3 10h18M9 4v16"/>',
    'analysis'     => '<path d="M4 19V9M10 19V5M16 19v-7"/><path d="M2 19h20"/>',
    'scores'       => '<path d="M9 6h11M9 12h11M9 18h11"/>',
    'register'     => '<rect x="5" y="4" width="14" height="17" rx="2"/><path d="M9 4a3 3 0 0 1 6 0"/><path d="M8 12.5h8M8 16.5h5"/>',
    'tests'        => '<path d="M14 2H7.5A2 2 0 0 0 5.5 4v16a2 2 0 0 0 2 2H17a2 2 0 0 0 2-2V8l-5-6Z"/><path d="M14 2v6h5"/>',
    'exam'         => '<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5Z"/>',
    'children'     => '<path d="M12 20s-7.4-4.4-9.7-8.9C.7 8 2.5 4.8 6 4.8c2 0 3.4 1.2 6 3.6 2.6-2.4 4-3.6 6-3.6 3.5 0 5.3 3.2 3.7 6.3C19.4 15.6 12 20 12 20Z"/>',
];

$educbt_icon = static function ( string $key ) use ( $educbt_icon_paths ): string {
    $paths = $educbt_icon_paths[ $key ] ?? '<circle cx="12" cy="12" r="2.4"/>';
    return '<svg class="educbt-sidebar__icon" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $paths . '</svg>';
};
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo esc_html( trim( $educbt_title . ' — ' . $school_name ) ); ?></title>
<?php wp_head(); ?>
</head>
<body class="educbt-portal educbt-portal--<?php echo esc_attr( $area ); ?>">
<a class="educbt-skip" href="#educbt-main">Skip to content</a>
<input type="checkbox" id="educbt-nav-toggle" class="educbt-nav-toggle" hidden>

<header class="educbt-topbar">
    <label for="educbt-nav-toggle" class="educbt-burger" aria-label="Menu" role="button" tabindex="0">
        <span></span><span></span><span></span>
    </label>

    <div class="educbt-topbar__brand">
        <strong class="educbt-topbar__role"><?php echo esc_html( $role_label ?: 'Account' ); ?></strong>
        <span class="educbt-topbar__schoolname"><?php echo esc_html( $school_name ); ?></span>
    </div>

    <h1 class="educbt-topbar__title"><?php echo esc_html( $educbt_title ); ?></h1>

    <div class="educbt-topbar__right">
        <a class="educbt-bell" href="<?php echo esc_url( home_url( '/portal/account/notifications/' ) ); ?>">
            <svg viewBox="0 0 24 24" width="19" height="19" aria-hidden="true"><path fill="currentColor" d="M12 22a2.1 2.1 0 0 0 2.1-2.1H9.9A2.1 2.1 0 0 0 12 22Zm6.3-6.3v-5.2c0-3.2-1.7-5.9-4.7-6.6v-.7a1.6 1.6 0 0 0-3.2 0v.7c-3 .7-4.7 3.4-4.7 6.6v5.2L3.6 17.8v1h16.8v-1Z"/></svg>
            <?php if ( $unread > 0 ) : ?><span class="educbt-bell__count"><?php echo esc_html( (string) min( 99, $unread ) ); ?></span><?php endif; ?>
        </a>
    </div>
</header>

<div class="educbt-layout">
    <label for="educbt-nav-toggle" class="educbt-scrim" aria-hidden="true"></label>

    <aside class="educbt-sidebar">
        <div class="educbt-sidebar__who">
            <strong class="educbt-sidebar__role"><?php echo esc_html( $role_label ?: 'Account' ); ?></strong>
            <span class="educbt-sidebar__schoolname"><?php echo esc_html( $school_name ); ?></span>
        </div>

        <div class="educbt-sidebar__scroll">
            <?php if ( count( $reachable_areas ) > 1 ) : ?>
                <div class="educbt-sidebar__block">
                    <p class="educbt-sidebar__label">Areas</p>
                    <?php foreach ( $reachable_areas as $slug => $label ) : ?>
                        <a class="educbt-sidebar__area <?php echo $slug === $area ? 'is-current' : ''; ?>" href="<?php echo esc_url( home_url( '/portal/' . $slug . '/' ) ); ?>">
                            <?php echo $educbt_icon( 'area:' . $slug ); ?>
                            <span><?php echo esc_html( $label ); ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div class="educbt-sidebar__block">
                <p class="educbt-sidebar__label"><?php echo esc_html( $areas[ $area ]['label'] ?? 'Menu' ); ?></p>
                <nav>
                    <?php foreach ( $educbt['navigation'] as $item ) : ?>
                        <a href="<?php echo esc_url( $item['url'] ); ?>" class="educbt-sidebar__link <?php echo $item['current'] ? 'is-current' : ''; ?>" <?php echo $item['current'] ? 'aria-current="page"' : ''; ?>>
                            <?php echo $educbt_icon( (string) ( $item['slug'] ?? '' ) ); ?>
                            <span><?php echo esc_html( $item['label'] ); ?></span>
                        </a>
                    <?php endforeach; ?>
                </nav>
            </div>
        </div>

        <div class="educbt-sidebar__foot">
            <a class="educbt-sidebar__account" href="<?php echo esc_url( home_url( '/portal/account/settings/' ) ); ?>">
                <span class="educbt-sidebar__avatar"><?php echo esc_html( $initials ); ?></span>
                <span class="educbt-sidebar__account-text">
                    <strong><?php echo esc_html( $user->display_name ); ?></strong>
                    <?php if ( $role_label !== '' ) : ?><small><?php echo esc_html( $role_label ); ?></small><?php endif; ?>
                </span>
            </a>
            <a class="educbt-sidebar__signout" href="<?php echo esc_url( wp_logout_url( home_url( '/' ) ) ); ?>">Sign out</a>
        </div>
    </aside>

    <main id="educbt-main" class="educbt-content">
        <?php if ( isset( $_GET['denied'] ) ) : ?>
            <p class="educbt-note educbt-note--warn">You do not have access to that area.</p>
        <?php endif; ?>
        <?php
        // Unread notifications, on every role's overview. A principal, exam officer,
        // teacher, parent or student all land here first, and a reminder that is
        // only visible behind the bell gets missed.
        if ( (string) $educbt['section'] === '' && $unread > 0 ) :
            $unread_preview = $notifier->inbox( $school_id, get_current_user_id(), true, 5 );
            ?>
            <section class="educbt-card" style="border-left:4px solid var(--edu-accent)">
                <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap">
                    <h2 style="margin:0">
                        <?php echo esc_html( sprintf( '%d unread notification%s', $unread, $unread === 1 ? '' : 's' ) ); ?>
                    </h2>
                    <a class="educbt-btn" href="<?php echo esc_url( home_url( '/portal/account/notifications/' ) ); ?>">Open notifications</a>
                </div>
                <ul class="educbt-list" style="margin-top:10px">
                    <?php foreach ( $unread_preview as $note ) : ?>
                        <li style="align-items:center">
                            <span style="flex:1 1 auto;min-width:0">
                                <strong><?php echo esc_html( (string) $note['title'] ); ?></strong>
                                <span class="educbt-muted" style="display:block;font-size:12.5px">
                                    <?php echo esc_html( mysql2date( 'j M Y, g:ia', (string) $note['created_at'] ) ); ?>
                                </span>
                            </span>
                            <a class="educbt-btn" style="padding:4px 12px;font-size:12.5px"
                               href="<?php echo esc_url( add_query_arg( 'open', absint( $note['id'] ), home_url( '/portal/account/notifications/' ) ) ); ?>">Open</a>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <?php if ( $unread > count( $unread_preview ) ) : ?>
                    <p class="educbt-muted" style="margin-top:8px">
                        <?php echo esc_html( sprintf( '%d more not shown.', $unread - count( $unread_preview ) ) ); ?>
                    </p>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <?php $educbt_body(); ?>
    </main>
</div>

<?php wp_footer(); ?>
</body>
</html>
