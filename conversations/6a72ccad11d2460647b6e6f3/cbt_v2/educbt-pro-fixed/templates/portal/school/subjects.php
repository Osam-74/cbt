<?php
/**
 * Subjects. Seeded with the standard WAEC/BECE set; this screen is for the ones a
 * school adds or removes on top of that.
 *
 * @var array<string,mixed> $educbt
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

global $wpdb;

$school_id = (int) $educbt['school_id'];
$flash     = \EduCBTPro\Frontend\PortalActions::flash();

$subjects_table = \EduCBTPro\Core\Schema::table( 'subjects_v2' );
$dept_table     = \EduCBTPro\Core\Schema::table( 'departments' );

$departments = (array) $wpdb->get_results(
    $wpdb->prepare( "SELECT id, name FROM {$dept_table} WHERE school_id = %d ORDER BY sort_order ASC", $school_id ),
    ARRAY_A
);

$subjects = (array) $wpdb->get_results(
    $wpdb->prepare(
        "SELECT s.*, d.name AS department FROM {$subjects_table} s
         LEFT JOIN {$dept_table} d ON d.id = s.department_id
         WHERE s.school_id = %d AND s.status = 'active'
         ORDER BY s.stage ASC, s.is_compulsory DESC, s.name ASC",
        $school_id
    ),
    ARRAY_A
);

// Who teaches what. Without this the subject list says nothing about whether a
// subject actually has anyone responsible for it.
$assign_tbl  = \EduCBTPro\Core\Schema::table( 'staff_assignments' );
$staff_tbl   = \EduCBTPro\Core\Schema::table( 'staff' );
$classes_tbl = \EduCBTPro\Core\Schema::table( 'classes' );

$teachers = [];

foreach ( (array) $wpdb->get_results(
    $wpdb->prepare(
        "SELECT a.subject_id, CONCAT(st.first_name, ' ', st.last_name) AS teacher, c.display_name AS class_name
         FROM {$assign_tbl} a
         INNER JOIN {$staff_tbl} st ON st.id = a.staff_id
         LEFT JOIN {$classes_tbl} c ON c.id = a.class_id
         WHERE a.school_id = %d AND a.status = 'active' AND a.assignment_type = 'subject_teacher'
         ORDER BY st.last_name ASC",
        $school_id
    ),
    ARRAY_A
) as $row ) {
    $teachers[ (int) $row['subject_id'] ][ (string) $row['teacher'] ][] = (string) $row['class_name'];
}

$educbt_title = 'Subjects';

$educbt_body = static function () use ( $flash, $subjects, $departments, $teachers ): void {
    require EDUCBT_PRO_PATH . 'templates/portal/partials/flash.php';
    ?>
    <section class="educbt-card">
        <h2>Add a subject</h2>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="educbt-form">
            <input type="hidden" name="action" value="educbt_save_subject">
            <?php wp_nonce_field( 'educbt_save_subject' ); ?>
            <div class="educbt-grid">
                <div><label for="name">Subject name *</label><input id="name" name="name" type="text" required></div>
                <div><label for="code">Code</label><input id="code" name="code" type="text" placeholder="auto"></div>
                <div>
                    <label for="stage">Level</label>
                    <select id="stage" name="stage">
                        <option value="both">Junior and senior</option>
                        <option value="junior">Junior only</option>
                        <option value="senior">Senior only</option>
                    </select>
                </div>
                <div>
                    <label for="department_id">Department</label>
                    <select id="department_id" name="department_id">
                        <option value="">None (open to all)</option>
                        <?php foreach ( $departments as $d ) : ?>
                            <option value="<?php echo esc_attr( (string) $d['id'] ); ?>"><?php echo esc_html( (string) $d['name'] ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <label style="display:flex;align-items:center;gap:8px;margin-top:12px;font-weight:400">
                <input type="checkbox" name="is_compulsory" value="1" style="width:auto"> Every student must offer this
            </label>
            <button type="submit" class="educbt-btn educbt-btn--primary" style="margin-top:16px">Add subject</button>
        </form>
    </section>

    <section class="educbt-card">
        <h2>Subjects <span class="educbt-muted">(<?php echo esc_html( (string) count( $subjects ) ); ?>)</span></h2>
        <table class="educbt-table">
            <thead><tr><th>Subject</th><th>Code</th><th>Level</th><th>Department</th><th>Compulsory</th><th>Taught by</th></tr></thead>
            <tbody>
            <?php foreach ( $subjects as $s ) : ?>
                <tr>
                    <td><?php echo esc_html( (string) $s['name'] ); ?></td>
                    <td><code><?php echo esc_html( (string) $s['code'] ); ?></code></td>
                    <td><?php echo esc_html( ucfirst( (string) $s['stage'] ) ); ?></td>
                    <td><?php echo esc_html( (string) ( $s['department'] ?: '—' ) ); ?></td>
                    <td><?php echo ! empty( $s['is_compulsory'] ) ? 'Yes' : '<span class="educbt-muted">No</span>'; ?></td>
                    <td>
                        <?php $who = $teachers[ (int) $s['id'] ] ?? []; ?>
                        <?php if ( empty( $who ) ) : ?>
                            <span class="educbt-muted">nobody assigned</span>
                        <?php else : ?>
                            <?php foreach ( $who as $name => $class_list ) : ?>
                                <div style="font-size:13px">
                                    <?php echo esc_html( $name ); ?>
                                    <span class="educbt-muted">— <?php echo esc_html( implode( ', ', array_filter( $class_list ) ) ); ?></span>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </section>
    <?php
};

require EDUCBT_PRO_PATH . 'templates/portal/shell.php';
