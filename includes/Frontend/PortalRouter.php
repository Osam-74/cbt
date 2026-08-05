<?php

namespace EduCBTPro\Frontend;

use EduCBTPro\Core\AdminLockdown;
use EduCBTPro\Core\Capabilities;
use EduCBTPro\Core\Gate;
use EduCBTPro\Core\Scope;
use EduCBTPro\Core\TenantContext;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * PHASE 10 — portal routing.
 *
 * Every dashboard lives under /portal/{area}/{section}. Routing is owned by the
 * PLUGIN, not the theme: v1 created its portal pages in the theme's functions.php,
 * which meant switching themes destroyed the portal. Templates remain overridable
 * by a theme, but the routes and the access control do not move.
 *
 * This is also where the Phase 2 lockout finally lands somewhere. Until now,
 * AdminLockdown redirected school users out of wp-admin to /portal/… and those
 * routes did not exist.
 *
 * Access is checked in ONE place, before any template loads. A per-template check
 * is one forgotten include away from an unguarded page.
 */
class PortalRouter {

    public const QUERY_AREA    = 'educbt_area';
    public const QUERY_SECTION = 'educbt_section';
    public const QUERY_ID      = 'educbt_id';

    private int $object_id = 0;

    /**
     * area => [ required capability, label ]
     *
     * @return array<string,array{capability:string,label:string}>
     */
    public static function areas(): array {
        return [
            'school'   => [ 'capability' => Capabilities::MANAGE_SCHOOL, 'label' => 'School' ],
            'teacher'  => [ 'capability' => Capabilities::ENTER_SCORES, 'label' => 'Teaching' ],
            'exams'    => [ 'capability' => Capabilities::VIEW_EXAMS, 'label' => 'Examinations' ],
            'student'  => [ 'capability' => Capabilities::STUDENT_PORTAL, 'label' => 'Student' ],
            'guardian' => [ 'capability' => Capabilities::GUARDIAN_PORTAL, 'label' => 'Parent' ],
        ];
    }

