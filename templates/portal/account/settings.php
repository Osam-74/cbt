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

global $wpdb;

$flash     = \EduCBTPro\Frontend\PortalActions::flash();
$user      = wp_get_current_user();
$school_id = (int) $educbt['school_id'];
$actor     = ( new \EduCBTPro\Core\Scope() )->actor();

// The school's own record of this person, which is what everyone else in the
// portal sees. The WordPress account is only the sign-in.
$me    = [];
$photo = '';
$role  = '';

if ( ( $actor['type'] ?? '' ) === 'staff' && absint( $actor['id'] ?? 0 ) > 0 ) {
    $me = (array) $wpdb->get_row(
        $wpdb->prepare(
            'SELECT * FROM ' . \EduCBTPro\Core\Schema::table( 'staff' ) . '
             WHERE id = %d AND school_id = %d',
            absint( $actor['id'] ),
            $school_id
        ),
        ARRAY_A
    );

    $photo = (string) ( $me['photo'] ?? '' );
    $role  = (string) ( $me['role_slug'] ?? '' );
} elseif ( ( $actor['type'] ?? '' ) === 'student' && absint( $actor['id'] ?? 0 ) > 0 ) {
    $me = (array) $wpdb->get_row(
        $wpdb->prepare(
            'SELECT * FROM ' . $wpdb->prefix . 'educbt_students WHERE id = %d AND school_id = %d',
            absint( $actor['id'] ),
            $school_id
        ),
        ARRAY_A
    );

    $photo = (string) ( $me['passport_photo'] ?? '' );
    $role  = \EduCBTPro\Core\Capabilities::ROLE_STUDENT;
}

// A principal usually has no staff row (see Scope::actor), so there is nothing to
// read a name from. The school's own record of its principal is the right source
// and is what the settings screen edits.
//
// Even when a staff row exists, the principal_name in the schools table is the
// canonical display name — the edit form on this page writes there. Override
// the staff row's name so what the principal just saved is what they see.
$_principal_school = ( $actor['role'] ?? '' ) === \EduCBTPro\Core\Capabilities::ROLE_PRINCIPAL;
if ( $_principal_school ) {
    $_pn = (string) $wpdb->get_var(
        $wpdb->prepare(
            'SELECT principal_name FROM ' . $wpdb->prefix . 'educbt_schools WHERE id = %d',
            $school_id
        )
    );
    if ( trim( $_pn ) !== '' ) {
        $_parts = preg_split( '/\s+/', trim( $_pn ) ) ?: [];
        $me['first_name'] = (string) array_shift( $_parts );
        $me['last_name']  = implode( ' ', $_parts );
    }
}

if ( empty( $me ) && ( $actor['type'] ?? '' ) === 'staff' ) {
    $principal_name = (string) $wpdb->get_var(
        $wpdb->prepare(
            'SELECT principal_name FROM ' . $wpdb->prefix . 'educbt_schools WHERE id = %d',
            $school_id
        )
    );

    if ( trim( $principal_name ) !== '' ) {
        $parts = preg_split( '/\s+/', trim( $principal_name ) ) ?: [];
        $me    = [
            'first_name' => (string) array_shift( $parts ),
            'last_name'  => implode( ' ', $parts ),
            'email'      => (string) $user->user_email,
        ];
    }

    // Load the principal's photo from the school row.
    $principal_photo = (string) $wpdb->get_var(
        $wpdb->prepare(
            'SELECT principal_photo FROM ' . $wpdb->prefix . 'educbt_schools WHERE id = %d',
            $school_id
        )
    );
    if ( trim( $principal_photo ) !== '' ) {
        $photo = $principal_photo;
    }

    $role = (string) ( $actor['role'] ?? '' );
}

$role_names = \EduCBTPro\Core\Capabilities::roles();
$role_label = (string) ( $role_names[ $role ] ?? 'Account' );

// What this person is responsible for — the detail that makes a staff profile
// worth opening rather than a page with a password box on it.
$duties = [];

