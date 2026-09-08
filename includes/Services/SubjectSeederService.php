<?php

namespace EduCBTPro\Services;

use EduCBTPro\Core\Schema;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * One-time subject seeding for a newly onboarded school.
 *
 * WHY THIS EXISTS
 * ---------------
 * A school arriving at an empty system has to type thirty-odd subjects before it
 * can do anything else, and every school types very nearly the same list. Seeding
 * the standard offering removes an hour of setup and, more usefully, gives every
 * school the same subject NAMES — which is what makes results comparable when a
 * student transfers.
 *
 * WHEN IT RUNS
 * ------------
 * Only for a school that has no subjects at all, and only once. Both conditions
 * matter and they guard different things:
 *
 *   - the emptiness check means an UPDATE never seeds, because an existing school
 *     always has subjects by then
 *   - the option flag means a school that deliberately deleted every subject does
 *     not have them silently restored on the next page load
 *
 * So this is safe to leave in place permanently. It cannot fire on an upgrade, a
 * reinstall over existing data, or a second activation.
 *
 * THE LIST
 * --------
 * Based on the NERDC offering for Nigerian secondary schools: junior subjects
 * common to JSS 1–3, and senior subjects grouped by the Science, Arts/Humanities
 * and Business/Commercial streams. Core subjects are marked compulsory so subject
 * registration assigns them automatically.
 *
 * Nigeria approved a revised curriculum for 2025/2026 which renames and
 * consolidates several subjects. Schools are mid-transition, so the list below
 * keeps the names schools currently use and recognise. Anything here can be
 * renamed, retired or added to afterwards — it is a starting point, not a fixture.
 */
class SubjectSeederService {

    private const FLAG = 'educbt_subjects_seeded_';

    /**
     * Seed if, and only if, this school has never had subjects.
     *
     * @return int Number of subjects created. Zero means it did not run.
     */
    public function maybe_seed( int $school_id, bool $force = false ): int {
        global $wpdb;

        if ( $school_id <= 0 ) {
            return 0;
        }

        // Already done for this school. A refresh passes $force, because the school
        // is asking for the list again on purpose.
        if ( ! $force && get_option( self::FLAG . $school_id, '' ) === 'yes' ) {
            return 0;
        }

        $table = Schema::table( 'subjects_v2' );

        // Count ACTIVE subjects only.
        //
        // Counting every row meant a refresh could never seed: retiring fourteen
        // subjects leaves fourteen rows in the table, the count came back as
        // fourteen, and the seeder concluded this was not a fresh school and bailed.
        // The result was "14 retired, 0 added" and an empty subject list — the exact
        // failure this comment now prevents.
        $existing = absint(
            $wpdb->get_var(
                $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE school_id = %d AND status = 'active'", $school_id )
            )
        );

        // A school with active subjects is not a fresh install. This is the check
        // that makes the seeder safe to leave in the codebase forever.
        if ( ! $force && $existing > 0 ) {
            update_option( self::FLAG . $school_id, 'yes', false );

            return 0;
        }

        $departments = $this->department_map( $school_id );
        $created     = 0;

        foreach ( $this->subjects() as $subject ) {
            $department_id = null;

            if ( ! empty( $subject['stream'] ) && isset( $departments[ $subject['stream'] ] ) ) {
                $department_id = $departments[ $subject['stream'] ];
            }

            $fields = [
                'name'          => $subject['name'],
                'code'          => $subject['code'],
                'stage'         => $subject['stage'],
                'category'      => ! empty( $subject['core'] ) ? 'core' : 'elective',
                'department_id' => $department_id,
                'is_compulsory' => ! empty( $subject['core'] ) ? 1 : 0,
                'status'        => 'active',
            ];

            // The table has a unique key on (school, code). A retired row still
            // holds its code, so inserting the same code again would fail silently
            // and the subject would simply never appear. Revive the existing row
            // instead — which also keeps every result already attached to it.
            $held = absint(
                $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT id FROM {$table} WHERE school_id = %d AND code = %s LIMIT 1",
                        $school_id,
                        $subject['code']
                    )
                )
            );

            if ( $held > 0 ) {
                $wpdb->update( $table, $fields, [ 'id' => $held, 'school_id' => $school_id ] );
                $created++;
                continue;
            }

            $fields['school_id'] = $school_id;

            $wpdb->insert( $table, $fields );