    /**
     * Sections within each area, and the capability each needs.
     *
     * Declaring them here rather than inside templates means the navigation and the
     * guard are generated from the same source — a menu item can never appear for a
     * section the user cannot open.
     *
     * @return array<string,array<string,array{capability:string,label:string}>>
     */
    public static function sections(): array {
        return [
            'school' => [
                ''            => [ 'capability' => Capabilities::MANAGE_SCHOOL, 'label' => 'Overview' ],
                'staff'       => [ 'capability' => Capabilities::VIEW_STAFF, 'label' => 'Staff' ],
                'students'    => [ 'capability' => Capabilities::VIEW_STUDENTS, 'label' => 'Students' ],
                'classes'     => [ 'capability' => Capabilities::MANAGE_CLASSES, 'label' => 'Classes' ],
                'subjects'    => [ 'capability' => Capabilities::MANAGE_SUBJECTS, 'label' => 'Subjects' ],
                'results'     => [ 'capability' => Capabilities::APPROVE_RESULTS, 'label' => 'Results' ],
                'promotion'   => [ 'capability' => Capabilities::RUN_PROMOTION, 'label' => 'Promotion' ],
                'transcripts' => [ 'capability' => Capabilities::ISSUE_TRANSCRIPT, 'label' => 'Transcripts' ],
                'transcript-print' => [ 'capability' => Capabilities::ISSUE_TRANSCRIPT, 'label' => '' ],
                'notices'     => [ 'capability' => Capabilities::SEND_ANNOUNCEMENT, 'label' => 'Notify Staff' ],
                'activity'    => [ 'capability' => Capabilities::VIEW_ACTIVITY_LOG, 'label' => 'Activity' ],
                'settings'    => [ 'capability' => Capabilities::MANAGE_SCHOOL, 'label' => 'Settings' ],
            ],
            'exams' => [
                ''            => [ 'capability' => Capabilities::VIEW_EXAMS, 'label' => 'Overview' ],
                'papers'      => [ 'capability' => Capabilities::MANAGE_PAPERS, 'label' => 'Papers' ],
                'timetable'   => [ 'capability' => Capabilities::VIEW_EXAMS, 'label' => 'Timetable' ],
                'questions'   => [ 'capability' => Capabilities::VIEW_QUESTIONS, 'label' => 'Question Bank' ],
                'approvals'   => [ 'capability' => Capabilities::APPROVE_QUESTIONS, 'label' => 'Approve Questions' ],
                'invigilation' => [ 'capability' => Capabilities::ASSIGN_INVIGILATORS, 'label' => 'Invigilation Schedule' ],
                'invigilate'  => [ 'capability' => Capabilities::INVIGILATE, 'label' => 'Live Exam Sessions' ],
                'marking'     => [ 'capability' => Capabilities::VIEW_EXAMS, 'label' => 'Marking' ],
                'broadsheet'  => [ 'capability' => Capabilities::VIEW_BROADSHEET, 'label' => 'Broadsheet' ],
            ],
            'teacher' => [
                ''             => [ 'capability' => Capabilities::ENTER_SCORES, 'label' => 'Dashboard' ],
                'classes'      => [ 'capability' => Capabilities::ENTER_SCORES, 'label' => 'Subjects' ],
                // A subject teacher sets their own CA tests. MANAGE_PAPERS is exam
                // officer level and would have hidden this from the people it is for.
                'tests'        => [ 'capability' => Capabilities::ENTER_SCORES, 'label' => 'Class Tests' ],
                'analysis'     => [ 'capability' => Capabilities::ENTER_SCORES, 'label' => 'Subject Results' ],
                'scores'       => [ 'capability' => Capabilities::ENTER_SCORES, 'label' => 'Record Scores' ],
                // A class teacher needs to see the students in their class(es) —
                // a list, a profile, and the ability to update basic details.
                'students'     => [ 'capability' => Capabilities::ENTER_SCORES, 'label' => 'My Students', 'requires_class_teacher' => true ],
                // Only a class teacher enrols students, and only into their own class.
                // Offering it to every teacher produced a menu item that always
                // refused — "you can only register students to a class you hold".
                'register'     => [ 'capability' => Capabilities::REGISTER_STUDENTS, 'label' => 'Class Register', 'requires_class_teacher' => true ],
                'results'      => [ 'capability' => Capabilities::ENTER_SCORES, 'label' => 'Class Results', 'requires_class_teacher' => true ],
            ],
            'student' => [
                ''          => [ 'capability' => Capabilities::STUDENT_PORTAL, 'label' => 'Dashboard' ],
                'exam'      => [ 'capability' => Capabilities::SIT_EXAM, 'label' => 'Take Exam' ],
                'timetable' => [ 'capability' => Capabilities::STUDENT_PORTAL, 'label' => 'Timetable' ],
                'subjects'  => [ 'capability' => Capabilities::STUDENT_PORTAL, 'label' => 'Subjects' ],
                'results'   => [ 'capability' => Capabilities::VIEW_OWN_RESULTS, 'label' => 'Results' ],
            ],
            'guardian' => [
                ''          => [ 'capability' => Capabilities::GUARDIAN_PORTAL, 'label' => 'Children' ],
                'results'   => [ 'capability' => Capabilities::VIEW_CHILD_RESULTS, 'label' => 'Results' ],
                'timetable' => [ 'capability' => Capabilities::GUARDIAN_PORTAL, 'label' => 'Timetable' ],
            ],
        ];
    }

    public function init(): void {
        add_action( 'init', [ $this, 'register_rewrites' ] );
        add_filter( 'query_vars', [ $this, 'register_query_vars' ] );
        add_action( 'template_redirect', [ $this, 'dispatch' ] );
    }

    public function register_rewrites(): void {
        add_rewrite_rule( '^portal/?$', 'index.php?' . self::QUERY_AREA . '=home', 'top' );
        add_rewrite_rule( '^portal/([^/]+)/?$', 'index.php?' . self::QUERY_AREA . '=$matches[1]', 'top' );
        add_rewrite_rule( '^portal/([^/]+)/([^/]+)/?$', 'index.php?' . self::QUERY_AREA . '=$matches[1]&' . self::QUERY_SECTION . '=$matches[2]', 'top' );
        add_rewrite_rule( '^portal/([^/]+)/([^/]+)/(\d+)/?$', 'index.php?' . self::QUERY_AREA . '=$matches[1]&' . self::QUERY_SECTION . '=$matches[2]&' . self::QUERY_ID . '=$matches[3]', 'top' );
    }

    /**
     * @param array<int,string> $vars
     * @return array<int,string>
     */
    public function register_query_vars( $vars ) {
        $vars[] = self::QUERY_AREA;
        $vars[] = self::QUERY_SECTION;
        $vars[] = self::QUERY_ID;

        return $vars;
    }

