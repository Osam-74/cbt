<?php
/**
 * A teacher's printable documents: questions, student performance, and recorded CA / exam scores.
 *
 *   /portal/teacher/export/?doc=questions&subject=4[&answers=1]
 *   /portal/teacher/export/?doc=performance&subject=4&class=4
 *   /portal/teacher/export/?doc=records&subject=4&class=4
 *
 * The query string is only a request. What the teacher may actually take is decided from their own
 * assignments in TeacherExportService, so editing the numbers in the address bar reveals nothing that
 * the staff screen would not already have let them see.
 *
 * @var array<string,mixed> $educbt
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

use EduCBTPro\Core\Capabilities;
use EduCBTPro\Core\Gate;
use EduCBTPro\Services\AcademicYearService;
use EduCBTPro\Services\TeacherDocuments;
use EduCBTPro\Services\TeacherExportService;

$school_id   = (int) $educbt['school_id'];
$actor       = $educbt['scope']->actor();
$staff_id    = (int) ( $actor['id'] ?? 0 );
$school_wide = $educbt['scope']->is_school_wide();

$fail = static function ( string $message ): void {
    echo '<div class="educbt-card"><p class="educbt-flash educbt-flash--error">' . esc_html( $message ) . '</p></div>';
};

if ( ! Gate::allows( Capabilities::ENTER_SCORES ) ) {
    $fail( 'You do not have access to this page.' );
    return;
}

$export     = new TeacherExportService();
$documents  = new TeacherDocuments();
$doc        = sanitize_key( (string) ( $_GET['doc'] ?? '' ) );
$subject_id = absint( $_GET['subject'] ?? 0 );
$class_id   = absint( $_GET['class'] ?? 0 );

// ---------------------------------------------------------------
// No document asked for: show what this teacher can download.
// ---------------------------------------------------------------
if ( $doc === '' ) {
    $pairs    = $export->pairs( $school_id, $staff_id, $school_wide );
    $subjects = [];

    foreach ( $pairs as $p ) {
        $subjects[ $p['subject_id'] ]['name']      = $p['subject_name'];
        $subjects[ $p['subject_id'] ]['classes'][] = $p;
    }

    $educbt_title = 'Downloads';
    $educbt_body  = static function () use ( $subjects ): void {
        $base = home_url( '/portal/teacher/export/' );
        ?>
        <section class="educbt-card">
            <h2>Downloads</h2>
            <p class="educbt-muted">Each opens as a printable page with your school's heading. Choose <strong>Save as PDF</strong> in the print dialog to keep a copy.</p>
            <?php if ( empty( $subjects ) ) : ?>
                <p class="educbt-muted">You have not been assigned any subject to teach yet.</p>
            <?php endif; ?>
            <?php foreach ( $subjects as $sid => $s ) : ?>
                <div style="border-top:1px solid var(--edu-line);padding:12px 0">
                    <h3 style="margin:0 0 6px;font-size:1rem"><?php echo esc_html( $s['name'] ); ?></h3>
                    <p style="margin:0 0 8px">
                        <strong style="font-size:.85rem">My questions:</strong>
                        <a class="educbt-btn" target="_blank" href="<?php echo esc_url( add_query_arg( [ 'doc' => 'questions', 'subject' => $sid ], $base ) ); ?>">Question paper</a>
                        <a class="educbt-btn" target="_blank" href="<?php echo esc_url( add_query_arg( [ 'doc' => 'questions', 'subject' => $sid, 'answers' => 1 ], $base ) ); ?>">With answers</a>
                    </p>
                    <?php foreach ( $s['classes'] as $c ) : ?>
                        <p style="margin:0 0 6px">
                            <strong style="font-size:.85rem;display:inline-block;min-width:90px"><?php echo esc_html( $c['class_name'] ); ?>:</strong>
                            <a class="educbt-btn" target="_blank" href="<?php echo esc_url( add_query_arg( [ 'doc' => 'performance', 'subject' => $sid, 'class' => $c['class_id'] ], $base ) ); ?>">Student performance</a>
                            <a class="educbt-btn" target="_blank" href="<?php echo esc_url( add_query_arg( [ 'doc' => 'records', 'subject' => $sid, 'class' => $c['class_id'] ], $base ) ); ?>">CA and exam records</a>
                        </p>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        </section>
        <?php
    };

    $educbt_body();
    return;
}

// ---------------------------------------------------------------
// A document. Check the request against the teacher's own duties first.
// ---------------------------------------------------------------
if ( ! in_array( $doc, [ 'questions', 'performance', 'records' ], true ) ) {
    $fail( 'That document does not exist.' );
    return;
}

if ( ! $export->may_export( $school_id, $staff_id, $subject_id, $doc === 'questions' ? 0 : $class_id, $school_wide ) ) {
    $fail( 'You can only download documents for subjects and classes you teach.' );
    return;
}

global $wpdb;

$subject_name = (string) $wpdb->get_var(
    $wpdb->prepare( 'SELECT name FROM ' . \EduCBTPro\Core\Schema::table( 'subjects_v2' ) . ' WHERE id = %d AND school_id = %d', $subject_id, $school_id )
);

if ( $doc === 'questions' ) {
    $questions = $export->questions( $school_id, $staff_id, $subject_id, $school_wide );
    $html      = $documents->questions( $school_id, $subject_name, $questions, ! empty( $_GET['answers'] ), $school_wide ? '' : trim( (string) ( $actor['name'] ?? '' ) ) );
} else {
    $year    = new AcademicYearService();
    $session = $year->current_session( $school_id );
    $term    = $year->current_term( $school_id );

    $class_name = (string) $wpdb->get_var(
        $wpdb->prepare( 'SELECT display_name FROM ' . \EduCBTPro\Core\Schema::table( 'classes' ) . ' WHERE id = %d AND school_id = %d', $class_id, $school_id )
    );

    $sheet = $export->recorded_scores( $school_id, $staff_id, $subject_id, $class_id, (int) ( $session['id'] ?? 0 ), (int) ( $term['id'] ?? 0 ), $school_wide );

    $html = $documents->scores(
        $school_id,
        $subject_name,
        $class_name,
        (string) ( $session['title'] ?? $session['name'] ?? '' ),
        (string) ( $term['title'] ?? '' ),
        $sheet,
        $doc === 'performance'
    );
}

// A whole HTML document: no portal chrome around it.
echo $html; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts by TeacherDocuments.
