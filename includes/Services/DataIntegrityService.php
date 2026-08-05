<?php

namespace EduCBTPro\Services;

use EduCBTPro\Core\Schema;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Find records that point at nothing.
 *
 * The specific fault this exists for: for a period, a missing column made the STUDENT
 * insert fail as a database warning while the ENROLMENT insert that followed still
 * succeeded. The result is a class that reports a headcount for students who exist
 * nowhere else — so Classes shows two, Students shows none, and nothing errors.
 *
 * A school cannot be expected to diagnose that. This names it, counts it, and offers
 * to clear the orphans so the students can be registered properly.
 */
class DataIntegrityService {

    /**
     * @return array<int,array{key:string,label:string,count:int,detail:string,fixable:bool}>
     */
    public function problems( int $school_id ): array {
        global $wpdb;

        $students    = $wpdb->prefix . 'educbt_students';
        $enrollments = Schema::table( 'enrollments' );
        $classes     = Schema::table( 'classes' );
        $out         = [];

        // Enrolments whose student row is gone.
        $orphan_enrolments = absint(
            $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$enrollments} e
                     LEFT JOIN {$students} st ON st.id = e.student_id
                     WHERE e.school_id = %d AND st.id IS NULL",
                    $school_id
                )
            )
        );

        if ( $orphan_enrolments > 0 ) {
            $out[] = [
                'key'     => 'orphan_enrolments',
                'label'   => 'Enrolments with no student record',
                'count'   => $orphan_enrolments,
                'detail'  => 'A class counts these in its headcount, but the students do not appear in the student list because the student record itself was never saved. Restoring creates the missing student record from the enrolment data so the student appears properly.',
                'fixable' => true,
            ];
        }

        // Students with no status, invisible to every "active" filter.
        $blank_status = absint(
            $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$students} WHERE school_id = %d AND (status IS NULL OR status = '')",
                    $school_id
                )
            )
        );

        if ( $blank_status > 0 ) {
            $out[] = [
                'key'     => 'blank_student_status',
                'label'   => 'Students with no status',
                'count'   => $blank_status,
                'detail'  => 'These students exist but every list filters on an active status, so none of them appear anywhere. They can be marked active.',
                'fixable' => true,
            ];
        }

        // Students with no enrolment in the current session.
        $session = ( new AcademicYearService() )->current_session( $school_id );

        if ( ! empty( $session['id'] ) ) {
            $unenrolled = absint(
                $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT COUNT(*) FROM {$students} st
                         WHERE st.school_id = %d AND st.status = 'active'
                           AND NOT EXISTS (
                               SELECT 1 FROM {$enrollments} e
                               WHERE e.student_id = st.id AND e.session_id = %d AND e.status = 'active'
                           )",
                        $school_id,
                        absint( $session['id'] )
                    )
                )
            );

            if ( $unenrolled > 0 ) {
                $out[] = [
                    'key'     => 'unenrolled_students',
                    'label'   => 'Students not enrolled in the current session',
                    'count'   => $unenrolled,
                    'detail'  => 'They exist but belong to no class this session, so they will not appear in class lists, sit exams, or receive results. Put them in a class from the student list.',
                    'fixable' => false,
                ];
            }
        }

        // Enrolments pointing at a class that no longer exists.
        $orphan_classes = absint(
            $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$enrollments} e
                     LEFT JOIN {$classes} c ON c.id = e.class_id
                     WHERE e.school_id = %d AND c.id IS NULL",
                    $school_id
                )
            )
        );

        if ( $orphan_classes > 0 ) {
            $out[] = [
                'key'     => 'orphan_class_links',
                'label'   => 'Enrolments pointing at a class that no longer exists',
                'count'   => $orphan_classes,
                'detail'  => 'These students are enrolled in nothing. Move them into a class from the student list.',
                'fixable' => false,
            ];
        }

        return $out;
    }

    /**
     * Counts a school can check against what it expects to see.
     *
     * @return array<string,int>
     */
    public function counts( int $school_id ): array {
        global $wpdb;

        $students    = $wpdb->prefix . 'educbt_students';
        $enrollments = Schema::table( 'enrollments' );
        $session     = ( new AcademicYearService() )->current_session( $school_id );
        $session_id  = absint( $session['id'] ?? 0 );

        return [
            'student_records'   => absint( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$students} WHERE school_id = %d", $school_id ) ) ),
            'active_students'   => absint( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$students} WHERE school_id = %d AND status = 'active'", $school_id ) ) ),
            'enrolments'        => absint( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$enrollments} WHERE school_id = %d", $school_id ) ) ),
            'enrolled_this_session' => $session_id > 0
                ? absint( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$enrollments} WHERE school_id = %d AND session_id = %d AND status = 'active'", $school_id, $session_id ) ) )
                : 0,
            'session_id'        => $session_id,
        ];
    }

    /**
     * @return array{fixed:int,message:string}
     */
    public function repair( int $school_id, string $key ): array {
        global $wpdb;

        $students    = $wpdb->prefix . 'educbt_students';
        $enrollments = Schema::table( 'enrollments' );

        if ( $key === 'orphan_enrolments' ) {
            // Restore: create the missing student record from the enrolment data
            // instead of deleting the enrolment. The enrolment carries the student_id,
            // class_id, and session_id — we create a minimal student row so the
            // student appears in lists, can sit exams, and can be edited later.
            $classes_table = Schema::table( 'classes' );
            $sessions_table = Schema::table( 'academic_sessions' );

            $orphans = (array) $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT e.student_id, e.class_id, e.session_id, e.school_id
                     FROM {$enrollments} e
                     LEFT JOIN {$students} st ON st.id = e.student_id
                     WHERE e.school_id = %d AND st.id IS NULL",
                    $school_id
                ),
                ARRAY_A
            );

            $fixed = 0;
            foreach ( $orphans as $orphan ) {
                $student_id = absint( $orphan['student_id'] );
                $class_id   = absint( $orphan['class_id'] );

                // Get class name for the student record
                $class_name = (string) $wpdb->get_var(
                    $wpdb->prepare( "SELECT display_name FROM {$classes_table} WHERE id = %d", $class_id )
                );

                // Generate a name placeholder — the school can edit it later
                $admission_number = 'REST-' . str_pad( (string) $student_id, 5, '0', STR_PAD_LEFT );

                $inserted = $wpdb->insert(
                    $students,
                    [
                        'id'                  => $student_id,
                        'school_id'           => $school_id,
                        'admission_number'    => $admission_number,
                        'registration_number' => $admission_number,
                        'student_id'          => $admission_number,
                        'full_name'           => 'Pending Student ' . $student_id,
                        'first_name'          => 'Pending',
                        'last_name'            => 'Student ' . $student_id,
                        'class'               => $class_name,
                        'status'              => 'active',
                    ],
                    [ '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
                );

                if ( $inserted ) {
                    $fixed++;
                }
            }

            return [ 'fixed' => $fixed, 'message' => sprintf( '%d student record(s) restored. Update their names from the student list.', $fixed ) ];
        }

        if ( $key === 'blank_student_status' ) {
            $fixed = absint(
                $wpdb->query(
                    $wpdb->prepare(
                        "UPDATE {$students} SET status = 'active' WHERE school_id = %d AND (status IS NULL OR status = '')",
                        $school_id
                    )
                )
            );

            return [ 'fixed' => $fixed, 'message' => sprintf( '%d student(s) marked active.', $fixed ) ];
        }

        return [ 'fixed' => 0, 'message' => 'Nothing to do.' ];
    }
}
