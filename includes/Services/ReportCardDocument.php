<?php

namespace EduCBTPro\Services;

use EduCBTPro\Core\Schema;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The TERMINAL REPORT SHEET — the document a parent receives at end of term.
 *
 * Shows: CA and exam split per subject, total, grade, position, class average,
 * remarks from class teacher and principal, a watermark of the school name,
 * a QR verification code, and staff signatures with names and roles.
 */
class ReportCardDocument {

    private DocumentBrandingService $branding;

    public function __construct( ?DocumentBrandingService $branding = null ) {
        $this->branding = $branding ?? new DocumentBrandingService();
    }

    /**
     * @return array{found:bool,html?:string,status?:string,reason?:string}
     */
    public function render( int $school_id, int $student_id, int $term_id, bool $allow_unpublished = false, string $extra_html = '' ): array {
        $data = ( new ResultCompilationService() )->report_card( $school_id, $student_id, $term_id );

        if ( empty( $data['found'] ) ) {
            return [ 'found' => false, 'reason' => 'no_result_for_this_term' ];
        }

        if ( ! $allow_unpublished && (string) $data['status'] !== ResultWorkflowService::PUBLISHED ) {
            return [ 'found' => false, 'reason' => 'not_yet_published', 'status' => (string) $data['status'] ];
        }

        $letterhead = $this->branding->letterhead( $school_id );
        $student    = $this->student( $school_id, $student_id );
        $context    = $this->context( $school_id, $term_id );

        // Build QR verification URL — a public page that confirms the result is genuine.
        $verify_url = add_query_arg(
            [
                'educbt_verify' => '1',
                'sid'           => $school_id,
                'stid'          => $student_id,
                'tid'           => $term_id,
                'h'             => wp_hash( $school_id . ':' . $student_id . ':' . $term_id ),
            ],
            home_url( '/verify-result/' )
        );

        // A report sheet is ONE page. Everything on it is sized relative to how
        // many subjects the student offers, so a 9-subject junior sheet and a
        // 14-subject senior sheet both fill the page without spilling onto a
        // second one that would carry two rows, a QR code and nothing else.
        //
        // Density is chosen here rather than left to the browser because the
        // browser has no way to know the difference between "this table is long"
        // and "this document must not exceed one page".
        $subject_count = count( (array) $data['subjects'] );
        $density       = $this->density_class( $subject_count );

        $body = '<div class="educbt-doc__sheet ' . esc_attr( $density ) . '"'
            . $this->branding->watermark_sheet_style( $letterhead ) . '>'
            . $this->render_watermark( $letterhead )
            . $this->branding->render_letterhead( $letterhead, 'Terminal Report Sheet' )
            . $this->bio_block( $student, $context, $data['summary'] )
            . $this->marks_table( $data['subjects'] )
            . $this->grade_key( $school_id )
            . $this->summary_block( $data['summary'] )
            . $this->remarks_block( $school_id, $data['summary'] )
            . $this->signature_block( $school_id, $data['summary'] )
            . $this->qr_block( $verify_url )
            . '</div>';

        $title = trim( $student['name'] . ' — ' . $context['term'] . ' Report' );

        return [
            'found'  => true,
            'status' => (string) $data['status'],
            'html'   => $this->branding->wrap( $body . $extra_html, $title, 'educbt-doc--report' ),
        ];
    }


    /**
     * Choose how tightly to set the sheet, from the number of subjects.
     *
     * The thresholds are the points at which the sheet stops fitting at the
     * previous density — measured against A4 with the letterhead, bio block,
     * grade key, summary, remarks, signatures and QR block all present, since
     * those are fixed overhead the table has to share the page with.
     *
     * Above 14 subjects nothing is compacted further: squeezing a 20-subject
     * sheet onto one page would produce type nobody can read, so it is allowed
     * to run to a second page, and the watermark and rules are drawn on both.
     */
    private function density_class( int $subject_count ): string {
        if ( $subject_count <= 9 ) {
            return 'educbt-doc--fit-roomy';
        }

        if ( $subject_count <= 11 ) {
            return 'educbt-doc--fit-snug';
        }

        if ( $subject_count <= 14 ) {
            return 'educbt-doc--fit-tight';
        }

        return 'educbt-doc--fit-overflow';
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
        // Only render the photo cell when a passport actually exists — when
        // there's no photo we omit the cell entirely so the table starts at
        // the Name column with no empty 27mm-wide gap on the left.
        $photo_cell = $student['photo'] !== ''
            ? '<td rowspan="3" style="width:27mm;text-align:center">'
                . sprintf( '<img class="educbt-doc__photo" src="%s" alt="">', esc_url( $student['photo'] ) )
                . '</td>'
            : '';

        return sprintf(
            '<table class="educbt-doc__bio"><tr>
                %s<td class="label">Name:</td><td><strong>%s</strong></td>
                <td class="label">Admission No.:</td><td>%s</td>
             </tr><tr>
                <td class="label">Class:</td><td>%s</td>
                <td class="label">Session:</td><td>%s</td>
             </tr><tr>
                <td class="label">Term:</td><td>%s</td>
                <td class="label">No. in Class:</td><td>%d</td>
             </tr></table>',
            $photo_cell,
            esc_html( $student['name'] ),
            esc_html( $student['admission_number'] ),
            esc_html( (string) ( $summary['class_name'] ?? '' ) ),
            esc_html( $context['session'] ),
            esc_html( $context['term'] ),
            absint( $summary['class_size'] ?? 0 )
        );
    }

