<?php
/**
 * A student's own exam timetable — only papers for subjects they offer.
 *
 * @var array<string,mixed> $educbt
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

$school_id  = (int) $educbt['school_id'];
$actor      = $educbt['scope']->actor();
$student_id = (int) $actor['id'];

$timetable = new \EduCBTPro\Services\TimetableService();
$upcoming  = $timetable->upcoming_for_student( $school_id, $student_id, 40 );
$active    = $timetable->active_for_student( $school_id, $student_id );

$educbt_title = 'My Timetable';

$educbt_body = static function () use ( $upcoming, $active ): void {
    if ( ! empty( $active ) ) : ?>
        <section class="educbt-card educbt-card--live">
            <h2>Open now</h2>
            <?php foreach ( $active as $p ) : ?>
                <div class="educbt-live">
                    <strong><?php echo esc_html( (string) $p['subject_name'] ); ?></strong>
                    <a class="educbt-btn educbt-btn--primary" href="<?php echo esc_url( home_url( '/portal/student/exam/' . (int) $p['id'] ) ); ?>">Open</a>
                </div>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>

    <section class="educbt-card">
        <h2>Coming up</h2>
        <?php if ( empty( $upcoming ) ) : ?>
            <p class="educbt-muted">Nothing scheduled yet.</p>
        <?php else : ?>
            <table class="educbt-table">
                <thead><tr><th>Subject</th><th>Date</th><th>Duration</th><th>Venue</th></tr></thead>
                <tbody>
                <?php foreach ( $upcoming as $p ) : ?>
                    <tr>
                        <td><?php echo esc_html( (string) $p['subject_name'] ); ?></td>
                        <td><?php echo esc_html( mysql2date( 'D j M, g:ia', (string) $p['scheduled_at'] ) ); ?></td>
                        <td><?php echo esc_html( (string) round( (int) $p['duration_seconds'] / 60 ) ); ?> min</td>
                        <td><?php echo esc_html( (string) ( $p['venue'] ?: '—' ) ); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>
    <?php
};

require EDUCBT_PRO_PATH . 'templates/portal/shell.php';
