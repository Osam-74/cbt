<?php

namespace EduCBTPro\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * PHASE 2 — subdomain routing.
 *
 * Each school lives at {subdomain}.yourdomain.com. Resolution is host-based and
 * host-based only; TenantContext already refuses request parameters, headers and
 * cookies as sources of tenant identity.
 *
 * This class adds the pieces around that:
 *
 *  - reserving labels that must never become a school
 *  - validating and allocating a subdomain when a school is created
 *  - BINDING AUTHENTICATION TO THE HOST, so a School A user authenticating on
 *    School B's subdomain is rejected even with entirely correct credentials
 *  - scoping the auth cookie to the exact host, so a stolen cookie is useless
 *    on a sibling subdomain
 *
 * Infrastructure this depends on, which is not something code can do for you:
 *   1. Wildcard DNS  *.yourdomain.com -> your server
 *   2. Wildcard TLS certificate, issued via the DNS-01 challenge. HTTP-01 cannot
 *      issue wildcards, which catches people out.
 *   3. The web server configured to serve the WordPress vhost for any subdomain.
 */
class HostRouter {

    /**
     * Labels that must never be allocated to a school, because they are either
     * infrastructure or would be confusing.
     *
     * @return array<int,string>
     */
    public static function reserved_labels(): array {
        return [
            'www', 'mail', 'email', 'smtp', 'imap', 'pop', 'ftp', 'ns1', 'ns2',
            'admin', 'administrator', 'api', 'app', 'apps', 'cdn', 'static',
            'assets', 'portal', 'dashboard', 'support', 'help', 'docs', 'blog',
            'status', 'test', 'staging', 'dev', 'demo', 'localhost', 'my',
            'account', 'billing', 'pay', 'secure', 'login', 'auth', 'sso',
        ];
    }

    /**
     * Normalise a school name into a candidate subdomain label.
     */
    public static function slugify( string $name ): string {
        $label = strtolower( trim( $name ) );
        $label = (string) preg_replace( '/[^a-z0-9]+/', '-', $label );
        $label = trim( $label, '-' );
        $label = (string) preg_replace( '/-{2,}/', '-', $label );

        return substr( $label, 0, 40 );
    }

    /**
     * @return array{valid:bool,reason:string}
     */
    public static function validate_label( string $label ): array {
        $label = strtolower( trim( $label ) );

        if ( $label === '' ) {
            return [ 'valid' => false, 'reason' => 'empty' ];
        }

        if ( strlen( $label ) < 3 ) {
            return [ 'valid' => false, 'reason' => 'too_short' ];
        }

        if ( strlen( $label ) > 63 ) {
            return [ 'valid' => false, 'reason' => 'too_long' ];
        }

        if ( ! preg_match( '/^[a-z0-9]([a-z0-9\-]*[a-z0-9])?$/', $label ) ) {
            return [ 'valid' => false, 'reason' => 'invalid_characters' ];
        }

        // Reserved for IDN punycode.
        if ( strpos( $label, 'xn--' ) === 0 ) {
            return [ 'valid' => false, 'reason' => 'reserved_prefix' ];
        }

        if ( in_array( $label, self::reserved_labels(), true ) ) {
            return [ 'valid' => false, 'reason' => 'reserved' ];
        }

        return [ 'valid' => true, 'reason' => '' ];
    }