    private function marks_table( array $subjects ): string {
        $rows = '';

        foreach ( $subjects as $line ) {
            $has_scores = ! empty( $line['has_scores'] ) || ! empty( $line['id'] );

            if ( ! $has_scores ) {
                // Registered subject with no compiled scores — show dashes
                $rows .= sprintf(
                    '<tr>
                        <td class="subject">%s</td>
                        <td>&mdash;</td><td>&mdash;</td><td>&mdash;</td>
                        <td>&mdash;</td><td>&mdash;</td><td>&mdash;</td><td>&mdash;</td><td>&mdash;</td>
                     </tr>',
                    esc_html( (string) $line['subject_name'] )
                );
            } else {
                $rows .= sprintf(
                    '<tr>
                        <td class="subject">%s</td>
                        <td>%s</td><td>%s</td><td><strong>%s</strong></td>
                        <td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td>
                     </tr>',
                    esc_html( (string) $line['subject_name'] ),
                    esc_html( self::num( $line['ca_total'] ?? 0 ) ),
                    esc_html( self::num( $line['exam_total'] ?? 0 ) ),
                    esc_html( self::num( $line['total'] ?? 0 ) ),
                    esc_html( (string) ( $line['grade'] ?? '—' ) ),
                    esc_html( self::ordinal( absint( $line['subject_position'] ?? 0 ) ) ),
                    esc_html( self::num( $line['class_average'] ?? 0 ) ),
                    esc_html( self::num( $line['highest_in_class'] ?? 0 ) ),
                    esc_html( (string) ( $line['remark'] ?? '—' ) )
                );
            }
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
            absint( $summary['subjects_offered'] ?? 0 ),
            esc_html( self::num( $summary['total_score'] ?? 0 ) ),
            esc_html( self::num( $summary['average_score'] ?? 0 ) ),
            esc_html( self::ordinal( absint( $summary['class_position'] ?? 0 ) ) )
        );
    }

    private function remarks_block( int $school_id, array $summary ): string {
        $average = (float) ( $summary['average_score'] ?? 0 );
        $remark_service = new RemarkService();

        // Prefer a manually saved/overridden remark. If none was stored — e.g.
        // the auto-remark ran before this school had any ranges set up, or a
        // range was added after publishing — fall back to computing it live
        // from the school's current ranges, so the sheet is never blank just
        // because the one-time auto-fill at publish time had nothing to fill
        // with yet.
        $class_teacher = trim( (string) ( $summary['class_teacher_remark'] ?? '' ) );
        if ( $class_teacher === '' ) {
            $class_teacher = trim( $remark_service->for_average( $school_id, 'class_teacher', $average ) );
        }

        $principal = trim( (string) ( $summary['principal_remark'] ?? '' ) );
        if ( $principal === '' ) {
            $principal = trim( $remark_service->for_average( $school_id, 'principal', $average ) );
        }

        return sprintf(
            '<div class="educbt-doc__remarks">
                <p><strong>Class Teacher&rsquo;s Remark:</strong> <span class="educbt-doc__remark-text">%s</span></p>
                <p><strong>Principal&rsquo;s Remark:</strong> <span class="educbt-doc__remark-text">%s</span></p>
             </div>',
            esc_html( $class_teacher !== '' ? $class_teacher : '—' ),
            esc_html( $principal !== '' ? $principal : '—' )
        );
    }

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

