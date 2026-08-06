<?php
/**
 * Guardian dashboard.
 *
 * A parent with three children signs in once and sees all three. v1 could not
 * express this at all: parent contact was text on the student row, so siblings were
 * three unrelated records and no parent had a login.
 *
 * @var array<string,mixed> $educbt
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$school_id   = (int) $educbt['school_id'];
$actor       = $educbt['scope']->actor();
$guardian_id = (int) $actor['id'];

$children = ( new \EduCBTPro\Services\GuardianService() )->children( $school_id, $guardian_id );
$results  = ( new \EduCBTPro\Services\ResultWorkflowService() )->published_for_guardian( $school_id, $guardian_id );

$by_child = [];

foreach ( $results as $row ) {
    $by_child[ (int) $row['student_id'] ][] = $row;
}

$educbt_title = 'My Children';

$educbt_body = static function () use ( $children, $by_child ): void {
    if ( empty( $children ) ) {
        echo '<div class="educbt-card"><p class="educbt-muted">No children are linked to this account yet. Please contact the school office.</p></div>';
        return;
    }

    foreach ( $children as $child ) :
        $rows = $by_child[ (int) $child['id'] ] ?? [];
        ?>
        <section class="educbt-card">
            <div class="educbt-child">
                <?php if ( ! empty( $child['passport_photo'] ) ) : ?>
                    <img class="educbt-child__photo" src="<?php echo esc_url( $child['passport_photo'] ); ?>" alt="">
                <?php endif; ?>
                <div>
                    <h2><?php echo esc_html( $child['first_name'] . ' ' . $child['last_name'] ); ?></h2>
                    <p class="educbt-muted">
                        <?php echo esc_html( (string) $child['admission_number'] ); ?>
                        <?php if ( ! empty( $child['class_name'] ) ) : ?>
                            &middot; <?php echo esc_html( (string) $child['class_name'] ); ?>
                        <?php endif; ?>
                    </p>
                </div>
            </div>

            <?php if ( empty( $child['can_view_results'] ) ) : ?>
                <p class="educbt-note">
                    Results for this child are not shared with this account. The school office can change this.
                </p>
            <?php elseif ( empty( $rows ) ) : ?>
                <p class="educbt-muted">No results have been published yet.</p>
            <?php else : ?>
                <ul class="educbt-list">
                <?php foreach ( $rows as $row ) : ?>
                    <li>
                        <span><?php echo esc_html( (string) $row['term_name'] ); ?></span>
                        <span class="educbt-muted">Average <?php echo esc_html( (string) $row['average_score'] ); ?>%</span>
                        <a class="educbt-btn"
                           href="<?php echo esc_url( home_url( '/portal/guardian/results/' . (int) $row['term_id'] ) ); ?>">View &amp; print</a>
                    </li>
                <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
        <?php
    endforeach;
};

require EDUCBT_PRO_PATH . 'templates/portal/shell.php';
