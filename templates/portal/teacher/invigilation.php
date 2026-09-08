<?php
/**
 * Teacher invigilation schedule — a timetable of duties.
 *
 * Shows the teacher's assigned invigilation: exam, class, date, time, invigilator.
 * This is NOT the live watch board — that's under Exams for school management.
 *
 * @var array<string,mixed> $educbt
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

global $wpdb;
$school_id = (int) $educbt['school_id'];
$actor     = $educbt['scope']->actor();
$staff_id = (int) $actor['id'];

$papers   = \EduCBTPro\Core\Schema::table( 'exam_papers' );
$subjects = \EduCBTPro\Core\Schema::table( 'subjects_v2' );
$classes  = \EduCBTPro\Core\Schema::table( 'classes' );
$invig    = \EduCBTPro\Core\Schema::table( 'paper_invigilators' );
$staff    = \EduCBTPro\Core\Schema::table( 'staff' );

// All invigilation duties for this teacher, grouped by date
$rows = (array) $wpdb->get_results(
    $wpdb->prepare(
        "SELECT p.id, p.scheduled_at, p.duration_seconds, p.venue, p.status,
                s.name AS subject_name, c.display_name AS class_name,
                GROUP_CONCAT(CONCAT(st.first_name, ' ', st.last_name) SEPARATOR ', ') AS invigilators
         FROM {$invig} i
         INNER JOIN {$papers} p ON p.id = i.paper_id
         INNER JOIN {$subjects} s ON s.id = p.subject_id
         LEFT JOIN {$classes} c ON c.id = p.class_id
         LEFT JOIN {$invig} pi ON pi.paper_id = p.id
         LEFT JOIN {$staff} st ON st.id = pi.staff_id
         WHERE i.school_id = %d AND i.staff_id = %d AND p.status <> 'cancelled'
         GROUP BY p.id
         ORDER BY p.scheduled_at ASC",
        $school_id,
        $staff_id
    ),
    ARRAY_A
);

// Group by date
$grouped = [];
foreach ( $rows as $row ) {
    $date = substr( (string) $row['scheduled_at'], 0, 10 );
    $row['duration_minutes'] = (int) round( absint( $row['duration_seconds'] ) / 60 );
    $row['time']             = gmdate( 'g:ia', (int) strtotime( (string) $row['scheduled_at'] ) );
    $row['ends_at']          = gmdate( 'g:ia', (int) strtotime( (string) $row['scheduled_at'] ) + absint( $row['duration_seconds'] ) );
    $grouped[ $date ][]      = $row;
}

$educbt_title = 'Invigilation Schedule';

$educbt_body = static function () use ( $grouped ): void {
    if ( empty( $grouped ) ) :
        ?>
        <section class="educbt-card">
            <p class="educbt-muted">You have no invigilation duties assigned.</p>
        </section>
        <?php
        return;
    endif;
    ?>

    <section class="educbt-card">
        <h2>Invigilation Schedule</h2>
        <p class="educbt-muted" style="margin-top:-6px">A timetable of exams you are assigned to invigilate.</p>

        <?php foreach ( $grouped as $date => $papers ) : ?>
            <h3 style="margin-top:20px;margin-bottom:8px"><?php echo esc_html( mysql2date( 'l, j F Y', $date . ' 00:00:00' ) ); ?></h3>
            <table class="educbt-table">
                <thead><tr><th>Exam</th><th>Class</th><th>Time</th><th>Duration</th><th>Venue</th><th>Invigilator(s)</th><th>Status</th></tr></thead>
                <tbody>
                <?php foreach ( $papers as $p ) : ?>
                    <tr>
                        <td><strong><?php echo esc_html( (string) $p['subject_name'] ); ?></strong></td>
                        <td><?php echo esc_html( (string) $p['class_name'] ); ?></td>
                        <td><?php echo esc_html( $p['time'] ); ?> — <?php echo esc_html( $p['ends_at'] ); ?></td>
                        <td><?php echo esc_html( (string) $p['duration_minutes'] ); ?> min</td>
                        <td><?php echo esc_html( (string) ( $p['venue'] ?? '—' ) ); ?></td>
                        <td><?php echo esc_html( (string) ( $p['invigilators'] ?? '—' ) ); ?></td>
                        <td>
                            <?php
                            $status_class = '';
                            $status_text = ucfirst( (string) $p['status'] );
                            if ( (string) $p['status'] === 'published' ) {
                                $status_class = 'educbt-pill--approved';
                            } elseif ( (string) $p['status'] === 'draft' ) {
                                $status_class = 'educbt-pill--draft';
                            }
                            ?>
                            <span class="educbt-pill <?php echo esc_attr( $status_class ); ?>"><?php echo esc_html( $status_text ); ?></span>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endforeach; ?>
    </section>
    <?php
};

require EDUCBT_PRO_PATH . 'templates/portal/shell.php';
