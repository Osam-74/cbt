<?php

namespace EduCBTPro\Services;

use EduCBTPro\Core\EventDispatcher;
use EduCBTPro\Core\Schema;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * PHASE 8 — the ACADEMIC TRANSCRIPT.
 *
 * ON THE DIFFERENCE BETWEEN THE TWO DOCUMENTS, since you asked.
 *
 * They are not two printouts of the same thing. They differ in period, audience,
 * purpose and legal weight:
 *
 *                    TERMINAL REPORT SHEET          ACADEMIC TRANSCRIPT
 *   Period           One term                       JSS1 through SS3, every term
 *   Audience         The parent                     Another institution
 *   Purpose          Progress — how is my child     Verification — what did this
 *                    doing right now                student achieve, officially
 *   Detail           CA + exam split, position,     Final subject scores and grades
 *                    class average, remarks         per session, cumulative average
 *   Remarks          Candid and behavioural         None. "Careless in Mathematics"
 *                                                   must never reach an admissions
 *                                                   office years later
 *   Issued           Automatically, every term      On request, and paid for
 *   Authority        Class teacher and principal    Principal only, sealed, serialised
 *   Frequency        3 per year, freely reprinted   Rare, and every copy recorded
 *
 * A Nigerian secondary transcript compiles results from JSS1 to SS3. It is NOT the
 * WAEC/NECO certificate — that reports one external examination, whereas the
 * transcript is the school's own internal record across the whole six years, and
 * universities abroad ask for both.
 *
 * The watermark, serial number and issuance log exist because this document travels
 * to people who cannot ring the school to check it. A report sheet has no such
 * problem, which is why it deliberately carries no watermark — marking every
 * document would make the marking meaningless.
 */
class TranscriptService {

    private DocumentBrandingService $branding;

    public function __construct( ?DocumentBrandingService $branding = null ) {
        $this->branding = $branding ?? new DocumentBrandingService();
    }

    /**
     * Assemble a student's full academic history.
     *
     * Sessions descend from the most recent, but terms within a session ascend, which
     * is how a reader actually scans a transcript: newest year first, then First,
     * Second, Third within it.
     *
     * @return array<string,mixed>
     */
    public function compile( int $school_id, int $student_id ): array {
        global $wpdb;

        $subject_results = Schema::table( 'subject_results' );
        $term_results    = Schema::table( 'term_results' );
        $subjects        = Schema::table( 'subjects_v2' );
        $terms           = Schema::table( 'terms' );
        $sessions        = Schema::table( 'academic_sessions' );
        $classes         = Schema::table( 'classes' );

        // Only PUBLISHED results appear. An unapproved mark must never leave the
        // school inside an official document.
        $rows = (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT sr.subject_id, sr.total, sr.grade, sr.term_id, sr.session_id,
                        s.name AS subject_name, s.code AS subject_code,
                        t.title AS term_name, t.term_order,
                        se.title AS session_name,
                        c.display_name AS class_name
                 FROM {$subject_results} sr
                 INNER JOIN {$term_results} tr
                         ON tr.student_id = sr.student_id AND tr.term_id = sr.term_id
                 INNER JOIN {$subjects} s ON s.id = sr.subject_id
                 INNER JOIN {$terms} t ON t.id = sr.term_id
                 INNER JOIN {$sessions} se ON se.id = sr.session_id
                 LEFT JOIN {$classes} c ON c.id = sr.class_id
                 WHERE sr.school_id = %d AND sr.student_id = %d AND tr.status = %s
                 ORDER BY se.title DESC, t.term_order ASC, s.name ASC",
                $school_id,
                $student_id,
                ResultWorkflowService::PUBLISHED
            ),
            ARRAY_A
        );

