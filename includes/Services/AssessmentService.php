<?php

namespace EduCBTPro\Services;

use EduCBTPro\Core\EventDispatcher;
use EduCBTPro\Core\Schema;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * PHASE 5d — continuous assessment (v2).
 *
 * The v1 ContinuousAssessmentService is retained untouched during the transition;
 * it keys on subject/term/session STRINGS, which is the shape Phase 1 replaced.
 *
 * CA reaches the system by two completely different routes, and a system that
 * supports only one is useless in a real school:
 *
 *   DELIVERED   The teacher builds a CA test as a CBT paper. Students sit it, it is
 *               auto-scored, and the mark lands in the CA component automatically.
 *
 *   AWARDED     The mark exists outside the system — a practical, an oral, a written
 *               test marked on paper, a project, a presentation, homework averaged
 *               over a term. The teacher simply types a number per student. This is
 *               the MAJORITY of CA in most schools, and no amount of CBT tooling
 *               replaces it.
 *
 * Both write to the same `assessment_scores` rows, so the broadsheet neither knows
 * nor cares which route a mark took. `source` records it, for one reason that
 * matters: a delivered mark can be recomputed from its attempt, an awarded mark
 * cannot. Losing an awarded mark is unrecoverable, so it is protected from silent
 * replacement.
 */
class AssessmentService {

    public const SOURCE_DELIVERED = 'cbt';
    public const SOURCE_AWARDED   = 'manual';

    // ---------------------------------------------------------------
    // Awarded: direct score entry
    // ---------------------------------------------------------------

    /**
     * Enter marks for a whole class in one action — the teacher opens their class
     * list, types a number beside each name, saves once.
     *
     * @param array<int,float|string> $scores student_id => score
     * @return array{saved:int,skipped:int,errors:array<int,array<string,mixed>>}
     */
    public function award_scores( int $school_id, int $component_id, array $context, array $scores, int $entered_by ): array {
        global $wpdb;

        $component = $this->component( $school_id, $component_id );

        if ( ! $component ) {
            return [ 'saved' => 0, 'skipped' => count( $scores ), 'errors' => [ [ 'error' => 'component_not_found' ] ] ];
        }

        $max        = (float) $component['max_score'];
        $subject_id = absint( $context['subject_id'] ?? 0 );
        $class_id   = absint( $context['class_id'] ?? 0 );
        $session_id = absint( $context['session_id'] ?? 0 );
        $term_id    = absint( $context['term_id'] ?? 0 );

        if ( $subject_id <= 0 || $class_id <= 0 || $session_id <= 0 || $term_id <= 0 ) {
            return [ 'saved' => 0, 'skipped' => count( $scores ), 'errors' => [ [ 'error' => 'incomplete_context' ] ] ];
        }

        $table  = Schema::table( 'assessment_scores' );
        $saved  = 0;
        $errors = [];

        foreach ( $scores as $student_id => $raw ) {
            $student_id = absint( $student_id );

            // An empty box means "not marked yet", which is NOT the same as zero.
            // Writing 0 for an unmarked student would quietly fail them.
            if ( $raw === '' || $raw === null ) {
                continue;
            }

            if ( ! is_numeric( $raw ) ) {
                $errors[] = [ 'student_id' => $student_id, 'error' => 'not_a_number', 'value' => (string) $raw ];
                continue;
            }

            $score = (float) $raw;

            if ( $score < 0 ) {
                $errors[] = [ 'student_id' => $student_id, 'error' => 'negative_score' ];
                continue;
            }

            if ( $score > $max ) {
                $errors[] = [ 'student_id' => $student_id, 'error' => 'exceeds_component_max:' . $score . '/' . $max ];
                continue;
            }

            if ( ! $this->student_offers_subject( $student_id, $subject_id, $session_id ) ) {
                $errors[] = [ 'student_id' => $student_id, 'error' => 'student_does_not_offer_subject' ];
                continue;
            }

            $wpdb->query(
                $wpdb->prepare(
                    "INSERT INTO {$table}
                        (school_id, student_id, subject_id, class_id, session_id, term_id, component_id, score, max_score, source, entered_by)
                     VALUES (%d, %d, %d, %d, %d, %d, %d, %f, %f, %s, %d)
                     ON DUPLICATE KEY UPDATE score = VALUES(score), max_score = VALUES(max_score),
                        source = VALUES(source), entered_by = VALUES(entered_by)",
                    $school_id, $student_id, $subject_id, $class_id, $session_id, $term_id,
                    $component_id, $score, $max, self::SOURCE_AWARDED, $entered_by
                )
            );

            $saved++;
        }

        EventDispatcher::action( 'educbt_ca_scores_awarded', [
            'school_id'    => $school_id,
            'component_id' => $component_id,
            'subject_id'   => $subject_id,
            'class_id'     => $class_id,
            'saved'        => $saved,
            'entered_by'   => $entered_by,
        ] );

        return [ 'saved' => $saved, 'skipped' => count( $errors ), 'errors' => $errors ];
    }

