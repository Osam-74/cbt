<?php

namespace EduCBTPro\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * PHASE 1 — academic defaults.
 *
 * Every school starts with a working Nigerian secondary structure instead of an
 * empty system: JSS1-SS3, the three senior departments, the standard BECE/SSCE
 * subject lists, WAEC grading, and a 40/60 CA split.
 *
 * All of it is ordinary data, so a school can rename, delete or re-weight anything
 * without a code change. Grade boundaries in particular must never be hard-coded —
 * every school tweaks them, and every tweak would otherwise be a deploy.
 */
class Seeder {

    public static function seed_school( int $school_id ): void {
        if ( $school_id <= 0 ) {
            return;
        }

        $departments = self::seed_departments( $school_id );
        self::seed_class_levels( $school_id );
        self::seed_subjects( $school_id, $departments );
        self::seed_grading( $school_id );
        self::seed_assessment_components( $school_id );
    }

    // ---------------------------------------------------------------

    private static function seed_departments( int $school_id ): array {
        global $wpdb;
        $table = Schema::table( 'departments' );

        $rows = [
            [ 'Science', 'SCI', 1 ],
            [ 'Commercial', 'COM', 2 ],
            [ 'Arts', 'ART', 3 ],
        ];

        $map = [];

        foreach ( $rows as [ $name, $code, $order ] ) {
            $existing = $wpdb->get_var(
                $wpdb->prepare( "SELECT id FROM {$table} WHERE school_id = %d AND code = %s", $school_id, $code )
            );

            if ( $existing ) {
                $map[ $code ] = absint( $existing );
                continue;
            }

            $wpdb->insert(
                $table,
                [
                    'school_id'  => $school_id,
                    'name'       => $name,
                    'code'       => $code,
                    'applies_to' => 'senior',
                    'sort_order' => $order,
                ],
                [ '%d', '%s', '%s', '%s', '%d' ]
            );

            $map[ $code ] = absint( $wpdb->insert_id );
        }

        return $map;
    }

    private static function seed_class_levels( int $school_id ): array {
        global $wpdb;
        $table = Schema::table( 'class_levels' );

        $levels = [
            [ 'JSS 1', 'JSS1', 'junior', 1, 0 ],
            [ 'JSS 2', 'JSS2', 'junior', 2, 0 ],
            [ 'JSS 3', 'JSS3', 'junior', 3, 1 ],
            [ 'SS 1', 'SS1', 'senior', 4, 0 ],
            [ 'SS 2', 'SS2', 'senior', 5, 0 ],
            [ 'SS 3', 'SS3', 'senior', 6, 1 ],
        ];

        $ids = [];

        foreach ( $levels as [ $name, $code, $stage, $order, $terminal ] ) {
            $existing = $wpdb->get_var(
                $wpdb->prepare( "SELECT id FROM {$table} WHERE school_id = %d AND code = %s", $school_id, $code )
            );

            if ( $existing ) {
                $ids[ $code ] = absint( $existing );
                continue;
            }

            $wpdb->insert(
                $table,
                [
                    'school_id'   => $school_id,
                    'name'        => $name,
                    'code'        => $code,
                    'stage'       => $stage,
                    'level_order' => $order,
                    'is_terminal' => $terminal,
                ],
                [ '%d', '%s', '%s', '%s', '%d', '%d' ]
            );

            $ids[ $code ] = absint( $wpdb->insert_id );
        }

        // JSS3 -> SS1 is a real progression, so the promotion engine can follow it.
        $chain = [ 'JSS1' => 'JSS2', 'JSS2' => 'JSS3', 'JSS3' => 'SS1', 'SS1' => 'SS2', 'SS2' => 'SS3' ];

        foreach ( $chain as $from => $to ) {
            if ( isset( $ids[ $from ], $ids[ $to ] ) ) {
                $wpdb->update( $table, [ 'next_level_id' => $ids[ $to ] ], [ 'id' => $ids[ $from ] ], [ '%d' ], [ '%d' ] );
            }
        }

        return $ids;
    }

