<?php
/**
 * The printable documents a teacher takes away: question paper, class performance, and recorded scores.
 *
 * Each is a branded A4 page: the school's crest, name and address across the top, then the subject (and
 * class) as the title line, then the table. The browser's own Save as PDF produces the file, so text stays
 * real text and table rules stay sharp (the same approach as report sheets and transcripts).
 *
 * This class only builds markup from data it is handed. Who may see what is decided by
 * TeacherExportService before anything reaches here.
 *
 * @package EduCBTPro
 */

namespace EduCBTPro\Services;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class TeacherDocuments {

    private DocumentBrandingService $branding;

    public function __construct( ?DocumentBrandingService $branding = null ) {
        $this->branding = $branding ?? new DocumentBrandingService();
    }

    /**
     * Heading shared by all three: letterhead, then "SUBJECT - CLASS" and the document's own name.
     */
    private function heading( int $school_id, string $title, string $sub_title ): string {
        $letterhead = $this->branding->letterhead( $school_id );

        return $this->branding->render_letterhead( $letterhead, $title )
            . ( $sub_title !== '' ? '<p class="educbt-doc__sub">' . esc_html( $sub_title ) . '</p>' : '' );
    }

    /**
     * Extra rules these documents need on top of the shared print stylesheet.
     */
    private function css(): string {
        return '
.educbt-doc__sub { text-align:center; margin:-3mm 0 5mm; font-size:10.5pt; color:#444; }
.educbt-doc__q { margin:0 0 4.5mm; break-inside:avoid; page-break-inside:avoid; }
.educbt-doc__q-head { display:flex; justify-content:space-between; gap:6mm; font-weight:bold; }
.educbt-doc__q-marks { font-weight:normal; color:#555; white-space:nowrap; font-size:9.5pt; }
.educbt-doc__opts { list-style:none; margin:1.5mm 0 0 7mm; padding:0; }
.educbt-doc__opts li { margin:0 0 .8mm; }
.educbt-doc__opts li.is-key { font-weight:bold; }
.educbt-doc__key-tick { color:#14532d; margin-left:2mm; }
.educbt-doc__parts { margin:1.5mm 0 0 7mm; padding:0; list-style:none; }
.educbt-doc__section { margin:6mm 0 3mm; padding-bottom:1mm; border-bottom:1px solid #6b6b6b; font-weight:bold; text-transform:uppercase; letter-spacing:1px; font-size:10pt; }
.educbt-doc__meta { margin:0 0 4mm; font-size:9.5pt; color:#555; text-align:center; }
.educbt-doc__table td.name { text-align:left; }
.educbt-doc__empty { text-align:center; color:#666; padding:10mm 0; }
.educbt-doc__fill { width:100%; margin:0 0 4mm; font-size:10pt; border-collapse:collapse; }
.educbt-doc__fill td { padding:2mm 0; white-space:nowrap; }
.educbt-doc__foot { margin-top:6mm; font-size:8.5pt; color:#666; text-align:right; }
';
    }

    private function page( string $body, string $title ): string {
        return $this->branding->wrap( '<style>' . $this->css() . '</style><div class="educbt-doc__sheet">' . $body . '</div>', $title );
    }

    private function fmt( $n ): string {
        if ( $n === null || $n === '' ) {
            return '<span class="blank">-</span>';
        }

        $f = (float) $n;

        return esc_html( rtrim( rtrim( number_format( $f, 2, '.', '' ), '0' ), '.' ) );
    }

    // ---------------------------------------------------------------
    // 1. Questions
    // ---------------------------------------------------------------

    /**
     * @param array<int,array<string,mixed>> $questions
     * @param bool                           $with_answers Teacher's marking copy (shows the key) or a clean paper.
     */
    public function questions( int $school_id, string $subject_name, array $questions, bool $with_answers, string $teacher_name = '' ): string {
        $title = $with_answers ? 'Marking Copy (with answers)' : 'Examination Paper';
        $html  = $this->heading( $school_id, $title, $subject_name );

        if ( empty( $questions ) ) {
            return $this->page( $html . '<p class="educbt-doc__empty">No questions have been set for this subject yet.</p>', $subject_name . ' ' . $title );
        }

        $total = 0.0;
        foreach ( $questions as $q ) {
            $total += (float) $q['marks'];
        }

        $meta = count( $questions ) . ' question' . ( count( $questions ) === 1 ? '' : 's' ) . '  •  ' . $this->plain( $total ) . ' marks';
        if ( $with_answers && $teacher_name !== '' ) {
            $meta .= '  •  Set by ' . $teacher_name;
        }
        $html .= '<p class="educbt-doc__meta">' . esc_html( $meta ) . '</p>';

        if ( ! $with_answers ) {
            // Handed to students to write on: somewhere for them to put their details.
            $html .= '<table class="educbt-doc__fill"><tr><td>Name: ______________________________</td><td>Class: ____________</td><td>Date: ____________</td></tr></table>';
        }

        $groups = [ 'objective' => [], 'theory' => [] ];
        foreach ( $questions as $q ) {
            $groups[ (string) $q['question_type'] === 'theory' ? 'theory' : 'objective' ][] = $q;
        }

        $n = 0;
        foreach ( [ 'objective' => 'Section A: Objective', 'theory' => 'Section B: Theory' ] as $key => $label ) {
            if ( empty( $groups[ $key ] ) ) {
                continue;
            }

            $html .= '<div class="educbt-doc__section">' . esc_html( $label ) . '</div>';

            foreach ( $groups[ $key ] as $q ) {
                $n++;
                $html .= '<div class="educbt-doc__q"><div class="educbt-doc__q-head"><span>' . $n . '. '
                    . wp_kses_post( (string) $q['question_text'] ) . '</span><span class="educbt-doc__q-marks">['
                    . $this->plain( (float) $q['marks'] ) . ' mark' . ( (float) $q['marks'] === 1.0 ? '' : 's' ) . ']</span></div>';

                if ( ! empty( $q['options'] ) ) {
                    $html .= '<ol class="educbt-doc__opts" type="A" style="list-style:upper-alpha">';
                    foreach ( $q['options'] as $o ) {
                        $is_key = $with_answers && ! empty( $o['is_correct'] );
                        $html  .= '<li' . ( $is_key ? ' class="is-key"' : '' ) . '>' . wp_kses_post( (string) $o['option_text'] )
                            . ( $is_key ? '<span class="educbt-doc__key-tick"> ✓</span>' : '' ) . '</li>';
                    }
                    $html .= '</ol>';
                }

                if ( ! empty( $q['sub_items'] ) ) {
                    $html .= '<ul class="educbt-doc__parts">';
                    foreach ( $q['sub_items'] as $s ) {
                        $html .= '<li>(' . esc_html( (string) $s['label'] ) . ') ' . esc_html( (string) $s['text'] )
                            . ' <span class="educbt-doc__q-marks">[' . $this->plain( (float) $s['marks'] ) . ']</span></li>';
                    }
                    $html .= '</ul>';
                }

                if ( $with_answers && ! empty( $q['marking_guide'] ) ) {
                    $html .= '<p style="margin:1.5mm 0 0 7mm;font-size:9.5pt;color:#14532d"><b>Marking guide:</b> ' . esc_html( (string) $q['marking_guide'] ) . '</p>';
                }

                $html .= '</div>';
            }
        }

        return $this->page( $html, $subject_name . ' ' . $title );
    }

    // ---------------------------------------------------------------
    // 2 and 3. Scores (one table serves "performance" and "recorded CA/exam")
    // ---------------------------------------------------------------

    /**
     * @param array<string,mixed> $sheet From TeacherExportService::recorded_scores()
     */
    public function scores( int $school_id, string $subject_name, string $class_name, string $session_label, string $term_label, array $sheet, bool $performance ): string {
        $title = $performance ? 'Student Performance' : 'CA and Exam Records';
        $html  = $this->heading( $school_id, $title, $subject_name . '  -  ' . $class_name );

        $meta = array_filter( [ $session_label, $term_label ] );
        if ( $meta ) {
            $html .= '<p class="educbt-doc__meta">' . esc_html( implode( '  •  ', $meta ) ) . '</p>';
        }

        if ( empty( $sheet['rows'] ) ) {
            return $this->page( $html . '<p class="educbt-doc__empty">No students or scores to show for this class yet.</p>', $subject_name . ' ' . $class_name . ' ' . $title );
        }

        $s = $sheet['summary'];

        if ( $performance && (int) $s['count'] > 0 ) {
            $html .= '<div class="educbt-doc__summary">'
                . $this->stat( (string) $s['count'], 'Scored' )
                . $this->stat( $this->plain( (float) $s['average'] ) . '%', 'Average' )
                . $this->stat( $this->plain( (float) $s['highest'] ) . '%', 'Highest' )
                . $this->stat( $this->plain( (float) $s['lowest'] ) . '%', 'Lowest' )
                . $this->stat( $this->plain( (float) $s['pass_rate'] ) . '%', 'Pass rate' )
                . '</div>';
        }

        $html .= '<table class="educbt-doc__table"><thead><tr><th>S/N</th><th>Admission No.</th><th>Name</th>';
        foreach ( $sheet['components'] as $c ) {
            $html .= '<th>' . esc_html( (string) $c['name'] ) . '<br><small>/' . $this->plain( (float) $c['max'] ) . '</small></th>';
        }
        $html .= '<th>Total<br><small>/' . $this->plain( (float) $s['max_total'] ) . '</small></th>';
        if ( $performance ) {
            $html .= '<th>%</th><th>Pos.</th>';
        }
        $html .= '</tr></thead><tbody>';

        $rows = $sheet['rows'];

        if ( $performance ) {
            // Best first: this document is about ranking, the records sheet stays in register order.
            usort(
                $rows,
                static fn ( array $a, array $b ): int => ( $b['total'] ?? -1 ) <=> ( $a['total'] ?? -1 )
            );
        }

        foreach ( $rows as $i => $r ) {
            $html .= '<tr><td>' . ( $i + 1 ) . '</td><td>' . esc_html( (string) $r['admission'] ) . '</td><td class="name">' . esc_html( (string) $r['name'] ) . '</td>';
            foreach ( $sheet['components'] as $c ) {
                $html .= '<td>' . $this->fmt( $r['cells'][ $c['id'] ] ?? null ) . '</td>';
            }
            $html .= '<td><b>' . $this->fmt( $r['total'] ) . '</b></td>';
            if ( $performance ) {
                $html .= '<td>' . ( $r['percent'] === null ? '<span class="blank">-</span>' : esc_html( $this->plain( (float) $r['percent'] ) ) ) . '</td>'
                    . '<td>' . ( $r['position'] === null ? '<span class="blank">-</span>' : (int) $r['position'] ) . '</td>';
            }
            $html .= '</tr>';
        }

        $html .= '</tbody></table>';

        $not_scored = count( $sheet['rows'] ) - (int) $s['count'];
        if ( $not_scored > 0 ) {
            $html .= '<p class="educbt-doc__foot">' . $not_scored . ' student' . ( $not_scored === 1 ? '' : 's' ) . ' without any recorded score ('
                . 'shown as "-", not counted in the average).</p>';
        }

        return $this->page( $html, $subject_name . ' ' . $class_name . ' ' . $title );
    }

    private function stat( string $value, string $label ): string {
        return '<div class="educbt-doc__stat"><b>' . esc_html( $value ) . '</b><span>' . esc_html( $label ) . '</span></div>';
    }

    private function plain( float $n ): string {
        return rtrim( rtrim( number_format( $n, 2, '.', '' ), '0' ), '.' );
    }
}
