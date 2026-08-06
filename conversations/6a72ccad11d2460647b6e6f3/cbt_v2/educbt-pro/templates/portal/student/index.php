<?php
/**
 * Student dashboard.
 *
 * The one question this screen must answer instantly is "is there an exam I can
 * open right now". Everything else is secondary, so the active paper comes first.
 *
 * @var array<string,mixed> $educbt
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$school_id  = (int) $educbt['school_id'];
$actor      = $educbt['scope']->actor();
$student_id = (int) $actor['id'];

$timetable = new \EduCBTPro\Services\TimetableService();
$active    = $timetable->active_for_student( $school_id, $student_id );
$upcoming  = $timetable->upcoming_for_student( $school_id, $student_id, 5 );
$results   = ( new \EduCBTPro\Services\ResultWorkflowService() )->published_for_student( $school_id, $student_id );

$educbt_title = 'Dashboard';

$educbt_body = static function () use ( $active, $upcoming, $results ): void {
    ?>
    <?php if ( ! empty( $active ) ) : ?>
        <section class="educbt-card educbt-card--live">
            <h2>Exam available now</h2>
            <?php foreach ( $active as $paper ) : ?>
                <div class="educbt-live">
                    <div>
                        <strong><?php echo esc_html( $paper['subject_name'] ); ?></strong>
                        <span class="educbt-muted">
                            <?php echo esc_html( (string) (int) $paper['question_count'] ); ?> questions &middot;
                            <?php echo esc_html( (string) round( (int) $paper['duration_seconds'] / 60 ) ); ?> minutes
                        </span>
                    </div>
                    <a class="educbt-btn educbt-btn--primary"
                       href="<?php echo esc_url( home_url( '/portal/student/exam/' . (int) $paper['id'] ) ); ?>">
                        <?php echo empty( $paper['attempt_id'] ) ? 'Start exam' : 'Continue exam'; ?>
                    </a>
                </div>
            <?php endforeach; ?>
        </section>
    <?php else : ?>
        <section class="educbt-card">
            <p class="educbt-muted">You have no exam open at the moment.</p>
        </section>
    <?php endif; ?>

    <section class="educbt-card">
        <h2>Coming up</h2>
        <?php if ( empty( $upcoming ) ) : ?>
            <p class="educbt-muted">Nothing scheduled yet.</p>
        <?php else : ?>
            <table class="educbt-table">
                <thead><tr><th>Subject</th><th>Date</th><th>Duration</th></tr></thead>
                <tbody>
                <?php foreach ( $upcoming as $paper ) : ?>
                    <tr>
                        <td><?php echo esc_html( $paper['subject_name'] ); ?></td>
                        <td><?php echo esc_html( mysql2date( 'D j M, g:ia', (string) $paper['scheduled_at'] ) ); ?></td>
                        <td><?php echo esc_html( (string) round( (int) $paper['duration_seconds'] / 60 ) ); ?> min</td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>

    <section class="educbt-card">
        <h2>My results</h2>
        <?php if ( empty( $results ) ) : ?>
            <p class="educbt-muted">No results have been published yet.</p>
        <?php else : ?>
            <ul class="educbt-list">
            <?php foreach ( $results as $row ) : ?>
                <li>
                    <span><?php echo esc_html( $row['term_name'] . ', ' . $row['session_name'] ); ?></span>
                    <span class="educbt-muted">
                        Average <?php echo esc_html( (string) $row['average_score'] ); ?>% &middot;
                        <?php echo esc_html( \EduCBTPro\Services\ReportCardDocument::ordinal( (int) $row['class_position'] ) ); ?>
                        of <?php echo esc_html( (string) $row['class_size'] ); ?>
                    </span>
                    <a class="educbt-btn" href="<?php echo esc_url( home_url( '/portal/student/results/' . (int) $row['term_id'] ) ); ?>">View &amp; print</a>
                </li>
            <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
    <?php
};

require EDUCBT_PRO_PATH . 'templates/portal/shell.php';