    /**
     * The class list a teacher marks against, with any existing score preloaded so
     * the form shows what is already entered rather than a blank grid.
     */
    public function entry_sheet( int $school_id, int $component_id, array $context ): array {
        global $wpdb;

        $students    = $wpdb->prefix . 'educbt_students';
        $enrollments = Schema::table( 'enrollments' );
        $registered  = Schema::table( 'student_subjects' );
        $scores      = Schema::table( 'assessment_scores' );

        $subject_id = absint( $context['subject_id'] ?? 0 );

        return (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT st.id AS student_id, st.admission_number, st.first_name, st.last_name,
                        sc.score, sc.source, sc.max_score
                 FROM {$enrollments} e
                 INNER JOIN {$students} st ON st.id = e.student_id
                 INNER JOIN {$registered} rs ON rs.student_id = st.id AND rs.subject_id = %d AND rs.session_id = e.session_id
                 LEFT JOIN {$scores} sc
                        ON sc.student_id = st.id AND sc.subject_id = %d
                       AND sc.term_id = %d AND sc.component_id = %d
                 WHERE e.school_id = %d AND e.class_id = %d AND e.session_id = %d AND e.status = 'active'
                 ORDER BY st.last_name ASC, st.first_name ASC",
                $subject_id, $subject_id,
                absint( $context['term_id'] ?? 0 ), $component_id,
                $school_id, absint( $context['class_id'] ?? 0 ), absint( $context['session_id'] ?? 0 )
            ),
            ARRAY_A
        );
    }

    // ---------------------------------------------------------------
    // Delivered: CA as a CBT paper
    // ---------------------------------------------------------------

    /**
     * Create a CA test as a real exam paper, credited to a CA component rather than
     * the exam component.
     *
     * A CA test differs from a terminal paper in ways that matter: it is short, it is
     * taken in class rather than under exam conditions, and an access code is friction
     * a teacher does not want for a twenty-minute Friday test.
     *
     * @return array{success:bool,paper_id?:int,errors?:array<int,string>}
     */
    public function create_ca_test( int $school_id, array $data ): array {
        $component_id = absint( $data['component_id'] ?? 0 );
        $component    = $this->component( $school_id, $component_id );

        if ( ! $component ) {
            return [ 'success' => false, 'errors' => [ 'component_not_found' ] ];
        }

        if ( ! empty( $component['is_exam'] ) ) {
            return [ 'success' => false, 'errors' => [ 'use_an_exam_series_for_the_exam_component' ] ];
        }

        $result = ( new ExamPaperService() )->create_paper(
            $school_id,
            array_merge(
                $data,
                [
                    'duration_seconds'     => absint( $data['duration_seconds'] ?? 0 ) ?: ( absint( $data['duration_minutes'] ?? 20 ) * 60 ),
                    'requires_access_code' => ! empty( $data['requires_access_code'] ),
                    'allow_review'         => $data['allow_review'] ?? 1,
                ]
            )
        );

        if ( empty( $result['success'] ) ) {
            return $result;
        }

        // The link that tells the grader which component to credit.
        update_option( 'educbt_paper_component_' . absint( $result['paper_id'] ), $component_id, false );

        return $result;
    }

    /**
     * Which component a paper's marks belong to. Defaults to the exam component, so
     * an ordinary terminal paper needs no configuration.
     */
    public function component_for_paper( int $school_id, int $paper_id ): int {
        $mapped = absint( get_option( 'educbt_paper_component_' . $paper_id, 0 ) );

        if ( $mapped > 0 ) {
            return $mapped;
        }

        global $wpdb;

        $id = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT id FROM ' . Schema::table( 'assessment_components' ) .
                " WHERE school_id = %d AND is_exam = 1 AND status = 'active' LIMIT 1",
                $school_id
            )
        );

        return $id ? absint( $id ) : 0;
    }

    /**
     * Scale a raw attempt mark into its component's weight.
     *
     * The scaling is the whole point: a 20-question CA test scored 15/20 must land as
     * 7.5 in a component worth 10, not as 15. Getting this wrong inflates every CA
     * mark in the school.
     */
    public function scale_score( float $raw, float $raw_max, float $component_max ): float {
        if ( $raw_max <= 0 ) {
            return 0.0;
        }

        return round( ( $raw / $raw_max ) * $component_max, 2 );
    }

    /**
     * Write a graded attempt into its component.
     *
     * An AWARDED mark is never silently overwritten by a delivered one: a teacher who
     * hand-entered a practical mark must not lose it because a test was later attached
     * to the same component.
     *
     * @return array{success:bool,score?:float,error?:string}
     */
    public function record_attempt_score( int $school_id, int $attempt_id, bool $overwrite_awarded = false ): array {
        global $wpdb;

        $attempts = Schema::table( 'attempts' );
        $papers   = Schema::table( 'exam_papers' );

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT a.*, p.subject_id, p.class_id FROM {$attempts} a
                 INNER JOIN {$papers} p ON p.id = a.paper_id
                 WHERE a.id = %d AND a.school_id = %d",
                $attempt_id,
                $school_id
            ),
            ARRAY_A
        );

        if ( ! $row ) {
            return [ 'success' => false, 'error' => 'attempt_not_found' ];
        }

        $component_id = $this->component_for_paper( $school_id, absint( $row['paper_id'] ) );
        $component    = $this->component( $school_id, $component_id );

        if ( ! $component ) {
            return [ 'success' => false, 'error' => 'no_component_for_paper' ];
        }

        $enrollment = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT session_id FROM ' . Schema::table( 'enrollments' ) .
                " WHERE student_id = %d AND class_id = %d AND status = 'active' LIMIT 1",
                absint( $row['student_id'] ),
                absint( $row['class_id'] )
            ),
            ARRAY_A
        );

        $session_id = absint( $enrollment['session_id'] ?? 0 );
        $term_id    = $this->current_term_id( $school_id );

        if ( $session_id <= 0 || $term_id <= 0 ) {
            return [ 'success' => false, 'error' => 'no_session_or_term' ];
        }

        $component_max = (float) $component['max_score'];
        $scaled        = $this->scale_score( (float) $row['raw_score'], (float) $row['max_score'], $component_max );

        $table = Schema::table( 'assessment_scores' );

        $existing = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, source FROM {$table}
                 WHERE student_id = %d AND subject_id = %d AND term_id = %d AND component_id = %d",
                absint( $row['student_id'] ), absint( $row['subject_id'] ), $term_id, $component_id
            ),
            ARRAY_A
        );

        if ( $existing && (string) $existing['source'] === self::SOURCE_AWARDED && ! $overwrite_awarded ) {
            return [ 'success' => false, 'error' => 'would_overwrite_manually_awarded_mark' ];
        }

        $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO {$table}
                    (school_id, student_id, subject_id, class_id, session_id, term_id, component_id, score, max_score, source, attempt_id)
                 VALUES (%d, %d, %d, %d, %d, %d, %d, %f, %f, %s, %d)
                 ON DUPLICATE KEY UPDATE score = VALUES(score), max_score = VALUES(max_score),
                    source = VALUES(source), attempt_id = VALUES(attempt_id)",
                $school_id, absint( $row['student_id'] ), absint( $row['subject_id'] ), absint( $row['class_id'] ),
                $session_id, $term_id, $component_id, $scaled, $component_max,
                self::SOURCE_DELIVERED, $attempt_id
            )
        );

        return [ 'success' => true, 'score' => $scaled ];
    }

    /**
     * How complete a class's CA is, per component. This is what a class teacher and an
     * exam officer both actually want before results are compiled: not the scores, but
     * who is MISSING one.
     */
    public function completion( int $school_id, int $subject_id, int $class_id, int $term_id ): array {
        global $wpdb;

        $components  = Schema::table( 'assessment_components' );
        $scores      = Schema::table( 'assessment_scores' );
        $enrollments = Schema::table( 'enrollments' );
        $registered  = Schema::table( 'student_subjects' );

        $expected = absint(
            $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$enrollments} e
                     INNER JOIN {$registered} rs ON rs.student_id = e.student_id
                        AND rs.subject_id = %d AND rs.session_id = e.session_id
                     WHERE e.class_id = %d AND e.status = 'active'",
                    $subject_id,
                    $class_id
                )
            )
        );

        $rows = (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT c.id, c.name, c.code, c.max_score, c.is_exam, COUNT(s.id) AS entered
                 FROM {$components} c
                 LEFT JOIN {$scores} s ON s.component_id = c.id AND s.subject_id = %d
                       AND s.class_id = %d AND s.term_id = %d
                 WHERE c.school_id = %d AND c.status = 'active'
                 GROUP BY c.id ORDER BY c.sort_order ASC",
                $subject_id, $class_id, $term_id, $school_id
            ),
            ARRAY_A
        );

        foreach ( $rows as &$row ) {
            $row['expected'] = $expected;
            $row['missing']  = max( 0, $expected - absint( $row['entered'] ) );
            $row['complete'] = $row['missing'] === 0;
        }

        return $rows;
    }

    // ---------------------------------------------------------------

    /**
     * The school's active assessment components, in the order they are marked.
     *
     * This was called from the score-entry screen before it existed here — a fatal
     * that only appeared when a teacher opened the page. There is now a static audit
     * that scans templates for service calls and checks the method is real.
     *
     * @return array<int,array<string,mixed>>
     */
    public function components( int $school_id ): array {
        global $wpdb;

        return (array) $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM ' . Schema::table( 'assessment_components' ) .
                " WHERE school_id = %d AND status = 'active' ORDER BY sort_order ASC, id ASC",
                $school_id
            ),
            ARRAY_A
        );
    }

    /**
     * Total obtainable marks in a term, from the component weights.
     */
    public function term_total( int $school_id ): float {
        $total = 0.0;

        foreach ( $this->components( $school_id ) as $component ) {
            $total += (float) $component['max_score'];
        }

        return $total;
    }

    private function component( int $school_id, int $component_id ): ?array {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM ' . Schema::table( 'assessment_components' ) . ' WHERE id = %d AND school_id = %d',
                $component_id,
                $school_id
            ),
            ARRAY_A
        );

        return $row ?: null;
    }

    private function student_offers_subject( int $student_id, int $subject_id, int $session_id ): bool {
        global $wpdb;

        return (bool) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT id FROM ' . Schema::table( 'student_subjects' ) .
                ' WHERE student_id = %d AND subject_id = %d AND session_id = %d',
                $student_id, $subject_id, $session_id
            )
        );
    }

    private function current_term_id( int $school_id ): int {
        global $wpdb;

        $id = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT id FROM ' . Schema::table( 'terms' ) . ' WHERE school_id = %d AND is_current = 1 LIMIT 1',
                $school_id
            )
        );

        return $id ? absint( $id ) : 0;
    }
}
