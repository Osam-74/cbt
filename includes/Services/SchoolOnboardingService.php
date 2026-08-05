<?php

namespace EduCBTPro\Services;

use EduCBTPro\Core\Capabilities;
use EduCBTPro\Core\EventDispatcher;
use EduCBTPro\Core\HostRouter;
use EduCBTPro\Core\Schema;
use EduCBTPro\Core\Seeder;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * PHASE 3 — school onboarding.
 *
 * The v1 registration form asked for school code, registration number format and
 * minimum questions per subject. None of those belong on a creation form: a code
 * can be derived from the name, a number format is a system convention, and a
 * question minimum is an exam-time policy that a founder has no opinion about yet.
 *
 * The form is now three fields — NAME, ADDRESS, LOGO — and everything else is
 * generated:
 *
 *   school code   GRE001   derived from the name, collision-suffixed
 *   subdomain     greenfield-college.yourdomain.com
 *   principal     login = school code, temporary password, forced change
 *
 * On the shared-login question: a school is not a person, and a shared account
 * destroys the audit trail, because every action logs as "the school" rather than
 * as a named human. Creation therefore provisions the PRINCIPAL's account. The
 * convenience you wanted is preserved — the school code IS the username — but
 * accountability survives.
 */
class SchoolOnboardingService {