    /**
     * Resolve the portal route from the URL directly.
     *
     * Rewrite rules were the single biggest source of "nothing happens": they only
     * exist after a permalink flush, and if that has not run, get_query_var() is
     * empty and the request falls through to whatever the theme does — which is how
     * a sign-in ended up back at wp-admin.
     *
     * Reading the path ourselves removes that dependency completely. The portal now
     * works on a fresh install, on plain permalinks, and on a host that rewrites
     * differently, with no flush required.
     *
     * @return array{0:string,1:string,2:int}|null
     */
    private function resolve_request(): ?array {
        $area = (string) get_query_var( self::QUERY_AREA );

        if ( $area !== '' ) {
            return [
                $area,
                (string) get_query_var( self::QUERY_SECTION ),
                absint( get_query_var( self::QUERY_ID ) ),
            ];
        }

        $uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
        $path = (string) wp_parse_url( $uri, PHP_URL_PATH );

        // Respect a WordPress install living in a subdirectory.
        $home = (string) wp_parse_url( home_url(), PHP_URL_PATH );

        if ( $home !== '' && $home !== '/' && strpos( $path, $home ) === 0 ) {
            $path = substr( $path, strlen( $home ) );
        }

        $segments = array_values( array_filter( explode( '/', trim( $path, '/' ) ) ) );

        if ( empty( $segments ) || strtolower( $segments[0] ) !== 'portal' ) {
            return null;
        }

        return [
            sanitize_key( $segments[1] ?? 'home' ) ?: 'home',
            sanitize_key( $segments[2] ?? '' ),
            absint( $segments[3] ?? 0 ),
        ];
    }

    /**
     * Is this request a portal page? Used by the asset loader so styles and the media
     * picker load on exactly the pages the router serves.
     */
    public static function is_portal_request(): bool {
        if ( is_admin() ) {
            return false;
        }

        $area = function_exists( 'get_query_var' ) ? (string) get_query_var( self::QUERY_AREA ) : '';

        if ( $area !== '' ) {
            return true;
        }

        $path = (string) wp_parse_url(
            isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '',
            PHP_URL_PATH
        );

        $home = (string) wp_parse_url( home_url(), PHP_URL_PATH );

        if ( $home !== '' && $home !== '/' && strpos( $path, $home ) === 0 ) {
            $path = substr( $path, strlen( $home ) );
        }

        $segments = array_values( array_filter( explode( '/', trim( $path, '/' ) ) ) );

        return ! empty( $segments ) && strtolower( $segments[0] ) === 'portal';
    }

    public function dispatch(): void {
        $resolved = $this->resolve_request();

        if ( $resolved === null ) {
            return;
        }

        [ $area, $section, $object_id ] = $resolved;

        $this->object_id = $object_id;

        // 0 — guardian invite acceptance is a PUBLIC route. The parent has no
        //     account yet; they are here to CREATE one by setting a password. This
        //     must happen before the login check, otherwise the link just bounces
        //     them to the sign-in page with no credentials to use.
        if ( $area === 'guardian' && $section === 'accept' ) {
            $this->render( 'guardian', 'accept' );
            exit;
        }

        // 1 — must be signed in. The portal owns its own sign-in screen rather than
        // bouncing to wp-login.php, which is what produced the "it keeps sending me
        // to the WordPress admin" loop: wp-login redirects on its own terms and the
        // theme was filtering it too.
        if ( ! is_user_logged_in() ) {
            if ( $area === 'login' || $section === 'login' ) {
                $this->render( 'account', 'login' );
                exit;
            }

            wp_safe_redirect( home_url( '/portal/login/' ) );
            exit;
        }

        // A signed-in user has no need of the sign-in screen.
        if ( $area === 'login' ) {
            wp_safe_redirect( AdminLockdown::portal_url() );
            exit;
        }

        // 2 — a temporary password must be changed before anything else is reachable.
        //     Checking this at the router rather than per page is what makes it
        //     actually mandatory: there is no page that forgets to ask.
        if ( $this->must_change_password() && $section !== 'password' ) {
            wp_safe_redirect( home_url( '/portal/account/password/' ) );
            exit;
        }

        // The account area is available to ANY signed-in user: it holds the forced
        // password change and the no-access explanation, both of which must be
        // reachable by someone who has no school role at all.
        if ( $area === 'account' ) {
            $allowed = [ 'password', 'no-access', 'notifications', 'login', 'settings' ];

            if ( ! in_array( $section, $allowed, true ) ) {
                $section = 'no-access';
            }

            $this->render( 'account', $section );
            exit;
        }

        // 3 — send /portal/ to wherever this user belongs.
        if ( $area === 'home' ) {
            $destination = AdminLockdown::portal_url();

            // Loop guard. If the destination resolves back to this same page, render
            // instead of redirecting: a redirect loop gives the user nothing at all,
            // whereas a page explaining the problem is at least actionable.
            if ( $this->is_same_url( $destination, home_url( '/portal/' ) ) ) {
                $this->render( 'account', 'no-access' );
                exit;
            }

            wp_safe_redirect( $destination );
            exit;
        }

        $areas = self::areas();

        if ( ! isset( $areas[ $area ] ) ) {
            $this->not_found();
            return;
        }

        // 4 — capability for the area, then for the section.
        if ( ! Gate::allows( $areas[ $area ]['capability'] ) ) {
            $this->forbidden( $area );
            return;
        }

        $sections = self::sections()[ $area ] ?? [];

        if ( ! array_key_exists( $section, $sections ) ) {
            $this->not_found();
            return;
        }

        if ( ! Gate::allows( $sections[ $section ]['capability'] ) ) {
            $this->forbidden( $area );
            return;
        }

        $this->render( $area, $section );
        exit;
    }