    /**
     * BECE and SSCE subject lists as commonly offered in Nigerian secondary schools.
     * Editable per school; this is a starting point, not a fixed curriculum.
     */
    private static function seed_subjects( int $school_id, array $departments ): void {
        global $wpdb;
        $table = Schema::table( 'subjects_v2' );

        // [ name, code, stage, category, department_code|null, compulsory ]
        $subjects = [
            // Junior — BECE
            [ 'English Studies', 'ENG-J', 'junior', 'core', null, 1 ],
            [ 'Mathematics', 'MTH-J', 'junior', 'core', null, 1 ],
            [ 'Basic Science', 'BSC', 'junior', 'core', null, 1 ],
            [ 'Basic Technology', 'BTC', 'junior', 'core', null, 1 ],
            [ 'Social Studies', 'SOS', 'junior', 'core', null, 1 ],
            [ 'Civic Education', 'CVE-J', 'junior', 'core', null, 1 ],
            [ 'Business Studies', 'BUS', 'junior', 'core', null, 0 ],
            [ 'Agricultural Science', 'AGR-J', 'junior', 'core', null, 0 ],
            [ 'Home Economics', 'HEC', 'junior', 'core', null, 0 ],
            [ 'Computer Studies', 'ICT-J', 'junior', 'core', null, 0 ],
            [ 'Physical and Health Education', 'PHE', 'junior', 'core', null, 0 ],
            [ 'Cultural and Creative Arts', 'CCA', 'junior', 'core', null, 0 ],
            [ 'Christian Religious Studies', 'CRS-J', 'junior', 'elective', null, 0 ],
            [ 'Islamic Religious Studies', 'IRS-J', 'junior', 'elective', null, 0 ],
            [ 'French', 'FRE-J', 'junior', 'elective', null, 0 ],
            [ 'Nigerian Language', 'NGL-J', 'junior', 'elective', null, 0 ],

            // Senior — compulsory for every department
            [ 'English Language', 'ENG', 'senior', 'core', null, 1 ],
            [ 'Mathematics', 'MTH', 'senior', 'core', null, 1 ],
            [ 'Civic Education', 'CVE', 'senior', 'core', null, 1 ],
            [ 'Data Processing', 'DPR', 'senior', 'trade', null, 0 ],

            // Science
            [ 'Physics', 'PHY', 'senior', 'departmental', 'SCI', 0 ],
            [ 'Chemistry', 'CHM', 'senior', 'departmental', 'SCI', 0 ],
            [ 'Biology', 'BIO', 'senior', 'departmental', 'SCI', 0 ],
            [ 'Further Mathematics', 'FMT', 'senior', 'departmental', 'SCI', 0 ],
            [ 'Agricultural Science', 'AGR', 'senior', 'departmental', 'SCI', 0 ],
            [ 'Technical Drawing', 'TDR', 'senior', 'departmental', 'SCI', 0 ],
            [ 'Computer Science', 'CSC', 'senior', 'departmental', 'SCI', 0 ],

            // Commercial
            [ 'Financial Accounting', 'ACC', 'senior', 'departmental', 'COM', 0 ],
            [ 'Commerce', 'CMM', 'senior', 'departmental', 'COM', 0 ],
            [ 'Economics', 'ECO', 'senior', 'departmental', 'COM', 0 ],
            [ 'Office Practice', 'OFP', 'senior', 'departmental', 'COM', 0 ],
            [ 'Marketing', 'MKT', 'senior', 'departmental', 'COM', 0 ],

            // Arts
            [ 'Literature-in-English', 'LIT', 'senior', 'departmental', 'ART', 0 ],
            [ 'Government', 'GOV', 'senior', 'departmental', 'ART', 0 ],
            [ 'History', 'HIS', 'senior', 'departmental', 'ART', 0 ],
            [ 'Geography', 'GEO', 'senior', 'departmental', 'ART', 0 ],
            [ 'Christian Religious Studies', 'CRS', 'senior', 'departmental', 'ART', 0 ],
            [ 'Islamic Religious Studies', 'IRS', 'senior', 'departmental', 'ART', 0 ],
            [ 'Visual Arts', 'VAR', 'senior', 'departmental', 'ART', 0 ],
            [ 'Music', 'MUS', 'senior', 'departmental', 'ART', 0 ],
            [ 'French', 'FRE', 'senior', 'departmental', 'ART', 0 ],
            [ 'Nigerian Language', 'NGL', 'senior', 'departmental', 'ART', 0 ],
        ];

        foreach ( $subjects as [ $name, $code, $stage, $category, $dept_code, $compulsory ] ) {
            $existing = $wpdb->get_var(
                $wpdb->prepare( "SELECT id FROM {$table} WHERE school_id = %d AND code = %s", $school_id, $code )
            );

            if ( $existing ) {
                continue;
            }

            $wpdb->insert(
                $table,
                [
                    'school_id'     => $school_id,
                    'name'          => $name,
                    'code'          => $code,
                    'stage'         => $stage,
                    'category'      => $category,
                    'department_id' => $dept_code !== null ? ( $departments[ $dept_code ] ?? null ) : null,
                    'is_compulsory' => $compulsory,
                ],
                [ '%d', '%s', '%s', '%s', '%s', '%d', '%d' ]
            );
        }
    }

