<?php

namespace EduCBTPro\Services;

use EduCBTPro\Core\Schema;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Continuous assessment TEST WINDOWS.
 *
 * Not to be confused with ContinuousAssessmentService, which computes CA scores
 * once marks exist. This class is about the test itself: opening the window,
 * collecting questions, composing the papers.
 *
 * HOW A CA TEST DIFFERS FROM A TERMINAL EXAMINATION
 * -------------------------------------------------
 * Mechanically it does not. It has a window, a timetable, papers, attempts and
 * marks, so it reuses the same tables rather than acquiring a parallel set that
 * would drift out of step the first time either side gained a feature.
 *
 * What differs is the process, and that difference is the point:
 *
 *   Terminal examination        CA test
 *   ------------------------    ---------------------------------------------
 *   Objective AND theory        Objective only — marked the morning after it
 *                               is sat, not over a week
 *   Reviewed and approved       Composed straight away, no approval stage
 *   One paper per subject       One short paper, several times a term
 *   Marks go to the exam column Marks go to a named CA component
 *
 * A Nigerian secondary term typically runs a first CA around week four, a second
 * around week eight, and terminal examinations from about week ten. The school
 * office opens the window; teachers write into it while it is open; the office
 * composes and publishes. Teachers never set the dates — that is what makes it a
 * school test rather than thirty teachers each testing on a different afternoon.
 *
 * Questions written for a CA test stay in the bank, and are offered as an extra
 * pool when the terminal paper is built, so a term's work compounds instead of
 * being written twice.
 */
class CaTestWindowService {

    public const SERIES_TYPE = 'ca_test';

    /**
     * Open a CA test window.
     *
     * @param array<string,mixed> $data
     * @return array{success:bool,series_id?:int,error?:string}
     */
    public function create_window( int $school_id, array $data, int $actor_id = 0 ): array {
        global $wpdb;

        $session_id   = absint( $data['session_id'] ?? 0 );
        $term_id      = absint( $data['term_id'] ?? 0 );
        $component_id = absint( $data['component_id'] ?? 0 );
        $title        = sanitize_text_field( (string) ( $data['title'] ?? '' ) );
        $opens        = sanitize_text_field( (string) ( $data['starts_on'] ?? '' ) );
        $closes       = sanitize_text_field( (string) ( $data['ends_on'] ?? '' ) );

        if ( $session_id <= 0 || $term_id <= 0 ) {
            return [ 'success' => false, 'error' => 'missing_term' ];
        }

        if ( $component_id <= 0 ) {
            return [ 'success' => false, 'error' => 'missing_component' ];
        }

        if ( $opens === '' || $closes === '' ) {
            return [ 'success' => false, 'error' => 'missing_dates' ];
        }

        if ( strtotime( $closes ) < strtotime( $opens ) ) {
            return [ 'success' => false, 'error' => 'dates_reversed' ];
        }

        // Name it after the component if the office did not, so a term's tests stay
        // distinguishable in a list a year later.
        if ( $title === '' ) {
            $title = (string) $wpdb->get_var(
                $wpdb->prepare(
                    'SELECT name FROM ' . Schema::table( 'assessment_components' ) . ' WHERE id = %d',
                    $component_id
                )
            );

            $title = trim( $title ) !== '' ? $title : 'Continuous Assessment';
        }

        // One window per component per term. Two open windows for the same CA would
        // leave teachers guessing which one to write into.
        $clash = absint(
            $wpdb->get_var(
                $wpdb->prepare(
                    'SELECT id FROM ' . Schema::table( 'exam_series' ) . "
                     WHERE school_id = %d AND session_id = %d AND COALESCE(term_id,0) = %d
                       AND series_type = %s AND component_id = %d AND status <> 'cancelled'
                     LIMIT 1",
                    $school_id,
                    $session_id,
                    $term_id,
                    self::SERIES_TYPE,
                    $component_id
                )
            )
        );

        if ( $clash > 0 ) {
            return [ 'success' => false, 'error' => 'window_exists' ];
        }

        $wpdb->insert(
            Schema::table( 'exam_series' ),
            [
                'school_id'             => $school_id,
                'session_id'            => $session_id,
                'term_id'               => $term_id,
                'title'                 => $title,
                'series_type'           => self::SERIES_TYPE,
                'component_id'          => $component_id,
                'starts_on'             => $opens,
                'ends_on'               => $closes,
                'questions_per_student' => max( 1, absint( $data['questions_per_student'] ?? 20 ) ),
                'duration_minutes'      => max( 1, absint( $data['duration_minutes'] ?? 30 ) ),
                'status'                => 'draft',
                'created_by'            => $actor_id ?: null,
            ]
        );

        $series_id = absint( $wpdb->insert_id );

        if ( $series_id <= 0 ) {
            return [ 'success' => false, 'error' => 'insert_failed' ];
        }

        // A window nobody knows about collects no questions.
        $this->notify_teachers( $school_id, $title, $opens, $closes );

        return [ 'success' => true, 'series_id' => $series_id ];
    }

