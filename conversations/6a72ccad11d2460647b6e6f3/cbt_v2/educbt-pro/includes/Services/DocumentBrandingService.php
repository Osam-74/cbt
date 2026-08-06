<?php

namespace EduCBTPro\Services;

use EduCBTPro\Core\Schema;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * PHASE 7b — printable documents.
 *
 * ON PDF GENERATION, and why there is no PDF library here.
 *
 * The obvious move is to bundle Dompdf or mPDF. I have deliberately not, because
 * for the hosting these schools actually use it is the wrong trade:
 *
 *   - mPDF with its font set is ~100 MB, and Dompdf is memory-hungry. A 40-page
 *     broadsheet regularly exhausts the 128 MB memory limit typical of shared
 *     hosting, and it fails at the exact moment a school is printing results.
 *   - Neither renders as reliably as a browser. The browser already has a
 *     production-grade layout engine, correct font handling, and "Save as PDF"
 *     built in on every desktop and phone.
 *
 * So the primary path is HTML plus a real print stylesheet: the user presses Print
 * and chooses Save as PDF, or prints straight to paper. It works everywhere, costs
 * nothing, and looks the same as what they saw on screen.
 *
 * Server-side rendering is still needed for one thing — attaching a transcript to
 * an email without a human pressing Print. `has_server_renderer()` detects Dompdf
 * if a school installs it, so that path can be added without changing any template.
 */
class DocumentBrandingService {