    /**
     * WAEC SSCE bands and the internal terminal scale most schools print.
     */
    private static function seed_grading( int $school_id ): void {
        global $wpdb;
        $scales_table = Schema::table( 'grading_scales' );
        $bands_table  = Schema::table( 'grade_bands' );

        $scales = [
            'WAEC' => [
                'name'    => 'WAEC SSCE',
                'stage'   => 'senior',
                'default' => 0,
                'bands'   => [
                    [ 'A1', 75, 100, 'Excellent', 1 ],
                    [ 'B2', 70, 74, 'Very Good', 1 ],
                    [ 'B3', 65, 69, 'Good', 1 ],
                    [ 'C4', 60, 64, 'Credit', 1 ],
                    [ 'C5', 55, 59, 'Credit', 1 ],
                    [ 'C6', 50, 54, 'Credit', 1 ],
                    [ 'D7', 45, 49, 'Pass', 1 ],
                    [ 'E8', 40, 44, 'Pass', 1 ],
                    [ 'F9', 0, 39, 'Fail', 0 ],
                ],
            ],
            'INTERNAL' => [
                'name'    => 'Internal Terminal',
                'stage'   => 'both',
                'default' => 1,
                'bands'   => [
                    [ 'A', 70, 100, 'Excellent', 1 ],
                    [ 'B', 60, 69, 'Very Good', 1 ],
                    [ 'C', 50, 59, 'Good', 1 ],
                    [ 'D', 45, 49, 'Pass', 1 ],
                    [ 'E', 40, 44, 'Weak Pass', 1 ],
                    [ 'F', 0, 39, 'Fail', 0 ],
                ],
            ],
        ];

        foreach ( $scales as $code => $scale ) {
            $existing = $wpdb->get_var(
                $wpdb->prepare( "SELECT id FROM {$scales_table} WHERE school_id = %d AND code = %s", $school_id, $code )
            );

            if ( $existing ) {
                continue;
            }

            $wpdb->insert(
                $scales_table,
                [
                    'school_id'        => $school_id,
                    'name'             => $scale['name'],
                    'code'             => $code,
                    'applies_to_stage' => $scale['stage'],
                    'is_default'       => $scale['default'],
                ],
                [ '%d', '%s', '%s', '%s', '%d' ]
            );

            $scale_id = absint( $wpdb->insert_id );
            $order    = 0;

            foreach ( $scale['bands'] as [ $grade, $min, $max, $remark, $pass ] ) {
                $wpdb->insert(
                    $bands_table,
                    [
                        'scale_id'   => $scale_id,
                        'grade'      => $grade,
                        'min_score'  => $min,
                        'max_score'  => $max,
                        'remark'     => $remark,
                        'is_pass'    => $pass,
                        'sort_order' => $order++,
                    ],
                    [ '%d', '%s', '%f', '%f', '%s', '%d', '%d' ]
                );
            }
        }
    }

    /**
     * 40/60 CA split. Weights must total 100; the service layer enforces that on edit.
     */
    private static function seed_assessment_components( int $school_id ): void {
        global $wpdb;
        $table = Schema::table( 'assessment_components' );

        $components = [
            [ 'First CA Test', 'CA1', 15, 0, 1 ],
            [ 'Second CA Test', 'CA2', 15, 0, 2 ],
            [ 'Assignment', 'ASG', 10, 0, 3 ],
            [ 'Examination', 'EXM', 60, 1, 4 ],
        ];

        foreach ( $components as [ $name, $code, $max, $is_exam, $order ] ) {
            $existing = $wpdb->get_var(
                $wpdb->prepare( "SELECT id FROM {$table} WHERE school_id = %d AND code = %s", $school_id, $code )
            );

            if ( $existing ) {
                continue;
            }

            $wpdb->insert(
                $table,
                [
                    'school_id'  => $school_id,
                    'name'       => $name,
                    'code'       => $code,
                    'max_score'  => $max,
                    'is_exam'    => $is_exam,
                    'sort_order' => $order,
                ],
                // Six columns need six placeholders. A short list silently shifts
                // every format one column left, so `code` was being written with %f
                // and every component ended up with the identical code "0.000000",
                // colliding on the UNIQUE (school_id, code) key.
                [ '%d', '%s', '%s', '%f', '%d', '%d' ]
            );
        }
    }
}
