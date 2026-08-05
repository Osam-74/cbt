<?php
/**
 * Classes — create arms of each level, see who teaches them.
 *
 * Nothing else works until this does: students enrol into a class, papers are set
 * for a class, and results are compiled per class.
 *
 * @var array<string,mixed> $educbt
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

global $wpdb;

$school_id = (int) $educbt['school_id'];
$flash     = \EduCBTPro\Frontend\PortalActions::flash();

$structure = new \EduCBTPro\Services\AcademicStructureService();
$session   = ( new \EduCBTPro\Services\AcademicYearService() )->current_session( $school_id );
$session_id = (int) ( $session['id'] ?? 0 );

$levels = (array) $wpdb->get_results(
    $wpdb->prepare(
        'SELECT id, name, code, stage FROM ' . \EduCBTPro\Core\Schema::table( 'class_levels' ) .
        ' WHERE school_id = %d ORDER BY level_order ASC',
        $school_id
    ),
    ARRAY_A
);

$departments = (array) $wpdb->get_results(
    $wpdb->prepare(
        'SELECT id, name FROM ' . \EduCBTPro\Core\Schema::table( 'departments' ) .
        ' WHERE school_id = %d ORDER BY sort_order ASC',
        $school_id
    ),
    ARRAY_A
);

$classes_table = \EduCBTPro\Core\Schema::table( 'classes' );
$levels_table  = \EduCBTPro\Core\Schema::table( 'class_levels' );
$enrol_table   = \EduCBTPro\Core\Schema::table( 'enrollments' );
$assign_table  = \EduCBTPro\Core\Schema::table( 'staff_assignments' );
$staff_table   = \EduCBTPro\Core\Schema::table( 'staff' );
$dept_table    = \EduCBTPro\Core\Schema::table( 'departments' );

$classes = (array) $wpdb->get_results(
    $wpdb->prepare(
        "SELECT c.id, c.display_name, c.arm, c.capacity, l.name AS level_name, l.level_order, d.name AS department,
                (SELECT COUNT(*) FROM {$enrol_table} e WHERE e.class_id = c.id AND e.session_id = %d AND e.status = 'active') AS students,
                (SELECT CONCAT(s.first_name, ' ', s.last_name) FROM {$assign_table} a
                   INNER JOIN {$staff_table} s ON s.id = a.staff_id
                  WHERE a.class_id = c.id AND a.assignment_type = 'class_teacher' AND a.status = 'active' LIMIT 1) AS class_teacher
         FROM {$classes_table} c
         INNER JOIN {$levels_table} l ON l.id = c.level_id
         LEFT JOIN {$dept_table} d ON d.id = c.department_id
         WHERE c.school_id = %d AND c.status = 'active'
         ORDER BY l.level_order ASC, c.arm ASC",
        $session_id,
        $school_id
    ),
    ARRAY_A
);

$educbt_title = 'Classes';

$educbt_body = static function () use ( $flash, $levels, $departments, $classes, $session ): void {
    require EDUCBT_PRO_PATH . 'templates/portal/partials/flash.php';
    ?>
    <section class="educbt-card">
        <h2>Create classes</h2>
        <p class="educbt-muted" style="margin-top:-6px">
            Add every arm of a level at once — type <code>A, B, C</code>. Leave the arms
            box empty if the level has only one class.
        </p>

        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="educbt-form">
            <input type="hidden" name="action" value="educbt_create_classes">
            <?php wp_nonce_field( 'educbt_create_classes' ); ?>

            <div class="educbt-grid">
                <div>
                    <label for="level_id">Level *</label>
                    <select id="level_id" name="level_id" required>
                        <option value="">Choose a level</option>
                        <?php foreach ( $levels as $level ) : ?>
                            <option value="<?php echo esc_attr( (string) $level['id'] ); ?>" data-stage="<?php echo esc_attr( (string) $level['stage'] ); ?>">
                                <?php echo esc_html( (string) $level['name'] ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="arms">Arms</label>
                    <input id="arms" name="arms" type="text" placeholder="A, B, C">
                </div>
                <div>
                    <label for="department_id">Department</label>
                    <select id="department_id" name="department_id">
                        <option value="">None</option>
                        <?php foreach ( $departments as $department ) : ?>
                            <option value="<?php echo esc_attr( (string) $department['id'] ); ?>"><?php echo esc_html( (string) $department['name'] ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <button type="submit" class="educbt-btn educbt-btn--primary" style="margin-top:16px">Create classes</button>
        </form>
        <script>
        // Auto-switch department to None when a JSS class level is chosen.
        document.getElementById('level_id')?.addEventListener('change', function() {
            var isJss = /JSS/i.test(this.options[this.selectedIndex].text);
            var dept = document.getElementById('department_id');
            if (isJss) {
                dept.value = '';
                dept.disabled = true;
            } else {
                dept.disabled = false;
            }
        });
        // Trigger on load
        document.getElementById('level_id')?.dispatchEvent(new Event('change'));
        </script>
    </section>

    <section class="educbt-card">
        <h2>Classes <span class="educbt-muted">(<?php echo esc_html( (string) count( $classes ) ); ?>)</span></h2>

        <?php if ( empty( $classes ) ) : ?>
            <p class="educbt-muted">No classes yet. Create some above — students cannot be registered until a class exists.</p>
        <?php else : ?>
            <table class="educbt-table">
                <thead><tr><th>Class</th><th>Department</th><th>Class teacher</th><th>Students</th><th></th></tr></thead>
                <tbody>
                <?php foreach ( $classes as $class ) :
                    $cid = (int) $class['id']; ?>
                    <tr>
                        <td><strong><?php echo esc_html( (string) $class['display_name'] ); ?></strong></td>
                        <td><?php echo esc_html( (string) ( $class['department'] ?: '—' ) ); ?></td>
                        <td>
                            <?php if ( ! empty( $class['class_teacher'] ) ) : ?>
                                <?php echo esc_html( trim( (string) $class['class_teacher'] ) ); ?>
                            <?php else : ?>
                                <span class="educbt-muted">not assigned</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html( (string) $class['students'] ); ?></td>
                        <td style="white-space:nowrap">
                            <button type="button" class="educbt-btn"
                                    onclick="document.getElementById('c-<?php echo esc_attr( (string) $cid ); ?>').toggleAttribute('hidden')">Edit</button>
                        </td>
                    </tr>
                    <tr id="c-<?php echo esc_attr( (string) $cid ); ?>" hidden>
                        <td colspan="5" style="background:var(--edu-bg)">
                            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="educbt-form" style="padding:12px 0">
                                <input type="hidden" name="action" value="educbt_update_class">
                                <input type="hidden" name="class_id" value="<?php echo esc_attr( (string) $cid ); ?>">
                                <?php wp_nonce_field( 'educbt_update_class' ); ?>
                                <div class="educbt-grid">
                                    <div><label>Arm</label><input name="arm" type="text" value="<?php echo esc_attr( (string) $class['arm'] ); ?>"></div>
                                    <div><label>Capacity <span class="educbt-muted">(0 = no limit)</span></label>
                                        <input name="capacity" type="number" min="0" value="<?php echo esc_attr( (string) ( $class['capacity'] ?? 0 ) ); ?>"></div>
                                    <div>
                                        <label>Department</label>
                                        <select name="department_id">
                                            <option value="">None</option>
                                            <?php foreach ( $departments as $d ) : ?>
                                                <option value="<?php echo esc_attr( (string) $d['id'] ); ?>" <?php selected( (string) $d['name'], (string) $class['department'] ); ?>>
                                                    <?php echo esc_html( (string) $d['name'] ); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <button type="submit" class="educbt-btn educbt-btn--primary" style="margin-top:12px">Save changes</button>
                            </form>

                            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
                                  style="border-top:1px solid var(--edu-line);padding-top:12px"
                                  onsubmit="return confirm('Remove this class?');">
                                <input type="hidden" name="action" value="educbt_remove_class">
                                <input type="hidden" name="class_id" value="<?php echo esc_attr( (string) $cid ); ?>">
                                <?php wp_nonce_field( 'educbt_remove_class' ); ?>
                                <button type="submit" class="educbt-btn" style="color:#b91c1c;border-color:#f3c9c9">Remove class</button>
                                <?php if ( (int) $class['students'] > 0 ) : ?>
                                    <span class="educbt-muted" style="margin-left:8px">Move the <?php echo esc_html( (string) $class['students'] ); ?> student(s) out first.</span>
                                <?php endif; ?>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>
    <?php
};

require EDUCBT_PRO_PATH . 'templates/portal/shell.php';