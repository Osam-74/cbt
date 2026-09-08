<?php
/**
 * A teacher's own dashboard: my teaching assignments and invigilation duties.
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
         ORDER BY a.assignment_type ASC, s.name ASC, c.display_name ASC",
        $school_id,
        $staff_id
    ),
    ARRAY_A
);

// Group assignments into Subject Teaching and General Roles
$subject_teaching    = [];
$class_teacher_roles = [];

foreach ( $held as $h ) {
    $type = (string) ( $h['assignment_type'] ?? '' );
    if ( $type === 'class_teacher' || $type === 'form_teacher' ) {
        $class_teacher_roles[] = $h;
    } else {
        $sub_name = (string) ( $h['subject_name'] ?? 'Unspecified Subject' );
        if ( ! isset( $subject_teaching[ $sub_name ] ) ) {
            $subject_teaching[ $sub_name ] = [];
        }
        $subject_teaching[ $sub_name ][] = $h;
    }
}

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

$educbt_title = 'My Teaching Assignments';

$educbt_body = static function () use ( $subject_teaching, $class_teacher_roles, $duties, $session, $term ): void {
    ?>
    <p class="educbt-muted" style="margin-top:-8px;margin-bottom:20px">
        <?php echo esc_html( (string) ( $session['title'] ?? '' ) . ' · ' . (string) ( $term['title'] ?? 'no current term' ) ); ?>
    </p>

    <h2 style="margin:0 0 16px;font-size:18px;font-weight:600;color:var(--forest-dark, #14532d)">Subject Teaching</h2>

    <?php if ( empty( $subject_teaching ) ) : ?>
        <section class="educbt-card" style="margin-bottom:24px">
            <p class="educbt-muted">No subject teaching assignments. The school office assigns these under Staff.</p>
        </section>
    <?php else : ?>
        <?php foreach ( $subject_teaching as $sub_name => $class_rows ) : ?>
            <section class="educbt-card" style="margin-bottom:20px">
                <h3 style="margin:0 0 14px;font-size:16px;font-weight:600;color:var(--forest-dark, #14532d)"><?php echo esc_html( $sub_name ); ?></h3>
                <table class="educbt-table">
                    <thead>
                        <tr>
                            <th>Class</th>
                            <th>Students</th>
                            <th style="text-align:right">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ( $class_rows as $row ) : ?>
                        <tr>
                            <td style="width:30%">
                                <span class="educbt-pill educbt-pill--active" style="text-transform:none"><?php echo esc_html( (string) $row['class_name'] ); ?></span>
                            </td>
                            <td>
                                <?php
                                $cnt = (int) $row['students'];
                                echo esc_html( $cnt . ' ' . ( $cnt === 1 ? 'Student' : 'Students' ) );
                                ?>
                            </td>
                            <td style="text-align:right">
                                <a class="educbt-btn educbt-btn--primary" href="<?php echo esc_url( add_query_arg( [ 'class' => (int) $row['class_id'], 'subject' => (int) $row['subject_id'] ], home_url( '/portal/teacher/scores/' ) ) ); ?>">Record CA</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </section>
        <?php endforeach; ?>
    <?php endif; ?>

    <?php
    // Detect roles by capability so exam officers and principals see their
    // role even though they don't have a staff_assignments row for it.
    $_is_exam_off = \EduCBTPro\Core\Gate::allows( \EduCBTPro\Core\Capabilities::MANAGE_PAPERS )
        && ! \EduCBTPro\Core\Gate::allows( \EduCBTPro\Core\Capabilities::MANAGE_SCHOOL );
    $_is_principal = \EduCBTPro\Core\Gate::allows( \EduCBTPro\Core\Capabilities::MANAGE_SCHOOL );
    ?>

    <section class="educbt-card" style="margin-bottom:24px">
        <h2>General Roles</h2>
        <?php
        $has_any_role = false;
        if ( $_is_exam_off ) :
            $has_any_role = true;
        ?>
            <div style="padding:10px 0;border-bottom:1px solid #e2e8e4">
                <span>
                    <strong>Exam Officer</strong> &bull; You are assigned as the school's examination officer.
                </span>
            </div>
        <?php endif; ?>
        <?php if ( $_is_principal ) :
            $has_any_role = true;
        ?>
            <div style="padding:10px 0;border-bottom:1px solid #e2e8e4">
                <span>
                    <strong>Principal</strong> &bull; You have school-wide management access.
                </span>
            </div>
        <?php endif; ?>
        <?php if ( ! empty( $class_teacher_roles ) ) :
            $has_any_role = true;
            foreach ( $class_teacher_roles as $role ) : ?>
                <div style="padding:10px 0;border-bottom:1px solid #e2e8e4">
                    <span>
                        <strong>Class Teacher</strong> &bull; <?php echo esc_html( (string) $role['class_name'] ); ?> — <?php echo esc_html( (int) $role['students'] . ' ' . ( (int) $role['students'] === 1 ? 'student' : 'students' ) ); ?>
                    </span>
                </div>
            <?php endforeach;
        endif;
        if ( ! $has_any_role ) : ?>
            <p class="educbt-muted">No general roles assigned.</p>
        <?php endif; ?>
    </section>

    <section class="educbt-card">
        <h2>Invigilation Schedule</h2>
        <?php if ( empty( $duties ) ) : ?>
            <p class="educbt-muted">No invigilation sessions assigned.</p>
        <?php else : ?>
            <ul class="educbt-list">
            <?php foreach ( $duties as $d ) : ?>
                <li style="padding:10px 0;border-bottom:1px solid #e2e8e4">
                    <span><?php echo esc_html( $d['subject_name'] . ' — ' . $d['class_name'] ); ?></span>
                    <span class="educbt-muted"><?php echo esc_html( wp_date( 'D j M, g:ia', strtotime( (string) $d['scheduled_at'] . ' UTC' ) ) ); ?></span>
                </li>
            <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
    <?php
};

require EDUCBT_PRO_PATH . 'templates/portal/shell.php';