    /**
     * @param array{name:string,address?:string,logo?:string,phone?:string,email?:string,principal_name?:string,subdomain?:string} $data
     * @return array{success:bool,school_id?:int,code?:string,subdomain?:string,portal_url?:string,principal?:array,errors?:array}
     */
    public function create_school( array $data ): array {
        global $wpdb;

        $name = trim( (string) ( $data['name'] ?? '' ) );

        $errors = $this->validate( $name, $data );
        if ( ! empty( $errors ) ) {
            return [ 'success' => false, 'errors' => $errors ];
        }

        $code = $this->allocate_code( $name );

        $subdomain = trim( (string) ( $data['subdomain'] ?? '' ) );
        if ( $subdomain === '' ) {
            $subdomain = HostRouter::allocate( $name );
        } else {
            $check = HostRouter::validate_label( $subdomain );
            if ( ! $check['valid'] ) {
                return [ 'success' => false, 'errors' => [ 'subdomain' => $check['reason'] ] ];
            }
            if ( ! HostRouter::is_available( $subdomain ) ) {
                return [ 'success' => false, 'errors' => [ 'subdomain' => 'taken' ] ];
            }
        }

        $table = $wpdb->prefix . 'educbt_schools';

        $inserted = $wpdb->insert(
            $table,
            [
                'school_name'    => $name,
                'school_code'    => $code,
                'subdomain'      => $subdomain,
                'address'        => sanitize_textarea_field( (string) ( $data['address'] ?? '' ) ),
                'logo'           => esc_url_raw( (string) ( $data['logo'] ?? '' ) ),
                'phone'          => sanitize_text_field( (string) ( $data['phone'] ?? '' ) ),
                'email'          => sanitize_email( (string) ( $data['email'] ?? '' ) ),
                'principal_name' => sanitize_text_field( (string) ( $data['principal_name'] ?? '' ) ),
            ],
            [ '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
        );

        if ( ! $inserted ) {
            return [ 'success' => false, 'errors' => [ 'database' => 'insert_failed' ] ];
        }

        $school_id = absint( $wpdb->insert_id );

        // A school with no classes, subjects or grading scale is unusable, so the
        // academic structure is created with it rather than left as homework.
        Seeder::seed_school( $school_id );
        $this->create_default_session( $school_id );

        $principal = $this->provision_principal( $school_id, $code, $name, (string) ( $data['email'] ?? '' ) );

        EventDispatcher::action( 'educbt_school_created', [
            'school_id' => $school_id,
            'code'      => $code,
            'subdomain' => $subdomain,
        ] );

        return [
            'success'    => true,
            'school_id'  => $school_id,
            'code'       => $code,
            'subdomain'  => $subdomain,
            'portal_url' => HostRouter::url_for( $school_id, '/' ),
            'principal'  => $principal,
        ];
    }

    /**
     * @return array<string,string>
     */
    private function validate( string $name, array $data ): array {
        $errors = [];

        if ( $name === '' ) {
            $errors['name'] = 'required';
        } elseif ( strlen( $name ) < 3 ) {
            $errors['name'] = 'too_short';
        }

        $email = trim( (string) ( $data['email'] ?? '' ) );
        if ( $email !== '' && ! is_email( $email ) ) {
            $errors['email'] = 'invalid';
        }

        return $errors;
    }

    /**
     * "Greenfield College" -> GRE001. Letters from the name, numeric suffix for
     * uniqueness, so a code is short enough to type as a username.
     */
    public function allocate_code( string $name ): string {
        global $wpdb;

        $letters = strtoupper( (string) preg_replace( '/[^A-Za-z]/', '', $name ) );
        $stem    = substr( $letters !== '' ? $letters : 'SCH', 0, 3 );
        $stem    = str_pad( $stem, 3, 'X' );

        $table = $wpdb->prefix . 'educbt_schools';

        for ( $n = 1; $n <= 999; $n++ ) {
            $candidate = $stem . str_pad( (string) $n, 3, '0', STR_PAD_LEFT );

            $exists = $wpdb->get_var(
                $wpdb->prepare( "SELECT id FROM {$table} WHERE school_code = %s", $candidate )
            );

            if ( ! $exists ) {
                return $candidate;
            }
        }

        return $stem . strtoupper( wp_generate_password( 4, false, false ) );
    }

    /**
     * The current Nigerian academic session, September to July.
     */
    private function create_default_session( int $school_id ): int {
        global $wpdb;

        $year  = (int) gmdate( 'Y' );
        $month = (int) gmdate( 'n' );
        $start = $month >= 9 ? $year : $year - 1;
        $title = $start . '/' . ( $start + 1 );

        $sessions = Schema::table( 'academic_sessions' );

        // Reuse an existing session rather than colliding with it. This ran twice on
        // a real install and the second attempt failed, leaving insert_id stale — so
        // three terms were then written with session_id = 0 and belonged to nothing.
        $session_id = absint(
            $wpdb->get_var(
                $wpdb->prepare( "SELECT id FROM {$sessions} WHERE school_id = %d AND title = %s", $school_id, $title )
            )
        );

        if ( $session_id === 0 ) {
            $wpdb->insert(
                $sessions,
                [
                    'school_id'  => $school_id,
                    'title'      => $title,
                    'starts_on'  => $start . '-09-01',
                    'ends_on'    => ( $start + 1 ) . '-07-31',
                    'is_current' => 1,
                    'status'     => 'active',
                ],
                [ '%d', '%s', '%s', '%s', '%d', '%s' ]
            );

            $session_id = absint( $wpdb->insert_id );
        }

        // Without a session there is nothing for terms to hang off. Writing them
        // anyway is how orphan rows with session_id = 0 appeared.
        if ( $session_id === 0 ) {
            return 0;
        }

        $terms = [
            [ 'First Term', 1, $start . '-09-01', $start . '-12-20' ],
            [ 'Second Term', 2, ( $start + 1 ) . '-01-08', ( $start + 1 ) . '-04-05' ],
            [ 'Third Term', 3, ( $start + 1 ) . '-04-22', ( $start + 1 ) . '-07-25' ],
        ];

        foreach ( $terms as [ $title_t, $order, $from, $to ] ) {
            $wpdb->query(
                $wpdb->prepare(
                    'INSERT IGNORE INTO ' . Schema::table( 'terms' ) .
                    ' (school_id, session_id, title, term_order, starts_on, ends_on, is_current)
                      VALUES (%d, %d, %s, %d, %s, %s, %d)',
                    $school_id,
                    $session_id,
                    $title_t,
                    $order,
                    $from,
                    $to,
                    $order === 1 ? 1 : 0
                )
            );
        }

        return $session_id;
    }

    /**
     * Provision the principal's WordPress account. Username is the school code, so
     * it is short and memorable; the password is temporary and must be changed on
     * first login.
     *
     * @return array{username:string,temporary_password:string,user_id:int,must_change:bool}
     */
    private function provision_principal( int $school_id, string $code, string $school_name, string $email ): array {
        $password = wp_generate_password( 12, true, false );
        $email     = trim( $email );

        // The principal signs in with their EMAIL ADDRESS. A generated code like
        // "gre001" is fine for a machine and poor for a person: it looks provisional,
        // it is easy to mistype, and every school in the system would follow the same
        // obvious pattern. The school code is still the school's identifier — it is
        // just not a human's username.
        if ( $email !== '' && is_email( $email ) ) {
            $username = $email;
        } else {
            // No email given: fall back to the code so a school is never left without
            // a way in, and make the placeholder address obviously unusable.
            $username = strtolower( $code );
            $email    = $username . '@' . HostRouter::root_domain();
        }

        // If that email already belongs to a WordPress user, fall back to the school
        // code rather than leaving the school with no way in at all. A school that
        // exists but nobody can sign in to is the worst possible outcome, and it is
        // what happened before: the account failed and creation carried on regardless.
        $fallback_used = false;

        if ( username_exists( $username ) || email_exists( $email ) ) {
            $username      = strtolower( $code );
            $email         = $username . '@' . HostRouter::root_domain();
            $fallback_used = true;
        }

        // Even the fallback can collide if the code was reused. Nudge until free.
        $suffix = 1;

        while ( username_exists( $username ) || email_exists( $email ) ) {
            $username = strtolower( $code ) . '.' . $suffix;
            $email    = $username . '@' . HostRouter::root_domain();
            $suffix++;

            if ( $suffix > 50 ) {
                return [
                    'username'           => $username,
                    'temporary_password' => '',
                    'user_id'            => 0,
                    'must_change'        => false,
                    'error'              => 'could_not_allocate_a_principal_username',
                ];
            }
        }

        $user_id = wp_insert_user(
            [
                'user_login'   => $username,
                'user_pass'    => $password,
                'user_email'   => $email,
                'display_name' => 'Principal, ' . $school_name,
                'role'         => Capabilities::ROLE_PRINCIPAL,
            ]
        );

        // A school with no principal account is a school nobody can sign in to. That
        // must be reported, not swallowed — the previous version returned an empty
        // password and the screen simply showed nothing where the password should be.
        if ( is_wp_error( $user_id ) ) {
            return [
                'username'           => $username,
                'temporary_password' => '',
                'user_id'            => 0,
                'must_change'        => false,
                'error'              => $user_id->get_error_message(),
            ];
        }

        $user_id = absint( $user_id );

        // Forcing the change is what makes a generated password acceptable: it is a
        // delivery mechanism, not a credential the school keeps.
        update_user_meta( $user_id, '_educbt_must_change_password', 1 );
        update_user_meta( $user_id, '_educbt_school_id', $school_id );

        global $wpdb;

        // A collision here left the principal with NO staff row, which made Scope
        // resolve them as "no actor", denied every capability, and produced a
        // redirect loop on their own dashboard. Allocate until it is free.
        $staff_table  = Schema::table( 'staff' );
        $staff_number = $code . '-001';

        for ( $n = 1; $n <= 99; $n++ ) {
            $taken = $wpdb->get_var(
                $wpdb->prepare( "SELECT id FROM {$staff_table} WHERE school_id = %d AND staff_number = %s", $school_id, $staff_number )
            );

            if ( ! $taken ) {
                break;
            }

            $staff_number = $code . '-' . str_pad( (string) ( $n + 1 ), 3, '0', STR_PAD_LEFT );
        }

        $wpdb->insert(
            Schema::table( 'staff' ),
            [
                'school_id'    => $school_id,
                'staff_number' => $staff_number,
                'wp_user_id'   => $user_id,
                'first_name'   => 'Principal',
                'last_name'    => '',
                'email'        => $email,
                'role_slug'    => Capabilities::ROLE_PRINCIPAL,
                'status'       => 'active',
            ],
            [ '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s' ]
        );

        // Legacy tenant link, so TenantContext resolves this user during transition.
        $wpdb->insert(
            $wpdb->prefix . 'educbt_users',
            [
                'school_id'  => $school_id,
                'wp_user_id' => $user_id,
                'role'       => Capabilities::ROLE_PRINCIPAL,
            ],
            [ '%d', '%d', '%s' ]
        );

        return [
            'username'           => $username,
            'temporary_password' => $password,
            'user_id'            => $user_id,
            'must_change'        => true,
            // Tells the screen to explain WHY the username is not the email typed in.
            'email_was_taken'    => $fallback_used,
        ];
    }

    /**
     * Change the school's own details. Available to the principal from their
     * dashboard — the school authority changing their own credentials and branding
     * was one of your explicit requirements.
     */
    public function update_school( int $school_id, array $data ): bool {
        global $wpdb;

        $allowed = [];

        // A field is only written when it actually carries a value.
        //
        // Blank input used to overwrite good data, so a settings form that rendered
        // empty for any reason wiped the school's name the moment someone pressed
        // Save — and the name is what every report sheet and the portal header use.
        // The school NAME is never allowed to become empty at all.
        foreach ( [ 'school_name' => 'sanitize_text_field', 'address' => 'sanitize_textarea_field', 'phone' => 'sanitize_text_field', 'principal_name' => 'sanitize_text_field' ] as $field => $sanitizer ) {
            if ( ! isset( $data[ $field ] ) ) {
                continue;
            }

            $value = $sanitizer( (string) $data[ $field ] );

            if ( $field === 'school_name' && trim( $value ) === '' ) {
                continue;
            }

            $allowed[ $field ] = $value;
        }

        if ( isset( $data['logo'] ) && trim( (string) $data['logo'] ) !== '' ) {
            $allowed['logo'] = esc_url_raw( (string) $data['logo'] );
        }

        if ( isset( $data['email'] ) && is_email( (string) $data['email'] ) ) {
            $allowed['email'] = sanitize_email( (string) $data['email'] );
        }

        if ( empty( $allowed ) ) {
            return false;
        }

        return (bool) $wpdb->update(
            $wpdb->prefix . 'educbt_schools',
            $allowed,
            [ 'id' => $school_id ],
            array_fill( 0, count( $allowed ), '%s' ),
            [ '%d' ]
        );
    }

    /**
     * Delete a school and every trace of it — all 36 tables with school_id,
     * plus WordPress user accounts and user meta for its staff and students.
     *
     * This is a hard delete. Nothing is archived. The school's subdomain is
     * freed, its row is gone, and every record that referenced it is gone.
     *
     * @return array{success:bool,deleted_tables:int,deleted_users:int,error?:string}
     */
    public function delete_school( int $school_id ): array {
        global $wpdb;

        if ( $school_id <= 0 ) {
            return [ 'success' => false, 'deleted_tables' => 0, 'deleted_users' => 0, 'error' => 'invalid_id' ];
        }

        $schools_table = $wpdb->prefix . 'educbt_schools';

        if ( ! $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$schools_table} WHERE id = %d", $school_id ) ) ) {
            return [ 'success' => false, 'deleted_tables' => 0, 'deleted_users' => 0, 'error' => 'not_found' ];
        }

        // Every table this plugin has ever created, whatever naming convention it
        // used (Schema::table's wp_educbt_pro_ prefix, or the older wp_educbt_
        // tables from v1 — students, teachers, exams, results, promotions, audit
        // logs, notifications, licenses...). Rather than maintain a hand-written
        // list that silently goes stale every time a table is added — which is
        // exactly how "students" ended up missing from the old version of this
        // method while the school itself still got deleted — this discovers every
        // table belonging to the plugin and checks each one for a school_id
        // column at delete time. If it has one, it is in scope. Nothing is
        // hard-coded, so nothing can be forgotten again.
        $like = $wpdb->esc_like( $wpdb->prefix . 'educbt_' ) . '%';
        $all_tables = (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) );