if ( ( $actor['type'] ?? '' ) === 'staff' && absint( $actor['id'] ?? 0 ) > 0 ) {
    $duties = (array) $wpdb->get_results(
        $wpdb->prepare(
            "SELECT sub.name AS subject_name, c.display_name AS class_name, a.assignment_type
             FROM " . \EduCBTPro\Core\Schema::table( 'staff_assignments' ) . " a
             LEFT JOIN " . \EduCBTPro\Core\Schema::table( 'subjects_v2' ) . " sub ON sub.id = a.subject_id
             LEFT JOIN " . \EduCBTPro\Core\Schema::table( 'classes' ) . " c ON c.id = a.class_id
             WHERE a.school_id = %d AND a.staff_id = %d AND a.status = 'active'
             ORDER BY c.display_name ASC, sub.name ASC",
            $school_id,
            absint( $actor['id'] )
        ),
        ARRAY_A
    );
}

$educbt_title = 'My Account';

// Compute this outside the closure so we can pass it in.
$is_principal = ( $role === \EduCBTPro\Core\Capabilities::ROLE_PRINCIPAL );

$educbt_body = static function () use ( $flash, $user, $me, $photo, $role_label, $duties, $is_principal, $school_id ): void {
    require EDUCBT_PRO_PATH . 'templates/portal/partials/flash.php';
    ?>
    <?php
    $full_name = trim( (string) ( $me['first_name'] ?? '' ) . ' ' . (string) ( $me['last_name'] ?? '' ) );

    if ( $full_name === '' ) {
        $full_name = (string) $user->display_name;
    }
    ?>

    <style>
    body.educbt-portal .educbt-card.educbt-me-card {
        background: linear-gradient(155deg, #14532d 0%, #0F2818 100%) !important;
        border-color: #0F2818 !important;
        color: #fff !important;
    }
    body.educbt-portal .educbt-card.educbt-me-card h2 { color: #fff !important; }
    body.educbt-portal .educbt-me-card .educbt-muted { color: rgba(255,255,255,.72) !important; }
    .educbt-me-facts { display: grid; grid-template-columns: auto 1fr; gap: 8px 16px; margin: 0;
                       font-size: .92rem; align-items: baseline; }
    .educbt-me-facts dt { white-space: nowrap; color: rgba(255,255,255,.68); }
    .educbt-me-facts dd { margin: 0; color: #fff; font-weight: 500; }
    </style>

    <?php // Who the school says you are. Read-only: a member of staff does not
          // edit their own name, role or subjects — the school office does. ?>
    <section class="educbt-card educbt-me-card">
        <div style="display:flex;gap:24px;flex-wrap:wrap;align-items:flex-start">
            <div style="flex:0 0 auto">
                <?php if ( $photo !== '' ) : ?>
                    <img src="<?php echo esc_url( $photo ); ?>" alt=""
                         style="width:112px;height:134px;object-fit:cover;border-radius:10px;border:2px solid rgba(203,235,110,.65)">
                <?php else : ?>
                    <div style="width:112px;height:134px;border-radius:10px;border:2px dashed rgba(203,235,110,.5);
                                display:flex;align-items:center;justify-content:center;color:rgba(255,255,255,.75);
                                font-size:.8rem;text-align:center;padding:8px">No photograph</div>
                <?php endif; ?>
            </div>

            <div style="flex:1 1 300px;min-width:250px">
                <h2 style="margin:0 0 4px"><?php echo esc_html( $full_name ); ?></h2>
                <p style="margin:0 0 14px;color:rgba(255,255,255,.72)">
                    <?php echo esc_html( $role_label ); ?>
                    &nbsp;·&nbsp;
                    <?php echo esc_html( (string) ( $me['staff_number'] ?? $me['admission_number'] ?? $user->user_login ) ); ?>
                </p>

                <?php
                $facts = [
                    'Username' => (string) $user->user_login,
                    'Email'    => (string) ( $me['email'] ?? $user->user_email ),
                    'Phone'    => (string) ( $me['phone'] ?? '' ),
                ];
                ?>
                <dl class="educbt-me-facts">
                    <?php foreach ( $facts as $label => $value ) : ?>
                        <dt><?php echo esc_html( $label ); ?>:</dt>
                        <dd><?php echo esc_html( $value !== '' ? $value : '—' ); ?></dd>
                    <?php endforeach; ?>
                </dl>
            </div>
            <?php if ( $is_principal ) : ?>
            <div style="flex:0 0 auto;align-self:flex-start">
                <button type="button" id="educbt-edit-principal-btn"
                    class="educbt-btn"
                    style="background:rgba(255,255,255,.16);color:#fff;border:1px solid rgba(255,255,255,.32);padding:8px 18px;border-radius:8px;cursor:pointer;font-size:.85rem;white-space:nowrap">
                    ✎ Edit
                </button>
            </div>
            <?php endif; ?>
        </div>
    </section>

    <?php if ( $is_principal ) : ?>
    <section class="educbt-card" id="educbt-principal-edit" style="display:none">
        <h2>Edit my profile</h2>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="educbt-form" enctype="multipart/form-data">
            <input type="hidden" name="action" value="educbt_update_principal_profile">
            <?php wp_nonce_field( 'educbt_update_principal_profile' ); ?>
            <div class="educbt-grid">
                <div><label>First name</label><input name="first_name" type="text" value="<?php echo esc_attr( (string) ( $me['first_name'] ?? '' ) ); ?>" required></div>
                <div><label>Surname</label><input name="last_name" type="text" value="<?php echo esc_attr( (string) ( $me['last_name'] ?? '' ) ); ?>" required></div>
                <div><label>Email</label><input name="email" type="email" value="<?php echo esc_attr( (string) ( $me['email'] ?? $user->user_email ) ); ?>"></div>
                <div><label>Phone</label><input name="phone" type="text" value="<?php echo esc_attr( (string) ( $me['phone'] ?? '' ) ); ?>"></div>
                <div>
                    <label>Passport Photo</label>
                    <input name="principal_photo_file" type="file" accept="image/*" onchange="educbtPrincipalPhotoPreview(this)">
                    <?php if ( ! empty( $photo ) ) : ?>
                        <div style="margin-top:6px">
                            <img id="principal-edit-photo" src="<?php echo esc_url( $photo ); ?>" alt="" style="width:60px;height:72px;object-fit:cover;border-radius:6px;border:1px solid var(--line,#ccc)">
                            <span class="educbt-muted" style="font-size:.8rem;margin-left:4px">Current photo</span>
                        </div>
                    <?php else : ?>
                        <div id="principal-edit-photo-wrap" style="margin-top:6px;display:none">
                            <img id="principal-edit-photo" src="" alt="" style="width:60px;height:72px;object-fit:cover;border-radius:6px;border:1px solid var(--line,#ccc)">
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <div style="display:flex;gap:10px;margin-top:14px">
                <button type="submit" class="educbt-btn educbt-btn--primary">Save profile</button>
                <button type="button" class="educbt-btn" id="educbt-cancel-edit" style="background:transparent;border:1px solid var(--line,#ccc)">Cancel</button>
            </div>
        </form>
    </section>
    <script>
    (function() {
        var btn = document.getElementById('educbt-edit-principal-btn');
        var panel = document.getElementById('educbt-principal-edit');
        var cancel = document.getElementById('educbt-cancel-edit');
        if (btn)  btn.addEventListener('click', function() { panel.style.display = 'block'; panel.scrollIntoView({behavior:'smooth',block:'nearest'}); });
        if (cancel) cancel.addEventListener('click', function() { panel.style.display = 'none'; });
    })();
    function educbtPrincipalPhotoPreview(input) {
        var img = document.getElementById('principal-edit-photo');
        var wrap = document.getElementById('principal-edit-photo-wrap');
        if (img && input && input.files && input.files[0]) {
            var reader = new FileReader();
            reader.onload = function(e) { img.src = e.target.result; if (wrap) wrap.style.display = 'block'; };
            reader.readAsDataURL(input.files[0]);
        }
    }
    </script>
    <?php endif; ?>

    <?php if ( ! empty( $duties ) ) : ?>
        <section class="educbt-card">
            <h2>What I teach</h2>
            <ul style="margin:8px 0 0;padding-left:20px;list-style:disc">
                <?php foreach ( $duties as $duty ) : ?>
                    <li style="padding:3px 0">
                        <?php
                        $line = trim( (string) ( $duty['subject_name'] ?? '' ) );
                        $cls  = trim( (string) ( $duty['class_name'] ?? '' ) );

                        if ( (string) $duty['assignment_type'] === 'class_teacher' ) {
                            echo esc_html( 'Class teacher — ' . ( $cls !== '' ? $cls : 'unassigned class' ) );
                        } else {
                            echo esc_html( ( $line !== '' ? $line : 'Subject' ) . ( $cls !== '' ? ' — ' . $cls : '' ) );
                        }
                        ?>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php if ( ! $is_principal ) : ?>
            <p class="educbt-muted" style="margin-top:10px;font-size:.82rem">
                To change any of this, or your name or photograph, speak to the school office.
            </p>
            <?php endif; ?>
        </section>
    <?php endif; ?>

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
