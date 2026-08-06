<?php

namespace EduCBTPro\Admin;

use EduCBTPro\Core\HostRouter;
use EduCBTPro\Core\Schema;
use EduCBTPro\Services\SchoolOnboardingService;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The platform owner's screen.
 *
 * Creating a school is genuinely a platform-owner task, not a school task, so it is
 * the one thing that legitimately stays in wp-admin. Everything a SCHOOL does lives
 * on the front end; nobody at a school ever sees this page.
 *
 * This replaces the fifteen v1 menus with one. The v1 menus are hidden by default —
 * see AdminController::should_show_legacy_menus() — because they write directly to
 * the v1 tables and bypass every rule the service layer enforces.
 */
class PlatformAdminController {

    public const MENU_SLUG = 'educbt-schools';

    public function init(): void {
        add_action( 'admin_menu', [ $this, 'register_menu' ] );
        add_action( 'admin_post_educbt_create_school', [ $this, 'handle_create' ] );
        add_action( 'admin_post_educbt_toggle_subdomain', [ $this, 'handle_toggle_subdomain' ] );
        add_action( 'admin_post_educbt_admin_update_school', [ $this, 'handle_update' ] );
        add_action( 'admin_post_educbt_reset_principal', [ $this, 'handle_reset_principal' ] );
        add_action( 'admin_post_educbt_delete_school', [ $this, 'handle_delete' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
    }

    public function register_menu(): void {
        add_menu_page(
            __( 'EduCBT Schools', 'educbt-pro' ),
            __( 'EduCBT Schools', 'educbt-pro' ),
            'manage_options',
            self::MENU_SLUG,
            [ $this, 'render' ],
            'dashicons-welcome-learn-more',
            26
        );
    }

    public function enqueue( $hook ): void {
        if ( strpos( (string) $hook, self::MENU_SLUG ) === false ) {
            return;
        }

        // The media library is what provides the logo picker.
        wp_enqueue_media();
    }

    /**
     * Create a school. All the generation — code, subdomain, principal account —
     * happens in SchoolOnboardingService; this method only collects and reports.
     */
    public function handle_create(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'educbt-pro' ) );
        }

        check_admin_referer( 'educbt_create_school' );

        $result = ( new SchoolOnboardingService() )->create_school(
            [
                'name'           => (string) wp_unslash( $_POST['school_name'] ?? '' ),
                'address'        => (string) wp_unslash( $_POST['address'] ?? '' ),
                'logo'           => (string) wp_unslash( $_POST['logo'] ?? '' ),
                'phone'          => (string) wp_unslash( $_POST['phone'] ?? '' ),
                'email'          => (string) wp_unslash( $_POST['email'] ?? '' ),
                'principal_name' => (string) wp_unslash( $_POST['principal_name'] ?? '' ),
                'subdomain'      => (string) wp_unslash( $_POST['subdomain'] ?? '' ),
            ]
        );

        if ( empty( $result['success'] ) ) {
            set_transient( 'educbt_school_errors_' . get_current_user_id(), $result['errors'] ?? [], 60 );

            wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&created=0' ) );
            exit;
        }

        // The temporary password is shown ONCE and never stored in readable form.
        // A transient rather than an option, so it expires rather than sitting in
        // the database indefinitely.
        set_transient(
            'educbt_new_school_' . get_current_user_id(),
            [
                'code'          => $result['code'],
                'subdomain'     => $result['subdomain'],
                'portal_url'    => $result['portal_url'],
                'username'      => $result['principal']['username'] ?? '',
                'password'      => $result['principal']['temporary_password'] ?? '',
                'account_error' => $result['principal']['error'] ?? '',
                'email_taken'   => ! empty( $result['principal']['email_was_taken'] ),
            ],
            300
        );

        wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&created=1' ) );
        exit;
    }

    public function handle_update(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'educbt-pro' ) );
        }

        check_admin_referer( 'educbt_admin_update_school' );

        ( new SchoolOnboardingService() )->update_school(
            absint( $_POST['school_id'] ?? 0 ),
            [
                'school_name'    => (string) wp_unslash( $_POST['school_name'] ?? '' ),
                'address'        => (string) wp_unslash( $_POST['address'] ?? '' ),
                'phone'          => (string) wp_unslash( $_POST['phone'] ?? '' ),
                'email'          => (string) wp_unslash( $_POST['email'] ?? '' ),
                'logo'           => (string) wp_unslash( $_POST['logo'] ?? '' ),
                'principal_name' => (string) wp_unslash( $_POST['principal_name'] ?? '' ),
            ]
        );

        wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&updated=1' ) );
        exit;
    }

    /**
     * Hard-delete a school and everything attached to it.
     */
    public function handle_delete(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'educbt-pro' ) );
        }

        check_admin_referer( 'educbt_delete_school' );

        $school_id = absint( $_POST['school_id'] ?? 0 );

        if ( $school_id <= 0 ) {
            wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&deleted=0' ) );
            exit;
        }

        $result = ( new SchoolOnboardingService() )->delete_school( $school_id );

        if ( empty( $result['success'] ) ) {
            set_transient( 'educbt_delete_error_' . get_current_user_id(), $result['error'] ?? 'unknown', 60 );
            wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&deleted=0' ) );
            exit;
        }

        wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&deleted=1' ) );
        exit;
    }

    /**
     * Reset a school's principal password.
     *
     * The platform owner needs this because a principal locked out of their own
     * school has nobody above them inside the portal to help. The new password is
     * shown once and the account is forced to change it at next sign-in.
     */
    public function handle_reset_principal(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'educbt-pro' ) );
        }

        check_admin_referer( 'educbt_reset_principal' );

        global $wpdb;

        $school_id = absint( $_POST['school_id'] ?? 0 );

        // The account is CHOSEN, not guessed.
        //
        // This previously picked the lowest-id staff row holding the principal role,
        // which could easily be a demo account rather than the school's real one — so
        // the reset appeared to work and the password did not fit the account being
        // signed into. Worse, once every principal had been demoted there was no row
        // to find at all and the school became unreachable.
        $user_id = absint( $_POST['user_id'] ?? 0 );

        if ( $user_id === 0 ) {
            set_transient(
                'educbt_school_errors_' . get_current_user_id(),
                [ 'choose which account to reset' ],
                60
            );

            wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG ) );
            exit;
        }

        // The chosen account must belong to this school.
        $belongs = absint(
            $wpdb->get_var(
                $wpdb->prepare(
                    'SELECT id FROM ' . Schema::table( 'staff' ) . ' WHERE school_id = %d AND wp_user_id = %d LIMIT 1',
                    $school_id,
                    $user_id
                )
            )
        );

        if ( $belongs === 0 ) {
            set_transient( 'educbt_school_errors_' . get_current_user_id(), [ 'that account does not belong to this school' ], 60 );
            wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG ) );
            exit;
        }

        // Restoring the principal role is part of the reset. A school with no
        // principal cannot approve results, and nothing inside the portal can fix it.
        if ( ! empty( $_POST['restore_principal'] ) ) {
            $wpdb->update(
                Schema::table( 'staff' ),
                [ 'role_slug' => \EduCBTPro\Core\Capabilities::ROLE_PRINCIPAL, 'status' => 'active' ],
                [ 'school_id' => $school_id, 'wp_user_id' => $user_id ],
                [ '%s', '%s' ],
                [ '%d', '%d' ]
            );

            $user_object = get_userdata( $user_id );

            if ( $user_object ) {
                foreach ( array_keys( \EduCBTPro\Core\Capabilities::roles() ) as $slug ) {
                    $user_object->remove_role( $slug );
                }

                $user_object->add_role( \EduCBTPro\Core\Capabilities::ROLE_PRINCIPAL );
            }
        }

        $password = wp_generate_password( 12, true, false );

        wp_set_password( $password, $user_id );
        update_user_meta( $user_id, '_educbt_must_change_password', 1 );

        $user = get_userdata( $user_id );

        set_transient(
            'educbt_reset_result_' . get_current_user_id(),
            [ 'username' => $user ? $user->user_login : '', 'password' => $password ],
            300
        );

        wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG ) );
        exit;
    }

    public function handle_toggle_subdomain(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'educbt-pro' ) );
        }

        check_admin_referer( 'educbt_toggle_subdomain' );

        HostRouter::set_subdomain_mode( ! empty( $_POST['enabled'] ) );

        wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG ) );
        exit;
    }

    public function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        global $wpdb;

        $schools_table = $wpdb->prefix . 'educbt_schools';

        $schools = $wpdb->get_results(
            'SELECT * FROM ' . $schools_table . ' ORDER BY id DESC',
            ARRAY_A
        );

        // SHOW COLUMNS fallback. When the table was created by an early version and
        // dbDelta silently failed to add newer columns (academic_settings,
        // report_settings), SELECT * could return null on some MySQL configurations.
        // Discover which columns actually exist and query only those — the schools
        // list and settings form stay populated instead of appearing empty.
        if ( $schools === null && ! empty( $wpdb->last_error ) ) {
            $wpdb->last_error = '';
            $cols = $wpdb->get_col( 'SHOW COLUMNS FROM `' . $schools_table . '`' );

            if ( is_array( $cols ) && ! empty( $cols ) ) {
                $col_list = implode( ', ', array_map( static fn( string $c ): string => '`' . esc_sql( $c ) . '`', $cols ) );
                $schools = $wpdb->get_results(
                    'SELECT ' . $col_list . ' FROM `' . $schools_table . '` ORDER BY id DESC',
                    ARRAY_A
                );
            }
        }

        $schools = is_array( $schools ) ? $schools : [];

        $user_id = get_current_user_id();
        $created = get_transient( 'educbt_new_school_' . $user_id );
        $errors  = get_transient( 'educbt_school_errors_' . $user_id );

        delete_transient( 'educbt_new_school_' . $user_id );
        delete_transient( 'educbt_school_errors_' . $user_id );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'EduCBT Schools', 'educbt-pro' ); ?></h1>

            <?php
            $reset = get_transient( 'educbt_reset_result_' . $user_id );
            delete_transient( 'educbt_reset_result_' . $user_id );
            ?>

            <?php if ( isset( $_GET['updated'] ) ) : ?>
                <div class="notice notice-success"><p><?php esc_html_e( 'School details saved.', 'educbt-pro' ); ?></p></div>
            <?php endif; ?>

            <?php if ( is_array( $reset ) ) : ?>
                <div class="notice notice-success">
                    <p><strong><?php esc_html_e( 'Principal password reset. Shown once.', 'educbt-pro' ); ?></strong></p>
                    <table class="widefat striped" style="max-width:520px;margin-bottom:1em">
                        <tr><td><?php esc_html_e( 'Username', 'educbt-pro' ); ?></td><td><code><?php echo esc_html( (string) $reset['username'] ); ?></code></td></tr>
                        <tr><td><?php esc_html_e( 'New password', 'educbt-pro' ); ?></td><td><code style="font-size:15px"><?php echo esc_html( (string) $reset['password'] ); ?></code></td></tr>
                    </table>
                </div>
            <?php endif; ?>

            <?php if ( is_array( $created ) ) : ?>
                <div class="notice notice-success">
                    <h2 style="margin-top:.6em"><?php esc_html_e( 'School created', 'educbt-pro' ); ?></h2>
                    <?php if ( ! empty( $created['account_error'] ) || $created['password'] === '' ) : ?>
                        <div class="notice notice-error inline" style="margin:8px 0;padding:10px 12px">
                            <p style="margin:0 0 6px"><strong><?php esc_html_e( 'The school was created, but the principal account was NOT.', 'educbt-pro' ); ?></strong></p>
                            <p style="margin:0 0 6px"><?php echo esc_html( (string) ( $created['account_error'] ?: __( 'The account could not be created.', 'educbt-pro' ) ) ); ?></p>
                            <p style="margin:0"><?php esc_html_e( 'Nobody can sign in to this school yet. The usual cause is that the email address already belongs to another WordPress user — use a different one, or add the principal as staff from inside the portal.', 'educbt-pro' ); ?></p>
                        </div>
                    <?php else : ?>
                        <p><strong><?php esc_html_e( 'Give these details to the principal. The password is shown once and cannot be retrieved again.', 'educbt-pro' ); ?></strong></p>
                        <?php if ( ! empty( $created['email_taken'] ) ) : ?>
                            <p class="description" style="color:#8a6d1f">
                                <?php esc_html_e( 'That email address already belonged to another user on this site, so the school code was used as the username instead. The principal can change it later from their profile.', 'educbt-pro' ); ?>
                            </p>
                        <?php endif; ?>
                    <?php endif; ?>
                    <table class="widefat striped" style="max-width:640px;margin-bottom:1em">
                        <tr><td><?php esc_html_e( 'Portal address', 'educbt-pro' ); ?></td>
                            <td><a href="<?php echo esc_url( $created['portal_url'] ); ?>" target="_blank"><?php echo esc_html( $created['portal_url'] ); ?></a></td></tr>
                        <tr><td><?php esc_html_e( 'School code', 'educbt-pro' ); ?></td><td><code><?php echo esc_html( $created['code'] ); ?></code></td></tr>
                        <tr><td><?php esc_html_e( 'Principal username', 'educbt-pro' ); ?></td><td><code><?php echo esc_html( $created['username'] ); ?></code></td></tr>
                        <tr><td><?php esc_html_e( 'Temporary password', 'educbt-pro' ); ?></td>
                            <td>
                                <?php if ( $created['password'] !== '' ) : ?>
                                    <code style="font-size:15px"><?php echo esc_html( $created['password'] ); ?></code>
                                <?php else : ?>
                                    <em style="color:#b32d2e"><?php esc_html_e( 'not created — see the error above', 'educbt-pro' ); ?></em>
                                <?php endif; ?>
                            </td></tr>
                    </table>
                    <p class="description"><?php esc_html_e( 'The principal will be asked to choose their own password at first sign-in.', 'educbt-pro' ); ?></p>
                    <?php if ( ! \EduCBTPro\Core\HostRouter::subdomain_mode() ) : ?>
                        <p class="description">
                            <?php esc_html_e( 'Subdomain addresses are switched off, so every school signs in on the main site. The subdomain is still reserved and will start working the moment you enable it below.', 'educbt-pro' ); ?>
                        </p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if ( is_array( $errors ) && ! empty( $errors ) ) : ?>
                <div class="notice notice-error"><p>
                    <?php echo esc_html( __( 'Could not create the school: ', 'educbt-pro' ) . implode( ', ', array_map( 'strval', (array) $errors ) ) ); ?>
                </p></div>
            <?php endif; ?>

            <?php
            $repair   = new RepairTool();
            $problems = $repair->diagnose();
            $repaired = get_transient( 'educbt_repair_result_' . get_current_user_id() );
            delete_transient( 'educbt_repair_result_' . get_current_user_id() );
            ?>

            <?php if ( is_array( $repaired ) ) : ?>
                <div class="notice notice-success"><p><strong><?php esc_html_e( 'Repair complete.', 'educbt-pro' ); ?></strong></p>
                <?php if ( empty( $repaired ) ) : ?>
                    <p><?php esc_html_e( 'Nothing needed fixing.', 'educbt-pro' ); ?></p>
                <?php else : ?>
                    <ul style="list-style:disc;margin-left:20px">
                    <?php foreach ( $repaired as $line ) : ?>
                        <li><?php echo esc_html( (string) $line ); ?></li>
                    <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if ( ! empty( $problems ) ) : ?>
                <div class="notice notice-warning">
                    <p><strong><?php esc_html_e( 'Some schools are incomplete and their staff may be unable to sign in.', 'educbt-pro' ); ?></strong></p>
                    <ul style="list-style:disc;margin-left:20px">
                    <?php foreach ( $problems as $problem ) : ?>
                        <li><?php echo esc_html( $problem['name'] . ' — ' . implode( '; ', $problem['problems'] ) ); ?></li>
                    <?php endforeach; ?>
                    </ul>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:10px 0 14px">
                        <input type="hidden" name="action" value="educbt_repair_schools">
                        <?php wp_nonce_field( 'educbt_repair_schools' ); ?>
                        <button type="submit" class="button button-primary"><?php esc_html_e( 'Repair these schools', 'educbt-pro' ); ?></button>
                    </form>
                </div>
            <?php endif; ?>

            <?php $subdomain_on = \EduCBTPro\Core\HostRouter::subdomain_mode(); ?>
            <div class="notice notice-info inline" style="margin:14px 0;padding:10px 12px">
                <p style="margin:0 0 6px">
                    <strong><?php esc_html_e( 'Portal addresses:', 'educbt-pro' ); ?></strong>
                    <?php if ( $subdomain_on ) : ?>
                        <?php esc_html_e( 'each school uses its own subdomain.', 'educbt-pro' ); ?>
                    <?php else : ?>
                        <?php echo esc_html( sprintf( __( 'all schools sign in at %s.', 'educbt-pro' ), home_url( '/portal/' ) ) ); ?>
                    <?php endif; ?>
                </p>
                <?php if ( ! $subdomain_on ) : ?>
                    <p class="description" style="margin:0">
                        <?php esc_html_e( 'Only turn subdomains on once wildcard DNS (*.yourdomain.com) and a wildcard SSL certificate are both in place — otherwise school links will not open.', 'educbt-pro' ); ?>
                    </p>
                <?php endif; ?>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:8px">
                    <input type="hidden" name="action" value="educbt_toggle_subdomain">
                    <input type="hidden" name="enabled" value="<?php echo $subdomain_on ? '0' : '1'; ?>">
                    <?php wp_nonce_field( 'educbt_toggle_subdomain' ); ?>
                    <button type="submit" class="button">
                        <?php echo $subdomain_on
                            ? esc_html__( 'Switch back to main-domain addresses', 'educbt-pro' )
                            : esc_html__( 'Enable subdomain addresses', 'educbt-pro' ); ?>
                    </button>
                </form>
            </div>

            <div style="display:flex;gap:28px;flex-wrap:wrap;align-items:flex-start">

                <div style="flex:1 1 380px;max-width:520px">
                    <h2><?php esc_html_e( 'Register a new school', 'educbt-pro' ); ?></h2>
                    <p class="description" style="margin-bottom:1em">
                        <?php esc_html_e( 'Only the name is required. The school code, subdomain, principal account and full academic structure are generated automatically.', 'educbt-pro' ); ?>
                    </p>

                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                        <input type="hidden" name="action" value="educbt_create_school">
                        <?php wp_nonce_field( 'educbt_create_school' ); ?>

                        <table class="form-table" role="presentation">
                            <tr>
                                <th scope="row"><label for="school_name"><?php esc_html_e( 'School name', 'educbt-pro' ); ?> <span style="color:#b32d2e">*</span></label></th>
                                <td><input name="school_name" id="school_name" type="text" class="regular-text" required></td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="address"><?php esc_html_e( 'Address', 'educbt-pro' ); ?></label></th>
                                <td><textarea name="address" id="address" rows="2" class="regular-text"></textarea></td>
                            </tr>
                            <tr>
                                <th scope="row"><label><?php esc_html_e( 'School logo', 'educbt-pro' ); ?></label></th>
                                <td>
                                    <div id="educbt-logo-preview" style="margin-bottom:8px"></div>
                                    <input type="hidden" name="logo" id="educbt-logo">
                                    <button type="button" class="button" id="educbt-logo-pick"><?php esc_html_e( 'Choose logo', 'educbt-pro' ); ?></button>
                                    <button type="button" class="button-link" id="educbt-logo-clear" style="margin-left:8px"><?php esc_html_e( 'Remove', 'educbt-pro' ); ?></button>
                                    <p class="description"><?php esc_html_e( 'Appears on the portal, report sheets, and as the transcript watermark. A square image works best.', 'educbt-pro' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="principal_name"><?php esc_html_e( 'Principal name', 'educbt-pro' ); ?></label></th>
                                <td><input name="principal_name" id="principal_name" type="text" class="regular-text"></td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="email"><?php esc_html_e( 'Principal email', 'educbt-pro' ); ?> <span style="color:#b32d2e">*</span></label></th>
                                <td>
                                    <input name="email" id="email" type="email" class="regular-text" required>
                                    <p class="description"><?php esc_html_e( 'This becomes the principal\'s username, and is used for password recovery. It must not already belong to another user on this site.', 'educbt-pro' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="phone"><?php esc_html_e( 'Phone', 'educbt-pro' ); ?></label></th>
                                <td><input name="phone" id="phone" type="text" class="regular-text"></td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="subdomain"><?php esc_html_e( 'Subdomain', 'educbt-pro' ); ?></label></th>
                                <td>
                                    <input name="subdomain" id="subdomain" type="text" class="regular-text" placeholder="<?php esc_attr_e( 'leave blank to generate', 'educbt-pro' ); ?>">
                                    <p class="description">
                                        <?php echo esc_html( sprintf( __( 'Becomes yourschool.%s', 'educbt-pro' ), HostRouter::root_domain() ) ); ?>
                                    </p>
                                </td>
                            </tr>
                        </table>

                        <?php submit_button( __( 'Create school', 'educbt-pro' ) ); ?>
                    </form>
                </div>

                <div style="flex:1 1 340px">
                    <h2><?php esc_html_e( 'Schools', 'educbt-pro' ); ?></h2>
                    <?php
                    // Distinguish "none created" from "could not be read", which look
                    // identical on screen and need completely different responses.
                    $raw_count = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'educbt_schools' );
                    ?>
                    <?php if ( empty( $schools ) && $raw_count > 0 ) : ?>
                        <div class="notice notice-error inline" style="margin:0 0 12px;padding:10px 12px">
                            <p style="margin:0"><strong><?php echo esc_html( sprintf( '%d school(s) exist but could not be read.', $raw_count ) ); ?></strong></p>
                            <p style="margin:6px 0 0"><?php esc_html_e( 'The table is missing a column this page needs. Deactivate and reactivate the plugin to apply pending schema updates, then reload.', 'educbt-pro' ); ?></p>
                        </div>
                    <?php elseif ( empty( $schools ) ) : ?>
                        <p><?php esc_html_e( 'No schools yet.', 'educbt-pro' ); ?></p>
                    <?php else : ?>
                        <table class="widefat striped">
                            <thead><tr>
                                <th><?php esc_html_e( 'School', 'educbt-pro' ); ?></th>
                                <th><?php esc_html_e( 'Principal', 'educbt-pro' ); ?></th>
                                <th><?php esc_html_e( 'Code', 'educbt-pro' ); ?></th>
                                <th><?php esc_html_e( 'Portal', 'educbt-pro' ); ?></th>
                                <th><?php esc_html_e( 'Actions', 'educbt-pro' ); ?></th>
                            </tr></thead>
                            <tbody>
                            <?php foreach ( $schools as $school ) :
                                $sid = (int) $school['id']; ?>
                                <tr>
                                    <td>
                                        <?php if ( ! empty( $school['logo'] ) ) : ?>
                                            <img src="<?php echo esc_url( (string) $school['logo'] ); ?>" alt="" style="width:36px;height:36px;object-fit:contain;vertical-align:middle;margin-right:8px;border-radius:4px">
                                        <?php else : ?>
                                            <span style="display:inline-block;width:36px;height:36px;line-height:36px;text-align:center;background:#f0f0f1;border-radius:4px;font-size:12px;color:#646970;vertical-align:middle;margin-right:8px">—</span>
                                        <?php endif; ?>
                                        <strong><?php echo esc_html( (string) $school['school_name'] ); ?></strong>
                                    </td>
                                    <td><?php echo esc_html( (string) ( $school['principal_name'] ?? '—' ) ); ?></td>
                                    <td><code><?php echo esc_html( (string) $school['school_code'] ); ?></code></td>
                                    <td>
                                        <?php $url = HostRouter::url_for( $sid, '/' ); ?>
                                        <a href="<?php echo esc_url( $url ); ?>" target="_blank"><?php echo esc_html( (string) ( $school['subdomain'] ?: '—' ) ); ?></a>
                                        <br>
                                        <button type="button" class="button-link"
                                                onclick="var r=document.getElementById('sch-<?php echo esc_attr( (string) $sid ); ?>');r.style.display=r.style.display==='none'?'table-row':'none';">Edit</button>
                                    </td>
                                    <td>
                                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline"
                                              onsubmit="return confirm('Permanently delete <?php echo esc_attr( (string) $school['school_name'] ); ?>?\n\nThis removes the school and ALL its data — staff, students, questions, exams, results — from every table. This cannot be undone.');">
                                            <input type="hidden" name="action" value="educbt_delete_school">
                                            <input type="hidden" name="school_id" value="<?php echo esc_attr( (string) $sid ); ?>">
                                            <?php wp_nonce_field( 'educbt_delete_school' ); ?>
                                            <button type="submit" class="button button-small" style="color:#a00;border-color:#a00">Delete</button>
                                        </form>
                                    </td>
                                </tr>
                                <tr id="sch-<?php echo esc_attr( (string) $sid ); ?>" style="display:none;background:#f6f7f7">
                                    <td colspan="5">
                                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="padding:10px 0">
                                            <input type="hidden" name="action" value="educbt_admin_update_school">
                                            <input type="hidden" name="school_id" value="<?php echo esc_attr( (string) $sid ); ?>">
                                            <?php wp_nonce_field( 'educbt_admin_update_school' ); ?>
                                            <p><label>School name<br>
                                                <input name="school_name" type="text" class="regular-text" value="<?php echo esc_attr( (string) $school['school_name'] ); ?>"></label></p>
                                            <p><label>Principal name<br>
                                                <input name="principal_name" type="text" class="regular-text" value="<?php echo esc_attr( (string) ( $school['principal_name'] ?? '' ) ); ?>"></label></p>
                                            <p><label>Address<br>
                                                <textarea name="address" rows="2" class="regular-text"><?php echo esc_textarea( (string) ( $school['address'] ?? '' ) ); ?></textarea></label></p>
                                            <p><label>Phone<br>
                                                <input name="phone" type="text" class="regular-text" value="<?php echo esc_attr( (string) ( $school['phone'] ?? '' ) ); ?>"></label></p>
                                            <p><label>Email<br>
                                                <input name="email" type="email" class="regular-text" value="<?php echo esc_attr( (string) ( $school['email'] ?? '' ) ); ?>"></label></p>
                                            <p><label>Logo URL<br>
                                                <input name="logo" type="url" class="regular-text" value="<?php echo esc_attr( (string) ( $school['logo'] ?? '' ) ); ?>"></label></p>
                                            <p><button type="submit" class="button button-primary">Save changes</button></p>
                                        </form>

                                        <?php
                                        $accounts = (array) $wpdb->get_results(
                                            $wpdb->prepare(
                                                'SELECT st.wp_user_id, st.first_name, st.last_name, st.role_slug
                                                 FROM ' . Schema::table( 'staff' ) . ' st
                                                 WHERE st.school_id = %d AND st.wp_user_id IS NOT NULL
                                                 ORDER BY st.id ASC',
                                                $sid
                                            ),
                                            ARRAY_A
                                        );
                                        ?>
                                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
                                              style="border-top:1px solid #dcdcde;padding-top:10px"
                                              onsubmit="return confirm('Reset this account\'s password? The current one stops working immediately.');">
                                            <input type="hidden" name="action" value="educbt_reset_principal">
                                            <input type="hidden" name="school_id" value="<?php echo esc_attr( (string) $sid ); ?>">
                                            <?php wp_nonce_field( 'educbt_reset_principal' ); ?>

                                            <p><label>Account to reset<br>
                                                <select name="user_id" class="regular-text">
                                                    <option value="">Choose an account</option>
                                                    <?php foreach ( $accounts as $acc ) :
                                                        $u = get_userdata( (int) $acc['wp_user_id'] );
                                                        if ( ! $u ) { continue; } ?>
                                                        <option value="<?php echo esc_attr( (string) $acc['wp_user_id'] ); ?>">
                                                            <?php echo esc_html( trim( $acc['first_name'] . ' ' . $acc['last_name'] ) . ' — ' . $u->user_login . ' (' . $acc['role_slug'] . ')' ); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select></label></p>

                                            <p><label>
                                                <input type="checkbox" name="restore_principal" value="1">
                                                Also make this account the principal
                                            </label><br>
                                            <span class="description">Use this if every principal was demoted — nothing inside the portal can fix that.</span></p>

                                            <p><button type="submit" class="button">Reset password</button>
                                            <span class="description" style="margin-left:8px">Shown once. They must change it at next sign-in.</span></p>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <script>
        jQuery(function ($) {
            var frame;
            $('#educbt-logo-pick').on('click', function (e) {
                e.preventDefault();
                if (frame) { frame.open(); return; }
                frame = wp.media({ title: 'Select school logo', button: { text: 'Use this logo' }, multiple: false });
                frame.on('select', function () {
                    var img = frame.state().get('selection').first().toJSON();
                    $('#educbt-logo').val(img.url);
                    $('#educbt-logo-preview').html('<img src="' + img.url + '" style="max-width:110px;height:auto;border:1px solid #ddd;padding:4px;background:#fff">');
                });
                frame.open();
            });
            $('#educbt-logo-clear').on('click', function (e) {
                e.preventDefault();
                $('#educbt-logo').val('');
                $('#educbt-logo-preview').empty();
            });
        });
        </script>
        <?php
    }
}
