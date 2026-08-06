<?php

namespace EduCBTPro\Frontend;

use EduCBTPro\Core\TenantContext;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * PHASE 10 — the front door.
 *
 * You said the theme homepage was a mess because it advertised what the plugin and
 * theme do. It was, and the deeper problem is that there are TWO front doors on a
 * subdomain platform and v1 had one:
 *
 *   ROOT DOMAIN        yourdomain.com          the product site. Say whatever you
 *                                              like about EduCBT here.
 *   SCHOOL SUBDOMAIN   greenfield.yourdomain   the school's own portal. No marketing
 *                                              at all — their crest, their name, a
 *                                              sign-in box, their notices.
 *
 * A parent arriving at their child's school portal should see the school, not a
 * pitch for the software the school happens to run.
 */
class LandingController {

    public function init(): void {
        add_filter( 'template_include', [ $this, 'route_front_page' ], 20 );
        add_filter( 'login_redirect', [ $this, 'after_login' ], 20, 3 );
        add_action( 'wp_head', [ $this, 'school_meta' ] );
    }

    /**
     * On a school host, the front page is the school's landing page.
     */
    public function route_front_page( $template ) {
        if ( ! is_front_page() || is_admin() ) {
            return $template;
        }

        if ( $this->current_school_id() === 0 ) {
            // Root domain: leave the theme alone. The marketing site is ordinary
            // WordPress and a school never sees it.
            return $template;
        }

        // A signed-in user has no business on a sign-in page.
        if ( is_user_logged_in() ) {
            wp_safe_redirect( \EduCBTPro\Core\AdminLockdown::portal_url() );
            exit;
        }

        foreach ( [
            get_stylesheet_directory() . '/educbt/school-landing.php',
            get_template_directory() . '/educbt/school-landing.php',
            EDUCBT_PRO_PATH . 'templates/school-landing.php',
        ] as $candidate ) {
            if ( file_exists( $candidate ) ) {
                return $candidate;
            }
        }

        return $template;
    }

    public function after_login( $redirect_to, $requested, $user ) {
        if ( is_wp_error( $user ) || ! is_object( $user ) || ! isset( $user->ID ) ) {
            return $redirect_to;
        }

        if ( get_user_meta( $user->ID, '_educbt_must_change_password', true ) ) {
            return home_url( '/portal/account/password/' );
        }

        return \EduCBTPro\Core\AdminLockdown::portal_url( (int) $user->ID );
    }

    /**
     * Use the school's own crest as the favicon on its subdomain, so a parent with
     * six tabs open can tell which one is their child's school.
     */
    public function school_meta(): void {
        $school_id = $this->current_school_id();

        if ( $school_id === 0 ) {
            return;
        }

        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM ' . $wpdb->prefix . 'educbt_schools WHERE id = %d',
                $school_id
            ),
            ARRAY_A
        );

        if ( ! $row ) {
            return;
        }

        if ( ! empty( $row['logo'] ) ) {
            printf( '<link rel="icon" href="%s">' . "\n", esc_url( (string) $row['logo'] ) );
        }

        printf( '<meta name="application-name" content="%s">' . "\n", esc_attr( (string) $row['school_name'] ) );
    }

    /**
     * Host-derived only. Never a request parameter — the Phase 0 rule.
     */
    private function current_school_id(): int {
        static $resolved = null;

        if ( $resolved === null ) {
            $resolved = absint( ( new TenantContext() )->resolve_from_host() ?? 0 );
        }

        return $resolved;
    }
}
