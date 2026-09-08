<?php
/**
 * Class teacher — subject registration.
 *
 * Two jobs, both of which used to have no screen at all:
 *
 *  - Register the compulsory subjects for the whole class in one action.
 *  - Open a single student and add or remove their electives.
 *
 * Registration is not paperwork. A student's registered subjects are what decide
 * which CA tests and which examinations appear on their portal, so a student
 * registered in nothing sees nothing.
 *
 * @var array<string,mixed> $educbt
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

global $wpdb;

$school_id = (int) $educbt['school_id'];
$actor     = $educbt['scope']->actor();
$flash     = \EduCBTPro\Frontend\PortalActions::flash();

$structure  = new \EduCBTPro\Services\AcademicStructureService();
$session    = ( new \EduCBTPro\Services\AcademicYearService() )->current_session( $school_id );
$session_id = (int) ( $session['id'] ?? 0 );

$classes_table = \EduCBTPro\Core\Schema::table( 'classes' );
$assign_table  = \EduCBTPro\Core\Schema::table( 'staff_assignments' );
$enrol_table   = \EduCBTPro\Core\Schema::table( 'enrollments' );
$students_tbl  = $wpdb->prefix . 'educbt_students';

// Classes this teacher is responsible for. School-wide staff see all of them.
if ( $educbt['scope']->is_school_wide() ) {
    $my_classes = (array) $wpdb->get_results(
        $wpdb->prepare(
            "SELECT id, display_name FROM {$classes_table}
             WHERE school_id = %d AND status = 'active' ORDER BY display_name ASC",
            $school_id
        ),
        ARRAY_A
    );
} else {
    $my_classes = (array) $wpdb->get_results(
        $wpdb->prepare(
            "SELECT DISTINCT c.id, c.display_name
             FROM {$assign_table} a
             INNER JOIN {$classes_table} c ON c.id = a.class_id
             WHERE a.school_id = %d AND a.staff_id = %d AND a.status = 'active'
               AND a.assignment_type = 'class_teacher' AND c.status = 'active'
             ORDER BY c.display_name ASC",
            $school_id,
            (int) $actor['id']
        ),
        ARRAY_A
    );
}

$class_id = absint( $_GET['class'] ?? ( $my_classes[0]['id'] ?? 0 ) );

$students = [];
$class_row = [];
$split     = [ 'core' => [], 'electives' => [], 'rules' => [ 'min' => 0, 'max' => 0 ] ];

if ( $class_id > 0 && $session_id > 0 ) {
    $class_row = (array) $wpdb->get_row(
        $wpdb->prepare(
            "SELECT id, display_name, level_id, department_id FROM {$classes_table}
             WHERE id = %d AND school_id = %d",
            $class_id,
            $school_id
        ),
        ARRAY_A
    );

    if ( $class_row ) {
        $split = $structure->offering_split(
            $school_id,
            absint( $class_row['level_id'] ),
            $class_row['department_id'] !== null ? absint( $class_row['department_id'] ) : null
        );

        $registered_tbl = \EduCBTPro\Core\Schema::table( 'student_subjects' );

        $students = (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT st.id, st.first_name, st.last_name, st.admission_number,
                        (SELECT COUNT(*) FROM {$registered_tbl} r
                          WHERE r.student_id = st.id AND r.session_id = %d) AS registered
                 FROM {$enrol_table} e
                 INNER JOIN {$students_tbl} st ON st.id = e.student_id
                 WHERE e.school_id = %d AND e.class_id = %d AND e.session_id = %d AND e.status = 'active'
                 ORDER BY st.last_name ASC, st.first_name ASC",
                $session_id,
                $school_id,
                $class_id,
                $session_id
            ),
            ARRAY_A
        );
    }
}

// One student opened for individual editing.
$edit_student_id = absint( $_GET['student'] ?? 0 );
$edit_view       = [];

if ( $edit_student_id > 0 && $session_id > 0 ) {
    $edit_view = $structure->student_registration_view( $school_id, $edit_student_id, $session_id );
}

// ── Idempotency: is everyone already registered? ──────────────────
$core_count      = count( (array) ( $split['core'] ?? [] ) );
$all_registered  = false;

if ( $core_count > 0 && ! empty( $students ) ) {
    $all_registered = true;
    foreach ( $students as $st ) {
        if ( (int) $st['registered'] < $core_count ) {
            $all_registered = false;
            break;
        }
    }
}

$educbt_title = 'Subject Registration';

$educbt_body = static function () use (
    $flash, $my_classes, $class_id, $class_row, $students, $split, $edit_student_id, $edit_view, $core_count, $all_registered
): void {
    require EDUCBT_PRO_PATH . 'templates/portal/partials/flash.php';

    if ( empty( $my_classes ) ) {
        echo '<div class="educbt-card"><p class="educbt-muted">You are not the class teacher for any class, so there is nothing to register here.</p></div>';
        return;
    }
    ?>
    <section class="educbt-card no-print">
        <form method="get" class="educbt-form" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap">
            <div style="flex:1 1 240px">
                <label for="class">Class</label>
                <select id="class" name="class" onchange="this.form.submit()">
                    <?php foreach ( $my_classes as $c ) : ?>
                        <option value="<?php echo esc_attr( (string) $c['id'] ); ?>" <?php selected( (int) $c['id'], $class_id ); ?>>
                            <?php echo esc_html( (string) $c['display_name'] ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </form>
    </section>

    <?php if ( empty( $class_row ) ) : ?>
        <div class="educbt-card"><p class="educbt-muted">Choose a class.</p></div>
        <?php return; ?>
    <?php endif; ?>

    <section class="educbt-card">
        <h2>Compulsory subjects for <?php echo esc_html( (string) $class_row['display_name'] ); ?></h2>

        <?php if ( empty( $split['core'] ) ) : ?>
            <p class="educbt-note educbt-note--warn">
                This class level has no compulsory subjects defined. The school office sets these under Subjects.
            </p>
        <?php else : ?>
            <ul class="educbt-list">
                <?php foreach ( (array) $split['core'] as $s ) : ?>
                    <li><span><?php echo esc_html( (string) $s['name'] ); ?></span></li>
                <?php endforeach; ?>
            </ul>

            <?php
            // "Generally": apply the core set to everyone at once. Electives each
            // student has already chosen are left untouched — only what is missing
            // is added.
            ?>
            <?php if ( $all_registered ) : ?>
                <p class="educbt-note educbt-note--ok" style="margin-top:12px">
                    ✓ Fully registered — every student in this class already has all <?php echo esc_html( (string) $core_count ); ?> compulsory subjects.
                </p>
            <?php else : ?>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:12px">
                    <input type="hidden" name="action" value="educbt_register_class_subjects">
                    <input type="hidden" name="class_id" value="<?php echo esc_attr( (string) $class_id ); ?>">
                    <?php wp_nonce_field( 'educbt_register_class_subjects' ); ?>
                    <button type="submit" class="educbt-btn educbt-btn--primary">
                        Register these for the whole class
                    </button>
                    <p class="educbt-muted" style="margin-top:6px">
                        Adds anything missing. Electives students have already chosen stay as they are.
                    </p>
                </form>
            <?php endif; ?>
        <?php endif; ?>
    </section>

    <section class="educbt-card">
        <h2>Students <span class="educbt-muted">(<?php echo esc_html( (string) count( $students ) ); ?>)</span></h2>

        <?php if ( empty( $students ) ) : ?>
            <p class="educbt-muted">No active students in this class for the current session.</p>
        <?php else : ?>
            <table class="educbt-table">
                <thead><tr><th>Student</th><th>Student ID</th><th>Subjects registered</th><th class="no-print"></th></tr></thead>
                <tbody>
                <?php foreach ( $students as $st ) : ?>
                    <tr>
                        <td><?php echo esc_html( trim( (string) $st['first_name'] . ' ' . (string) $st['last_name'] ) ); ?></td>
                        <td><?php echo esc_html( (string) $st['admission_number'] ); ?></td>
                        <td>
                            <?php if ( (int) $st['registered'] === 0 ) : ?>
                                <span class="educbt-pill educbt-pill--draft">none yet</span>
                            <?php elseif ( (int) $st['registered'] >= $core_count ) : ?>
                                <?php echo esc_html( (string) (int) $st['registered'] ); ?>
                                <span class="educbt-pill educbt-pill--approved" style="margin-left:6px">✓</span>
                            <?php else : ?>
                                <?php echo esc_html( (string) (int) $st['registered'] ); ?>
                                <span class="educbt-pill educbt-pill--pending" style="margin-left:6px">incomplete</span>
                            <?php endif; ?>
                        </td>
                        <td class="no-print">
                            <a class="educbt-btn"
                               href="<?php echo esc_url( add_query_arg( [ 'class' => $class_id, 'student' => (int) $st['id'] ] ) ); ?>#student">
                                Edit subjects
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>

    <?php if ( $edit_student_id > 0 && empty( $edit_view['error'] ) ) : ?>
        <section class="educbt-card" id="student">
            <h2>Electives — <?php echo esc_html( (string) ( $edit_view['class'] ?? '' ) ); ?></h2>
            <p class="educbt-muted">
                <?php echo esc_html( sprintf(
                    '%d of %d–%d subjects registered.',
                    (int) $edit_view['selected_total'],
                    (int) $edit_view['minimum'],
                    (int) $edit_view['maximum']
                ) ); ?>
            </p>

            <?php if ( empty( $edit_view['electives'] ) ) : ?>
                <p class="educbt-muted">This level has no electives — every subject is compulsory.</p>
            <?php else : ?>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <input type="hidden" name="action" value="educbt_register_student_subjects">
                    <input type="hidden" name="student_id" value="<?php echo esc_attr( (string) $edit_student_id ); ?>">
                    <?php wp_nonce_field( 'educbt_register_student_subjects' ); ?>

                    <ul class="educbt-list">
                        <?php foreach ( (array) $edit_view['electives'] as $s ) : ?>
                            <li>
                                <label style="display:flex;align-items:center;gap:10px;width:100%;font-weight:400;cursor:pointer">
                                    <input type="checkbox" name="elective_ids[]"
                                           value="<?php echo esc_attr( (string) (int) $s['id'] ); ?>"
                                           <?php checked( ! empty( $s['selected'] ) ); ?>
                                           style="width:auto">
                                    <span style="flex:1"><?php echo esc_html( (string) $s['name'] ); ?></span>
                                </label>
                            </li>
                        <?php endforeach; ?>
                    </ul>

                    <button type="submit" class="educbt-btn educbt-btn--primary" style="margin-top:12px">Save subjects</button>
                    <p class="educbt-muted" style="margin-top:6px">
                        Compulsory subjects are not listed here — they cannot be removed. A subject
                        already examined or marked cannot be dropped either.
                    </p>
                </form>
            <?php endif; ?>
        </section>
    <?php elseif ( $edit_student_id > 0 ) : ?>
        <section class="educbt-card" id="student">
            <p class="educbt-note educbt-note--warn">That student is not enrolled in a class for the current session.</p>
        </section>
    <?php endif; ?>
    <?php
};

require EDUCBT_PRO_PATH . 'templates/portal/shell.php';