    /**
     * Compare two URLs ignoring scheme, trailing slash and query string.
     */
    private function is_same_url( string $a, string $b ): bool {
        $normalise = static function ( string $url ): string {
            $parts = wp_parse_url( $url );

            return strtolower( (string) ( $parts['host'] ?? '' ) ) . '/' . trim( (string) ( $parts['path'] ?? '' ), '/' );
        };

        return $normalise( $a ) === $normalise( $b );
    }

    private function must_change_password(): bool {
        $user_id = get_current_user_id();

        return $user_id > 0 && (bool) get_user_meta( $user_id, '_educbt_must_change_password', true );
    }

    /**
     * Navigation for the current user: only sections they can actually open.
     *
     * Generated from the same table the guard uses, so a visible menu item is never
     * a dead end and a hidden one is never reachable by typing the URL.
     *
     * @return array<int,array{slug:string,label:string,url:string,current:bool}>
     */
    /**
     * Areas a user should actually be offered.
     *
     * "My Teaching" is a teacher's own workspace — their classes, their questions,
     * their score entry. A principal holds ENTER_SCORES so they could open it, but
     * offering it alongside School made the dashboard look like a teacher's. They
     * manage staff from the School area instead.
     *
     * @return array<string,string>
     */
    public static function areas_for_user(): array {
        $out = [];

        foreach ( self::areas() as $slug => $config ) {
            if ( ! Gate::allows( $config['capability'] ) ) {
                continue;
            }

            // Anyone who manages the school does not need a personal teaching area
            // unless they actually hold a teaching assignment.
            if ( $slug === 'teacher' && Gate::allows( Capabilities::MANAGE_STAFF ) && ! self::has_teaching_duties() ) {
                continue;
            }

            $out[ $slug ] = $config['label'];
        }

        return $out;
    }

