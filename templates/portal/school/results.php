<?php
/**
 * Results — compile a class, then move it along the approval chain.
 *
 * draft → submitted → compiled → approved → published. Nothing is visible to a
 * student or parent before the last step.
 *
 * @var array<string,mixed> $educbt
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

global $wpdb;

$school_id = (int) $educbt['school_id'];
$flash     = \EduCBTPro\Frontend\PortalActions::flash();

$year       = new \EduCBTPro\Services\AcademicYearService();
$session    = $year->current_session( $school_id );
$term       = $year->current_term( $school_id );
$session_id = (int) ( $session['id'] ?? 0 );
$term_id    = (int) ( $term['id'] ?? 0 );

$structure = new \EduCBTPro\Services\AcademicStructureService();
$classes   = $structure->list_classes( $school_id );
$workflow  = new \EduCBTPro\Services\ResultWorkflowService();
$pipeline  = $term_id > 0 ? $workflow->pipeline_overview( $school_id, $term_id ) : [];

$status_by_class = [];

foreach ( $pipeline as $row ) {
    $status_by_class[ (int) $row['class_id'] ] = $row;
}

$next_step = [
    'compiled' => [ 'approved', 'Approve' ],
    'approved' => [ 'published', 'Publish to students and parents' ],
];

$enrolment_counts = [];

foreach ( (array) $wpdb->get_results(
    $wpdb->prepare(
        "SELECT class_id, COUNT(*) AS n FROM " . \EduCBTPro\Core\Schema::table( 'enrollments' ) . "
         WHERE school_id = %d AND session_id = %d AND status = 'active' GROUP BY class_id",
        $school_id,
        $session_id
    ),
    ARRAY_A
) as $row ) {
    $enrolment_counts[ (int) $row['class_id'] ] = (int) $row['n'];
}

$educbt_title = 'Results';

$educbt_body = static function () use ( $flash, $classes, $status_by_class, $next_step, $session_id, $term_id, $session, $term, $enrolment_counts ): void {
    require EDUCBT_PRO_PATH . 'templates/portal/partials/flash.php';

    if ( $term_id === 0 ) {
        echo '<div class="educbt-card"><p class="educbt-note educbt-note--warn">No current term is set.</p></div>';
        return;
    }
    ?>
    <p class="educbt-muted" style="margin-top:-8px">
        <?php echo esc_html( (string) ( $session['title'] ?? '' ) . ' · ' . (string) ( $term['title'] ?? '' ) ); ?>
    </p>

    <section class="educbt-card">
        <h2>Classes</h2>

        <?php if ( empty( $classes ) ) : ?>
            <p class="educbt-muted">No classes yet.</p>
        <?php else : ?>
            <table class="educbt-table">
                <thead><tr><th>Class</th><th>Stage</th><th>Students</th><th>Action</th></tr></thead>
                <tbody>
                <?php foreach ( $classes as $class ) :
                    $cid    = (int) $class['id'];
                    $row    = $status_by_class[ $cid ] ?? null;
                    $status = $row ? (string) $row['status'] : '';
                    ?>
                    <tr>
                        <td><strong><?php echo esc_html( (string) $class['display_name'] ); ?></strong></td>
                        <td>
                            <?php if ( $status === '' ) : ?>
                                <span class="educbt-muted">not compiled</span>
                            <?php else : ?>
                                <span class="educbt-pill educbt-pill--<?php echo esc_attr( $status ); ?>"><?php echo esc_html( ucfirst( $status ) ); ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php
                            // Two different nothings: a class with nobody in it, and a
                            // class full of students whose results have not been
                            // compiled. Showing "0" for both was the confusing part.
                            $enrolled = (int) ( $enrolment_counts[ $cid ] ?? 0 );
                            ?>
                            <?php if ( $row ) : ?>
                                <?php echo esc_html( (string) $row['students'] ); ?> compiled
                            <?php elseif ( $enrolled > 0 ) : ?>
                                <span class="educbt-muted"><?php echo esc_html( (string) $enrolled ); ?> enrolled, none compiled</span>
                            <?php else : ?>
                                <span class="educbt-muted">no students enrolled</span>
                            <?php endif; ?>
                        </td>
                        <td style="display:flex;gap:8px;flex-wrap:wrap">
                            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                                <input type="hidden" name="action" value="educbt_compile_results">
                                <input type="hidden" name="class_id" value="<?php echo esc_attr( (string) $cid ); ?>">
                                <input type="hidden" name="session_id" value="<?php echo esc_attr( (string) $session_id ); ?>">
                                <input type="hidden" name="term_id" value="<?php echo esc_attr( (string) $term_id ); ?>">
                                <?php wp_nonce_field( 'educbt_compile_results' ); ?>
                                <button type="submit" class="educbt-btn"><?php echo $status === '' ? 'Compile' : 'Recompile'; ?></button>
                            </form>

                            <?php if ( isset( $next_step[ $status ] ) ) :
                                [ $to, $label ] = $next_step[ $status ]; ?>
                                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                                    <input type="hidden" name="action" value="educbt_move_results">
                                    <input type="hidden" name="to" value="<?php echo esc_attr( $to ); ?>">
                                    <input type="hidden" name="class_id" value="<?php echo esc_attr( (string) $cid ); ?>">
                                    <input type="hidden" name="session_id" value="<?php echo esc_attr( (string) $session_id ); ?>">
                                    <input type="hidden" name="term_id" value="<?php echo esc_attr( (string) $term_id ); ?>">
                                    <?php wp_nonce_field( 'educbt_move_results' ); ?>
                                    <button type="submit" class="educbt-btn educbt-btn--primary"><?php echo esc_html( $label ); ?></button>
                                </form>
                            <?php endif; ?>

                            <?php if ( $status !== '' ) : ?>
                                <a class="educbt-btn" href="<?php echo esc_url( home_url( '/portal/exams/broadsheet/' . $cid ) ); ?>">Broadsheet</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <p class="educbt-muted" style="margin-top:12px">
                Compiling works out totals, grades and positions. Nothing reaches a student
                or parent until it has been approved and then published.
            </p>
        <?php endif; ?>
    </section>
    <?php
};

require EDUCBT_PRO_PATH . 'templates/portal/shell.php';
