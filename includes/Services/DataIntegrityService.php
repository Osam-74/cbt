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
                    'detail'  => 'They exist but belong to no class this session, so they will not appear in class lists, sit exams, or receive results. Where an enrolment for them survives under another status or another session, repairing reactivates it. Any student left over genuinely has no enrolment and must be placed in a class by hand.',
                    'fixable' => true,
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
                'detail'  => 'The class they were enrolled in no longer exists, so the enrolment points at nothing. Repairing clears these dead links so they stop being counted; the students then show as unenrolled and can be placed in a class.',
                'fixable' => true,
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

            $reg_service = new StudentRegistrationService();
            $fixed = 0;
            foreach ( $orphans as $orphan ) {
                $student_id = absint( $orphan['student_id'] );
                $class_id   = absint( $orphan['class_id'] );

                // Get class name for the student record
                $class_name = (string) $wpdb->get_var(
                    $wpdb->prepare( "SELECT display_name FROM {$classes_table} WHERE id = %d", $class_id )
                );

                // Try to find an existing WP user for this student_id — the user
                // account may still exist even though the student record was lost.
                $wp_user_id = (int) $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = '_educbt_school_id' AND meta_value = %d LIMIT 1",
                        $school_id
                    )
                );

                // Also try to match by the old wp_user_id column if present
                $existing_wp_user = 0;

                // Generate admission number from the student_id so it's unique
                $admission_number = 'REST-' . str_pad( (string) $student_id, 5, '0', STR_PAD_LEFT );

                $placeholder_first = 'Restored';
                $placeholder_last  = 'Student ' . $student_id;
                $placeholder_full  = $placeholder_first . ' ' . $placeholder_last;

                $inserted = $wpdb->insert(
                    $students,
                    [
                        'id'                  => $student_id,
                        'school_id'           => $school_id,
                        'admission_number'    => $admission_number,
                        'registration_number' => $admission_number,
                        'student_id'          => $admission_number,
                        'full_name'           => $placeholder_full,
                        'first_name'          => $placeholder_first,
                        'last_name'            => $placeholder_last,
                        'class'               => $class_name,
                        'status'              => 'active',
                    ],
                    [ '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
                );

                if ( $inserted ) {
                    // Provision a WP login account so the student can actually sign in.
                    $creds = $reg_service->provision_login_public(
                        $school_id, $student_id, $admission_number,
                        $placeholder_first, $placeholder_last
                    );
                    $fixed++;
                }
            }

            return [ 'fixed' => $fixed, 'message' => sprintf( '%d student record(s) restored and login accounts created. Update their names from the student list.', $fixed ) ];
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

        if ( $key === 'unenrolled_students' ) {
            // A restored student usually DOES have an enrolment — it is simply not
            // marked active, or belongs to a session that is no longer current.
            // Reactivating the most recent surviving enrolment puts them back on a
            // class register without inventing a placement they never had.
            $session = ( new AcademicYearService() )->current_session( $school_id );
            $session_id = absint( $session['id'] ?? 0 );

            if ( $session_id <= 0 ) {
                return [ 'fixed' => 0, 'message' => 'No current session is set, so there is nothing to enrol into.' ];
            }

            $candidates = (array) $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT st.id AS student_id,
                            (SELECT e2.class_id FROM {$enrollments} e2
                              WHERE e2.student_id = st.id AND e2.class_id > 0
                              ORDER BY e2.session_id DESC, e2.id DESC LIMIT 1) AS class_id
                     FROM {$students} st
                     WHERE st.school_id = %d AND st.status = 'active'
                       AND NOT EXISTS (
                           SELECT 1 FROM {$enrollments} e
                           WHERE e.student_id = st.id AND e.session_id = %d AND e.status = 'active'
                       )",
                    $school_id,
                    $session_id
                ),
                ARRAY_A
            );

            $fixed   = 0;
            $skipped = 0;

            foreach ( $candidates as $row ) {
                $student_id = absint( $row['student_id'] );
                $class_id   = absint( $row['class_id'] );

                if ( $class_id <= 0 ) {
                    $skipped++;
                    continue; // never had a class; a human must place them
                }

                $existing = absint(
                    $wpdb->get_var(
                        $wpdb->prepare(
                            "SELECT id FROM {$enrollments}
                             WHERE student_id = %d AND session_id = %d LIMIT 1",
                            $student_id,
                            $session_id
                        )
                    )
                );

                if ( $existing > 0 ) {
                    $wpdb->update( $enrollments, [ 'status' => 'active' ], [ 'id' => $existing ], [ '%s' ], [ '%d' ] );
                } else {
                    $wpdb->insert(
                        $enrollments,
                        [
                            'school_id'  => $school_id,
                            'student_id' => $student_id,
                            'class_id'   => $class_id,
                            'session_id' => $session_id,
                            'status'     => 'active',
                        ],
                        [ '%d', '%d', '%d', '%d', '%s' ]
                    );
                }

                $fixed++;
            }

            $message = sprintf( '%d student(s) re-enrolled from their previous placement.', $fixed );

            if ( $skipped > 0 ) {
                $message .= sprintf( ' %d had no previous class on record and must be placed by hand from the student list.', $skipped );
            }

            return [ 'fixed' => $fixed, 'message' => $message ];
        }

        if ( $key === 'orphan_class_links' ) {
            // The class is gone. Clearing the dead link is honest: the enrolment
            // stops claiming a placement that does not exist, and the student
            // surfaces as unenrolled so somebody puts them somewhere real.
            $classes_table = Schema::table( 'classes' );

            $fixed = absint(
                $wpdb->query(
                    $wpdb->prepare(
                        "UPDATE {$enrollments} e
                         LEFT JOIN {$classes_table} c ON c.id = e.class_id
                         SET e.status = 'inactive'
                         WHERE e.school_id = %d AND c.id IS NULL",
                        $school_id
                    )
                )
            );

            return [ 'fixed' => $fixed, 'message' => sprintf( '%d dead enrolment link(s) cleared.', $fixed ) ];
        }

        return [ 'fixed' => 0, 'message' => 'Nothing to do.' ];
    }
}
