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

// ── Filters (GET-driven) ────────────────────────────────────────────
$filter_stage      = sanitize_text_field( (string) ( $_GET['stage'] ?? 'all' ) );
$filter_assigned    = sanitize_text_field( (string) ( $_GET['assigned'] ?? 'all' ) );
$filter_department = absint( $_GET['department_id'] ?? 0 );

// Build the WHERE clause based on filters
$where  = "s.school_id = %d AND s.status = 'active'";
$params = [ $school_id ];

if ( $filter_stage === 'junior' ) {
    $where .= " AND s.stage IN ('junior', 'both')";
} elseif ( $filter_stage === 'senior' ) {
    $where .= " AND s.stage IN ('senior', 'both')";
}

if ( $filter_department > 0 ) {
    $where .= " AND s.department_id = %d";
    $params[] = $filter_department;
}

if ( $filter_assigned === 'assigned' || $filter_assigned === 'unassigned' ) {
    $assign_tbl = \EduCBTPro\Core\Schema::table( 'staff_assignments' );
    if ( $filter_assigned === 'assigned' ) {
        $where .= " AND EXISTS (SELECT 1 FROM {$assign_tbl} a WHERE a.subject_id = s.id AND a.school_id = s.school_id AND a.status = 'active' AND a.assignment_type = 'subject_teacher')";
    } else {
        $where .= " AND NOT EXISTS (SELECT 1 FROM {$assign_tbl} a WHERE a.subject_id = s.id AND a.school_id = s.school_id AND a.status = 'active' AND a.assignment_type = 'subject_teacher')";
    }
}

$subjects = (array) $wpdb->get_results(
    $wpdb->prepare(
        "SELECT s.*, d.name AS department FROM {$subjects_table} s
         LEFT JOIN {$dept_table} d ON d.id = s.department_id
         WHERE {$where}
         ORDER BY s.stage ASC, s.is_compulsory DESC, s.name ASC",
        ...$params
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

// Should the "load the standard list" card be offered?
//
// Hiding it on the flag alone was wrong. The flag is set as soon as the seeder
// runs — including a run that added nothing — so a school left with an empty
// subject list had the one control that could fix it hidden from them.
//
// What actually matters is whether the school HAS subjects. A school with none
// always needs this card, whatever the flag says. A school that already has its
// list does not.
$active_subject_count = absint(
    $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM {$subjects_table} WHERE school_id = %d AND status = 'active'",
            $school_id
        )
    )
);

$standard_loaded = $active_subject_count > 0
    && get_option( 'educbt_subjects_seeded_' . $school_id, '' ) === 'yes';

