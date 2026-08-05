<?php

namespace EduCBTPro\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * PHASE 2 — wp-admin lockout.
 *
 * The stated goal is that schools run entirely from front-end dashboards and never
 * see WordPress. v1 undermined that in two ways: every feature lived in wp-admin
 * menus, and `educbt_school_administrator` was granted the core capabilities
 * `list_users` and `edit_users`, which is a door straight into user management.
 *
 * This class shuts that door. Anyone whose role is a school role is redirected to
 * their portal, the admin bar is hidden, and the file-editing and user-management
 * screens are made unreachable even by direct URL.
 *
 * AJAX and REST are deliberately exempt: the front-end portal talks to
 * admin-ajax.php and the REST API, and blocking those would break the portal.
 */
class AdminLockdown {

    public function init(): void {
        add_action( 'admin_init', [ $this, 'block_admin_access' ], 1 );
        add_filter( 'show_admin_bar', [ $this, 'hide_admin_bar' ] );
        add_action( 'admin_menu', [ $this, 'strip_menus' ], 999 );
        add_filter( 'login_redirect', [ $this, 'redirect_after_login' ], 10, 3 );
    }

    /**
     * School roles. A WordPress administrator is not one of these and keeps access,
     * so a site owner can never lock themselves out.
     *
     * @return array<int,string>
     */
    public static function locked_roles(): array {
        return [
            Capabilities::ROLE_PRINCIPAL,
            Capabilities::ROLE_VICE_PRINCIPAL,
            Capabilities::ROLE_EXAM_OFFICER,
            Capabilities::ROLE_TEACHER,
            Capabilities::ROLE_STUDENT,
            Capabilities::ROLE_GUARDIAN,
        ];
    }

    public static function is_locked_user( ?int $user_id = null ): bool {
        $user_id = $user_id ?? ( function_exists( 'get_current_user_id' ) ? absint( get_current_user_id() ) : 0 );

        if ( $user_id <= 0 ) {
            return false;
        }

        if ( user_can( $user_id, 'manage_options' ) ) {
            return false;
        }

        $user = get_userdata( $user_id );
        if ( ! $user || empty( $user->roles ) ) {
            return false;
        }

        return (bool) array_intersect( (array) $user->roles, self::locked_roles() );
    }

    public function block_admin_access(): void {
        // These endpoints live under /wp-admin/ but are NOT the admin interface —
        // they are how the front-end portal submits forms and fetches data.
        //
        // admin-post.php loads wp-admin/admin.php, which fires admin_init, so
        // without this exemption every single portal form POST was redirected away
        // before its handler could run: the user pressed Save, landed back on their
        // dashboard, and nothing was written.
        if ( $this->is_portal_endpoint() ) {
            return;
        }

        if ( ! self::is_locked_user() ) {
            return;
        }

        wp_safe_redirect( self::portal_url() );
        exit;
    }

    /**
     * Requests that must pass through even for a locked-out user.
     */
    private function is_portal_endpoint(): bool {
        if ( wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
            return true;
        }

        if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
            return true;
        }

        $script = isset( $_SERVER['SCRIPT_NAME'] ) ? basename( (string) wp_unslash( $_SERVER['SCRIPT_NAME'] ) ) : '';

        // async-upload.php is how the media modal uploads a file, and media-upload.php
        // is the modal itself. Blocking them leaves a picker that opens and then fails.
        return in_array( $script, [ 'admin-post.php', 'admin-ajax.php', 'async-upload.php', 'media-upload.php' ], true );
    }

    public function hide_admin_bar( $show ) {
        return self::is_locked_user() ? false : $show;
    }

    /**
     * Defence in depth: even if a redirect is somehow bypassed, the sensitive
     * screens are removed from the menu for locked users.
     */
    public function strip_menus(): void {
        if ( ! self::is_locked_user() ) {
            return;
        }

        foreach ( [ 'users.php', 'tools.php', 'options-general.php', 'plugins.php', 'themes.php' ] as $slug ) {
            remove_menu_page( $slug );
        }
    }

    public function redirect_after_login( $redirect_to, $requested, $user ) {
        if ( is_object( $user ) && isset( $user->ID ) && self::is_locked_user( absint( $user->ID ) ) ) {
            return self::portal_url();
        }

        return $redirect_to;
    }

    /**
     * The portal landing for the current user's role.
     */
    public static function portal_url( ?int $user_id = null ): string {
        $user_id = $user_id ?? ( function_exists( 'get_current_user_id' ) ? absint( get_current_user_id() ) : 0 );
        $user    = $user_id > 0 ? get_userdata( $user_id ) : null;
        $roles   = $user && ! empty( $user->roles ) ? (array) $user->roles : [];

        $map = [
            Capabilities::ROLE_STUDENT       => 'portal/student',
            Capabilities::ROLE_GUARDIAN      => 'portal/guardian',
            Capabilities::ROLE_TEACHER       => 'portal/teacher',
            Capabilities::ROLE_EXAM_OFFICER  => 'portal/exams',
            Capabilities::ROLE_VICE_PRINCIPAL => 'portal/school',
            Capabilities::ROLE_PRINCIPAL     => 'portal/school',
        ];

        foreach ( $map as $role => $path ) {
            if ( in_array( $role, $roles, true ) ) {
                return home_url( '/' . $path . '/' );
            }
        }

        // A platform administrator has no school dashboard of their own. Sending them
        // to the school list is the useful answer.
        if ( $user_id > 0 && user_can( $user_id, 'manage_options' ) ) {
            return admin_url( 'admin.php?page=educbt-schools' );
        }

        // NEVER return /portal/ here. /portal/ redirects to this method, so returning
        // it produces an infinite redirect — which is exactly what happened to anyone
        // signed in without one of the roles above.
        return home_url( '/portal/account/no-access/' );
    }
}
