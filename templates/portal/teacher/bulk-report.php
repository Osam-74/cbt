<?php
/**
 * Bulk Report — renders ALL students' report sheets on one printable page.
 * Reached via /portal/teacher/bulk-report/?class_id=X&term_id=Y
 * Only works for published results.
 *
 * @var array<string,mixed> $educbt
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

global $wpdb;

$school_id = (int) ( $educbt['school_id'] ?? 0 );
$class_id  = absint( $_GET['class_id'] ?? 0 );
$term_id   = absint( $_GET['term_id'] ?? 0 );

if ( $class_id <= 0 || $term_id <= 0 ) {
    echo '<p class="educbt-flash educbt-flash--error">Missing class or term ID.</p>';
    return;
}

// Scope check — the teacher must hold this class
if ( ! \EduCBTPro\Core\Gate::allows( \EduCBTPro\Core\Capabilities::REMARK_RESULTS, [ 'class_id' => $class_id ] ) ) {
    echo '<p class="educbt-flash educbt-flash--error">You can only access reports for your own class.</p>';
    return;
}

// Verify results are published
$term_results_t = \EduCBTPro\Core\Schema::table( 'term_results' );
$status = (string) $wpdb->get_var(
    $wpdb->prepare(
        "SELECT status FROM {$term_results_t} WHERE school_id = %d AND class_id = %d AND term_id = %d LIMIT 1",
        $school_id, $class_id, $term_id
    )
);

if ( $status !== 'published' ) {
    echo '<p class="educbt-flash educbt-flash--error">Results for this class have not been published yet.</p>';
    return;
}

// Get all students in this class
$enrolments_t = \EduCBTPro\Core\Schema::table( 'enrollments' );
$students_t   = \EduCBTPro\Core\Schema::table( 'students' );
$classes_t    = \EduCBTPro\Core\Schema::table( 'classes' );

$students = (array) $wpdb->get_results(
    $wpdb->prepare(
        "SELECT s.id, s.first_name, s.last_name, s.admission_number
         FROM {$enrolments_t} e
         INNER JOIN {$students_t} s ON s.id = e.student_id
         WHERE e.school_id = %d AND e.class_id = %d AND e.status = 'active'
         ORDER BY s.last_name ASC, s.first_name ASC",
        $school_id, $class_id
    ),
    ARRAY_A
);

$class_name = (string) $wpdb->get_var(
    $wpdb->prepare( "SELECT display_name FROM {$classes_t} WHERE id = %d LIMIT 1", $class_id )
);

// Render each student's report — extract only the CSS + content (no scripts,
// no download buttons, no nested <html> documents)
$doc_service = new \EduCBTPro\Services\ReportCardDocument();
$reports_html = '';
$doc_css = '';
$report_count = 0;

foreach ( $students as $student ) {
    $sid = (int) $student['id'];
    $doc = $doc_service->render( $school_id, $sid, $term_id, true );
    if ( empty( $doc['found'] ) ) {
        continue;
    }
    $report_count++;

    $doc_html = $doc['html'];

    // Extract the <style> block (only need it once — all reports share it)
    if ( $doc_css === '' && preg_match( '/<style>(.*?)<\/style>/s', $doc_html, $css_match ) ) {
        $doc_css = $css_match[1];
    }

    // Extract just the #educbt-pdf-content innerHTML (skip scripts, buttons)
    if ( preg_match( '/<div id="educbt-pdf-content">(.*?)<\/div>\s*<script/s', $doc_html, $m ) ) {
        $reports_html .= $m[1];
    } else {
        // Fallback: try to extract the .educbt-doc__sheet div
        if ( preg_match( '/<div class="educbt-doc__sheet">(.*?)<\/div>\s*(?:<\/div>)?\s*<script/s', $doc_html, $m ) ) {
            $reports_html .= '<div class="educbt-doc__sheet">' . $m[1] . '</div>';
        }
    }

    // Page break between students
    if ( $report_count < count( $students ) ) {
        $reports_html .= '<div style="page-break-after:always"></div>';
    }
}

if ( $report_count === 0 ) {
    echo '<p class="educbt-flash educbt-flash--error">No published results found for students in this class.</p>';
    return;
}

// Get the session/term info
$year_svc = new \EduCBTPro\Services\AcademicYearService();
$session = $year_svc->current_session( $school_id );
$term    = $year_svc->current_term( $school_id );
$session_title = (string) ( $session['title'] ?? '' );
$term_title    = (string) ( $term['title'] ?? '' );

// Output a full standalone HTML page
header( 'Content-Type: text/html; charset=UTF-8' );
echo '<!DOCTYPE html>
<html ' . get_language_attributes() . '>
<head>
<meta charset="' . esc_attr( get_bloginfo( 'charset' ) ) . '">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>' . esc_html( $class_name ) . ' — ' . esc_html( $term_title ) . ' — Bulk Reports</title>
<style>
' . $doc_css . '

/* Bulk report specific styles */
@media print {
    .bulk-toolbar { display: none !important; }
    body { margin: 0; padding: 0; background: #fff !important; }
    .educbt-doc__sheet {
        /* Break BEFORE each sheet rather than after it.
           `page-break-after: always` on every sheet forces a break after the last
           one too, and the `:last-of-type` reset only cancelled the legacy
           property while `break-after` still fired — so a blank leaf followed the
           run, and any sheet that overflowed by a line produced another. Breaking
           before, and exempting the first, cannot leave a trailing page. */
        page-break-inside: avoid;
        margin: 0 !important;
        /* No padding here: @page already sets the 10mm margin. Adding 20px on
           top pushed each sheet past the page box, which is what tipped a report
           that fits onto a second page and left a near-empty leaf behind it. */
        padding: 0 !important;
        box-shadow: none !important;
        border: none !important;
        width: 100% !important;
        max-width: none !important;
        overflow: visible !important;
    }
    .educbt-doc__sheet + .educbt-doc__sheet { page-break-before: always; break-before: page; }
    .educbt-doc__toolbar { display: none !important; }
}
@media screen {
    body { font-family: system-ui, -apple-system, sans-serif; background: #e9eef5; margin: 0; padding: 20px; }
    .bulk-toolbar {
        position: sticky; top: 0; z-index: 100; background: #fff;
        border: 1px solid #cbd5e1; border-radius: 8px; padding: 12px 20px;
        margin-bottom: 20px; display: flex; align-items: center;
        justify-content: space-between; box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        max-width: 800px; margin-left: auto; margin-right: auto;
    }
    .bulk-toolbar h1 { margin: 0; font-size: 1.1rem; }
    .bulk-toolbar .btn { background: #14532d; color: #fff; border: none; padding: 8px 20px; border-radius: 6px; font-size: .9rem; cursor: pointer; }
    .bulk-toolbar .btn:hover { background: #166534; }
    .bulk-hint { font-size: .78rem; color: #475569; max-width: 190px; line-height: 1.35; }
    .educbt-doc__sheet {
        background: #fff; max-width: 800px; margin: 0 auto 30px;
        padding: 40px; box-shadow: 0 1px 4px rgba(0,0,0,0.1); border-radius: 4px;
    }
}
</style>
</head>
<body class="educbt-doc educbt-doc--report">
<div class="bulk-toolbar">
    <h1>' . esc_html( $class_name ) . ' &mdash; ' . esc_html( $term_title ) . ' (' . esc_html( (string) $report_count ) . ' reports)</h1>
    <div style="display:flex;gap:8px">
        <button class="btn" onclick="window.print()">Download / Print</button>
        <span class="bulk-hint">Choose <strong>Save as PDF</strong> in the print dialog to download.</span>
    </div>
</div>
<div id="educbt-bulk-content">
' . $reports_html . '
</div>
<script>
// Downloading is the browser own print-to-PDF, not a screenshot library.
//
// This used to run html2pdf/html2canvas, which rasterises the page and then
// slices the image at fixed intervals. It has no idea where a report ends, so
// sheets started halfway down a page, bled into the next, and left blank pages
// between them. And because the output was a picture, table rules and signature
// lines came out as whatever the rasteriser managed to redraw, which is why the
// borders went missing.
//
// The browser print engine already understands page-break-after on each sheet,
// and prints real vector text with real borders. Save as PDF from the print
// dialog gives a correct, selectable, sharp document.
</script>
</body>
</html>';