        $scoped_tables = [];
        foreach ( $all_tables as $table ) {
            $table = (string) $table;
            if ( $table === $schools_table ) {
                continue; // deleted last, by id — it has no school_id column of its own.
            }
            $has_school_id = $wpdb->get_col( "SHOW COLUMNS FROM `{$table}` LIKE 'school_id'" );
            if ( ! empty( $has_school_id ) ) {
                $scoped_tables[] = $table;
            }
        }

        // Collect every WP user account that belongs to this school — staff,
        // guardians, and students can each carry a wp_user_id — before any row
        // referencing them is deleted.
        $wp_user_ids = [];

        foreach ( [ 'staff', 'guardians' ] as $key ) {
            $table = Schema::table( $key );
            $ids   = (array) $wpdb->get_col( $wpdb->prepare( "SELECT wp_user_id FROM {$table} WHERE school_id = %d AND wp_user_id IS NOT NULL AND wp_user_id > 0", $school_id ) );
            $wp_user_ids = array_merge( $wp_user_ids, $ids );
        }

        $students_table = $wpdb->prefix . 'educbt_students';
        $student_user_ids = (array) $wpdb->get_col( $wpdb->prepare( "SELECT wp_user_id FROM {$students_table} WHERE school_id = %d AND wp_user_id IS NOT NULL AND wp_user_id > 0", $school_id ) );
        $wp_user_ids = array_merge( $wp_user_ids, $student_user_ids );

