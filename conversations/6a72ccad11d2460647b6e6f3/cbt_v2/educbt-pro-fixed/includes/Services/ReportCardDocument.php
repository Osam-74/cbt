<?php

namespace EduCBTPro\Services;

use EduCBTPro\Core\Schema;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * PHASE 7b — the TERMINAL REPORT SHEET.
 *
 * This is the document a parent receives at the end of every term. It answers
 * "how did my child do this term".
 *
 * It covers ONE term and shows the working: CA and exam split out per subject,
 * total, grade, subject position, class average, and the class teacher's and
 * principal's remarks. It is a progress report, and it is expected to be candid —
 * "shows little interest in Mathematics" belongs here.
 *
 * It is NOT a transcript. See TranscriptService for that distinction.
 */
class ReportCardDocument {

    private DocumentBrandingService $branding;

    public function __construct( ?DocumentBrandingService $branding = null ) {
        $this->branding = $branding ?? new DocumentBrandingService();
    }

    /**
     * @return array{found:bool,html?:string,status?:string,reason?:string}
     */
    public function render( int $school_id, int $student_id, int $term_id, bool $allow_unpublished = false ): array {
        $data = ( new ResultCompilationService() )->report_card( $school_id, $student_id, $term_id );

        if ( empty( $data['found'] ) ) {
            return [ 'found' => false, 'reason' => 'no_result_for_this_term' ];
        }

        // A parent must never be handed a report that has not been approved and
        // published. Staff previewing before publication pass the flag explicitly.
        if ( ! $allow_unpublished && (string) $data['status'] !== ResultWorkflowService::PUBLISHED ) {
            return [ 'found' => false, 'reason' => 'not_yet_published', 'status' => (string) $data['status'] ];
        }

        $letterhead = $this->branding->letterhead( $school_id );
        $student    = $this->student( $school_id, $student_id );
        $context    = $this->context( $school_id, $term_id );

        $body = '<div class="educbt-doc__sheet">'
            . $this->branding->render_letterhead( $letterhead, 'Terminal Report Sheet' )
            . $this->bio_block( $student, $context, $data['summary'] )
            . $this->marks_table( $data['subjects'] )
            . $this->summary_block( $data['summary'] )
            . $this->remarks_block( $data['summary'], $letterhead )
            . $this->grade_key( $school_id )
            . $this->signature_block()
            . '</div>';

        // Deliberately no watermark. A report sheet is a routine termly document,
        // not a certified record, and a watermark on every one would make the
        // marking on the transcript meaningless.
        $title = trim( $student['name'] . ' — ' . $context['term'] . ' Report' );

        return [
            'found'  => true,
            'status' => (string) $data['status'],
            'html'   => $this->branding->wrap( $body, $title, 'educbt-doc--report' ),
        ];
    }

