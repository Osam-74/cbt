<?php

namespace EduCBTPro\Admin;

use EduCBTPro\Core\Capabilities;
use EduCBTPro\Core\Schema;
use EduCBTPro\Core\Seeder;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Repair schools left half-created by an earlier failure.
 *
 * A school that was created before the idempotency fixes can be missing its
 * session, its terms, or its principal's staff row. A missing staff row is the
 * worst of the three: it denies the principal every capability and loops them on
 * their own dashboard.
 *
 * This finds and fixes those without needing anyone to touch the database.
 */
class RepairTool {

    public function init(): void {
        add_action( 'admin_post_educbt_repair_schools', [ $this, 'handle' ] );
    }

    /**
     * @return array<int,array{school_id:int,name:string,problems:array<int,string>}>
     */
    public function diagnose(): array {
        global $wpdb;

        $schools = (array) $wpdb->get_results(
            'SELECT * FROM ' . $wpdb->prefix . 'educbt_schools ORDER BY id ASC',
            ARRAY_A
        );

        $out = [];

        foreach ( $schools as $school ) {
            $school_id = absint( $school['id'] );
            $problems  = [];

            $sessions = absint(
                $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Schema::table( 'academic_sessions' ) . ' WHERE school_id = %d', $school_id ) )
            );

            if ( $sessions === 0 ) {
                $problems[] = 'no academic session';
            }