    public static function is_available( string $label, int $ignore_school_id = 0 ): bool {
        global $wpdb;

        $table = $wpdb->prefix . 'educbt_schools';

        $existing = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$table} WHERE subdomain = %s AND id <> %d LIMIT 1",
                $label,
                $ignore_school_id
            )
        );

        return empty( $existing );
    }

    /**
     * Allocate a unique label, appending a numeric suffix on collision.
     */
    public static function allocate( string $school_name ): string {
        $base = self::slugify( $school_name );

        if ( ! self::validate_label( $base )['valid'] ) {
            $base = 'school';
        }

        $candidate = $base;
        $n         = 2;

        while ( ! self::is_available( $candidate ) || ! self::validate_label( $candidate )['valid'] ) {
            $candidate = substr( $base, 0, 58 ) . '-' . $n;
            $n++;

            if ( $n > 500 ) {
                $candidate = 'school-' . wp_generate_password( 6, false, false );
                break;
            }
        }

        return strtolower( $candidate );
    }

    /**
     * Is subdomain routing actually usable on this installation?
     *
     * Subdomains need wildcard DNS and a wildcard TLS certificate. Neither can be
     * created from PHP, so until the operator confirms both are in place, handing a
     * school a subdomain link produces a browser error and a support call.
     *
     * Default OFF. Everything works on the main domain: tenant identity comes from
     * the signed-in user's own record, so /portal/ resolves correctly either way.
     * Turning this on is a deployment decision, not a code one.
     */
    public static function subdomain_mode(): bool {
        return (bool) get_option( 'educbt_subdomain_mode', false );
    }

    public static function set_subdomain_mode( bool $enabled ): bool {
        return update_option( 'educbt_subdomain_mode', $enabled ? 1 : 0, false );
    }

    public static function url_for( int $school_id, string $path = '/' ): string {
        global $wpdb;

        // Subdomain routing not yet enabled: keep everyone on the main domain, where
        // the portal genuinely works today.
        if ( ! self::subdomain_mode() ) {
            return home_url( $path );
        }

        $table = $wpdb->prefix . 'educbt_schools';

        $row = $wpdb->get_row(
            $wpdb->prepare( "SELECT subdomain, custom_domain FROM {$table} WHERE id = %d", $school_id ),
            ARRAY_A
        );

        if ( ! $row ) {
            return home_url( $path );
        }

        $scheme = is_ssl() ? 'https' : 'http';

        if ( ! empty( $row['custom_domain'] ) ) {
            return $scheme . '://' . $row['custom_domain'] . $path;
        }

        if ( empty( $row['subdomain'] ) ) {
            return home_url( $path );
        }

        $root = self::root_domain();

        return $scheme . '://' . $row['subdomain'] . '.' . $root . $path;
    }

    /**
     * The platform's registrable domain, from the WordPress home URL.
     */
    public static function root_domain(): string {
        $host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
        $host = preg_replace( '/^www\./', '', strtolower( $host ) ) ?? '';

        return $host;
    }

    // -------------------------------------------------------------------
    // Authentication binding
    // -------------------------------------------------------------------

    public function init(): void {
        add_filter( 'authenticate', [ $this, 'bind_login_to_host' ], 90, 3 );
        add_filter( 'auth_cookie_expiration', [ $this, 'cookie_expiration' ], 10, 3 );
        add_action( 'init', [ $this, 'scope_cookies_to_host' ], 0 );
    }

    /**
     * Reject an authentication whose user belongs to a different school than the
     * host being used. Without this, credentials leaked from one school could be
     * replayed against another school's portal.
     *
     * @param \WP_User|\WP_Error|null $user
     * @return \WP_User|\WP_Error|null
     */
    public function bind_login_to_host( $user, $username, $password ) {
        unset( $username, $password );

        if ( ! is_object( $user ) || ! isset( $user->ID ) || is_wp_error( $user ) ) {
            return $user;
        }

        // Platform admins are not bound to a single school.
        if ( user_can( $user->ID, 'manage_options' ) ) {
            return $user;
        }

        $tenant      = new TenantContext();
        $host_school = $tenant->resolve_from_host();

        // Root domain, or a host with no school configured yet: nothing to bind to.
        if ( $host_school === null ) {
            return $user;
        }

        $user_school = self::school_of_user( absint( $user->ID ) );

        if ( $user_school === 0 || $user_school === $host_school ) {
            return $user;
        }

        EventDispatcher::action( 'educbt_cross_school_login_blocked', [
            'user_id'        => absint( $user->ID ),
            'user_school_id' => $user_school,
            'host_school_id' => $host_school,
        ] );

        return new \WP_Error(
            'educbt_wrong_school',
            __( 'This account does not belong to this school portal. Please use your own school address.', 'educbt-pro' )
        );
    }

    public static function school_of_user( int $user_id ): int {
        global $wpdb;

        $students = $wpdb->prefix . 'educbt_students';
        $school   = $wpdb->get_var(
            $wpdb->prepare( "SELECT school_id FROM {$students} WHERE wp_user_id = %d LIMIT 1", $user_id )
        );

        if ( $school ) {
            return absint( $school );
        }

        foreach ( [ Schema::table( 'staff' ), Schema::table( 'guardians' ) ] as $table ) {
            $school = $wpdb->get_var(
                $wpdb->prepare( "SELECT school_id FROM {$table} WHERE wp_user_id = %d LIMIT 1", $user_id )
            );

            if ( $school ) {
                return absint( $school );
            }
        }

        $users = $wpdb->prefix . 'educbt_users';
        $school = $wpdb->get_var(
            $wpdb->prepare( "SELECT school_id FROM {$users} WHERE wp_user_id = %d LIMIT 1", $user_id )
        );

        return $school ? absint( $school ) : 0;
    }

    /**
     * Pin the auth cookie to the exact host. A cookie issued on
     * greenfield.example.com must not be presented on unity.example.com.
     */
    public function scope_cookies_to_host(): void {
        if ( defined( 'COOKIE_DOMAIN' ) ) {
            return;
        }

        $host = isset( $_SERVER['HTTP_HOST'] ) ? strtolower( (string) wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';
        $host = (string) preg_replace( '/:\d+$/', '', $host );
        $host = (string) preg_replace( '/[^a-z0-9.\-]/', '', $host );

        if ( $host === '' ) {
            return;
        }

        // An empty-string domain makes the browser scope the cookie to the exact
        // host, which is precisely what we want here.
        define( 'COOKIE_DOMAIN', '' );
    }

    /**
     * Students sitting an exam should not be logged out mid-paper; everyone else
     * gets a shorter window.
     */
    public function cookie_expiration( $length, $user_id, $remember ) {
        if ( user_can( $user_id, Capabilities::SIT_EXAM ) ) {
            return max( (int) $length, 6 * HOUR_IN_SECONDS );
        }

        return $remember ? $length : min( (int) $length, DAY_IN_SECONDS );
    }
}
