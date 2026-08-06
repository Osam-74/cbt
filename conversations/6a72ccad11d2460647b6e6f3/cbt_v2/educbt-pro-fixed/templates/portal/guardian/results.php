<?php
/**
 * A parent's view of their children's published results, printable.
 *
 * @var array<string,mixed> $educbt
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

$school_id   = (int) $educbt['school_id'];
$actor       = $educbt['scope']->actor();
$guardian_id = (int) $actor['id'];
$term_id     = (int) $educbt['id'];

$workflow = new \EduCBTPro\Services\ResultWorkflowService();
$rows     = $workflow->published_for_guardian( $school_id, $guardian_id );

if ( $term_id > 0 ) {
    // Only a child of THIS guardian, and only a published term.
    $allowed = false;
    $student = 0;

    foreach ( $rows as $row ) {
        if ( (int) $row['term_id'] === $term_id ) {
            $allowed = true;
            $student = (int) $row['student_id'];
            break;
        }
    }

    if ( $allowed ) {
        $document = ( new \EduCBTPro\Services\ReportCardDocument() )->render( $school_id, $student, $term_id );

        if ( ! empty( $document['found'] ) ) {
            echo $document['html']; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped at build time
            return;
        }
    }
}

$educbt_title = 'Results';

$educbt_body = static function () use ( $rows ): void {
    if ( empty( $rows ) ) {
        echo '<div class="educbt-card"><p class="educbt-muted">No results have been published yet.</p></div>';
        return;
    }

    $by_child = [];

    foreach ( $rows as $row ) {
        $by_child[ trim( $row['first_name'] . ' ' . $row['last_name'] ) ][] = $row;
    }

    foreach ( $by_child as $name => $terms ) : ?>
        <section class="educbt-card">
            <h2><?php echo esc_html( $name ); ?></h2>
            <ul class="educbt-list">
            <?php foreach ( $terms as $row ) : ?>
                <li>
                    <span><?php echo esc_html( (string) $row['term_name'] ); ?></span>
                    <span class="educbt-muted">
                        <?php echo esc_html( (string) $row['average_score'] ); ?>% ·
                        <?php echo esc_html( \EduCBTPro\Services\ReportCardDocument::ordinal( (int) $row['class_position'] ) ); ?>
                        of <?php echo esc_html( (string) $row['class_size'] ); ?>
                    </span>
                    <a class="educbt-btn educbt-btn--primary" href="<?php echo esc_url( home_url( '/portal/guardian/results/' . (int) $row['term_id'] ) ); ?>">View &amp; print</a>
                </li>
            <?php endforeach; ?>
            </ul>
        </section>
    <?php endforeach;
};

require EDUCBT_PRO_PATH . 'templates/portal/shell.php';
