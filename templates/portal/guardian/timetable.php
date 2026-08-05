<?php
/**
 * Exam timetable for a parent's children.
 *
 * @var array<string,mixed> $educbt
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

$school_id   = (int) $educbt['school_id'];
$actor       = $educbt['scope']->actor();
$guardian_id = (int) $actor['id'];

$children  = ( new \EduCBTPro\Services\GuardianService() )->children( $school_id, $guardian_id );
$timetable = new \EduCBTPro\Services\TimetableService();

$educbt_title = 'Timetable';

$educbt_body = static function () use ( $children, $timetable, $school_id ): void {
    if ( empty( $children ) ) {
        echo '<div class="educbt-card"><p class="educbt-muted">No children are linked to this account.</p></div>';
        return;
    }

    foreach ( $children as $child ) :
        $upcoming = $timetable->upcoming_for_student( $school_id, (int) $child['id'], 20 );
        ?>
        <section class="educbt-card">
            <h2><?php echo esc_html( $child['first_name'] . ' ' . $child['last_name'] ); ?></h2>
            <?php if ( empty( $upcoming ) ) : ?>
                <p class="educbt-muted">Nothing scheduled.</p>
            <?php else : ?>
                <table class="educbt-table">
                    <thead><tr><th>Subject</th><th>Date</th><th>Duration</th></tr></thead>
                    <tbody>
                    <?php foreach ( $upcoming as $p ) : ?>
                        <tr>
                            <td><?php echo esc_html( (string) $p['subject_name'] ); ?></td>
                            <td><?php echo esc_html( mysql2date( 'D j M, g:ia', (string) $p['scheduled_at'] ) ); ?></td>
                            <td><?php echo esc_html( (string) round( (int) $p['duration_seconds'] / 60 ) ); ?> min</td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </section>
    <?php endforeach;
};

require EDUCBT_PRO_PATH . 'templates/portal/shell.php';