    /**
     * Is the signed-in user the CLASS TEACHER of at least one class?
     *
     * Distinct from teaching a subject. "My classes" meant both things at once,
     * which is why it was confusing: a subject teacher takes a class for a subject;
     * a class teacher is responsible for the class itself — its register, its
     * remarks, its promotion.
     */
    public static function holds_a_class(): bool {
        $scope = new Scope();

        if ( $scope->is_school_wide() ) {
            return true;
        }

        $actor = $scope->actor();

        if ( $actor['type'] !== Scope::ACTOR_STAFF || absint( $actor['id'] ) === 0 ) {
            return false;
        }

        global $wpdb;

        return (bool) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT id FROM ' . \EduCBTPro\Core\Schema::table( 'staff_assignments' ) . "
                 WHERE school_id = %d AND staff_id = %d AND status = 'active'
                   AND assignment_type = 'class_teacher' LIMIT 1",
                absint( $actor['school_id'] ),
                absint( $actor['id'] )
            )
        );
    }

    /**
     * Does the signed-in user personally teach anything this session?
     */
    private static function has_teaching_duties(): bool {
        $scope = new Scope();
        $actor = $scope->actor();

        if ( $actor['type'] !== Scope::ACTOR_STAFF || absint( $actor['id'] ) === 0 ) {
            return false;
        }

        global $wpdb;

        return (bool) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT id FROM ' . \EduCBTPro\Core\Schema::table( 'staff_assignments' ) . "
                 WHERE school_id = %d AND staff_id = %d AND status = 'active'
                   AND assignment_type IN ('subject_teacher','class_teacher') LIMIT 1",
                absint( $actor['school_id'] ),
                absint( $actor['id'] )
            )
        );
    }

    public static function navigation( string $area, string $current ): array {
        $items = [];

        foreach ( self::sections()[ $area ] ?? [] as $slug => $section ) {
            // A section with no label is reachable but not advertised — print views
            // and the like, which belong in the flow rather than in the menu.
            if ( $section['label'] === '' || ! Gate::allows( $section['capability'] ) ) {
                continue;
            }

            // Some sections only make sense for a class teacher. Showing them to a
            // subject teacher gives a link that can only ever refuse.
            if ( ! empty( $section['requires_class_teacher'] ) && ! self::holds_a_class() ) {
                continue;
            }

            $items[] = [
                'slug'    => $slug,
                'label'   => $section['label'],
                'url'     => home_url( '/portal/' . $area . '/' . $slug ),
                'current' => $slug === $current,
            ];
        }

        return $items;
    }

    /**
     * Load a template, letting a child theme override it.
     *
     * Lookup order: theme/educbt/{area}/{section}.php, then the plugin's own. The
     * theme may restyle anything; it cannot take ownership of routing or access.
     */
    /**
     * Resolve the school_id with a fallback chain: TenantContext first,
     * then the logged-in user's staff/users/students record, then user meta.
     */
    private static function resolve_school_id(): int {
        $sid = absint( ( new TenantContext() )->get_school_id() ?? 0 );
        if ( $sid > 0 ) {
            return $sid;
        }

        $uid = function_exists( 'get_current_user_id' ) ? absint( get_current_user_id() ) : 0;
        if ( $uid <= 0 ) {
            return 0;
        }

        global $wpdb;

        $sid = absint( $wpdb->get_var(
            $wpdb->prepare(
                "SELECT school_id FROM {$wpdb->prefix}educbt_users WHERE wp_user_id = %d LIMIT 1",
                $uid
            )
        ) );

        if ( $sid <= 0 ) {
            $sid = absint( $wpdb->get_var(
                $wpdb->prepare(
                    'SELECT school_id FROM ' . Schema::table( 'staff' ) . ' WHERE wp_user_id = %d LIMIT 1',
                    $uid
                )
            ) );
        }

        if ( $sid <= 0 ) {
            $sid = absint( $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT school_id FROM {$wpdb->prefix}educbt_students WHERE wp_user_id = %d LIMIT 1",
                    $uid
                )
            ) );
        }

        if ( $sid <= 0 ) {
            $sid = absint( get_user_meta( $uid, '_educbt_school_id', true ) );
        }

        return $sid;
    }

    private function render( string $area, string $section ): void {
        $file = $section === '' ? 'index' : sanitize_file_name( $section );
        $area = sanitize_file_name( $area );

        $candidates = [
            get_stylesheet_directory() . "/educbt/{$area}/{$file}.php",
            get_template_directory() . "/educbt/{$area}/{$file}.php",
            EDUCBT_PRO_PATH . "templates/portal/{$area}/{$file}.php",
            EDUCBT_PRO_PATH . 'templates/portal/fallback.php',
        ];

        $context = [
            'area'       => $area,
            'section'    => $section,
            'id'         => $this->object_id ?: absint( get_query_var( self::QUERY_ID ) ),
            'school_id'  => self::resolve_school_id(),
            'scope'      => new Scope(),
            'navigation' => self::navigation( $area, $section ),
        ];

        foreach ( $candidates as $candidate ) {
            if ( file_exists( $candidate ) ) {
                ( static function ( string $__template, array $educbt ): void {
                    include $__template;
                } )( $candidate, $context );

                return;
            }
        }

        $this->not_found();
    }

    private function forbidden( string $area ): void {
        status_header( 403 );
        nocache_headers();

        $own = AdminLockdown::portal_url();

        // THE LOOP. If the destination is the very page being denied, redirecting
        // sends the browser straight back here and it bounces until it gives up.
        // That is what happened when a principal's staff row was missing: every
        // capability check failed, including the one for their own dashboard.
        if ( $this->is_same_url( $own, home_url( '/portal/' . $area . '/' ) ) ) {
            $this->render( 'account', 'no-access' );
            exit;
        }

        wp_safe_redirect( add_query_arg( 'denied', $area, $own ) );
        exit;
    }

    private function not_found(): void {
        global $wp_query;

        $wp_query->set_404();
        status_header( 404 );
    }
}