            $orphan_terms = absint(
                $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Schema::table( 'terms' ) . ' WHERE school_id = %d AND session_id = 0', $school_id ) )
            );

            if ( $orphan_terms > 0 ) {
                $problems[] = $orphan_terms . ' terms attached to no session';
            }

            $components = absint(
                $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Schema::table( 'assessment_components' ) . " WHERE school_id = %d AND status = 'active'", $school_id ) )
            );

            if ( $components < 2 ) {
                $problems[] = 'assessment components missing';
            }

            $levels = absint(
                $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Schema::table( 'class_levels' ) . ' WHERE school_id = %d', $school_id ) )
            );

            if ( $levels === 0 ) {
                $problems[] = 'no class levels';
            }

            $principals = absint(
                $wpdb->get_var(
                    $wpdb->prepare(
                        'SELECT COUNT(*) FROM ' . Schema::table( 'staff' ) . ' WHERE school_id = %d AND role_slug = %s',
                        $school_id,
                        Capabilities::ROLE_PRINCIPAL
                    )
                )
            );

            if ( $principals === 0 ) {
                $problems[] = 'principal has no staff record — they will be locked out';
            }

            if ( ! empty( $problems ) ) {
                $out[] = [
                    'school_id' => $school_id,
                    'name'      => (string) $school['school_name'],
                    'problems'  => $problems,
                ];
            }
        }

        return $out;
    }

    public function handle(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'educbt-pro' ) );
        }

        check_admin_referer( 'educbt_repair_schools' );

        $fixed = $this->repair_all();

        set_transient( 'educbt_repair_result_' . get_current_user_id(), $fixed, 120 );

        wp_safe_redirect( admin_url( 'admin.php?page=' . PlatformAdminController::MENU_SLUG ) );
        exit;
    }

    /**
     * @return array<int,string>
     */
    public function repair_all(): array {
        global $wpdb;

        $log = [];

        foreach ( $this->diagnose() as $school ) {
            $school_id = $school['school_id'];

            // Clean out terms that belong to nothing before re-seeding.
            $wpdb->query(
                $wpdb->prepare( 'DELETE FROM ' . Schema::table( 'terms' ) . ' WHERE school_id = %d AND session_id = 0', $school_id )
            );

            Seeder::seed_school( $school_id );

            $session_id = $this->ensure_session( $school_id );

            if ( $session_id > 0 ) {
                $this->ensure_terms( $school_id, $session_id );
            }

            $this->ensure_principal_record( $school_id );

            $log[] = sprintf( '%s (#%d): %s', $school['name'], $school_id, implode( '; ', $school['problems'] ) );
        }

        return $log;
    }

    private function ensure_session( int $school_id ): int {
        global $wpdb;

        $table = Schema::table( 'academic_sessions' );

        $existing = absint(
            $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE school_id = %d ORDER BY id ASC LIMIT 1", $school_id ) )
        );

        if ( $existing > 0 ) {
            $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET is_current = 0 WHERE school_id = %d", $school_id ) );
            $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET is_current = 1 WHERE id = %d", $existing ) );

            return $existing;
        }

        $year  = (int) gmdate( 'Y' );
        $start = (int) gmdate( 'n' ) >= 9 ? $year : $year - 1;

        $wpdb->insert(
            $table,
            [
                'school_id'  => $school_id,
                'title'      => $start . '/' . ( $start + 1 ),
                'starts_on'  => $start . '-09-01',
                'ends_on'    => ( $start + 1 ) . '-07-31',
                'is_current' => 1,
                'status'     => 'active',
            ],
            [ '%d', '%s', '%s', '%s', '%d', '%s' ]
        );

        return absint( $wpdb->insert_id );
    }

    private function ensure_terms( int $school_id, int $session_id ): void {
        global $wpdb;

        $start = (int) substr( (string) $wpdb->get_var(
            $wpdb->prepare( 'SELECT title FROM ' . Schema::table( 'academic_sessions' ) . ' WHERE id = %d', $session_id )
        ), 0, 4 );

        $terms = [
            [ 'First Term', 1, $start . '-09-01', $start . '-12-20' ],
            [ 'Second Term', 2, ( $start + 1 ) . '-01-08', ( $start + 1 ) . '-04-05' ],
            [ 'Third Term', 3, ( $start + 1 ) . '-04-22', ( $start + 1 ) . '-07-25' ],
        ];

        foreach ( $terms as [ $title, $order, $from, $to ] ) {
            $wpdb->query(
                $wpdb->prepare(
                    'INSERT IGNORE INTO ' . Schema::table( 'terms' ) .
                    ' (school_id, session_id, title, term_order, starts_on, ends_on, is_current)
                      VALUES (%d, %d, %s, %d, %s, %s, %d)',
                    $school_id,
                    $session_id,
                    $title,
                    $order,
                    $from,
                    $to,
                    $order === 1 ? 1 : 0
                )
            );
        }
    }

    /**
     * Rebuild the principal's staff row from whichever WordPress user holds the
     * principal role for this school.
     */
    private function ensure_principal_record( int $school_id ): void {
        global $wpdb;

        $staff = Schema::table( 'staff' );

        $exists = absint(
            $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$staff} WHERE school_id = %d AND role_slug = %s",
                    $school_id,
                    Capabilities::ROLE_PRINCIPAL
                )
            )
        );

        if ( $exists > 0 ) {
            return;
        }

        $users = get_users(
            [
                'role'       => Capabilities::ROLE_PRINCIPAL,
                'meta_key'   => '_educbt_school_id',
                'meta_value' => $school_id,
                'number'     => 1,
            ]
        );

        if ( empty( $users ) ) {
            return;
        }

        $user = $users[0];
        $code = (string) $wpdb->get_var(
            $wpdb->prepare( 'SELECT school_code FROM ' . $wpdb->prefix . 'educbt_schools WHERE id = %d', $school_id )
        );

        $number = ( $code ?: 'SCH' ) . '-' . str_pad( (string) wp_rand( 1, 999 ), 3, '0', STR_PAD_LEFT );

        $wpdb->insert(
            $staff,
            [
                'school_id'    => $school_id,
                'staff_number' => $number,
                'wp_user_id'   => absint( $user->ID ),
                'first_name'   => 'Principal',
                'last_name'    => '',
                'email'        => (string) $user->user_email,
                'role_slug'    => Capabilities::ROLE_PRINCIPAL,
                'status'       => 'active',
            ],
            [ '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s' ]
        );
    }
}
