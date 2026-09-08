<?php

namespace EduCBTPro\Services;

use EduCBTPro\Core\Schema;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Auto-remarks keyed by average score range.
 *
 * A school sets up ranges (0-39 "Needs improvement", 40-59 "Good", etc.)
 * for both class teacher and principal roles. When results are compiled,
 * the system picks the matching remark for each student's average.
 *
 * Teachers and principals can override individual remarks manually.
 */
class RemarkService {

    /**
     * Get all remark ranges for a school + role.
     */
    public function ranges( int $school_id, string $role ): array {
        global $wpdb;

        return (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM " . Schema::table( 'remark_ranges' ) .
                " WHERE school_id = %d AND role = %s AND status = 'active'
                 ORDER BY min_avg DESC",
                $school_id,
                $role
            ),
            ARRAY_A
        );
    }

    /**
     * Get all remark ranges for a school (all roles).
     */
    public function all_ranges( int $school_id ): array {
        global $wpdb;

        return (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM " . Schema::table( 'remark_ranges' ) .
                " WHERE school_id = %d AND status = 'active'
                 ORDER BY role ASC, min_avg DESC",
                $school_id
            ),
            ARRAY_A
        );
    }

    /**
     * Pick the remark that matches the student's average.
     */
    public function for_average( int $school_id, string $role, float $average ): string {
        $ranges = $this->ranges( $school_id, $role );

        foreach ( $ranges as $range ) {
            $min = (float) $range['min_avg'];
            $max = (float) $range['max_avg'];

            if ( $average >= $min && $average <= $max ) {
                return (string) $range['remark'];
            }
        }

        return '';
    }

    /**
     * Save a range (upsert).
     */
    public function save_range( int $school_id, string $role, float $min, float $max, string $remark ): bool {
        global $wpdb;

        $table = Schema::table( 'remark_ranges' );

        $wpdb->insert(
            $table,
            [
                'school_id'  => $school_id,
                'role'       => $role,
                'min_avg'    => $min,
                'max_avg'    => $max,
                'remark'     => $remark,
                'sort_order' => (int) ( $max * 100 ),
            ],
            [ '%d', '%s', '%f', '%f', '%s', '%d' ]
        );

        return true;
    }

    /**
     * Save an individual student's remark for a term — overrides whatever the
     * auto-remark range produced, or fills a blank one in. Kept separate from
     * apply_auto_remarks() so a manual edit is never silently overwritten by it.
     */
    public function save_student_remark( int $school_id, int $student_id, int $term_id, string $role, string $remark ): bool {
        global $wpdb;

        $column = $role === 'principal' ? 'principal_remark' : 'class_teacher_remark';

        $updated = $wpdb->update(
            Schema::table( 'term_results' ),
            [ $column => $remark ],
            [ 'school_id' => $school_id, 'student_id' => $student_id, 'term_id' => $term_id ],
            [ '%s' ],
            [ '%d', '%d', '%d' ]
        );

        return $updated !== false;
    }

    /**
     * The five default bands seeded for a new school.
     *
     * @return array<int,array{min:float,max:float,remark:string}>
     */
    public static function default_bands( string $role ): array {
        if ( $role === 'principal' ) {
            return [
                [ 'min' => 0,  'max' => 39,  'remark' => 'Poor performance. Must show significant improvement next term.' ],
                [ 'min' => 40, 'max' => 49,  'remark' => 'Fair performance. Expected to work harder to improve.' ],
                [ 'min' => 50, 'max' => 59,  'remark' => 'Good performance. Encouraged to strive for excellence.' ],
                [ 'min' => 60, 'max' => 74,  'remark' => 'Very good performance. A commendable effort.' ],
                [ 'min' => 75, 'max' => 100, 'remark' => 'Excellent performance. Highly commendable.' ],
            ];
        }

        return [
            [ 'min' => 0,  'max' => 39,  'remark' => 'A poor result. You need to put in a lot more effort next term.' ],
            [ 'min' => 40, 'max' => 49,  'remark' => 'A fair result. You can do much better with more focus and consistent study.' ],
            [ 'min' => 50, 'max' => 59,  'remark' => 'A good result. Keep working hard, you are improving steadily.' ],
            [ 'min' => 60, 'max' => 74,  'remark' => 'A very good result. Well done.' ],
            [ 'min' => 75, 'max' => 100, 'remark' => 'An excellent result! Outstanding performance.' ],
        ];
    }

    /**
     * Seed the five default bands for a school + role, but only if it has none
     * yet — never overwrites ranges a school has already set up.
     */
    public function seed_defaults( int $school_id, string $role ): void {
        if ( ! empty( $this->ranges( $school_id, $role ) ) ) {
            return;
        }

        foreach ( self::default_bands( $role ) as $band ) {
            $this->save_range( $school_id, $role, (float) $band['min'], (float) $band['max'], $band['remark'] );
        }
    }

    /**
     * Delete a range.
     */
    public function delete_range( int $school_id, int $range_id ): bool {
        global $wpdb;

        $wpdb->delete(
            Schema::table( 'remark_ranges' ),
            [ 'id' => $range_id, 'school_id' => $school_id ],
            [ '%d', '%d' ]
        );

        return true;
    }

    /**
     * Apply auto-remarks to all students in a class after compilation.
     * Only fills in remarks that are empty — does not overwrite manual ones.
     */
    public function apply_auto_remarks( int $school_id, int $class_id, int $term_id ): int {
        global $wpdb;

        $term_results = Schema::table( 'term_results' );
        $updated = 0;

        $rows = (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, student_id, average_score, class_teacher_remark, principal_remark
                 FROM {$term_results}
                 WHERE school_id = %d AND class_id = %d AND term_id = %d",
                $school_id,
                $class_id,
                $term_id
            ),
            ARRAY_A
        );

        foreach ( $rows as $row ) {
            $avg    = (float) $row['average_score'];
            $changes = [];

            // Only fill empty remarks
            if ( empty( trim( (string) $row['class_teacher_remark'] ) ) ) {
                $remark = $this->for_average( $school_id, 'class_teacher', $avg );
                if ( $remark !== '' ) {
                    $changes['class_teacher_remark'] = $remark;
                }
            }

            if ( empty( trim( (string) $row['principal_remark'] ) ) ) {
                $remark = $this->for_average( $school_id, 'principal', $avg );
                if ( $remark !== '' ) {
                    $changes['principal_remark'] = $remark;
                }
            }

            if ( ! empty( $changes ) ) {
                $wpdb->update( $term_results, $changes, [ 'id' => (int) $row['id'] ] );
                $updated++;
            }
        }

        return $updated;
    }
}
