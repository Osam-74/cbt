<?php
/**
 * Public result verification page.
 *
 * Reached by scanning the QR code on a report sheet or transcript.
 * Requires no login. Shows only basic verification info.
 *
 * Two URL formats are supported:
 *  1. Report card: /verify-result/?educbt_verify=1&sid=X&stid=Y&tid=Z&h=HASH
 *  2. Transcript:  /portal/verify/SERIAL/
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

global $wpdb;

$verified     = false;
$student_name = '';
$term_name    = '';
$class_name   = '';
$average      = '';
$status       = '';
$doc_type     = '';
$issue_date   = '';
$serial       = '';

// --- Route 1: Transcript serial via query param (?tr=SERIAL) ---
// The serial contains slashes (e.g. SCH/TR/2026/0001) so it cannot go in the
// URL path — it would break WordPress routing. It comes in as ?tr=SERIAL.
$serial = sanitize_text_field( (string) ( $_GET['tr'] ?? '' ) );

// Fallback: older transcripts may still use the path format (/portal/verify/SERIAL/)
if ( $serial === '' ) {
    $serial = sanitize_text_field( (string) ( get_query_var( 'educbt_section' ) ?? '' ) );
}
if ( $serial === '' ) {
    $req_path = (string) ( $_SERVER['REQUEST_URI'] ?? '' );
    if ( preg_match( '#/portal/verify/(.+)/?#', $req_path, $m ) ) {
        $serial = sanitize_text_field( $m[1] );
    }
}

if ( $serial !== '' ) {
    // Look up the transcript by serial.
    $tr_table  = \EduCBTPro\Core\Schema::table( 'transcripts' );
    $stu_table = $wpdb->prefix . 'educbt_students';

    $row = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT t.serial, t.status, t.issued_at, t.school_id, t.student_id,
                    CONCAT(s.first_name, ' ', s.last_name) AS student_name
             FROM {$tr_table} t
             INNER JOIN {$stu_table} s ON s.id = t.student_id
             WHERE t.serial = %s",
            $serial
        ),
        ARRAY_A
    );

    if ( $row ) {
        $doc_type    = 'Transcript';
        $student_name = (string) $row['student_name'];
        $status       = (string) $row['status'];
        $issue_date   = (string) $row['issued_at'];
        $verified     = in_array( $status, [ 'issued', 'reissued' ], true );
    }
} else {
    // --- Route 2: Report card query params (?sid=X&stid=Y&tid=Z&h=HASH) ---
    $school_id  = absint( $_GET['sid'] ?? 0 );
    $student_id = absint( $_GET['stid'] ?? 0 );
    $term_id    = absint( $_GET['tid'] ?? 0 );
    $hash       = sanitize_text_field( $_GET['h'] ?? '' );

    $expected_hash = wp_hash( $school_id . ':' . $student_id . ':' . $term_id );
    $valid = ( $hash === $expected_hash && $school_id > 0 && $student_id > 0 && $term_id > 0 );

    if ( $valid ) {
        $tr       = \EduCBTPro\Core\Schema::table( 'term_results' );
        $students = $wpdb->prefix . 'educbt_students';
        $terms    = \EduCBTPro\Core\Schema::table( 'terms' );
        $classes  = \EduCBTPro\Core\Schema::table( 'classes' );

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT tr.average_score, tr.status,
                        CONCAT(s.first_name, ' ', s.last_name) AS student_name,
                        t.title AS term_name,
                        c.display_name AS class_name
                 FROM {$tr} tr
                 INNER JOIN {$students} s ON s.id = tr.student_id
                 INNER JOIN {$terms} t ON t.id = tr.term_id
                 LEFT JOIN {$classes} c ON c.id = tr.class_id
                 WHERE tr.school_id = %d AND tr.student_id = %d AND tr.term_id = %d",
                $school_id,
                $student_id,
                $term_id
            ),
            ARRAY_A
        );

        if ( $row ) {
            $doc_type    = 'Report Card';
            $verified     = (string) $row['status'] === 'published';
            $student_name = (string) $row['student_name'];
            $term_name    = (string) $row['term_name'];
            $class_name    = (string) $row['class_name'];
            $average      = (string) $row['average_score'];
            $status       = (string) $row['status'];
        }
    }
}
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo( 'charset' ); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Result Verification — EduCBT</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f8fafc; color: #1e293b; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 20px; }
        .verify-card { background: #fff; border-radius: 16px; box-shadow: 0 4px 24px rgba(0,0,0,0.08); max-width: 480px; width: 100%; padding: 40px; text-align: center; }
        .verify-badge { width: 80px; height: 80px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-size: 40px; margin-bottom: 20px; }
        .verify-badge--ok { background: #dcfce7; color: #16a34a; }
        .verify-badge--fail { background: #fee2e2; color: #dc2626; }
        .verify-card h2 { font-size: 22px; margin-bottom: 16px; }
        .verify-info { width: 100%; border-collapse: collapse; margin: 20px 0; text-align: left; }
        .verify-info th { padding: 10px 12px; background: #f1f5f9; font-weight: 600; font-size: 14px; width: 40%; }
        .verify-info td { padding: 10px 12px; font-size: 14px; border-bottom: 1px solid #e2e8f0; }
        .verify-note { font-size: 13px; color: #64748b; margin-top: 16px; line-height: 1.5; }
    </style>
</head>
<body>
    <div class="verify-card">
        <?php if ( $verified ) : ?>
            <div class="verify-badge verify-badge--ok">&#10003;</div>
            <h2><?php echo esc_html( $doc_type ); ?> Verified</h2>
            <p style="color:#64748b;margin-bottom:20px;">This <?php echo esc_html( strtolower( $doc_type ) ); ?> is genuine and was issued through the school's result management system.</p>
            <table class="verify-info">
                <tr><th>Student</th><td><?php echo esc_html( $student_name ); ?></td></tr>
                <?php if ( $doc_type === 'Report Card' ) : ?>
                    <tr><th>Class</th><td><?php echo esc_html( $class_name ); ?></td></tr>
                    <tr><th>Term</th><td><?php echo esc_html( $term_name ); ?></td></tr>
                    <tr><th>Average Score</th><td><?php echo esc_html( $average ); ?>%</td></tr>
                    <tr><th>Status</th><td><?php echo esc_html( ucfirst( $status ) ); ?></td></tr>
                <?php else : ?>
                    <tr><th>Serial</th><td><?php echo esc_html( $serial ); ?></td></tr>
                    <tr><th>Status</th><td><?php echo esc_html( ucfirst( $status ) ); ?></td></tr>
                    <?php if ( $issue_date ) : ?>
                        <tr><th>Issued</th><td><?php echo esc_html( date( 'M j, Y', strtotime( $issue_date ) ) ); ?></td></tr>
                    <?php endif; ?>
                <?php endif; ?>
            </table>
            <p class="verify-note">For the full <?php echo esc_html( strtolower( $doc_type ) ); ?>, the student or guardian can log in to their portal.</p>
        <?php else : ?>
            <div class="verify-badge verify-badge--fail">&#10007;</div>
            <h2>Could Not Verify</h2>
            <p style="color:#64748b;margin-bottom:20px;">This QR code is invalid, or the result has not been published yet. Please contact the school if you believe this is an error.</p>
        <?php endif; ?>
    </div>
</body>
</html>