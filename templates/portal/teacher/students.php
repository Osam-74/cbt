<?php
/**
 * Class teacher's view of students in their class(es).
 *
 * A class teacher may head more than one class. This page lists every student
 * in each class they hold, with a profile view for each student so the teacher
 * can check and update basic details (name, parent contact, notes).
 *
 * @var array<string,mixed> $educbt
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

global $wpdb;

$school_id = (int) $educbt['school_id'];
$flash     = \EduCBTPro\Frontend\PortalActions::flash();
$actor     = $educbt['scope']->actor();
$staff_id  = (int) $actor['id'];

$year    = new \EduCBTPro\Services\AcademicYearService();
$session = $year->current_session( $school_id );
$session_id = (int) ( $session['id'] ?? 0 );

// Classes this teacher heads as class teacher (not subject-teacher classes).
$assign_table  = \EduCBTPro\Core\Schema::table( 'staff_assignments' );
$classes_table = \EduCBTPro\Core\Schema::table( 'classes' );

$my_classes = (array) $wpdb->get_results(
    $wpdb->prepare(
        "SELECT c.id, c.display_name
         FROM {$assign_table} a
         INNER JOIN {$classes_table} c ON c.id = a.class_id
         WHERE a.school_id = %d AND a.staff_id = %d AND a.assignment_type = 'class_teacher' AND a.status = 'active'
         ORDER BY c.display_name ASC",
        $school_id, $staff_id
    ),
    ARRAY_A
);

// Which class to view (default: first).
$view_class = absint( $_GET['class'] ?? ( $my_classes[0]['id'] ?? 0 ) );
$view_student = absint( $_GET['student'] ?? 0 );

$enrollments = \EduCBTPro\Core\Schema::table( 'enrollments' );
$stu_table   = $wpdb->prefix . 'educbt_students';

// Fetch students for the selected class.
$students = [];
if ( $view_class > 0 && $session_id > 0 ) {
    $students = (array) $wpdb->get_results(
        $wpdb->prepare(
            "SELECT st.id, st.admission_number, st.first_name, st.last_name, st.full_name,
                    st.gender, st.date_of_birth, st.class, st.status,
                    st.parent_name, st.parent_phone, st.parent_email,
                    st.home_address, st.medical_notes
             FROM {$enrollments} e
             INNER JOIN {$stu_table} st ON st.id = e.student_id
             WHERE e.school_id = %d AND e.class_id = %d AND e.session_id = %d AND e.status = 'active'
               AND st.status = 'active'
             ORDER BY st.last_name ASC, st.first_name ASC",
            $school_id, $view_class, $session_id
        ),
        ARRAY_A
    );
}

// Single student profile view.
$student_profile = null;
if ( $view_student > 0 ) {
    $student_profile = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT st.*,
                    (SELECT COUNT(*) FROM {$enrollments} e2 WHERE e2.student_id = st.id AND e2.status = 'active') AS enrolment_count,
                    c.display_name AS current_class
             FROM {$stu_table} st
             LEFT JOIN {$enrollments} e ON e.student_id = st.id AND e.status = 'active' AND e.session_id = %d
             LEFT JOIN {$classes_table} c ON c.id = e.class_id
             WHERE st.id = %d AND st.school_id = %d
             LIMIT 1",
            $session_id, $view_student, $school_id
        ),
        ARRAY_A
    );

    // Security: verify this student is in a class the teacher holds.
    if ( $student_profile ) {
        $in_my_class = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$enrollments} e
                 INNER JOIN {$assign_table} a ON a.class_id = e.class_id AND a.staff_id = %d AND a.assignment_type = 'class_teacher' AND a.status = 'active'
                 WHERE e.student_id = %d AND e.status = 'active'",
                $staff_id, $view_student
            )
        );

        if ( $in_my_class === 0 && ! $educbt['scope']->is_school_wide() ) {
            $student_profile = null;
        }
    }
}

$educbt_title = 'My Students';

$educbt_body = static function () use ( $flash, $my_classes, $view_class, $students, $student_profile, $view_student, $session ): void {
    require EDUCBT_PRO_PATH . 'templates/portal/partials/flash.php';

    if ( empty( $my_classes ) ) : ?>
        <div class="educbt-card">
            <p class="educbt-muted">You are not the class teacher of any class. The school office assigns class teacher roles under Staff.</p>
        </div>
    <?php
        return;
    endif;

    // ---- Single student profile view ----
    if ( $student_profile ) :
        $s = $student_profile;
    ?>
        <section class="educbt-card no-print">
            <a class="educbt-btn" href="<?php echo esc_url( add_query_arg( [ 'class' => $view_class ], remove_query_arg( 'student' ) ) ); ?>">&larr; Back to list</a>
        </section>

        <section class="educbt-card">
            <h2><?php echo esc_html( (string) ( $s['full_name'] ?: $s['last_name'] . ', ' . $s['first_name'] ) ); ?></h2>
            <p class="educbt-muted" style="margin-top:-6px">
                <?php echo esc_html( (string) ( $s['admission_number'] ?: '' ) ); ?>
                <?php if ( ! empty( $s['current_class'] ) ) : ?> · <?php echo esc_html( (string) $s['current_class'] ); ?><?php endif; ?>
            </p>

            <div class="educbt-grid" style="margin-top:16px">
                <div>
                    <label>First name</label>
                    <p class="educbt-muted"><?php echo esc_html( (string) ( $s['first_name'] ?: '—' ) ); ?></p>
                </div>
                <div>
                    <label>Last name</label>
                    <p class="educbt-muted"><?php echo esc_html( (string) ( $s['last_name'] ?: '—' ) ); ?></p>
                </div>
                <div>
                    <label>Gender</label>
                    <p class="educbt-muted"><?php echo esc_html( ucfirst( (string) ( $s['gender'] ?? '—' ) ) ); ?></p>
                </div>
                <div>
                    <label>Date of birth</label>
                    <p class="educbt-muted"><?php echo esc_html( (string) ( $s['date_of_birth'] ?: '—' ) ); ?></p>
                </div>
            </div>

            <h3 style="margin-top:20px;font-size:15px">Parent / Guardian</h3>
            <div class="educbt-grid">
                <div>
                    <label>Name</label>
                    <p class="educbt-muted"><?php echo esc_html( (string) ( $s['parent_name'] ?? '—' ) ); ?></p>
                </div>
                <div>
                    <label>Phone</label>
                    <p class="educbt-muted"><?php echo esc_html( (string) ( $s['parent_phone'] ?? '—' ) ); ?></p>
                </div>
                <div>
                    <label>Email</label>
                    <p class="educbt-muted"><?php echo esc_html( (string) ( $s['parent_email'] ?? '—' ) ); ?></p>
                </div>
            </div>

            <h3 style="margin-top:20px;font-size:15px">Other</h3>
            <div class="educbt-grid">
                <div>
                    <label>Home address</label>
                    <p class="educbt-muted"><?php echo esc_html( (string) ( $s['home_address'] ?? '—' ) ); ?></p>
                </div>
                <div>
                    <label>Medical notes</label>
                    <p class="educbt-muted"><?php echo esc_html( (string) ( $s['medical_notes'] ?? '—' ) ); ?></p>
                </div>
            </div>

            <details style="margin-top:16px">
                <summary style="cursor:pointer;font-weight:600;font-size:14px">Update details</summary>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="educbt-form" style="margin-top:12px">
                    <input type="hidden" name="action" value="educbt_update_student_profile">
                    <input type="hidden" name="student_id" value="<?php echo esc_attr( (string) $s['id'] ); ?>">
                    <input type="hidden" name="redirect_to" value="<?php echo esc_attr( add_query_arg( [ 'class' => $view_class, 'student' => $view_student ], home_url( '/portal/teacher/students/' ) ) ); ?>">
                    <?php wp_nonce_field( 'educbt_update_student_profile' ); ?>

                    <div class="educbt-grid">
                        <div>
                            <label for="upd_first_name">First name</label>
                            <input id="upd_first_name" name="first_name" type="text" value="<?php echo esc_attr( (string) ( $s['first_name'] ?? '' ) ); ?>">
                        </div>
                        <div>
                            <label for="upd_last_name">Last name</label>
                            <input id="upd_last_name" name="last_name" type="text" value="<?php echo esc_attr( (string) ( $s['last_name'] ?? '' ) ); ?>">
                        </div>
                        <div>
                            <label for="upd_parent_name">Parent / Guardian name</label>
                            <input id="upd_parent_name" name="parent_name" type="text" value="<?php echo esc_attr( (string) ( $s['parent_name'] ?? '' ) ); ?>">
                        </div>
                        <div>
                            <label for="upd_parent_phone">Parent phone</label>
                            <input id="upd_parent_phone" name="parent_phone" type="text" value="<?php echo esc_attr( (string) ( $s['parent_phone'] ?? '' ) ); ?>">
                        </div>
                        <div>
                            <label for="upd_parent_email">Parent email</label>
                            <input id="upd_parent_email" name="parent_email" type="email" value="<?php echo esc_attr( (string) ( $s['parent_email'] ?? '' ) ); ?>">
                        </div>
                    </div>

                    <label for="upd_home_address" style="margin-top:8px">Home address</label>
                    <textarea id="upd_home_address" name="home_address" rows="2"><?php echo esc_textarea( (string) ( $s['home_address'] ?? '' ) ); ?></textarea>

                    <label for="upd_medical_notes" style="margin-top:8px">Medical notes</label>
                    <textarea id="upd_medical_notes" name="medical_notes" rows="2"><?php echo esc_textarea( (string) ( $s['medical_notes'] ?? '' ) ); ?></textarea>

                    <button type="submit" class="educbt-btn educbt-btn--primary" style="margin-top:12px">Save changes</button>
                </form>
            </details>
        </section>

    <?php
        return;
    endif;

    // ---- Student list view ----
    ?>
    <section class="educbt-card no-print">
        <form method="get" class="educbt-form" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap">
            <div style="flex:1 1 260px">
                <label for="class">Class</label>
                <select id="class" name="class" onchange="this.form.submit()">
                    <?php foreach ( $my_classes as $c ) : ?>
                        <option value="<?php echo esc_attr( (string) $c['id'] ); ?>" <?php selected( (int) $c['id'], $view_class ); ?>>
                            <?php echo esc_html( (string) $c['display_name'] ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <span class="educbt-muted"><?php echo esc_html( (string) ( $session['title'] ?? '' ) ); ?></span>
        </form>
    </section>

    <?php if ( empty( $students ) ) : ?>
        <div class="educbt-card">
            <p class="educbt-muted">No students enrolled in this class for the current session.</p>
        </div>
    <?php else : ?>
        <section class="educbt-card">
            <h2>Students <span class="educbt-muted">(<?php echo esc_html( (string) count( $students ) ); ?>)</span></h2>
            <div style="overflow-x:auto">
                <table class="educbt-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Name</th>
                            <th>Admission No.</th>
                            <th>Parent / Guardian</th>
                            <th>Phone</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $students as $i => $st ) : ?>
                            <tr>
                                <td><?php echo esc_html( (string) ( $i + 1 ) ); ?></td>
                                <td style="white-space:nowrap">
                                    <a href="<?php echo esc_url( add_query_arg( [ 'class' => $view_class, 'student' => (int) $st['id'] ] ) ); ?>" style="font-weight:600;color:var(--edu-accent)">
                                        <?php echo esc_html( (string) ( $st['full_name'] ?: $st['last_name'] . ', ' . $st['first_name'] ) ); ?>
                                    </a>
                                </td>
                                <td><?php echo esc_html( (string) ( $st['admission_number'] ?: '—' ) ); ?></td>
                                <td><?php echo esc_html( (string) ( $st['parent_name'] ?? '—' ) ); ?></td>
                                <td><?php echo esc_html( (string) ( $st['parent_phone'] ?? '—' ) ); ?></td>
                                <td>
                                    <a class="educbt-btn" href="<?php echo esc_url( add_query_arg( [ 'class' => $view_class, 'student' => (int) $st['id'] ] ) ); ?>">View profile</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php
    endif;
};

require EDUCBT_PRO_PATH . 'templates/portal/shell.php';
