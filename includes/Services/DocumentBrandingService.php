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
    /**
     * The style attribute that paints the watermark as a repeating background on
     * the sheet.
     *
     * A positioned watermark element is painted once and does not repeat across
     * printed pages — page two of a long document comes out bare. A background
     * image on the sheet repeats per page box, so this is what actually reaches
     * paper. The opacity is baked into the image via a wrapper gradient rather
     * than an opacity property, because opacity on the sheet would fade the
     * content sitting on top of it too.
     *
     * Returns an empty string when the school has no crest; the text fallback
     * handles that case.
     */
    public function watermark_sheet_style( array $letterhead ): string {
        if ( ( $letterhead['logo'] ?? '' ) === '' ) {
            return '';
        }

        // A custom property, not background-image directly: the transcript layers
        // an OFFICIAL COPY mark over the crest, and an inline background-image
        // would win over the stylesheet and wipe that layer out.
        return sprintf(
            ' style="--doc-wm-crest:url(%s)"',
            esc_url( (string) $letterhead['logo'] )
        );
    }

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
        $css       = $this->print_css();
        $filename  = sanitize_title( $title ) . '.pdf';

        // A standalone HTML page, not a theme template, so it renders identically
        // whatever theme the school runs.
        //
        // Downloading is the browser own print-to-PDF. This used to run
        // html2pdf/html2canvas, which rasterises the page into an image and then
        // slices it at fixed intervals. It has no idea where a document ends, so
        // transcripts and report sheets began halfway down a page, bled into the
        // next and left blank leaves behind them. Because the output was a
        // picture, table rules and signature lines came out as whatever the
        // rasteriser managed to redraw.
        //
        // The browser print engine understands the page box and the break rules
        // in the stylesheet below, and prints real vector text with real borders.
        return '<!DOCTYPE html>
<html ' . get_language_attributes() . '>
<head>
<meta charset="' . esc_attr( get_bloginfo( 'charset' ) ) . '">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>' . esc_html( $title ) . '</title>
<style>' . $css . '</style>
</head>
<body class="educbt-doc ' . esc_attr( $extra_class ) . '">
<div class="educbt-doc__toolbar no-print">
    <button type="button" id="educbt-download-pdf" class="educbt-doc__print">Download / Print</button>
    <span class="educbt-doc__hint">Choose <strong>Save as PDF</strong> in the dialog to download a copy.</span>
