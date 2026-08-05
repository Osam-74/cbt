<?php
/**
 * A teacher's own dashboard: what they hold, and what needs marking.
 *
 * @var array<string,mixed> $educbt
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

global $wpdb;
$school_id = (int) $educbt['school_id'];
$actor     = $educbt['scope']->actor();
$staff_id  = (int) $actor['id'];

$year    = new \EduCBTPro\Services\AcademicYearService();
$session = $year->current_session( $school_id );
$term    = $year->current_term( $school_id );

$assignments = \EduCBTPro\Core\Schema::table( 'staff_assignments' );
$classes     = \EduCBTPro\Core\Schema::table( 'classes' );
$subjects    = \EduCBTPro\Core\Schema::table( 'subjects_v2' );
$enrolments  = \EduCBTPro\Core\Schema::table( 'enrollments' );

$held = (array) $wpdb->get_results(
    $wpdb->prepare(
        "SELECT a.assignment_type, a.class_id, a.subject_id, c.display_name AS class_name, s.name AS subject_name,
                (SELECT COUNT(*) FROM {$enrolments} e WHERE e.class_id = a.class_id AND e.status = 'active') AS students
         FROM {$assignments} a
         LEFT JOIN {$classes} c ON c.id = a.class_id
         LEFT JOIN {$subjects} s ON s.id = a.subject_id
         WHERE a.school_id = %d AND a.staff_id = %d AND a.status = 'active'
         ORDER BY a.assignment_type ASC, c.display_name ASC",
        $school_id,
        $staff_id
    ),
    ARRAY_A
);

$papers = \EduCBTPro\Core\Schema::table( 'exam_papers' );
$invig  = \EduCBTPro\Core\Schema::table( 'paper_invigilators' );

$duties = (array) $wpdb->get_results(
    $wpdb->prepare(
        "SELECT p.id, p.scheduled_at, s.name AS subject_name, c.display_name AS class_name
         FROM {$invig} i
         INNER JOIN {$papers} p ON p.id = i.paper_id
         INNER JOIN {$subjects} s ON s.id = p.subject_id
         LEFT JOIN {$classes} c ON c.id = p.class_id
         WHERE i.school_id = %d AND i.staff_id = %d AND p.scheduled_at >= DATE_SUB(%s, INTERVAL 1 DAY)
         ORDER BY p.scheduled_at ASC LIMIT 8",
        $school_id,
        $staff_id,
        current_time( 'mysql', true )
    ),
    ARRAY_A
);

$educbt_title = 'Subjects';

$educbt_body = static function () use ( $held, $duties, $session, $term ): void {
    ?>
    <p class="educbt-muted" style="margin-top:-8px">
        <?php echo esc_html( (string) ( $session['title'] ?? '' ) . ' · ' . (string) ( $term['title'] ?? 'no current term' ) ); ?>
    </p>

    <section class="educbt-card">
        <h2>What I hold</h2>
        <?php if ( empty( $held ) ) : ?>
            <p class="educbt-muted">You have not been assigned a class or subject yet. The school office does this under Staff.</p>
        <?php else : ?>
            <ul class="educbt-list">
            <?php foreach ( $held as $h ) : ?>
                <li>
                    <span>
                        <?php if ( (string) $h['assignment_type'] === 'class_teacher' ) : ?>
                            <strong>Class teacher</strong> — <?php echo esc_html( (string) $h['class_name'] ); ?>
                        <?php else : ?>
                            <strong><?php echo esc_html( (string) $h['subject_name'] ); ?></strong> — <?php echo esc_html( (string) $h['class_name'] ); ?>
                        <?php endif; ?>
                    </span>
                    <span class="educbt-muted"><?php echo esc_html( (string) $h['students'] ); ?> students</span>
                    <?php if ( (string) $h['assignment_type'] === 'subject_teacher' ) : ?>
                        <a class="educbt-btn" href="<?php echo esc_url( add_query_arg( [ 'class' => (int) $h['class_id'], 'subject' => (int) $h['subject_id'] ], home_url( '/portal/teacher/scores/' ) ) ); ?>">Record CA tests result</a>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

    <section class="educbt-card">
        <h2>Invigilation Schedule</h2>
        <?php if ( empty( $duties ) ) : ?>
            <p class="educbt-muted">Nothing assigned.</p>
        <?php else : ?>
            <ul class="educbt-list">
            <?php foreach ( $duties as $d ) : ?>
                <li>
                    <span><?php echo esc_html( $d['subject_name'] . ' — ' . $d['class_name'] ); ?></span>
                    <span class="educbt-muted"><?php echo esc_html( mysql2date( 'D j M, g:ia', (string) $d['scheduled_at'] ) ); ?></span>
                </li>
            <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
    <?php
};

require EDUCBT_PRO_PATH . 'templates/portal/shell.php';