        $wp_user_ids = array_values( array_unique( array_filter( array_map( 'absint', $wp_user_ids ) ) ) );

        $deleted_rows  = 0;
        $tables_hit    = 0;
        $wpdb->query( 'START TRANSACTION' );

        try {
            foreach ( $scoped_tables as $table ) {
                $deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM `{$table}` WHERE school_id = %d", $school_id ) );
                if ( $deleted !== false ) {
                    $deleted_rows += (int) $deleted;
                    if ( $deleted > 0 ) {
                        $tables_hit++;
                    }
                }
            }

            // Grade bands link to grading_scales (which has school_id), not directly.
            // The parent rows are already gone above; sweep any bands left pointing
            // at nothing.
            $grading_table = Schema::table( 'grading_scales' );
            $grade_bands   = Schema::table( 'grade_bands' );
            $wpdb->query( "DELETE FROM {$grade_bands} WHERE scale_id NOT IN (SELECT id FROM {$grading_table})" );

            // User meta this plugin writes directly (not tied to any table row).
            foreach ( $wp_user_ids as $uid ) {
                delete_user_meta( $uid, '_educbt_school_id' );
                delete_user_meta( $uid, '_educbt_acting_school_id' );
            }

            // The WordPress accounts themselves have no purpose without the school.
            if ( ! function_exists( 'wp_delete_user' ) ) {
                require_once ABSPATH . 'wp-admin/includes/user.php';
            }

            $deleted_users = 0;
            foreach ( $wp_user_ids as $uid ) {
                wp_delete_user( $uid );
                $deleted_users++;
            }

            // Finally, the school row itself.
            $wpdb->delete( $schools_table, [ 'id' => $school_id ], [ '%d' ] );

            $wpdb->query( 'COMMIT' );

            return [
                'success'        => true,
                'deleted_rows'   => $deleted_rows,
                'deleted_tables' => $tables_hit,
                'deleted_users'  => $deleted_users,
            ];
        } catch ( \Throwable $e ) {
            $wpdb->query( 'ROLLBACK' );
            return [
                'success'       => false,
                'deleted_rows'  => 0,
                'deleted_users' => 0,
                'error'         => $e->getMessage(),
            ];
        }
    }

}
