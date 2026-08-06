<?php
/**
 * A student's published results, with a printable report sheet.
 *
 * @var array<string,mixed> $educbt
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

$school_id  = (int) $educbt['school_id'];
$actor      = $educbt['scope']->actor();
$student_id = (int) $actor['id'];
$term_id    = (int) $educbt['id'];

$workflow = new \EduCBTPro\Services\ResultWorkflowService();
$results  = $workflow->published_for_student( $school_id, $student_id );

// A specific term requested: render the printable report sheet instead.
if ( $term_id > 0 ) {
    $document = ( new \EduCBTPro\Services\ReportCardDocument() )->render( $school_id, $student_id, $term_id );

    if ( ! empty( $document['found'] ) ) {
        echo $document['html']; // phpcs:ignore WordPress.Security.EscapeOutput -- fully escaped when built
        return;
    }
}

$educbt_title = 'My Results';

$educbt_body = static function () use ( $results, $term_id ): void {
    if ( $term_id > 0 ) {
        echo '<p class="educbt-note educbt-note--warn">That result is not available.</p>';
    }

    if ( empty( $results ) ) {
        echo '<div class="educbt-card"><p class="educbt-muted">No results have been published yet.</p></div>';
        return;
    }
    ?>
    <section class="educbt-card">
        <h2>Published results</h2>
        <ul class="educbt-list">
        <?php foreach ( $results as $row ) : ?>
            <li>
                <span><strong><?php echo esc_html( $row['term_name'] . ', ' . $row['session_name'] ); ?></strong><br>
                    <span class="educbt-muted"><?php echo esc_html( (string) $row['class_name'] ); ?></span></span>
                <span class="educbt-muted">
                    <?php echo esc_html( (string) $row['average_score'] ); ?>% ·
                    <?php echo esc_html( \EduCBTPro\Services\ReportCardDocument::ordinal( (int) $row['class_position'] ) ); ?>
                    of <?php echo esc_html( (string) $row['class_size'] ); ?>
                </span>
                <a class="educbt-btn educbt-btn--primary" href="<?php echo esc_url( home_url( '/portal/student/results/' . (int) $row['term_id'] ) ); ?>">View &amp; print</a>
            </li>
        <?php endforeach; ?>
        </ul>
    </section>
    <?php
};

require EDUCBT_PRO_PATH . 'templates/portal/shell.php';
