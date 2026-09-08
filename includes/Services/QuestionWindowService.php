<?php

namespace EduCBTPro\Services;

use EduCBTPro\Core\Schema;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * What the question bank is currently open for.
 *
 * THE PROBLEM THIS SOLVES
 * -----------------------
 * A teacher opening the question bank used to have no way of knowing what they
 * were writing for. The office would open a first CA window; the teacher would
 * write twenty items; those items would land against the terminal examination
 * because that is what the bank happened to be pointing at. Nobody discovered it
 * until the CA test composed with nothing in it.
 *
 * So the bank has exactly ONE open window at a time, and it is the school office
 * that decides which. Everything a teacher writes goes to that window. There is no
 * ambiguity because there is no choice.
 *
 * WHY ONLY ONE
 * ------------
 * Two open windows would put the question straight back to the teacher: "is this
 * for the second CA or the examination?" Terms run in sequence — first CA, second
 * CA, then examinations — so the windows run in sequence too. Opening a new one
 * closes the last, which is what the school is doing in practice anyway.
 *
 * A window can also be closed by hand, which shuts the bank entirely. That is the
 * right state between assessments: nothing is being collected, so nothing can be
 * written into the wrong place.
 */
class QuestionWindowService {

    private const OPTION = 'educbt_open_question_window_';

    /**
     * Open a window for a series, closing whatever was open before.
     *
     * @param string $type 'exam' or 'ca_test'
     */
    public function open( int $school_id, int $series_id, string $type ): void {
        update_option(
            self::OPTION . $school_id,
            [ 'series_id' => $series_id, 'type' => $type, 'opened_at' => current_time( 'mysql' ) ],
            false
        );
    }

    /**
     * Shut the bank. Nothing can be written until a window is opened again.
     */
    public function close( int $school_id ): void {
        delete_option( self::OPTION . $school_id );
    }

    /**
     * The open window, with the detail a teacher needs to know what they are
     * writing for. Null when the bank is shut.
     *
     * @return array<string,mixed>|null
     */
    public function current( int $school_id ): ?array {
        global $wpdb;

        $stored = (array) get_option( self::OPTION . $school_id, [] );
        $series_id = absint( $stored['series_id'] ?? 0 );

        if ( $series_id <= 0 ) {
            return null;
        }

        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT se.*, c.name AS component_name, c.max_score
                 FROM ' . Schema::table( 'exam_series' ) . ' se
                 LEFT JOIN ' . Schema::table( 'assessment_components' ) . ' c ON c.id = se.component_id
                 WHERE se.id = %d AND se.school_id = %d',
                $series_id,
                $school_id
            ),
            ARRAY_A
        );

        // The series was deleted out from under the option. Treat the bank as shut
        // rather than pointing teachers at something that no longer exists.
        if ( ! $row ) {
            $this->close( $school_id );

            return null;
        }

        $is_ca = (string) ( $row['series_type'] ?? '' ) === CaTestWindowService::SERIES_TYPE;

        return [
            'series_id'  => $series_id,
            'type'       => $is_ca ? 'ca_test' : 'exam',
            'is_ca'      => $is_ca,
            'title'      => (string) $row['title'],
            'component'  => (string) ( $row['component_name'] ?? '' ),
            'max_score'  => (float) ( $row['max_score'] ?? 0 ),
            'starts_on'  => (string) ( $row['starts_on'] ?? '' ),
            'closes_on'  => (string) ( $row['ends_on'] ?? '' ),
            'per_student' => absint( $row['questions_per_student'] ?? 0 ),
            'duration'   => absint( $row['duration_minutes'] ?? 0 ),
            'opened_at'  => (string) ( $stored['opened_at'] ?? '' ),
        ];
    }

    /**
     * The school's practice exam for this term, if one exists.
     *
     * Practice is always available. It is not scheduled and not reviewed, so there
     * is nothing for the office to open or close — a teacher can add to it whenever
     * they think of a good question, including while the bank is otherwise shut.
     *
     * @return array<string,mixed>|null
     */
    public function practice( int $school_id ): ?array {
        global $wpdb;

        $ay         = new AcademicYearService();
        $session    = $ay->current_session( $school_id );
        $session_id = absint( $session['id'] ?? 0 );
        $term       = $ay->resolve_current_term( $school_id, $session_id );

        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT id, title FROM ' . Schema::table( 'exam_series' ) . "
                 WHERE school_id = %d AND session_id = %d AND COALESCE(term_id,0) = %d
                   AND series_type = 'practice' LIMIT 1",
                $school_id,
                $session_id,
                absint( $term['id'] ?? 0 )
            ),
            ARRAY_A
        );

        if ( ! $row ) {
            return null;
        }

        return [
            'series_id' => absint( $row['id'] ),
            'type'      => 'practice',
            'is_ca'     => false,
            'is_practice' => true,
            'title'     => (string) $row['title'],
            'component' => '',
            'closes_on' => '',
            'per_student' => 0,
            'duration'  => 0,
        ];
    }

    /**
     * A short line for a dashboard or a menu badge.
     */
    public function label( int $school_id ): string {
        $window = $this->current( $school_id );

        if ( ! $window ) {
            return '';
        }

        return $window['is_ca']
            ? 'Set questions — ' . $window['title']
            : 'Set examination questions — ' . $window['title'];
    }
}