    /**
     * Letterhead data. Every printed document opens with this.
     *
     * @return array<string,string>
     */
    public function letterhead( int $school_id ): array {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                // SELECT * deliberately: a report sheet losing its school name because
                // one unrelated column is absent is not a trade worth making.
                'SELECT * FROM ' . $wpdb->prefix . 'educbt_schools WHERE id = %d',
                $school_id
            ),
            ARRAY_A
        );

        if ( ! $row ) {
            return [ 'name' => '', 'logo' => '', 'address' => '', 'contact' => '', 'code' => '' ];
        }

        $contact = array_filter( [ (string) $row['phone'], (string) $row['email'] ] );

        return [
            'name'      => (string) $row['school_name'],
            'code'      => (string) $row['school_code'],
            'logo'      => (string) $row['logo'],
            'address'   => (string) $row['address'],
            'contact'   => implode( '  •  ', $contact ),
            'principal' => (string) $row['principal_name'],
        ];
    }

    /**
     * The letterhead block, shared by every printed document.
     *
     * The logo is sized in millimetres rather than pixels: this markup is going to
     * paper, and a pixel size that looks right on a 1080p screen is wrong on A4.
     */
    public function render_letterhead( array $letterhead, string $document_title ): string {
        $logo = '';

        if ( $letterhead['logo'] !== '' ) {
            $logo = sprintf(
                '<img class="educbt-doc__logo" src="%s" alt="%s crest">',
                esc_url( $letterhead['logo'] ),
                esc_attr( $letterhead['name'] )
            );
        }

        // Crest CENTRED ABOVE the name, then the name, then the address — the layout
        // every Nigerian school letterhead uses. The crest beside the name reads as a
        // web header, not as a document.
        return sprintf(
            '<header class="educbt-doc__head">
                %s
                <div class="educbt-doc__identity">
                    <h1 class="educbt-doc__school">%s</h1>
                    <p class="educbt-doc__address">%s</p>
                    <p class="educbt-doc__contact">%s</p>
                </div>
            </header>
            <p class="educbt-doc__title">%s</p>',
            $logo,
            esc_html( $letterhead['name'] ),
            esc_html( $letterhead['address'] ),
            esc_html( $letterhead['contact'] ),
            esc_html( $document_title )
        );
    }

    /**
     * The transcript watermark: the school crest, enlarged and faint, sitting
     * upright behind the content.
     *
     * Two details matter for it to survive printing:
     *
     *  - `print-color-adjust: exact` — browsers strip background imagery from print
     *    by default as an ink-saving measure, which would silently remove the
     *    watermark from every printed copy.
     *  - `position: fixed` — so it repeats on every page of a multi-page transcript
     *    rather than only the first.
     */
    public function render_watermark( array $letterhead, float $opacity = 0.08 ): string {
        if ( $letterhead['logo'] === '' ) {
            // No crest: fall back to the school name as text, so a transcript is
            // never printed with no watermark at all.
            return sprintf(
                '<div class="educbt-doc__watermark educbt-doc__watermark--text" aria-hidden="true"><span>%s</span></div>',
                esc_html( $letterhead['name'] )
            );
        }

        return sprintf(
            '<div class="educbt-doc__watermark" aria-hidden="true" style="opacity:%s"><img src="%s" alt=""></div>',
            esc_attr( (string) max( 0.03, min( 0.2, $opacity ) ) ),
            esc_url( $letterhead['logo'] )
        );
    }

    /**
     * Whether a server-side PDF engine is available. Returns false on a stock
     * install, which is the expected and supported case.
     */
    public function has_server_renderer(): bool {
        return class_exists( '\Dompdf\Dompdf' ) || class_exists( '\Mpdf\Mpdf' );
    }

    /**
     * Wrap a document body in a printable page.
     *
     * Deliberately a standalone HTML document rather than a theme template: a report
     * card must print identically regardless of what theme a school is running, and
     * a theme's own CSS is the most common cause of a broken printout.
     */
    public function wrap( string $body, string $title, string $extra_class = '' ): string {
        $css = $this->print_css();

        return sprintf(
            '<!DOCTYPE html>
<html %s>
<head>
<meta charset="%s">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>%s</title>
<style>%s</style>
</head>
<body class="educbt-doc %s">
<div class="educbt-doc__toolbar no-print">
    <button type="button" onclick="window.print()" class="educbt-doc__print">Print / Save as PDF</button>
    <span class="educbt-doc__hint">Choose &ldquo;Save as PDF&rdquo; in the print dialog to keep a copy.</span>
</div>
%s
</body>
</html>',
            get_language_attributes(),
            esc_attr( get_bloginfo( 'charset' ) ),
            esc_html( $title ),
            $css,
            esc_attr( $extra_class ),
            $body
        );
    }

    /**
     * Print stylesheet. Inlined so a printed document never depends on an external
     * request succeeding — a stylesheet that 404s at print time produces an
     * unreadable page and the user has no idea why.
     */
    public function print_css(): string {
        return <<<'CSS'
:root {
    --doc-ink: #1a1a1a;
    --doc-muted: #555;
    --doc-rule: #c9c9c9;
    --doc-accent: #14532d;
}
* { box-sizing: border-box; }
body.educbt-doc {
    margin: 0;
    padding: 12mm 10mm;
    font-family: "Times New Roman", Georgia, serif;
    font-size: 11pt;
    line-height: 1.35;
    color: var(--doc-ink);
    background: #f4f4f4;
    position: relative;
}
.educbt-doc__sheet {
    max-width: 210mm;
    margin: 0 auto;
    background: #fff;
    padding: 10mm;
    position: relative;
    z-index: 1;
    box-shadow: 0 1px 6px rgba(0,0,0,.15);
}
.educbt-doc__toolbar {
    max-width: 210mm;
    margin: 0 auto 8mm;
    display: flex;
    align-items: center;
    gap: 12px;
}
.educbt-doc__print {
    font: inherit;
    padding: 8px 18px;
    background: var(--doc-accent);
    color: #fff;
    border: 0;
    border-radius: 4px;
    cursor: pointer;
}
.educbt-doc__hint { color: var(--doc-muted); font-size: 10pt; }

/* Letterhead */
.educbt-doc__head {
    text-align: center;
    border-bottom: 2px solid var(--doc-ink);
    padding-bottom: 4mm;
}
.educbt-doc__logo { width: 24mm; height: 24mm; object-fit: contain; display: block; margin: 0 auto 2mm; }
.educbt-doc__identity { text-align: center; }
.educbt-doc__school {
    margin: 0;
    font-size: 17pt;
    letter-spacing: .5px;
    text-transform: uppercase;
    color: var(--doc-accent);
}
.educbt-doc__address, .educbt-doc__contact { margin: 1mm 0 0; font-size: 9.5pt; color: var(--doc-muted); }
.educbt-doc__title {
    margin: 4mm 0 5mm;
    text-align: center;
    font-size: 12pt;
    font-weight: bold;
    text-transform: uppercase;
    letter-spacing: 2px;
}

/* Student identity block */
.educbt-doc__bio { width: 100%; border-collapse: collapse; margin-bottom: 5mm; font-size: 10pt; }
.educbt-doc__bio td { padding: 1.5mm 2mm; border-bottom: 1px dotted var(--doc-rule); }
.educbt-doc__bio .label { color: var(--doc-muted); width: 26mm; }
.educbt-doc__photo { width: 25mm; height: 30mm; object-fit: cover; border: 1px solid var(--doc-rule); }

/* Marks tables */
.educbt-doc__table { width: 100%; border-collapse: collapse; font-size: 9.5pt; }
.educbt-doc__table th, .educbt-doc__table td {
    border: 1px solid var(--doc-rule);
    padding: 1.6mm 2mm;
    text-align: center;
}
.educbt-doc__table th { background: #ececec; font-size: 9pt; text-transform: uppercase; letter-spacing: .3px; }
.educbt-doc__table td.subject { text-align: left; }
.educbt-doc__table tfoot td { font-weight: bold; background: #f6f6f6; }
.educbt-doc__table .blank { color: #aaa; }

/* Summary, remarks, key */
.educbt-doc__summary { display: flex; gap: 4mm; margin: 4mm 0; }
.educbt-doc__stat { flex: 1; border: 1px solid var(--doc-rule); padding: 2.5mm; text-align: center; }
.educbt-doc__stat b { display: block; font-size: 14pt; }
.educbt-doc__stat span { font-size: 8.5pt; color: var(--doc-muted); text-transform: uppercase; }
.educbt-doc__remarks { margin-top: 4mm; font-size: 10pt; }
.educbt-doc__remarks p { margin: 0 0 3mm; }
.educbt-doc__key { margin-top: 4mm; font-size: 8.5pt; color: var(--doc-muted); }
.educbt-doc__sign { display: flex; justify-content: space-between; margin-top: 12mm; font-size: 9.5pt; }
.educbt-doc__sign div { text-align: center; border-top: 1px solid var(--doc-ink); padding-top: 1.5mm; width: 55mm; }
.educbt-doc__serial { margin-top: 6mm; font-size: 8.5pt; color: var(--doc-muted); display: flex; justify-content: space-between; }

/* Watermark — behind everything, repeated on every page.

   A LOGO SITS UPRIGHT. A crest is a designed mark with its own orientation, and
   rotating it reads as a printing fault rather than as a watermark. The slant
   belongs to the text fallback, where a long school name set diagonally covers the
   page evenly and is unmistakably a watermark rather than content. */
.educbt-doc__watermark {
    position: fixed;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    width: 55%;
    max-width: 130mm;
    opacity: .08;
    z-index: 0;
    pointer-events: none;
}
.educbt-doc__watermark img { width: 100%; height: auto; }

/* Text fallback only: rotated, and wider because words need the room. */
.educbt-doc__watermark--text {
    transform: translate(-50%, -50%) rotate(-32deg);
    width: 85%;
    max-width: 180mm;
}
.educbt-doc__watermark--text span {
    display: block;
    font-size: 44pt;
    font-weight: bold;
    text-transform: uppercase;
    letter-spacing: 4px;
    text-align: center;
    line-height: 1.1;
    color: #000;
    opacity: .07;
}

@page { size: A4 portrait; margin: 10mm; }

@media print {
    body.educbt-doc { background: #fff; padding: 0; }
    .educbt-doc__sheet { box-shadow: none; padding: 0; max-width: none; }
    .no-print { display: none !important; }

    /* Browsers strip background imagery from print to save ink, which would
       silently remove the watermark from every printed transcript. */
    .educbt-doc, .educbt-doc__watermark, .educbt-doc__table th {
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }

    /* A subject row split across a page break is unreadable. */
    .educbt-doc__table { page-break-inside: auto; }
    .educbt-doc__table tr { page-break-inside: avoid; page-break-after: auto; }
    .educbt-doc__table thead { display: table-header-group; }
    .educbt-doc__session { page-break-inside: avoid; }
    .educbt-doc__sign { page-break-inside: avoid; }
}
CSS;
    }
}
