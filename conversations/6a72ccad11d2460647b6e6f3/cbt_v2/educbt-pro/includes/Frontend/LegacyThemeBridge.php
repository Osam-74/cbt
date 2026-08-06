<?php

namespace EduCBTPro\Frontend;

use EduCBTPro\Core\AdminLockdown;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Takes routing back from the old theme.
 *
 * The v1 theme creates its own `portal-login` and `portal-dashboard` pages and
 * gates them in its own `template_redirect` handler. With the plugin also routing
 * /portal/, a user is told to sign in at two different addresses and lands on the
 * theme's old dashboard rather than the real one.
 *
 * Rather than requiring a specific theme, the plugin simply asserts ownership:
 * the theme's portal hooks are unhooked, and its portal pages redirect into
 * /portal/. That way the portal behaves identically whatever theme is active,
 * which was the point of moving routing out of the theme in the first place.
 */
class LegacyThemeBridge {

    /** Theme functions that duplicate what the plugin now owns. */
    private const LEGACY_HOOKS = [
        'template_redirect' => [ 'educbt_theme_handle_page_access' ],
        'init'              => [ 'educbt_theme_handle_preview_query' ],
        'after_switch_theme' => [ 'educbt_theme_create_required_pages' ],
    ];

    /** Theme page option keys whose pages should now point at the portal. */
    private const LEGACY_PAGES = [
        'login'          => '/portal/',
        'dashboard'      => '/portal/',
        'exams'          => '/portal/student/timetable/',
        'results_print'  => '/portal/student/results/',
        'student_search' => '/portal/school/students/',
    ];

    public function init(): void {
        // Late, so the theme has finished registering before we unhook it.
        add_action( 'wp', [ $this, 'release_theme_hooks' ], 1 );
        add_action( 'template_redirect', [ $this, 'redirect_legacy_pages' ], 5 );
        add_filter( 'login_redirect', [ $this, 'single_login_destination' ], 99, 3 );
        add_action( 'admin_notices', [ $this, 'notice' ] );
    }

    public function release_theme_hooks(): void {
        foreach ( self::LEGACY_HOOKS as $hook => $callbacks ) {
            foreach ( $callbacks as $callback ) {
                if ( function_exists( $callback ) ) {
                    remove_action( $hook, $callback );
                }
            }
        }
    }

    /**
     * Anyone landing on a legacy portal page goes to the real one.
     */
    public function redirect_legacy_pages(): void {
        if ( is_admin() || ! is_page() ) {
            return;
        }

        $page_id = absint( get_queried_object_id() );

        if ( $page_id <= 0 ) {
            return;
        }

        foreach ( self::LEGACY_PAGES as $key => $destination ) {
            if ( absint( get_option( 'educbt_theme_page_id_' . $key, 0 ) ) !== $page_id ) {
                continue;
            }

            // Not signed in and heading for the old login page: send them to the
            // WordPress login, which is the one place credentials are handled.
            if ( ! is_user_logged_in() ) {
                wp_safe_redirect( home_url( '/portal/login/' ) );
                exit;
            }

            wp_safe_redirect( home_url( $destination ) );
            exit;
        }
    }

    /**
     * ONE destination after signing in.
     *
     * The theme also filters login_redirect, so both were competing and a user saw
     * two different "sign in here" addresses. Priority 99 means this runs last and
     * wins, and it defers to the same method the rest of the plugin uses.
     */
    public function single_login_destination( $redirect_to, $requested, $user ) {
        if ( is_wp_error( $user ) || ! is_object( $user ) || ! isset( $user->ID ) ) {
            return $redirect_to;
        }

        if ( get_user_meta( $user->ID, '_educbt_must_change_password', true ) ) {
            return home_url( '/portal/account/password/' );
        }

        return AdminLockdown::portal_url( (int) $user->ID );
    }

    public function notice(): void {
        if ( ! current_user_can( 'manage_options' ) || ! $this->legacy_theme_active() ) {
            return;
        }

        if ( get_option( 'educbt_legacy_theme_notice_dismissed' ) ) {
            return;
        }

        echo '<div class="notice notice-warning"><p><strong>EduCBT:</strong> the active theme includes its own portal pages, which the plugin has taken over. '
            . 'The portal will work correctly, but the theme&rsquo;s own dashboard and login pages are no longer used. '
            . 'Everything now lives at <code>' . esc_html( home_url( '/portal/' ) ) . '</code>.</p></div>';
    }

    private function legacy_theme_active(): bool {
        return function_exists( 'educbt_theme_page_definitions' );
    }
}
