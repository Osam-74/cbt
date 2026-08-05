<?php
/**
 * The invigilator's live board.
 *
 * Answers the four questions an invigilator actually has: who has not started, who
 * looks disconnected, who is flagged, and how long each student has left.
 *
 * @var array<string,mixed> $educbt
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

global $wpdb;
$school_id = (int) $educbt['school_id'];
$paper_id  = (int) $educbt['id'];

$papers_table   = \EduCBTPro\Core\Schema::table( 'exam_papers' );
$subjects_table = \EduCBTPro\Core\Schema::table( 'subjects_v2' );
$classes_table  = \EduCBTPro\Core\Schema::table( 'classes' );

$live = (array) $wpdb->get_results(
    $wpdb->prepare(
        "SELECT p.id, p.scheduled_at, s.name AS subject_name, c.display_name AS class_name
         FROM {$papers_table} p
         INNER JOIN {$subjects_table} s ON s.id = p.subject_id
         LEFT JOIN {$classes_table} c ON c.id = p.class_id
         WHERE p.school_id = %d AND p.status = 'published'
           AND p.scheduled_at <= DATE_ADD(%s, INTERVAL 1 DAY)
           AND DATE_ADD(p.scheduled_at, INTERVAL p.duration_seconds + 86400 SECOND) > %s
         ORDER BY p.scheduled_at ASC",
        $school_id,
        current_time( 'mysql', true ),
        current_time( 'mysql', true )
    ),
    ARRAY_A
);

$board = $paper_id > 0 ? ( new \EduCBTPro\Services\InvigilatorService() )->board( $school_id, $paper_id ) : null;

$educbt_title = 'Invigilation Schedule';

$educbt_body = static function () use ( $live, $board, $paper_id ): void {
    ?>
    <section class="educbt-card">
        <h2>Papers today</h2>
        <?php if ( empty( $live ) ) : ?>
            <p class="educbt-muted">No published paper is running today.</p>
        <?php else : ?>
            <ul class="educbt-list">
            <?php foreach ( $live as $p ) : ?>
                <li>
                    <span><strong><?php echo esc_html( (string) $p['subject_name'] ); ?></strong> — <?php echo esc_html( (string) $p['class_name'] ); ?></span>
                    <span class="educbt-muted"><?php echo esc_html( mysql2date( 'g:ia', (string) $p['scheduled_at'] ) ); ?></span>
                    <a class="educbt-btn <?php echo (int) $p['id'] === $paper_id ? 'educbt-btn--primary' : ''; ?>"
                       href="<?php echo esc_url( home_url( '/portal/exams/invigilate/' . (int) $p['id'] ) ); ?>">Watch</a>
                </li>
            <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

    <?php if ( $board && ! empty( $board['paper'] ) ) : ?>
        <section class="educbt-card">
            <h2>Access code</h2>
            <p style="font-size:26px;font-weight:700;letter-spacing:6px"><?php echo esc_html( (string) $board['paper']['access_code'] ); ?></p>
            <p class="educbt-muted">Read this out when the paper starts. Students cannot open it without the code.</p>
        </section>

        <section class="educbt-stats">
            <div class="educbt-stat"><b><?php echo esc_html( (string) $board['summary']['not_started'] ); ?></b><span>Not started</span></div>
            <div class="educbt-stat"><b><?php echo esc_html( (string) $board['summary']['in_progress'] ); ?></b><span>Writing</span></div>
            <div class="educbt-stat"><b><?php echo esc_html( (string) $board['summary']['submitted'] ); ?></b><span>Submitted</span></div>
            <div class="educbt-stat"><b><?php echo esc_html( (string) $board['summary']['disconnected'] ); ?></b><span>Quiet</span></div>
        </section>

        <section class="educbt-card">
            <h2>Students <span class="educbt-muted">(refresh to update)</span></h2>
            <table class="educbt-table">
                <thead><tr><th>Student</th><th>State</th><th>Answered</th><th>Time left</th><th>Flags</th></tr></thead>
                <tbody>
                <?php foreach ( $board['students'] as $row ) : ?>
                    <tr>
                        <td><?php echo esc_html( (string) $row['name'] ); ?><br>
                            <span class="educbt-muted"><?php echo esc_html( (string) $row['admission_number'] ); ?></span></td>
                        <td>
                            <span class="educbt-pill"><?php echo esc_html( str_replace( '_', ' ', (string) $row['state'] ) ); ?></span>
                            <?php if ( ! empty( $row['disconnected'] ) ) : ?>
                                <br><span class="educbt-muted">no activity for 2 min</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html( $row['answered'] . '/' . $row['total'] ); ?></td>
                        <td>
                            <?php echo $row['remaining_seconds'] === null
                                ? '—'
                                : esc_html( (string) floor( (int) $row['remaining_seconds'] / 60 ) . ' min' ); ?>
                        </td>
                        <td><?php echo (int) $row['flags'] > 0 ? esc_html( (string) $row['flags'] ) : '<span class="educbt-muted">—</span>'; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </section>
    <?php endif; ?>
    <?php
};

require EDUCBT_PRO_PATH . 'templates/portal/shell.php';