$educbt_body = static function () use ( $flash, $subjects, $departments, $teachers, $filter_stage, $filter_assigned, $filter_department, $standard_loaded, $active_subject_count ): void {
    require EDUCBT_PRO_PATH . 'templates/portal/partials/flash.php';
    ?>
    <?php
    // Seeding fires only for a brand new school, so a school onboarded earlier keeps
    // whatever list it started with. This is the deliberate way to adopt the
    // standard offering — explicit, confirmed, and never automatic.
    //
    // Once the standard list is in place the card has nothing left to offer, so it
    // goes. Leaving a "load the standard list" button on a screen that already shows
    // the standard list invites somebody to press it again and wonder what happened.
    ?>
    <?php if ( ! $standard_loaded ) : ?>
    <section class="educbt-card">
        <h2>Standard subject list</h2>
        <?php if ( $active_subject_count === 0 ) : ?>
            <p class="educbt-note educbt-note--warn">
                This school currently has no active subjects. Load the standard list to
                get started — any subject retired earlier will be brought back in place,
                keeping the results already attached to it.
            </p>
        <?php endif; ?>
        <p class="educbt-muted">
            Replace the list below with the standard NERDC offering — junior and senior
            subjects, cores marked compulsory, senior subjects grouped by department.
            Codes distinguish the levels, so junior Mathematics (MTH-J) and senior
            General Mathematics (MTH) are never confused.
        </p>
        <p class="educbt-muted">
            Subjects already carrying results, scores, registrations or questions are
            <strong>retired, not deleted</strong> — deleting them would make past report
            cards unreadable. Only unused subjects are removed.
        </p>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
              onsubmit="return confirm('Replace the subject list with the standard offering? Subjects already in use will be retired rather than deleted.');">
            <input type="hidden" name="action" value="educbt_refresh_subjects">
            <?php wp_nonce_field( 'educbt_refresh_subjects' ); ?>
            <button type="submit" class="educbt-btn">Load standard subject list</button>
        </form>
    </section>
    <?php endif; ?>

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

        <!-- Filters -->
        <form method="get" action="" style="margin-bottom:16px;display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end">
            <input type="hidden" name="p" value="educbt_portal">
            <input type="hidden" name="section" value="subjects">
            <div>
                <label style="font-size:.8rem;font-weight:600;display:block;margin-bottom:4px">Assigned</label>
                <select name="assigned" onchange="this.form.submit()">
                    <option value="all" <?php selected( $filter_assigned, 'all' ); ?>>All subjects</option>
                    <option value="assigned" <?php selected( $filter_assigned, 'assigned' ); ?>>Has teacher</option>
                    <option value="unassigned" <?php selected( $filter_assigned, 'unassigned' ); ?>>No teacher yet</option>
                </select>
            </div>
            <div>
                <label style="font-size:.8rem;font-weight:600;display:block;margin-bottom:4px">Level</label>
                <select name="stage" id="stageFilter" onchange="educbtToggleDeptFilter(); this.form.submit()">
                    <option value="all" <?php selected( $filter_stage, 'all' ); ?>>All levels</option>
                    <option value="junior" <?php selected( $filter_stage, 'junior' ); ?>>Junior</option>
                    <option value="senior" <?php selected( $filter_stage, 'senior' ); ?>>Senior</option>
                </select>
            </div>
            <div id="deptFilterWrap" style="<?php echo $filter_stage === 'senior' ? '' : 'display:none'; ?>">
                <label style="font-size:.8rem;font-weight:600;display:block;margin-bottom:4px">Department</label>
                <select name="department_id" onchange="this.form.submit()">
                    <option value="0" <?php selected( $filter_department, 0 ); ?>>All departments</option>
                    <?php foreach ( $departments as $d ) : ?>
                        <option value="<?php echo esc_attr( (string) $d['id'] ); ?>" <?php selected( $filter_department, (int) $d['id'] ); ?>><?php echo esc_html( (string) $d['name'] ); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <noscript><button type="submit" class="educbt-btn">Apply</button></noscript>
        </form>
        <script>
        function educbtToggleDeptFilter() {
            var stage = document.getElementById('stageFilter');
            var wrap = document.getElementById('deptFilterWrap');
            if (stage && wrap) {
                wrap.style.display = (stage.value === 'senior') ? '' : 'none';
                if (stage.value !== 'senior') {
                    var sel = wrap.querySelector('select');
                    if (sel) sel.value = '0';
                }
            }
        }
        </script>

        <table class="educbt-table">
            <thead><tr><th>Subject</th><th>Code</th><th>Level</th><th>Department</th><th>Compulsory</th><th>Taught by</th><th></th></tr></thead>
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
                    <td style="white-space:nowrap">
                        <button type="button" class="educbt-btn" style="font-size:.8rem;padding:3px 10px"
                                onclick="var r=document.getElementById('subj-<?php echo esc_attr( (string) (int) $s['id'] ); ?>');r.toggleAttribute('hidden');">Edit</button>
                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline"
                              onsubmit="return confirm('Remove <?php echo esc_js( (string) $s['name'] ); ?>? If it already carries results or questions it will be retired rather than deleted, so existing records stay readable.');">
                            <input type="hidden" name="action" value="educbt_delete_subject">
                            <input type="hidden" name="subject_id" value="<?php echo esc_attr( (string) (int) $s['id'] ); ?>">
                            <?php wp_nonce_field( 'educbt_delete_subject' ); ?>
                            <button type="submit" class="educbt-btn" style="font-size:.8rem;padding:3px 10px;color:#b91c1c;border-color:#fca5a5">Remove</button>
                        </form>
                    </td>
                </tr>

                <?php // Inline edit, same handler as creation so the two cannot drift apart. ?>
                <tr id="subj-<?php echo esc_attr( (string) (int) $s['id'] ); ?>" hidden>
                    <td colspan="7" style="background:var(--edu-bg)">
                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
                              class="educbt-form" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;padding:12px 0">
                            <input type="hidden" name="action" value="educbt_save_subject">
                            <input type="hidden" name="subject_id" value="<?php echo esc_attr( (string) (int) $s['id'] ); ?>">
                            <?php wp_nonce_field( 'educbt_save_subject' ); ?>
                            <div>
                                <label>Subject name</label>
                                <input name="name" type="text" value="<?php echo esc_attr( (string) $s['name'] ); ?>" required>
                            </div>
                            <div>
                                <label>Code</label>
                                <input name="code" type="text" value="<?php echo esc_attr( (string) $s['code'] ); ?>" style="max-width:110px">
                            </div>
                            <div>
                                <label>Level</label>
                                <select name="stage">
                                    <?php foreach ( [ 'both' => 'Both', 'junior' => 'Junior', 'senior' => 'Senior' ] as $val => $lbl ) : ?>
                                        <option value="<?php echo esc_attr( $val ); ?>" <?php selected( (string) $s['stage'], $val ); ?>><?php echo esc_html( $lbl ); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label>Department</label>
                                <select name="department_id">
                                    <option value="0">All / none</option>
                                    <?php foreach ( $departments as $d ) : ?>
                                        <option value="<?php echo esc_attr( (string) $d['id'] ); ?>" <?php selected( (int) ( $s['department_id'] ?? 0 ), (int) $d['id'] ); ?>>
                                            <?php echo esc_html( (string) $d['name'] ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label style="display:flex;align-items:center;gap:7px;font-weight:400">
                                    <input type="checkbox" name="is_compulsory" value="1" <?php checked( ! empty( $s['is_compulsory'] ) ); ?> style="width:auto">
                                    Compulsory
                                </label>
                            </div>
                            <button type="submit" class="educbt-btn educbt-btn--primary">Save changes</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </section>
    <?php
};

require EDUCBT_PRO_PATH . 'templates/portal/shell.php';