        $summaries = (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT tr.*, t.title AS term_name, t.term_order, se.title AS session_name,
                        c.display_name AS class_name
                 FROM {$term_results} tr
                 INNER JOIN {$terms} t ON t.id = tr.term_id
                 INNER JOIN {$sessions} se ON se.id = tr.session_id
                 LEFT JOIN {$classes} c ON c.id = tr.class_id
                 WHERE tr.school_id = %d AND tr.student_id = %d AND tr.status = %s
                 ORDER BY se.title DESC, t.term_order ASC",
                $school_id,
                $student_id,
                ResultWorkflowService::PUBLISHED
            ),
            ARRAY_A
        );

        // Group into sessions, each holding its terms.
        $grouped = [];

        foreach ( $summaries as $summary ) {
            $session = (string) $summary['session_name'];
            $term_id = absint( $summary['term_id'] );

            $grouped[ $session ]['session'] = $session;
            $grouped[ $session ]['class']   = (string) $summary['class_name'];

            $grouped[ $session ]['terms'][ $term_id ] = [
                'term'      => (string) $summary['term_name'],
                'subjects'  => [],
                'average'   => (float) $summary['average_score'],
                'position'  => absint( $summary['class_position'] ),
                'class_size' => absint( $summary['class_size'] ),
            ];
        }

        foreach ( $rows as $row ) {
            $session = (string) $row['session_name'];
            $term_id = absint( $row['term_id'] );

            if ( ! isset( $grouped[ $session ]['terms'][ $term_id ] ) ) {
                continue;
            }

            $grouped[ $session ]['terms'][ $term_id ]['subjects'][] = [
                'name'  => (string) $row['subject_name'],
                'code'  => (string) $row['subject_code'],
                'score' => (float) $row['total'],
                'grade' => (string) $row['grade'],
            ];
        }

        $averages = array_map( static fn( array $s ): float => (float) $s['average_score'], $summaries );

        return [
            'found'              => ! empty( $summaries ),
            'sessions'           => array_values( $grouped ),
            'terms_recorded'     => count( $summaries ),
            'cumulative_average' => $averages ? round( array_sum( $averages ) / count( $averages ), 2 ) : 0.0,
            'student'            => $this->student( $school_id, $student_id ),
        ];
    }

    private function student( int $school_id, int $student_id ): array {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT admission_number, first_name, last_name, gender, date_of_birth, passport_photo, status
                 FROM ' . $wpdb->prefix . 'educbt_students WHERE id = %d AND school_id = %d',
                $student_id,
                $school_id
            ),
            ARRAY_A
        );

        if ( ! $row ) {
            return [ 'name' => '', 'admission_number' => '' ];
        }

        return [
            'name'             => trim( $row['first_name'] . ' ' . $row['last_name'] ),
            'admission_number' => (string) $row['admission_number'],
            'gender'           => (string) $row['gender'],
            'date_of_birth'    => (string) $row['date_of_birth'],
            'photo'            => (string) $row['passport_photo'],
            'status'           => (string) $row['status'],
        ];
    }

    /**
     * Issue a transcript: record it, allocate a serial, render it.
     *
     * Issuance is recorded BEFORE rendering. If the render fails the school still has
     * a record that a transcript was requested — the opposite order would let a copy
     * leave the building with no trace.
     *
     * @return array{success:bool,serial?:string,html?:string,error?:string}
     */
    public function issue( int $school_id, int $student_id, int $issued_by, string $purpose = '' ): array {
        $data = $this->compile( $school_id, $student_id );

        if ( empty( $data['found'] ) ) {
            return [ 'success' => false, 'error' => 'no_published_results_to_transcribe' ];
        }

        $serial = $this->allocate_serial( $school_id );

        global $wpdb;

        $wpdb->insert(
            Schema::table( 'transcripts' ),
            [
                'school_id'  => $school_id,
                'student_id' => $student_id,
                'serial'     => $serial,
                'purpose'    => sanitize_text_field( $purpose ),
                'issued_by'  => $issued_by,
                'issued_at'  => current_time( 'mysql', true ),
                'checksum'   => $this->checksum( $serial, $data ),
                'status'     => 'issued',
            ],
            [ '%d', '%d', '%s', '%s', '%d', '%s', '%s', '%s' ]
        );

        EventDispatcher::action( 'educbt_transcript_issued', [
            'school_id'  => $school_id,
            'student_id' => $student_id,
            'serial'     => $serial,
            'issued_by'  => $issued_by,
            'purpose'    => $purpose,
        ] );

        return [
            'success' => true,
            'serial'  => $serial,
            'html'    => $this->render( $school_id, $data, $serial ),
        ];
    }

    /**
     * GRE001/TR/2026/0007 — school, document type, year, sequence.
     */
    public function allocate_serial( int $school_id ): string {
        global $wpdb;

        $code = (string) $wpdb->get_var(
            $wpdb->prepare( 'SELECT school_code FROM ' . $wpdb->prefix . 'educbt_schools WHERE id = %d', $school_id )
        );

        $prefix = ( $code !== '' ? $code : 'SCH' ) . '/TR/' . gmdate( 'Y' ) . '/';
        $table  = Schema::table( 'transcripts' );

        $last = (string) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT serial FROM {$table} WHERE school_id = %d AND serial LIKE %s ORDER BY id DESC LIMIT 1",
                $school_id,
                $wpdb->esc_like( $prefix ) . '%'
            )
        );

        $sequence = 1;

        if ( $last !== '' && preg_match( '/(\d+)$/', $last, $m ) ) {
            $sequence = (int) $m[1] + 1;
        }

        return $prefix . str_pad( (string) $sequence, 4, '0', STR_PAD_LEFT );
    }

    /**
     * A short digest over the serial and the transcribed content.
     *
     * It lets the school confirm that a returned copy matches what was issued. It is
     * NOT a signature and proves nothing to a third party on its own — the seal and
     * the school's own records remain the real authority.
     */
    private function checksum( string $serial, array $data ): string {
        $material = $serial . '|' . $data['student']['admission_number'] . '|' . $data['terms_recorded'] . '|' . $data['cumulative_average'];

        return strtoupper( substr( hash( 'sha256', $material . wp_salt( 'auth' ) ), 0, 12 ) );
    }

    /**
     * Render the transcript, watermark included.
     */
    public function render( int $school_id, array $data, string $serial ): string {
        $letterhead = $this->branding->letterhead( $school_id );
        $student    = $data['student'];

        $body = $this->branding->render_watermark( $letterhead )
            . '<div class="educbt-doc__sheet">'
            . $this->branding->render_letterhead( $letterhead, 'Academic Transcript' )
            . $this->bio_block( $student, $data )
            . $this->history( $data['sessions'] )
            . $this->cumulative_block( $data )
            . $this->attestation( $letterhead, $serial )
            . '</div>';

        return $this->branding->wrap(
            $body,
            trim( $student['name'] . ' — Academic Transcript' ),
            'educbt-doc--transcript'
        );
    }

    private function bio_block( array $student, array $data ): string {
        $photo = ! empty( $student['photo'] )
            ? sprintf( '<img class="educbt-doc__photo" src="%s" alt="">', esc_url( $student['photo'] ) )
            : '';

        return sprintf(
            '<table class="educbt-doc__bio"><tr>
                <td class="label">Name</td><td><strong>%s</strong></td>
                <td class="label">Admission No.</td><td>%s</td>
                <td rowspan="3" style="width:27mm;text-align:center">%s</td>
             </tr><tr>
                <td class="label">Date of Birth</td><td>%s</td>
                <td class="label">Sex</td><td>%s</td>
             </tr><tr>
                <td class="label">Terms Recorded</td><td>%d</td>
                <td class="label">Status</td><td>%s</td>
             </tr></table>',
            esc_html( $student['name'] ),
            esc_html( $student['admission_number'] ),
            $photo,
            esc_html( (string) ( $student['date_of_birth'] ?? '' ) ?: '—' ),
            esc_html( ucfirst( (string) ( $student['gender'] ?? '' ) ) ?: '—' ),
            absint( $data['terms_recorded'] ),
            esc_html( ucfirst( (string) ( $student['status'] ?? '' ) ) )
        );
    }

    /**
     * One block per session, one table per term. No remarks anywhere — a candid
     * termly comment must not follow a student into an admissions office.
     */
    private function history( array $sessions ): string {
        $html = '';

        foreach ( $sessions as $session ) {
            $html .= sprintf(
                '<div class="educbt-doc__session"><h2 style="font-size:11pt;margin:5mm 0 2mm">%s &nbsp;&middot;&nbsp; %s</h2>',
                esc_html( (string) $session['session'] ),
                esc_html( (string) $session['class'] )
            );

            foreach ( (array) $session['terms'] as $term ) {
                $rows = '';

                foreach ( (array) $term['subjects'] as $subject ) {
                    $rows .= sprintf(
                        '<tr><td class="subject">%s</td><td>%s</td><td>%s</td></tr>',
                        esc_html( (string) $subject['name'] ),
                        esc_html( self::num( $subject['score'] ) ),
                        esc_html( (string) $subject['grade'] )
                    );
                }

                $html .= sprintf(
                    '<table class="educbt-doc__table" style="margin-bottom:3mm">
                        <thead><tr>
                            <th style="text-align:left">%s — Subject</th><th style="width:18mm">Score</th><th style="width:18mm">Grade</th>
                        </tr></thead>
                        <tbody>%s</tbody>
                        <tfoot><tr><td class="subject">Term average %s%% &nbsp;&middot;&nbsp; Position %s of %d</td><td colspan="2"></td></tr></tfoot>
                     </table>',
                    esc_html( (string) $term['term'] ),
                    $rows,
                    esc_html( self::num( $term['average'] ) ),
                    esc_html( ReportCardDocument::ordinal( absint( $term['position'] ) ) ),
                    absint( $term['class_size'] )
                );
            }

            $html .= '</div>';
        }

        return $html;
    }

    private function cumulative_block( array $data ): string {
        return sprintf(
            '<div class="educbt-doc__summary">
                <div class="educbt-doc__stat"><b>%d</b><span>Terms Recorded</span></div>
                <div class="educbt-doc__stat"><b>%s%%</b><span>Cumulative Average</span></div>
                <div class="educbt-doc__stat"><b>%d</b><span>Sessions</span></div>
             </div>',
            absint( $data['terms_recorded'] ),
            esc_html( self::num( $data['cumulative_average'] ) ),
            count( (array) $data['sessions'] )
        );
    }

    private function attestation( array $letterhead, string $serial ): string {
        return sprintf(
            '<p class="educbt-doc__key" style="margin-top:6mm">
                This transcript is a complete record of the internal academic results of the named
                student at %s. It is not a substitute for the West African Senior School Certificate
                (WASSCE) or NECO result, which reports a separate external examination.
                It is invalid without the official seal of the school.
             </p>
             <div class="educbt-doc__sign">
                <div>Registrar</div>
                <div>Principal</div>
             </div>
             <div class="educbt-doc__serial">
                <span>Serial: <strong>%s</strong></span>
                <span>Issued: %s</span>
             </div>',
            esc_html( $letterhead['name'] ),
            esc_html( $serial ),
            esc_html( gmdate( 'j F Y' ) )
        );
    }

    /**
     * Every copy ever issued for a student. A transcript that turns up in a dispute
     * can be traced to who issued it, when, and for what.
     *
     * @return array<int,array<string,mixed>>
     */
    public function issuance_history( int $school_id, int $student_id ): array {
        global $wpdb;

        return (array) $wpdb->get_results(
            $wpdb->prepare(
                'SELECT serial, purpose, issued_by, issued_at, checksum, status
                 FROM ' . Schema::table( 'transcripts' ) . '
                 WHERE school_id = %d AND student_id = %d ORDER BY issued_at DESC',
                $school_id,
                $student_id
            ),
            ARRAY_A
        );
    }

    /**
     * Revoke a transcript issued in error, so a later verification can say so.
     */
    public function revoke( int $school_id, string $serial, string $reason ): array {
        global $wpdb;

        if ( trim( $reason ) === '' ) {
            return [ 'success' => false, 'error' => 'reason_required' ];
        }

        $updated = $wpdb->update(
            Schema::table( 'transcripts' ),
            [ 'status' => 'revoked', 'purpose' => sanitize_text_field( $reason ) ],
            [ 'school_id' => $school_id, 'serial' => $serial ],
            [ '%s', '%s' ],
            [ '%d', '%s' ]
        );

        return [ 'success' => (bool) $updated ];
    }

    /**
     * Verify a serial. Returns only what a legitimate checker needs — never the
     * student's marks, because this may be exposed to an unauthenticated verifier.
     *
     * @return array{found:bool,valid?:bool,student?:string,issued_at?:string,status?:string}
     */
    public function verify( string $serial, string $checksum = '' ): array {
        global $wpdb;

        $students = $wpdb->prefix . 'educbt_students';

        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT t.serial, t.issued_at, t.status, t.checksum,
                        CONCAT(s.first_name, " ", s.last_name) AS student_name
                 FROM ' . Schema::table( 'transcripts' ) . ' t
                 INNER JOIN ' . $students . ' s ON s.id = t.student_id
                 WHERE t.serial = %s LIMIT 1',
                $serial
            ),
            ARRAY_A
        );

        if ( ! $row ) {
            return [ 'found' => false ];
        }

        $valid = (string) $row['status'] === 'issued';

        if ( $checksum !== '' ) {
            $valid = $valid && hash_equals( (string) $row['checksum'], strtoupper( $checksum ) );
        }

        return [
            'found'     => true,
            'valid'     => $valid,
            'student'   => (string) $row['student_name'],
            'issued_at' => (string) $row['issued_at'],
            'status'    => (string) $row['status'],
        ];
    }

    private static function num( $value ): string {
        $float = (float) $value;

        return $float == (int) $float ? (string) (int) $float : number_format( $float, 1 );
    }
}
