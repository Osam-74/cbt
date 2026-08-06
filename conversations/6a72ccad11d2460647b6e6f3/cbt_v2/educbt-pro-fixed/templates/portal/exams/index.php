<?php
/**
 * Examinations overview.
 *
 * @var array<string,mixed> $educbt
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

global $wpdb;
$school_id = (int) $educbt['school_id'];

$papers   = \EduCBTPro\Core\Schema::table( 'exam_papers' );
$subjects = \EduCBTPro\Core\Schema::table( 'subjects_v2' );
$classes  = \EduCBTPro\Core\Schema::table( 'classes' );
$attempts = \EduCBTPro\Core\Schema::table( 'attempts' );
$questions = $wpdb->prefix . 'educbt_questions';

// Count questions for THIS school regardless of who wrote them. The teacher's own
// count lives on their dashboard; this is the school-wide picture.
$stats = [
    'questions' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$questions} WHERE school_id = %d AND status = 'active'", $school_id ) ),
    'papers'    => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$papers} WHERE school_id = %d AND status <> 'cancelled'", $school_id ) ),
    'published' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$papers} WHERE school_id = %d AND status = 'published'", $school_id ) ),
    'sat'       => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$attempts} WHERE school_id = %d AND status = 'graded'", $school_id ) ),
];

$upcoming = (array) $wpdb->get_results(
    $wpdb->prepare(
        "SELECT p.id, p.scheduled_at, p.status, s.name AS subject_name, c.display_name AS class_name
         FROM {$papers} p
         INNER JOIN {$subjects} s ON s.id = p.subject_id
         LEFT JOIN {$classes} c ON c.id = p.class_id
         WHERE p.school_id = %d AND p.status <> 'cancelled' AND p.scheduled_at >= DATE_SUB(%s, INTERVAL 1 DAY)
         ORDER BY p.scheduled_at ASC LIMIT 12",
        $school_id,
        current_time( 'mysql', true )
    ),
    ARRAY_A
);

$educbt_title = 'Examinations';

$educbt_body = static function () use ( $stats, $upcoming, $educbt ): void {
    ?>
    <section class="educbt-stats">
        <div class="educbt-stat"><b><?php echo esc_html( (string) $stats['questions'] ); ?></b><span>Questions</span></div>
        <div class="educbt-stat"><b><?php echo esc_html( (string) $stats['papers'] ); ?></b><span>Papers</span></div>
        <div class="educbt-stat"><b><?php echo esc_html( (string) $stats['published'] ); ?></b><span>Published</span></div>
        <div class="educbt-stat"><b><?php echo esc_html( (string) $stats['sat'] ); ?></b><span>Sat &amp; graded</span></div>
    </section>

    <section class="educbt-card">
        <h2>Next papers</h2>
        <?php if ( empty( $upcoming ) ) : ?>
            <p class="educbt-muted">Nothing scheduled.</p>
            <?php if ( $educbt['scope']->is_school_wide() ) : ?>
                <p class="educbt-muted">Create the examination for a term, then teachers submit their questions against it.</p>
                <p><a class="educbt-btn educbt-btn--primary" href="<?php echo esc_url( home_url( '/portal/exams/papers/' ) ); ?>">Create examination</a></p>
            <?php else : ?>
                <p class="educbt-muted">Set up a class test for your students.</p>
                <p><a class="educbt-btn educbt-btn--primary" href="<?php echo esc_url( home_url( '/portal/teacher/tests/' ) ); ?>">Schedule a CA test</a></p>
            <?php endif; ?>
        <?php else : ?>
            <table class="educbt-table">
                <thead><tr><th>Subject</th><th>Class</th><th>When</th><th>Status</th></tr></thead>
                <tbody>
                <?php foreach ( $upcoming as $p ) : ?>
                    <tr>
                        <td><?php echo esc_html( (string) $p['subject_name'] ); ?></td>
                        <td><?php echo esc_html( (string) $p['class_name'] ); ?></td>
                        <td><?php echo esc_html( mysql2date( 'D j M, g:ia', (string) $p['scheduled_at'] ) ); ?></td>
                        <td><span class="educbt-pill educbt-pill--<?php echo esc_attr( (string) $p['status'] ); ?>"><?php echo esc_html( ucfirst( (string) $p['status'] ) ); ?></span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>
    <?php
};

require EDUCBT_PRO_PATH . 'templates/portal/shell.php';
