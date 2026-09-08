<?php
/**
 * Teacher → Report (hidden from nav) — a class teacher opens a student's
 * report sheet for viewing when results are APPROVED (they don't have to wait
 * for PUBLISHED). Includes a remark-override box below the rendered sheet.
 *
 * When published, a Download button appears to generate the PDF.
 * Reached via /portal/teacher/report/?student_id=X&term_id=Y
 *
 * @var array<string,mixed> $educbt
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

global $wpdb;

$school_id  = (int) ( $educbt['school_id'] ?? 0 );
$student_id = absint( $_GET['student_id'] ?? 0 );
$term_id    = absint( $_GET['term_id'] ?? 0 );

if ( $student_id <= 0 || $term_id <= 0 ) {
    echo '<p class="educbt-flash educbt-flash--error">Missing student or term ID.</p>';
    return;
}

// Scope check — the teacher must hold the class this student belongs to
$gate = \EduCBTPro\Core\Gate::allows( \EduCBTPro\Core\Capabilities::REMARK_RESULTS, [ 'student_id' => $student_id ] );
if ( ! $gate ) {
    echo '<p class="educbt-flash educbt-flash--error">You can only view reports for students in your own class.</p>';
    return;
}

// Resolve the current term if not provided
if ( $term_id <= 0 ) {
    $year   = new \EduCBTPro\Services\AcademicYearService();
    $term   = $year->current_term( $school_id );
    $term_id = (int) ( $term['id'] ?? 0 );
}

// Render the report card — allow_unpublished = true so the teacher can see
// the sheet as soon as it is APPROVED, not just PUBLISHED.
$doc = ( new \EduCBTPro\Services\ReportCardDocument() )->render( $school_id, $student_id, $term_id, true );

if ( empty( $doc['found'] ) ) {
    echo '<p class="educbt-flash educbt-flash--error">No results found for this student.</p>';
    return;
}

$status = $doc['status'];

// Only show the remark-override box when results are APPROVED but NOT yet
// published. Once published, the teacher can only download — no more edits.
$can_remark = ( $status === 'approved' );
$is_published = ( $status === 'published' );

// Get current remarks for this student
$term_results = \EduCBTPro\Core\Schema::table( 'term_results' );
$current_remarks = $wpdb->get_row(
    $wpdb->prepare(
        "SELECT class_teacher_remark, principal_remark FROM {$term_results}
         WHERE school_id = %d AND student_id = %d AND term_id = %d LIMIT 1",
        $school_id, $student_id, $term_id
    ),
    ARRAY_A
);

$ct_remark  = (string) ( $current_remarks['class_teacher_remark'] ?? '' );

// Get student name for the page title
$stu_table = $wpdb->prefix . 'educbt_students';
$student_name = (string) $wpdb->get_var(
    $wpdb->prepare(
        "SELECT CONCAT(first_name, ' ', last_name) FROM {$stu_table} WHERE id = %d",
        $student_id
    )
);

// What the sheet is ACTUALLY showing right now for the placeholder
$avg_row = $wpdb->get_row(
    $wpdb->prepare(
        "SELECT average_score FROM {$term_results}
         WHERE school_id = %d AND student_id = %d AND term_id = %d LIMIT 1",
        $school_id, $student_id, $term_id
    ),
    ARRAY_A
);
$average = (float) ( $avg_row['average_score'] ?? 0 );

$remark_service = new \EduCBTPro\Services\RemarkService();
$ct_auto = $ct_remark !== '' ? $ct_remark : trim( $remark_service->for_average( $school_id, 'class_teacher', $average ) );

$flash = \EduCBTPro\Frontend\PortalActions::flash();
$flash_msg = '';
if ( ! empty( $flash['error'] ) ) {
    $flash_msg = '<div class="educbt-flash educbt-flash--error">' . esc_html( $flash['error'] ) . '</div>';
} elseif ( ! empty( $flash['result'] ) && ( $flash['result']['type'] ?? '' ) === 'saved' ) {
    $flash_msg = '<div class="educbt-flash educbt-flash--success">Remark saved.</div>';
}
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo( 'charset' ); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo esc_html( $student_name ); ?> — Report</title>
    <?php wp_head(); ?>
    <style>
        .educbt-report-view { max-width: 210mm; margin: 0 auto; padding: 20px; }
        .educbt-report-toolbar { display: flex; gap: 10px; align-items: center; margin-bottom: 16px; flex-wrap: wrap; }
        .educbt-report-doc { border: 1px solid #ddd; border-radius: 8px; overflow: hidden; }
        /* Prevent the html2pdf.js auto-download script in the document HTML
           from firing on page load — we only want download on button click. */
        #educbt-pdf-canvas, .educbt-pdf-trigger { display: none !important; }
        .educbt-doc__sheet { box-shadow: none; margin: 0; }
    </style>
</head>
<body>
<div class="educbt-report-view">
    <div class="educbt-report-toolbar no-print">
        <a href="<?php echo esc_url( home_url( '/portal/teacher/results/' ) ); ?>" class="educbt-btn">
            &larr; Back to Class Results
        </a>
        <?php if ( $is_published ) : ?>
            <button class="educbt-btn educbt-btn--primary" onclick="window.print()">
                Download / Print
            </button>
            <span class="educbt-muted" style="font-size:.8rem">Choose <strong>Save as PDF</strong> in the dialog to download.</span>
        <?php endif; ?>
    </div>

    <?php echo $flash_msg; ?>

    <div class="educbt-report-doc">
        <?php
        // $doc['html'] is a full standalone HTML page with an auto-download
        // script. Extract the <style> block and the #educbt-pdf-content
        // innerHTML so the sheet displays inline without auto-downloading.
        $doc_html = $doc['html'];
        // Extract the <style> block for document CSS
        $doc_css = '';
        if ( preg_match( '/<style>(.*?)<\/style>/s', $doc_html, $css_match ) ) {
            $doc_css = $css_match[1];
        }
        if ( $doc_css !== '' ) {
            echo '<style>' . $doc_css . '</style>';
        }
        // Extract just the #educbt-pdf-content innerHTML
        if ( preg_match( '/<div id="educbt-pdf-content">(.*?)<\/div>\s*<script/s', $doc_html, $m ) ) {
            $doc_html = $m[1];
        }
        echo $doc_html;
        ?>
    </div>

    <?php if ( $can_remark ) : ?>
    <div class="educbt-doc__remarks-edit no-print" style="margin-top:20px;padding:16px;border:1px solid #c9c9c9;border-radius:8px;">
        <h3 style="margin-top:0;">Override Remarks</h3>
        <p style="font-size:13px;color:#555;margin-bottom:16px;">
            These override the auto-remark. Leave blank to keep the auto-generated one.
        </p>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <?php wp_nonce_field( 'educbt_save_student_remark' ); ?>
            <input type="hidden" name="action" value="educbt_save_student_remark">
            <input type="hidden" name="student_id" value="<?php echo esc_attr( (string) $student_id ); ?>">
            <input type="hidden" name="term_id" value="<?php echo esc_attr( (string) $term_id ); ?>">
            <input type="hidden" name="role" value="class_teacher">

            <div class="educbt-field">
                <label>Class Teacher Remark</label>
                <textarea name="remark" rows="3" placeholder="Auto: <?php echo esc_attr( $ct_auto ); ?>"><?php echo esc_textarea( $ct_remark ); ?></textarea>
            </div>

            <button type="submit" class="educbt-btn educbt-btn--primary">Save Remark</button>
        </form>
    </div>
    <?php endif; ?>
</div>



<?php wp_footer(); ?>
</body>
</html>
