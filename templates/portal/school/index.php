<?php
/**
 * Principal's overview.
 *
 * You asked how the principal sees what teachers and students are doing. It needs
 * no special surveillance feature: school-wide scope plus the audit log gives a real
 * activity feed, and the pipeline view answers the question a principal actually
 * asks at the end of term — "what is holding up results".
 *
 * @var array<string,mixed> $educbt
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

global $wpdb;

$school_id = (int) $educbt['school_id'];
$year      = new \EduCBTPro\Services\AcademicYearService();
$session   = $year->current_session( $school_id );
$term      = $year->current_term( $school_id );

$counts = [
    'students' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}educbt_students WHERE school_id = %d AND status = 'active'", $school_id ) ),
    'staff'    => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . \EduCBTPro\Core\Schema::table( 'staff' ) . " WHERE school_id = %d AND status = 'active'", $school_id ) ),
    'classes'  => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . \EduCBTPro\Core\Schema::table( 'classes' ) . " WHERE school_id = %d AND status = 'active'", $school_id ) ),
];

$pipeline = $term
    ? ( new \EduCBTPro\Services\ResultWorkflowService() )->pipeline_overview( $school_id, (int) $term['id'] )
    : [];

$activity = (array) $wpdb->get_results(
    $wpdb->prepare(
        'SELECT * FROM ' . $wpdb->prefix . 'educbt_audit_logs WHERE school_id = %d ORDER BY id DESC LIMIT 12',
        $school_id
    ),
    ARRAY_A
);

$flash = \EduCBTPro\Frontend\PortalActions::flash();

$educbt_title = 'School Overview';

$educbt_body = static function () use ( $counts, $session, $term, $pipeline, $activity, $flash ): void {
    // Rendered here too, so a message produced by a form elsewhere is never lost
    // if the user is returned to the dashboard rather than the form.
    require EDUCBT_PRO_PATH . 'templates/portal/partials/flash.php';

    ?>
    <section class="educbt-stats">
        <div class="educbt-stat"><b><?php echo esc_html( (string) $counts['students'] ); ?></b><span>Students</span></div>
        <div class="educbt-stat"><b><?php echo esc_html( (string) $counts['staff'] ); ?></b><span>Staff</span></div>
        <div class="educbt-stat"><b><?php echo esc_html( (string) $counts['classes'] ); ?></b><span>Classes</span></div>
        <div class="educbt-stat">
            <b><?php echo esc_html( $session['title'] ?? '—' ); ?></b>
            <span>
                <?php echo esc_html( $term['title'] ?? 'No current term' ); ?>
                &middot; <a href="<?php echo esc_url( home_url( '/portal/school/settings/' ) ); ?>">change</a>
            </span>
        </div>
    </section>

    <section class="educbt-card">
        <h2>Results pipeline</h2>
        <?php if ( empty( $pipeline ) ) : ?>
            <p class="educbt-muted">Nothing compiled for this term yet.</p>
        <?php else : ?>
            <table class="educbt-table">
                <thead><tr><th>Class</th><th>Stage</th><th>Students</th></tr></thead>
                <tbody>
                <?php foreach ( $pipeline as $row ) : ?>
                    <tr>
                        <td><?php echo esc_html( (string) $row['class_name'] ); ?></td>
                        <td><span class="educbt-pill educbt-pill--<?php echo esc_attr( (string) $row['status'] ); ?>"><?php echo esc_html( ucfirst( (string) $row['status'] ) ); ?></span></td>
                        <td><?php echo esc_html( (string) $row['students'] ); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>

    <section class="educbt-card">
        <h2>Recent activity</h2>
        <?php if ( empty( $activity ) ) : ?>
            <p class="educbt-muted">No activity recorded yet.</p>
        <?php else : ?>
            <ul class="educbt-list educbt-list--log">
            <?php foreach ( $activity as $entry ) : ?>
                <li>
                    <span><?php echo esc_html( (string) ( $entry['action'] ?? '' ) ); ?></span>
                    <span class="educbt-muted"><?php echo esc_html( (string) ( $entry['created_at'] ?? '' ) ); ?></span>
                </li>
            <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
    <?php
};

require EDUCBT_PRO_PATH . 'templates/portal/shell.php';