    /**
     * CA windows for a school, newest first.
     *
     * @return array<int,array<string,mixed>>
     */
    public function windows( int $school_id, int $session_id = 0, int $term_id = 0 ): array {
        global $wpdb;

        $sql = 'SELECT se.*, c.name AS component_name, c.max_score,
                       (SELECT COUNT(*) FROM ' . Schema::table( 'question_sets' ) . ' qs
                         WHERE qs.series_id = se.id) AS set_count,
                       (SELECT COUNT(*) FROM ' . Schema::table( 'exam_papers' ) . ' p
                         WHERE p.series_id = se.id) AS paper_count
                FROM ' . Schema::table( 'exam_series' ) . ' se
                LEFT JOIN ' . Schema::table( 'assessment_components' ) . ' c ON c.id = se.component_id
                WHERE se.school_id = %d AND se.series_type = %s';

        $params = [ $school_id, self::SERIES_TYPE ];

        if ( $session_id > 0 ) {
            $sql     .= ' AND se.session_id = %d';
            $params[] = $session_id;
        }

        if ( $term_id > 0 ) {
            $sql     .= ' AND COALESCE(se.term_id,0) = %d';
            $params[] = $term_id;
        }

        $sql .= ' ORDER BY se.id DESC';

        return (array) $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
    }