</div>
<div id="educbt-pdf-content">' . $body . '</div>
<script>
(function(){
    var btn = document.getElementById("educbt-download-pdf");
    if (!btn) { return; }
    btn.addEventListener("click", function(){ window.print(); });
})();
</script>
</body>
</html>';
    }

    /**
     * Print stylesheet. Inlined so a printed document never depends on an external
     * request succeeding — a stylesheet that 404s at print time produces an
     * unreadable page and the user has no idea why.
     */
    public function print_css(): string {
        return <<<'CSS'
@import url('https://fonts.googleapis.com/css2?family=Petit+Formal+Script&display=swap');
:root {
    --doc-ink: #1a1a1a;
    --doc-muted: #555;
    --doc-rule: #6b6b6b;
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
    overflow: hidden;
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
/* 26mm was too narrow for "Admission No.:", which wrapped onto a second line
   while the value column beside it sat half empty. The label column is sized to
   its content instead, and told not to wrap, so each label keeps one line and
   the space it does not need goes to the value. */
.educbt-doc__bio .label { color: var(--doc-muted); width: 1%; white-space: nowrap; padding-right: 3mm !important; }
.educbt-doc__bio td:not(.label) { padding-right: 6mm !important; }
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
.educbt-doc__remark-text { text-decoration: underline; text-underline-offset: 2px; }
.educbt-doc__key { margin-top: 4mm; font-size: 8.5pt; color: var(--doc-muted); }
.educbt-doc__sign { display: flex; justify-content: space-between; margin-top: 12mm; font-size: 9.5pt; }
.educbt-doc__sign-box { text-align: center; width: 42mm; }
.educbt-doc__sig-area { height: 8.8mm; display: flex; align-items: flex-end; justify-content: center; }
.educbt-doc__sig-img { max-height: 7.8mm; max-width: 24.5mm; object-fit: contain; }
/* A text signature — the staff member's retained name, rendered in a script
   font so it reads as a signature rather than a typed label. Falls back to
   system script fonts if the imported webfont doesn't load (e.g. offline). */
.educbt-doc__sig-text { font-family: 'Petit Formal Script', 'Brush Script MT', 'Segoe Script', cursive; font-size: 10.5pt; line-height: 1; color: var(--doc-ink); display: inline-block; padding-bottom: 2mm; }
.educbt-doc__sig-line { border-top: 1px solid var(--doc-ink); padding-top: 1.5mm; min-height: 4mm; }
.educbt-doc__sig-role { font-size: 6pt; color: var(--doc-muted); text-transform: uppercase; letter-spacing: .5px; margin-top: 1mm; }
/* The white plate exists so a scanner sees clean quiet zone around the code
   rather than watermark bleeding through it. It only needs to be as wide as the
   code itself — a band running the full width of the sheet punched a white
   stripe through the watermark for no reason. */
.educbt-doc__qr { position: relative; z-index: 1; display: flex; flex-direction: column; align-items: center; margin-top: 6mm; gap: 1.5mm; }
.educbt-doc__qr > * { background: #fff; }
.educbt-doc__qr img { padding: 2mm; border-radius: 1mm; }
.educbt-doc__qr span, .educbt-doc__qr small { padding: 0 2mm; }
.educbt-doc__qr img { border: 1px solid var(--doc-rule); padding: 2px; }
.educbt-doc__qr span { font-size: 8pt; color: var(--doc-muted); }
/* Negative z-index puts this behind the sheet's non-positioned content (the letterhead, table, remarks, signatures, QR) without needing to touch any of their styles — see the CSS stacking spec: a negative-z sibling paints before normal-flow content, a zero-or-positive one paints after. */
/* ONE watermark, everywhere: the school crest.
   The slanted repeated school name and the OFFICIAL COPY mark are both off, on
   screen as well as on paper. Two different watermarks depending on which screen
   you were looking at made the documents look like they came from two different
   systems — and the crest is what a school actually stamps a document with.
   The crest is painted as a repeating background on the sheet (see
   --doc-wm-crest), because a background repeats on every printed page while a
   positioned element is painted once and leaves later pages bare. */
.educbt-doc__wm, .educbt-doc__wm--official, .educbt-doc__watermark { display: none !important; }

.educbt-doc__sheet {
    background-image: var(--doc-wm-crest, none);
    background-repeat: repeat-y;
    background-position: center 6cm;
    background-size: 11cm auto;
}
/* Faded with a white veil rather than an opacity property, which would fade the
   text sitting on top of it too. */
.educbt-doc__sheet::before {
    content: "";
    position: absolute;
    inset: 0;
    background: #fff;
    opacity: .90;
    z-index: 0;
    pointer-events: none;
}
.educbt-doc__sheet > * { position: relative; z-index: 1; }
.educbt-doc__serial { margin-top: 6mm; font-size: 8.5pt; color: var(--doc-muted); display: flex; justify-content: space-between; }

/* OFFICIAL COPY watermark — single line, moderately large, slanted, 10% opacity.
   position: fixed in print CSS makes it repeat on every page of a multi-page
   transcript. For html2pdf the element is part of the DOM. */
/* Absolute, scoped to .educbt-doc__sheet's own stacking context (that
   element sets position:relative + z-index:1) — NOT position:fixed. A
   fixed element escapes the sheet entirely and is positioned relative to
   the whole page viewport instead, so a negative z-index then puts it
   behind the ENTIRE page (any opaque background above it in the root
   stacking context), not just behind the sheet's own content — which is
   why it rendered fully invisible. Absolute positioning keeps it painted
   behind only the sheet's own children, above the sheet's white
   background, exactly like .educbt-doc__wm above. Print media still
   switches this to position:fixed further down so it repeats correctly
   across printed pages — that is a genuinely different rendering context
   (native browser pagination) where fixed is required and safe. */


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

/* The slanted school-name mark is retained only for schools with no crest at
   all. Where a crest exists it is always preferred — a rotated wall of text
   over a report sheet reads as a fault rather than as a watermark. */
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

/* ── Fitting the sheet to one page ────────────────────────────────────────
   The overhead on a report sheet is fixed: letterhead, bio block, grade key,
   summary, remarks, signatures and QR. Only the marks table grows. So the
   table and the blocks around it are set at a density chosen from the subject
   count, rather than letting a 13-subject sheet spill two rows onto a second
   page that then carries almost nothing.

   Each step trims padding and type a little further. Nothing below is small
   enough to be hard to read — beyond 14 subjects the sheet is allowed a second
   page instead, because illegible is worse than long. */

.educbt-doc--fit-snug .educbt-doc__table th,
.educbt-doc--fit-snug .educbt-doc__table td { padding: 1.15mm 1.7mm; }
.educbt-doc--fit-snug .educbt-doc__table { font-size: 9pt; }
.educbt-doc--fit-snug .educbt-doc__summary { margin: 3mm 0; }
.educbt-doc--fit-snug .educbt-doc__stat { padding: 2mm; }
.educbt-doc--fit-snug .educbt-doc__stat b { font-size: 12.5pt; }
.educbt-doc--fit-snug .educbt-doc__remarks { margin-top: 3mm; font-size: 9.5pt; }
.educbt-doc--fit-snug .educbt-doc__remarks p { margin: 0 0 2.2mm; }
.educbt-doc--fit-snug .educbt-doc__logo { width: 21mm; height: 21mm; }

.educbt-doc--fit-tight .educbt-doc__table th,
.educbt-doc--fit-tight .educbt-doc__table td { padding: .8mm 1.4mm; }
.educbt-doc--fit-tight .educbt-doc__table { font-size: 8.4pt; }
.educbt-doc--fit-tight .educbt-doc__table th { font-size: 8pt; letter-spacing: 0; }
.educbt-doc--fit-tight .educbt-doc__summary { margin: 2.4mm 0; gap: 3mm; }
.educbt-doc--fit-tight .educbt-doc__stat { padding: 1.6mm; }
.educbt-doc--fit-tight .educbt-doc__stat b { font-size: 11.5pt; }
.educbt-doc--fit-tight .educbt-doc__stat span { font-size: 7.5pt; }
.educbt-doc--fit-tight .educbt-doc__remarks { margin-top: 2.4mm; font-size: 9pt; }
.educbt-doc--fit-tight .educbt-doc__remarks p { margin: 0 0 1.8mm; }
.educbt-doc--fit-tight .educbt-doc__head { padding-bottom: 3mm; }
.educbt-doc--fit-tight .educbt-doc__logo { width: 18mm; height: 18mm; margin-bottom: 1.2mm; }
.educbt-doc--fit-tight .educbt-doc__school { font-size: 15pt; }
.educbt-doc--fit-tight .educbt-doc__sig-area { height: 7.4mm; }
.educbt-doc--fit-tight .educbt-doc__qr { margin-top: 3.5mm; }
.educbt-doc--fit-tight .educbt-doc__qr img { width: 19mm; height: 19mm; }
.educbt-doc--fit-tight .educbt-doc__address,
.educbt-doc--fit-tight .educbt-doc__contact { font-size: 8.5pt; }
.educbt-doc--fit-tight .educbt-doc__bio td { padding: .9mm 1.4mm; font-size: 9pt; }
.educbt-doc--fit-tight .educbt-doc__key { font-size: 8pt; margin: 2mm 0; }
.educbt-doc--fit-tight .educbt-doc__sign { margin-top: 3mm !important; }
.educbt-doc--fit-tight .educbt-doc__sig-line small { font-size: 7.5pt; }
.educbt-doc--fit-tight .educbt-doc__qr small { font-size: 7.5pt; }

/* Roomy and snug get a lighter version of the same trims, so the step between
   densities is gradual rather than a jump from spacious to cramped. */
.educbt-doc--fit-snug .educbt-doc__bio td { padding: 1.1mm 1.6mm; font-size: 9.5pt; }
.educbt-doc--fit-snug .educbt-doc__key { font-size: 8.5pt; margin: 2.6mm 0; }
.educbt-doc--fit-snug .educbt-doc__sign { margin-top: 4mm !important; }
.educbt-doc--fit-snug .educbt-doc__qr img { width: 22mm; height: 22mm; }

@page { size: A4 portrait; margin: 10mm; }

@media print {
    body.educbt-doc { background: #fff; padding: 0; }
    .educbt-doc__sheet { box-shadow: none; padding: 0; max-width: none; }
    .no-print { display: none !important; }

    /* Browsers strip background imagery from print to save ink, which would
       silently remove the watermark from every printed transcript. */
    .educbt-doc, .educbt-doc__watermark, .educbt-doc__table th, .educbt-doc__wm, .educbt-doc__sig-img, .educbt-doc__qr img {
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }

    /* Ensure signature lines always print */
    .educbt-doc__sig-line { border-top: 1px solid var(--doc-ink) !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    /* THE CLIPPING BUG.
       `overflow: hidden` on a block that spans more than one printed page tells
       the print engine the box ends at the bottom of page one. Everything past
       that boundary — table rows, cell borders, the whole second page of a long
       report — is clipped away. It looks correct on screen, because on screen
       the box is one long column that never crosses a page boundary.
       The sheet must not clip in print. It only exists to hold the drop shadow
       and the watermark on screen. */
    .educbt-doc__sheet { overflow: visible !important; }

    /* THE VANISHING WATERMARK.
       `position: fixed` paints once, on the first page, in every current print
       engine — so page two of a long report came out with no watermark at all.
       `position: absolute` inside the sheet has the same problem in reverse: it
       is painted at one place in the flow and does not repeat.
       A background image on the sheet DOES repeat on every printed page,
       because the page box paints it per page. So the watermark is drawn twice:
       the positioned element for screen fidelity, and a repeating background
       behind the sheet that is what actually reaches paper. */
    .educbt-doc__watermark { display: none !important; }

    .educbt-doc__sheet {
        background-image: var(--doc-wm-crest, none);
        background-repeat: repeat-y;
        background-position: center 6cm;
        background-size: 11cm auto;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }

    /* The crest at full strength behind text would be unreadable. Faded here
       rather than with an opacity property, which would fade the content too. */
    .educbt-doc__sheet::before {
        content: "";
        position: absolute;
        inset: 0;
        background: #fff;
        opacity: .90;
        z-index: 0;
        pointer-events: none;
    }
    .educbt-doc__sheet > * { position: relative; z-index: 1; }

    /* THE DISAPPEARING RULES.
       Cell borders were declared with a custom property. Where a print engine
       resolves custom properties late — or the user prints with backgrounds off
       — the rules came out missing or barely there, which is why printed copies
       lost their column lines while the preview looked right. Stated literally
       here, and forced to print. */
    /* Every rule on the sheet, stated literally and forced to print.
       Two things were removing them:
       - widths in mm (.2mm is under a device pixel and rounds away to nothing)
       - colours behind a custom property, which some engines resolve to
         `currentColor` or drop entirely once the print stylesheet applies.
       Sizes here are in pt, which is a real unit on paper, and every colour is
       literal. */
    .educbt-doc__table,
    .educbt-doc__table th,
    .educbt-doc__table td,
    .educbt-doc__stat,
    .educbt-doc__photo,
    .educbt-doc__bio td,
    .educbt-doc__qr img {
        border-color: #333 !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
    .educbt-doc__stat { border: 1pt solid #333 !important; }
    .educbt-doc__photo { border: 1pt solid #333 !important; }
    .educbt-doc__bio td { border-bottom: .75pt dotted #555 !important; }
    .educbt-doc__qr img { border: .75pt solid #333 !important; }

    /* The signature line is the whole point of a signature block. */
    .educbt-doc__sig-line { border-top: 1pt solid #000 !important; }
    .educbt-doc__table th, .educbt-doc__table td { border: 1pt solid #333 !important; }
    .educbt-doc__table th { background: #ececec !important; }
    .educbt-doc__table tfoot td { background: #f6f6f6 !important; }
    .educbt-doc__head { border-bottom: 2pt solid #000 !important; }

    /* Keep the closing blocks together at the foot of the sheet. Splitting a
       signature away from its own line, or a QR code from its caption, is the
       one break that makes a document look broken rather than long. */
    .educbt-doc__sign, .educbt-doc__qr, .educbt-doc__summary { break-inside: avoid; page-break-inside: avoid; }
    .educbt-doc__sign { break-after: avoid; page-break-after: avoid; }

    /* USE THE WHOLE PAGE.
       A sheet with fewer subjects finished early and left the signatures and QR
       crowded together at the end of the content, with clear paper below them.
       The sheet is a full-page flex column, and the QR block takes the slack —
       so the closing blocks sit at the foot of the page whatever the subject
       count, instead of being pulled up tight behind the last row. */
    .educbt-doc__sheet {
        display: flex !important;
        flex-direction: column;
        min-height: 271mm;
    }
    .educbt-doc__qr { margin-top: auto !important; padding-top: 6mm; }

    /* A row divider went missing between two subjects. Cell borders collapse
       against each other, so where a renderer decided one row was a break
       candidate the shared edge could be dropped. Stating the row's own bottom
       edge as well means the line survives however the cells collapse. */
    .educbt-doc__table tbody tr { border-bottom: 1pt solid #333 !important; }
    .educbt-doc__table tbody tr td { border-bottom: 1pt solid #333 !important; }

    /* Above 14 subjects the sheet is allowed a second page. Repeat the column
       headings there so the second page is readable on its own. */
    .educbt-doc__table thead { display: table-header-group; }
    .educbt-doc__table tfoot { display: table-footer-group; }
    .educbt-doc__wm { position: absolute !important; top: -50% !important; left: -50% !important; width: 200% !important; height: 200% !important; opacity: 0.08 !important; z-index: -1 !important; }
    /* The crest is the watermark. The slanted repeated school name was a
       fallback for schools with no crest, and it read as a printing fault on
       documents that had one. Both text marks are off; the crest repeats. */
    .educbt-doc__wm, .educbt-doc__wm--official, .educbt-doc__watermark--text { display: none !important; }
    .educbt-doc__qr { page-break-inside: avoid; }

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