    private function student( int $school_id, int $student_id ): array {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT admission_number, first_name, last_name, gender, date_of_birth, passport_photo
                 FROM ' . $wpdb->prefix . 'educbt_students WHERE id = %d AND school_id = %d',
                $student_id,
                $school_id
            ),
            ARRAY_A
        );

        if ( ! $row ) {
            return [ 'name' => '', 'admission_number' => '', 'gender' => '', 'photo' => '' ];
        }

        return [
            'name'             => trim( $row['first_name'] . ' ' . $row['last_name'] ),
            'admission_number' => (string) $row['admission_number'],
            'gender'           => (string) $row['gender'],
            'photo'            => (string) $row['passport_photo'],
        ];
    }

    private function context( int $school_id, int $term_id ): array {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT t.title AS term, t.ends_on, s.title AS session
                 FROM ' . Schema::table( 'terms' ) . ' t
                 INNER JOIN ' . Schema::table( 'academic_sessions' ) . ' s ON s.id = t.session_id
                 WHERE t.id = %d AND t.school_id = %d',
                $term_id,
                $school_id
            ),
            ARRAY_A
        );

        return [
            'term'    => (string) ( $row['term'] ?? '' ),
            'session' => (string) ( $row['session'] ?? '' ),
            'ends_on' => (string) ( $row['ends_on'] ?? '' ),
        ];
    }

    private function bio_block( array $student, array $context, array $summary ): string {
        $photo = $student['photo'] !== ''
            ? sprintf( '<img class="educbt-doc__photo" src="%s" alt="">', esc_url( $student['photo'] ) )
            : '';

        return sprintf(
            '<table class="educbt-doc__bio"><tr>
                <td class="label">Name</td><td><strong>%s</strong></td>
                <td class="label">Admission No.</td><td>%s</td>
                <td rowspan="3" style="width:27mm;text-align:center">%s</td>
             </tr><tr>
                <td class="label">Class</td><td>%s</td>
                <td class="label">Session</td><td>%s</td>
             </tr><tr>
                <td class="label">Term</td><td>%s</td>
                <td class="label">No. in Class</td><td>%d</td>
             </tr></table>',
            esc_html( $student['name'] ),
            esc_html( $student['admission_number'] ),
            $photo,
            esc_html( (string) ( $summary['class_name'] ?? '' ) ),
            esc_html( $context['session'] ),
            esc_html( $context['term'] ),
            absint( $summary['class_size'] ?? 0 )
        );
    }

    private function marks_table( array $subjects ): string {
        $rows = '';

        foreach ( $subjects as $line ) {
            $rows .= sprintf(
                '<tr>
                    <td class="subject">%s</td>
                    <td>%s</td><td>%s</td><td><strong>%s</strong></td>
                    <td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td>
                 </tr>',
                esc_html( (string) $line['subject_name'] ),
                esc_html( self::num( $line['ca_total'] ) ),
                esc_html( self::num( $line['exam_total'] ) ),
                esc_html( self::num( $line['total'] ) ),
                esc_html( (string) $line['grade'] ),
                esc_html( self::ordinal( absint( $line['subject_position'] ) ) ),
                esc_html( self::num( $line['class_average'] ) ),
                esc_html( self::num( $line['highest_in_class'] ) ),
                esc_html( (string) $line['remark'] )
            );
        }

        return '<table class="educbt-doc__table">
            <thead><tr>
                <th style="text-align:left">Subject</th>
                <th>CA</th><th>Exam</th><th>Total</th><th>Grade</th>
                <th>Pos.</th><th>Class Avg</th><th>Highest</th><th>Remark</th>
            </tr></thead>
            <tbody>' . $rows . '</tbody></table>';
    }

    private function summary_block( array $summary ): string {
        return sprintf(
            '<div class="educbt-doc__summary">
                <div class="educbt-doc__stat"><b>%d</b><span>Subjects</span></div>
                <div class="educbt-doc__stat"><b>%s</b><span>Total Score</span></div>
                <div class="educbt-doc__stat"><b>%s%%</b><span>Average</span></div>
                <div class="educbt-doc__stat"><b>%s</b><span>Position in Class</span></div>
             </div>',
            absint( $summary['subjects_offered'] ),
            esc_html( self::num( $summary['total_score'] ) ),
            esc_html( self::num( $summary['average_score'] ) ),
            esc_html( self::ordinal( absint( $summary['class_position'] ) ) )
        );
    }

    private function remarks_block( array $summary, array $letterhead ): string {
        $class_teacher = trim( (string) ( $summary['class_teacher_remark'] ?? '' ) );
        $principal     = trim( (string) ( $summary['principal_remark'] ?? '' ) );

        return sprintf(
            '<div class="educbt-doc__remarks">
                <p><strong>Class Teacher&rsquo;s Remark:</strong> %s</p>
                <p><strong>Principal&rsquo;s Remark:</strong> %s</p>
             </div>',
            esc_html( $class_teacher !== '' ? $class_teacher : '—' ),
            esc_html( $principal !== '' ? $principal : '—' )
        );
    }

    /**
     * The grading key must be printed on the sheet. A parent cannot interpret "B3"
     * without it, and different schools use different bands.
     */
    private function grade_key( int $school_id ): string {
        $bands = ( new GradingService() )->bands( $school_id );

        if ( empty( $bands ) ) {
            return '';
        }

        $parts = [];

        foreach ( $bands as $band ) {
            $parts[] = sprintf(
                '%s: %s&ndash;%s (%s)',
                esc_html( (string) $band['grade'] ),
                esc_html( self::num( $band['min_score'] ) ),
                esc_html( self::num( $band['max_score'] ) ),
                esc_html( (string) $band['remark'] )
            );
        }

        return '<p class="educbt-doc__key"><strong>Grading Key:</strong> ' . implode( ' &nbsp;|&nbsp; ', $parts ) . '</p>';
    }

    private function signature_block(): string {
        return '<div class="educbt-doc__sign">
            <div>Class Teacher</div>
            <div>Principal</div>
        </div>';
    }

    private static function num( $value ): string {
        $float = (float) $value;

        // Trailing ".00" on every mark makes a dense table much harder to scan.
        return $float == (int) $float ? (string) (int) $float : number_format( $float, 1 );
    }

    /**
     * 1st, 2nd, 3rd, 4th, and correctly 11th/12th/13th.
     */
    public static function ordinal( int $n ): string {
        if ( $n <= 0 ) {
            return '—';
        }

        $mod100 = $n % 100;

        if ( $mod100 >= 11 && $mod100 <= 13 ) {
            return $n . 'th';
        }

        $suffix = [ 1 => 'st', 2 => 'nd', 3 => 'rd' ][ $n % 10 ] ?? 'th';

        return $n . $suffix;
    }
}