    /**
     * The window a teacher may currently write into, if any.
     *
     * Null outside the window. A teacher writing CA questions in week eleven, after
     * the office has composed and published, is writing into a test already sat.
     *
     * @return array<string,mixed>|null
     */
    public function open_window( int $school_id, int $session_id, int $term_id ): ?array {
        global $wpdb;

        $today = current_time( 'Y-m-d' );

        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT se.*, c.name AS component_name, c.max_score
                 FROM ' . Schema::table( 'exam_series' ) . ' se
                 LEFT JOIN ' . Schema::table( 'assessment_components' ) . " c ON c.id = se.component_id
                 WHERE se.school_id = %d AND se.series_type = %s
                   AND se.session_id = %d AND COALESCE(se.term_id,0) = %d
                   AND se.status IN ('draft','open')
                   AND (se.starts_on IS NULL OR se.starts_on <= %s)
                   AND (se.ends_on IS NULL OR se.ends_on >= %s)
                 ORDER BY se.id DESC LIMIT 1",
                $school_id,
                self::SERIES_TYPE,
                $session_id,
                $term_id,
                $today,
                $today
            ),
            ARRAY_A
        );

        return $row ?: null;
    }

    /**
     * Compose papers for every subject that has questions in this window.
     *
     * No approval stage: a CA test is short, low-stakes, and written by the subject
     * teacher who will mark it. Requiring a review cycle for twenty objective items
     * every four weeks guarantees the review is skipped or rubber-stamped, which is
     * worse than not having one.
     *
     * @return array{success:bool,created:int,skipped:int,short:array<int,string>,error?:string}
     */
    public function compose( int $school_id, int $series_id ): array {
        global $wpdb;

        $series = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM ' . Schema::table( 'exam_series' ) . ' WHERE id = %d AND school_id = %d AND series_type = %s',
                $series_id,
                $school_id,
                self::SERIES_TYPE
            ),
            ARRAY_A
        );

        if ( ! $series ) {
            return [ 'success' => false, 'created' => 0, 'skipped' => 0, 'short' => [], 'error' => 'window_not_found' ];
        }

        $sets      = Schema::table( 'question_sets' );
        $papers    = Schema::table( 'exam_papers' );
        $classes   = Schema::table( 'classes' );
        $subjects  = Schema::table( 'subjects_v2' );
        $questions = $wpdb->prefix . 'educbt_questions';

        $per_student = max( 1, absint( $series['questions_per_student'] ) );
        $duration    = max( 1, absint( $series['duration_minutes'] ) ) * 60;

        $candidates = (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT qs.id AS set_id, qs.subject_id, qs.level_id, qs.department_id,
                        s.name AS subject_name,
                        (SELECT COUNT(*) FROM {$questions} q
                          WHERE q.question_set_id = qs.id AND q.status = 'active') AS available
                 FROM {$sets} qs
                 LEFT JOIN {$subjects} s ON s.id = qs.subject_id
                 WHERE qs.school_id = %d AND qs.series_id = %d AND qs.exam_type = 'objective'",
                $school_id,
                $series_id
            ),
            ARRAY_A
        );

        if ( empty( $candidates ) ) {
            return [ 'success' => false, 'created' => 0, 'skipped' => 0, 'short' => [], 'error' => 'nothing_written' ];
        }

        $created = 0;
        $skipped = 0;
        $short   = [];

        foreach ( $candidates as $row ) {
            // A paper that cannot supply the number of questions each student must
            // answer is not composed. Silently shortening it would give one class a
            // twelve-question test and another twenty, marked out of the same total.
            if ( absint( $row['available'] ) < $per_student ) {
                $skipped++;
                $short[] = sprintf(
                    '%s (%d of %d)',
                    (string) ( $row['subject_name'] ?? 'Subject' ),
                    absint( $row['available'] ),
                    $per_student
                );
                continue;
            }

            $exists = absint(
                $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT id FROM {$papers}
                         WHERE school_id = %d AND series_id = %d AND subject_id = %d
                           AND COALESCE(level_id,0) = %d AND COALESCE(department_id,0) = %d
                         LIMIT 1",
                        $school_id,
                        $series_id,
                        absint( $row['subject_id'] ),
                        absint( $row['level_id'] ),
                        absint( $row['department_id'] )
                    )
                )
            );

            if ( $exists > 0 ) {
                $skipped++;
                continue;
            }

            $representative = absint(
                $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT id FROM {$classes}
                         WHERE school_id = %d AND level_id = %d
                           AND COALESCE(department_id,0) = %d AND status = 'active'
                         ORDER BY arm ASC LIMIT 1",
                        $school_id,
                        absint( $row['level_id'] ),
                        absint( $row['department_id'] )
                    )
                )
            );

            $wpdb->insert(
                $papers,
                [
                    'school_id'        => $school_id,
                    'series_id'        => $series_id,
                    'subject_id'       => absint( $row['subject_id'] ),
                    'class_id'         => $representative ?: null,
                    'level_id'         => absint( $row['level_id'] ),
                    'department_id'    => absint( $row['department_id'] ) ?: null,
                    'scheduled_at'     => (string) $series['starts_on'] . ' 09:00:00',
                    'duration_seconds' => $duration,
                    'question_count'   => $per_student,
                    'status'           => 'draft',
                ]
            );

            if ( absint( $wpdb->insert_id ) > 0 ) {
                $created++;
            }
        }

        if ( $created > 0 ) {
            $wpdb->update(
                Schema::table( 'exam_series' ),
                [ 'status' => 'composed' ],
                [ 'id' => $series_id, 'school_id' => $school_id ],
                [ '%s' ],
                [ '%d', '%d' ]
            );
        }

        return [ 'success' => true, 'created' => $created, 'skipped' => $skipped, 'short' => $short ];
    }

    /**
     * Questions already written for this term's CA tests, offered as an extra pool
     * when the terminal paper is being built.
     *
     * @return array<int,array<string,mixed>>
     */
    public function reusable_pool( int $school_id, int $session_id, int $term_id, int $subject_id, int $level_id ): array {
        global $wpdb;

        $questions = $wpdb->prefix . 'educbt_questions';

        return (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT q.id, q.question_text, q.marks, se.title AS from_test
                 FROM {$questions} q
                 INNER JOIN " . Schema::table( 'question_sets' ) . ' qs ON qs.id = q.question_set_id
                 INNER JOIN ' . Schema::table( 'exam_series' ) . " se ON se.id = qs.series_id
                 WHERE q.school_id = %d AND q.status = 'active'
                   AND se.series_type = %s AND se.session_id = %d AND COALESCE(se.term_id,0) = %d
                   AND qs.subject_id = %d AND qs.level_id = %d
                 ORDER BY se.id ASC, q.sequence ASC",
                $school_id,
                self::SERIES_TYPE,
                $session_id,
                $term_id,
                $subject_id,
                $level_id
            ),
            ARRAY_A
        );
    }

    /**
     * Let the teaching staff know a window has opened.
     */
    private function notify_teachers( int $school_id, string $title, string $opens, string $closes ): void {
        global $wpdb;

        $staff_ids = (array) $wpdb->get_col(
            $wpdb->prepare(
                'SELECT DISTINCT st.wp_user_id
                 FROM ' . Schema::table( 'staff' ) . ' st
                 INNER JOIN ' . Schema::table( 'staff_assignments' ) . " a ON a.staff_id = st.id
                 WHERE st.school_id = %d AND st.status = 'active' AND a.status = 'active'
                   AND st.wp_user_id IS NOT NULL AND st.wp_user_id > 0",
                $school_id
            )
        );

        $notifier = new NotificationService();

        $body = sprintf(
            'Set your objective questions for %s between %s and %s. Objective only — a CA test has no theory section. '
                . 'You do not set the dates or open the test; the school office does that once questions are in.',
            $title,
            mysql2date( 'j M Y', $opens ),
            mysql2date( 'j M Y', $closes )
        );

        foreach ( $staff_ids as $uid ) {
            $notifier->notify(
                $school_id,
                absint( $uid ),
                NotificationService::QUESTION_SUBMITTED,
                'Continuous assessment window open — ' . $title,
                $body,
                home_url( '/portal/exams/questions/' )
            );
        }
    }
}