    /**
     * Signature block with staff names, roles, and signature images.
     * The signature line (border-top) is always visible, even when printing.
     */
    private function signature_block( int $school_id, array $summary ): string {
        $sig_service = new SignatureService();
        $class_id    = absint( $summary['class_id'] ?? 0 );

        // Class teacher — resolved to the specific staff member who actually holds
        // this class, not just any row saved under the 'class_teacher' role.
        $ct_sig  = $sig_service->resolve_for_class( $school_id, $class_id, 'class_teacher' );
        $ct_area = $this->render_signature_mark( $ct_sig );

        // Principal — one per school.
        $pr_sig  = $sig_service->resolve_for_class( $school_id, $class_id, 'principal' );
        $pr_area = $this->render_signature_mark( $pr_sig );

        // Staff display names — fall back to the letterhead principal name
        $ct_name = $ct_sig['name'] ?? '';
        $pr_name = $pr_sig['name'] ?? (string) ( $summary['principal_name'] ?? '' );

        return sprintf(
            '<div class="educbt-doc__sign">
                <div class="educbt-doc__sign-box">
                    <div class="educbt-doc__sig-area">%s</div>
                    <div class="educbt-doc__sig-line">%s</div>
                    <div class="educbt-doc__sig-role">Class Teacher</div>
                </div>
                <div class="educbt-doc__sign-box">
                    <div class="educbt-doc__sig-area">%s</div>
                    <div class="educbt-doc__sig-line">%s</div>
                    <div class="educbt-doc__sig-role">Principal</div>
                </div>
            </div>',
            $ct_area,
            esc_html( $ct_name !== '' ? $ct_name : '____________________' ),
            $pr_area,
            esc_html( $pr_name !== '' ? $pr_name : '____________________' )
        );
    }

    /**
     * The mark inside a signature box — a drawn/uploaded image, a stylised text
     * signature rendered in a script font, or nothing (just the printed name and
     * the line beneath it, for a staff member who hasn't set one up yet).
     *
     * @param array{type:string,data:string,name:string}|null $sig
     */
    private function render_signature_mark( ?array $sig ): string {
        if ( ! $sig || empty( $sig['data'] ) ) {
            return '';
        }

        if ( ( $sig['type'] ?? '' ) === 'text' ) {
            return sprintf(
                '<span class="educbt-doc__sig-text">%s</span>',
                esc_html( (string) $sig['data'] )
            );
        }

        return sprintf(
            '<img src="%s" alt="Signature" class="educbt-doc__sig-img">',
            esc_url( $sig['data'] )
        );
    }

    /**
     * QR code block — links to a public verification page.
     * Uses a free QR API to render the code.
     */
    private function qr_block( string $verify_url ): string {
        $qr_url = 'https://api.qrserver.com/v1/create-qr-code/?size=120x120&data=' . rawurlencode( $verify_url );

        // Wrapped in its own stacking context (see .educbt-doc__qr in the print
        // CSS) so the watermark behind it can never bleed across the code and
        // break a scan.
        return sprintf(
            '<div class="educbt-doc__qr">
                <img src="%s" alt="Scan to verify" width="80" height="80">
                <span>Scan to verify</span>
             </div>',
            esc_url( $qr_url )
        );
    }

    /**
     * Watermark — the school name tiled diagonally across the whole sheet,
     * as a truly continuous, edge-to-edge, corner-to-corner pattern.
     *
     * The container (see .educbt-doc__wm in the print CSS) is deliberately
     * TWICE the sheet's width and height, centred over it, before being
     * rotated 35 degrees. Rotating a same-size box leaves blank diamond-
     * shaped corners where the pattern "stops" — oversizing it first means
     * the rotated footprint always fully covers every corner of the visible
     * sheet, with the sheet's own `overflow: hidden` (see .educbt-doc__sheet)
     * clipping the excess cleanly at the printable edge.
     *
     * Each row is the school name repeated end-to-end with a bullet separator
     * long enough to span the doubled-width container, so no row visibly ends
     * — it just gets clipped at the edge, reading as infinite. Rows stack
     * tightly (flex-start, no distributed gaps) and there are enough of them
     * to fill the doubled-height container top to bottom with no blank bands.
     */
    private function render_watermark( array $letterhead ): string {
        $school_name = (string) ( $letterhead['name'] ?? '' );

        if ( $school_name === '' ) {
            return '';
        }

        // One row's worth of text — long enough to fill the 200%-width
        // container edge-to-edge for any school name, short or long, so it
        // always gets clipped at the boundary instead of visibly running out.
        $unit = $school_name . ' • ';
        $row  = '';
        while ( mb_strlen( $row ) < 900 ) {
            $row .= $unit;
        }

        // Enough rows to fill the 200%-tall container with no gaps top or
        // bottom — tight stacking (flex-start, small fixed margin in CSS)
        // rather than space-around, which left uneven blank bands.
        $repeated = '';
        for ( $i = 0; $i < 90; $i++ ) {
            $repeated .= '<span>' . esc_html( $row ) . '</span>';
        }

        return sprintf(
            '<div class="educbt-doc__wm" aria-hidden="true">%s</div>',
            $repeated
        );
    }

    private static function num( $value ): string {
        $float = (float) $value;
        return $float == (int) $float ? (string) (int) $float : number_format( $float, 1 );
    }

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