            if ( absint( $wpdb->insert_id ) > 0 ) {
                $created++;
            }
        }

        update_option( self::FLAG . $school_id, 'yes', false );

        return $created;
    }

    /**
     * Refresh an existing school's subject list, on request.
     *
     * Seeding only ever fires for a brand new school, so a school onboarded before
     * the standard list existed keeps whatever it started with. This is the
     * deliberate way to bring it up to date, and it is destructive enough that it
     * must be an explicit act — never automatic.
     *
     * What it will and will not remove:
     *
     *   - a subject with NO history (no results, scores, registrations or
     *     questions) is deleted, because nothing points at it
     *   - a subject WITH history is retired, not deleted. Its results and report
     *     cards refer to it by id, and removing the row would make a student's past
     *     term unreadable
     *
     * @return array{added:int,retired:int,removed:int,kept:int}
     */
    public function refresh( int $school_id ): array {
        global $wpdb;

        $table = Schema::table( 'subjects_v2' );

        $existing = (array) $wpdb->get_results(
            $wpdb->prepare( "SELECT id, name, code FROM {$table} WHERE school_id = %d", $school_id ),
            ARRAY_A
        );

        $retired = 0;
        $removed = 0;

        foreach ( $existing as $row ) {
            $subject_id = absint( $row['id'] );

            if ( $this->is_in_use( $subject_id ) ) {
                $wpdb->update(
                    $table,
                    [ 'status' => 'retired' ],
                    [ 'id' => $subject_id, 'school_id' => $school_id ],
                    [ '%s' ],
                    [ '%d', '%d' ]
                );
                $retired++;
                continue;
            }

            $wpdb->delete( $table, [ 'id' => $subject_id, 'school_id' => $school_id ], [ '%d', '%d' ] );
            $removed++;
        }

        $added = $this->maybe_seed( $school_id, true );

        return [ 'added' => $added, 'retired' => $retired, 'removed' => $removed, 'kept' => $retired ];
    }

    /**
     * Does anything in the school's history point at this subject?
     */
    private function is_in_use( int $subject_id ): bool {
        global $wpdb;

        $checks = [
            Schema::table( 'subject_results' ),
            Schema::table( 'assessment_scores' ),
            Schema::table( 'student_subjects' ),
            Schema::table( 'question_sets' ),
            Schema::table( 'staff_assignments' ),
            $wpdb->prefix . 'educbt_questions',
        ];

        foreach ( $checks as $table ) {
            $found = absint(
                $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE subject_id = %d", $subject_id ) )
            );

            if ( $found > 0 ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Existing departments, keyed by the stream names used below. Senior subjects
     * are attached where a matching department exists; where it does not, the
     * subject is simply school-wide, which is correct for a school that has not
     * split into streams.
     *
     * @return array<string,int>
     */
    private function department_map( int $school_id ): array {
        global $wpdb;

        $rows = (array) $wpdb->get_results(
            $wpdb->prepare(
                'SELECT id, name FROM ' . Schema::table( 'departments' ) . " WHERE school_id = %d AND status = 'active'",
                $school_id
            ),
            ARRAY_A
        );

        $map = [];

        foreach ( $rows as $row ) {
            $name = strtolower( (string) $row['name'] );

            if ( strpos( $name, 'scien' ) !== false ) {
                $map['science'] = absint( $row['id'] );
            } elseif ( strpos( $name, 'art' ) !== false || strpos( $name, 'human' ) !== false ) {
                $map['arts'] = absint( $row['id'] );
            } elseif ( strpos( $name, 'commerc' ) !== false || strpos( $name, 'business' ) !== false ) {
                $map['business'] = absint( $row['id'] );
            }
        }

        return $map;
    }

    /**
     * The standard offering.
     *
     * @return array<int,array<string,mixed>>
     */
    private function subjects(): array {
        return [
            // ── Junior secondary (JSS 1–3) ────────────────────────────────────
            [ 'name' => 'English Studies',              'code' => 'ENG-J',  'stage' => 'junior', 'core' => true ],
            [ 'name' => 'Mathematics',                  'code' => 'MTH-J',  'stage' => 'junior', 'core' => true ],
            [ 'name' => 'Basic Science',                'code' => 'BSC',    'stage' => 'junior', 'core' => true ],
            [ 'name' => 'Basic Technology',             'code' => 'BTC',    'stage' => 'junior', 'core' => true ],
            [ 'name' => 'Social Studies',               'code' => 'SOS',    'stage' => 'junior', 'core' => true ],
            [ 'name' => 'Civic Education',              'code' => 'CVE-J',  'stage' => 'junior', 'core' => true ],
            [ 'name' => 'Business Studies',             'code' => 'BUS-J',  'stage' => 'junior', 'core' => true ],
            [ 'name' => 'Agricultural Science',         'code' => 'AGR-J',  'stage' => 'junior', 'core' => true ],
            [ 'name' => 'Physical and Health Education','code' => 'PHE-J',  'stage' => 'junior', 'core' => true ],
            [ 'name' => 'Cultural and Creative Arts',   'code' => 'CCA',    'stage' => 'junior', 'core' => true ],
            [ 'name' => 'Computer Studies',             'code' => 'CMP-J',  'stage' => 'junior', 'core' => true ],
            [ 'name' => 'Home Economics',               'code' => 'HEC-J',  'stage' => 'junior' ],
            [ 'name' => 'Christian Religious Studies',  'code' => 'CRS-J',  'stage' => 'junior' ],
            [ 'name' => 'Islamic Studies',              'code' => 'IRS-J',  'stage' => 'junior' ],
            [ 'name' => 'Nigerian Language',            'code' => 'NLG-J',  'stage' => 'junior' ],
            [ 'name' => 'French',                       'code' => 'FRN-J',  'stage' => 'junior' ],
            [ 'name' => 'History',                      'code' => 'HIS-J',  'stage' => 'junior' ],

            // ── Senior secondary — compulsory for every stream ─────────────────
            [ 'name' => 'English Language',             'code' => 'ENG',    'stage' => 'senior', 'core' => true ],
            [ 'name' => 'General Mathematics',          'code' => 'MTH',    'stage' => 'senior', 'core' => true ],
            [ 'name' => 'Civic Education',              'code' => 'CVE',    'stage' => 'senior', 'core' => true ],

            // ── Senior — Science ──────────────────────────────────────────────
            [ 'name' => 'Physics',                      'code' => 'PHY',    'stage' => 'senior', 'stream' => 'science' ],
            [ 'name' => 'Chemistry',                    'code' => 'CHM',    'stage' => 'senior', 'stream' => 'science' ],
            [ 'name' => 'Biology',                      'code' => 'BIO',    'stage' => 'senior', 'stream' => 'science' ],
            [ 'name' => 'Further Mathematics',          'code' => 'FMT',    'stage' => 'senior', 'stream' => 'science' ],
            [ 'name' => 'Agricultural Science',         'code' => 'AGR',    'stage' => 'senior', 'stream' => 'science' ],
            [ 'name' => 'Technical Drawing',            'code' => 'TDR',    'stage' => 'senior', 'stream' => 'science' ],

            // ── Senior — Arts and Humanities ──────────────────────────────────
            [ 'name' => 'Literature in English',        'code' => 'LIT',    'stage' => 'senior', 'stream' => 'arts' ],
            [ 'name' => 'Government',                   'code' => 'GOV',    'stage' => 'senior', 'stream' => 'arts' ],
            [ 'name' => 'History',                      'code' => 'HIS',    'stage' => 'senior', 'stream' => 'arts' ],
            [ 'name' => 'Christian Religious Studies',  'code' => 'CRS',    'stage' => 'senior', 'stream' => 'arts' ],
            [ 'name' => 'Islamic Studies',              'code' => 'IRS',    'stage' => 'senior', 'stream' => 'arts' ],
            [ 'name' => 'Geography',                    'code' => 'GEO',    'stage' => 'senior', 'stream' => 'arts' ],
            [ 'name' => 'Visual Arts',                  'code' => 'VAR',    'stage' => 'senior', 'stream' => 'arts' ],
            [ 'name' => 'Music',                        'code' => 'MUS',    'stage' => 'senior', 'stream' => 'arts' ],
            [ 'name' => 'French',                       'code' => 'FRN',    'stage' => 'senior', 'stream' => 'arts' ],
            [ 'name' => 'Nigerian Language',            'code' => 'NLG',    'stage' => 'senior', 'stream' => 'arts' ],

            // ── Senior — Business and Commercial ──────────────────────────────
            [ 'name' => 'Financial Accounting',         'code' => 'ACC',    'stage' => 'senior', 'stream' => 'business' ],
            [ 'name' => 'Commerce',                     'code' => 'COM',    'stage' => 'senior', 'stream' => 'business' ],
            [ 'name' => 'Economics',                    'code' => 'ECO',    'stage' => 'senior', 'stream' => 'business' ],
            [ 'name' => 'Office Practice',              'code' => 'OFP',    'stage' => 'senior', 'stream' => 'business' ],
            [ 'name' => 'Marketing',                    'code' => 'MKT',    'stage' => 'senior', 'stream' => 'business' ],
            [ 'name' => 'Insurance',                    'code' => 'INS',    'stage' => 'senior', 'stream' => 'business' ],

            // ── Senior — available to any stream ──────────────────────────────
            [ 'name' => 'Computer Science',             'code' => 'CMP',    'stage' => 'senior' ],
            [ 'name' => 'Data Processing',              'code' => 'DPR',    'stage' => 'senior' ],
            [ 'name' => 'Food and Nutrition',           'code' => 'FDN',    'stage' => 'senior' ],
            [ 'name' => 'Physical Education',           'code' => 'PHE',    'stage' => 'senior' ],
        ];
    }
}
