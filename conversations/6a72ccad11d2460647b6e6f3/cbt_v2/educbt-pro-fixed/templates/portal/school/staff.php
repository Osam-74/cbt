<?php
/**
 * Staff — add a teacher, then give them a class or a subject.
 *
 * Creating a teacher and assigning them are two separate acts, done by different
 * people at different times. That separation is the whole reason the role model can
 * express "a teacher who has not been given anything yet", which is the correct
 * state for a new hire.
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

$classes = $structure->list_classes( $school_id );

// Subjects carry their stage and department so the picker can narrow itself to the
// chosen class. Offering all 42 subjects for a JSS1 class is how a teacher ends up
// assigned to Further Mathematics in Primary 4.
$subjects = (array) $wpdb->get_results(
    $wpdb->prepare(
        'SELECT id, name, code, stage, department_id FROM ' . \EduCBTPro\Core\Schema::table( 'subjects_v2' ) .
        " WHERE school_id = %d AND status = 'active' ORDER BY name ASC",
        $school_id
    ),
    ARRAY_A
);

$class_meta = [];

foreach ( (array) $wpdb->get_results(
    $wpdb->prepare(
        'SELECT c.id, c.department_id, l.stage FROM ' . \EduCBTPro\Core\Schema::table( 'classes' ) . ' c
         INNER JOIN ' . \EduCBTPro\Core\Schema::table( 'class_levels' ) . " l ON l.id = c.level_id
         WHERE c.school_id = %d AND c.status = 'active'",
        $school_id
    ),
    ARRAY_A
) as $row ) {
    $class_meta[ (int) $row['id'] ] = [
        'stage'      => (string) $row['stage'],
        'department' => (int) $row['department_id'],
    ];
}

$staff_table = \EduCBTPro\Core\Schema::table( 'staff' );
$assignments = \EduCBTPro\Core\Schema::table( 'staff_assignments' );

$classes_table = \EduCBTPro\Core\Schema::table( 'classes' );
$subjects_tbl  = \EduCBTPro\Core\Schema::table( 'subjects_v2' );

$staff = (array) $wpdb->get_results(
    $wpdb->prepare(
        "SELECT s.*, COUNT(a.id) AS assignment_count
         FROM {$staff_table} s
         LEFT JOIN {$assignments} a ON a.staff_id = s.id AND a.status = 'active'
         WHERE s.school_id = %d AND s.status = 'active'
         GROUP BY s.id
         ORDER BY s.last_name ASC",
        $school_id
    ),
    ARRAY_A
);

// What each person actually holds. Shown before removal so a principal is never
// asked to confirm something they cannot see the consequences of.
$holdings = [];

foreach ( (array) $wpdb->get_results(
    $wpdb->prepare(
        "SELECT a.staff_id, a.assignment_type, c.display_name AS class_name, sub.name AS subject_name
         FROM {$assignments} a
         LEFT JOIN {$classes_table} c ON c.id = a.class_id
         LEFT JOIN {$subjects_tbl} sub ON sub.id = a.subject_id
         WHERE a.school_id = %d AND a.status = 'active'",
        $school_id
    ),
    ARRAY_A
) as $row ) {
    $staff_id_key = (int) $row['staff_id'];

    if ( (string) $row['assignment_type'] === 'class_teacher' ) {
        $holdings[ $staff_id_key ]['class_teacher'][] = (string) $row['class_name'];
    } else {
        // Group by subject so "Agricultural Science — JSS1 A, JSS2 A, JSS3 A" reads as
        // one duty rather than three identical-looking lines.
        $holdings[ $staff_id_key ]['subjects'][ (string) $row['subject_name'] ][] = (string) $row['class_name'];
    }
}

$educbt_title = 'Staff';

$educbt_body = static function () use ( $flash, $staff, $classes, $subjects, $session, $session_id, $holdings, $class_meta ): void {
    require EDUCBT_PRO_PATH . 'templates/portal/partials/flash.php';
    ?>
    <section class="educbt-card">
        <h2>Add a staff member</h2>
        <p class="educbt-muted" style="margin-top:-6px">The staff number and password are generated. Give them a class or subject below once added.</p>

        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="educbt-form">
            <input type="hidden" name="action" value="educbt_register_staff">
            <?php wp_nonce_field( 'educbt_register_staff' ); ?>

            <div class="educbt-grid">
                <div><label for="s_title">Title</label><input id="s_title" name="title" type="text" placeholder="Mr / Mrs / Dr"></div>
                <div><label for="s_first">First name *</label><input id="s_first" name="first_name" type="text" required></div>
                <div><label for="s_last">Surname *</label><input id="s_last" name="last_name" type="text" required></div>
                <div>
                    <label for="s_gender">Sex</label>
                    <select id="s_gender" name="gender"><option value="">—</option><option value="male">Male</option><option value="female">Female</option></select>
                </div>
                <div><label for="s_email">Email</label><input id="s_email" name="email" type="email"></div>
                <div><label for="s_phone">Phone</label><input id="s_phone" name="phone" type="text"></div>
                <div>
                    <label for="s_role">Role</label>
                    <select id="s_role" name="role_slug">
                        <?php foreach ( \EduCBTPro\Core\Capabilities::roles() as $slug => $label ) : ?>
                            <?php if ( in_array( $slug, [ \EduCBTPro\Core\Capabilities::ROLE_PLATFORM_ADMIN, \EduCBTPro\Core\Capabilities::ROLE_STUDENT, \EduCBTPro\Core\Capabilities::ROLE_GUARDIAN ], true ) ) { continue; } ?>
                            <option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $slug, \EduCBTPro\Core\Capabilities::ROLE_TEACHER ); ?>>
                                <?php echo esc_html( $label ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <button type="submit" class="educbt-btn educbt-btn--primary" style="margin-top:16px">Add staff member</button>
        </form>
    </section>

    <?php if ( ! empty( $staff ) && $session_id > 0 ) : ?>
    <section class="educbt-card">
        <h2>Assign teaching duties</h2>
        <p class="educbt-muted" style="margin-top:-6px">
            Pick a teacher, then every subject and every class they take — a subject
            teacher usually takes their subject right across a year group, so choose
            them all at once. Add another row for the next teacher, then save once.
        </p>

        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="educbt-form">
            <input type="hidden" name="action" value="educbt_assign_bulk">
            <input type="hidden" name="session_id" value="<?php echo esc_attr( (string) $session_id ); ?>">
            <?php wp_nonce_field( 'educbt_assign_bulk' ); ?>

            <div style="margin-bottom:14px">
                <label for="bulk_type">What are you assigning?</label>
                <select id="bulk_type" name="assignment_type" onchange="educbtAssignKind(this.value)">
                    <option value="subject_teacher">Subject teachers</option>
                    <option value="class_teacher">Class teachers</option>
                </select>
            </div>

            <div id="assign-rows"></div>

            <button type="button" class="educbt-btn" onclick="educbtAddRow()">+ Add another teacher</button>
            <button type="submit" class="educbt-btn educbt-btn--primary" style="margin-left:8px">Save assignments</button>
        </form>

        <template id="assign-row-template">
            <div class="assign-row">
                <div class="assign-row__field">
                    <label>Teacher</label>
                    <select name="row[__i__][staff_id]" required>
                        <option value="">Choose a teacher</option>
                        <?php foreach ( $staff as $member ) : ?>
                            <option value="<?php echo esc_attr( (string) $member['id'] ); ?>">
                                <?php echo esc_html( trim( $member['title'] . ' ' . $member['first_name'] . ' ' . $member['last_name'] ) ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="assign-row__field">
                    <label>Classes <span class="educbt-muted">(choose all that apply)</span></label>
                    <select name="row[__i__][class_ids][]" multiple size="6" required onchange="educbtNarrowSubjects(this)">
                        <?php foreach ( $classes as $class ) : ?>
                            <option value="<?php echo esc_attr( (string) $class['id'] ); ?>"><?php echo esc_html( (string) $class['display_name'] ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="assign-row__field assign-row__subjects">
                    <label>Subjects <span class="educbt-muted">(choose the classes first)</span></label>
                    <select name="row[__i__][subject_ids][]" multiple size="6">
                        <?php foreach ( $subjects as $subject ) : ?>
                            <option value="<?php echo esc_attr( (string) $subject['id'] ); ?>"
                                    data-stage="<?php echo esc_attr( (string) $subject['stage'] ); ?>"
                                    data-department="<?php echo esc_attr( (string) (int) $subject['department_id'] ); ?>">
                                <?php echo esc_html( (string) $subject['name'] ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <button type="button" class="educbt-btn educbt-btn--ghost assign-row__remove"
                        onclick="this.closest('.assign-row').remove()" aria-label="Remove this row">Remove</button>
            </div>
        </template>

        <script>
        var educbtRowIndex = 0;

        function educbtAddRow() {
            var tpl = document.getElementById('assign-row-template').innerHTML;
            var wrap = document.createElement('div');
            wrap.innerHTML = tpl.replace(/__i__/g, educbtRowIndex++);
            var row = wrap.firstElementChild;
            document.getElementById('assign-rows').appendChild(row);
            educbtAssignKind(document.getElementById('bulk_type').value);
        }

        var educbtClassMeta = <?php echo wp_json_encode( $class_meta ); ?>;

        /* Classes drive the subject list.
           Choosing JSS1 and JSS2 should leave only subjects those year groups
           actually offer — showing all 42 invites a Mathematics teacher to be filed
           under Further Mathematics in a junior class, and nothing downstream would
           catch it. Selecting several classes shows the union of their subjects. */
        function educbtNarrowSubjects(classSelect) {
            var row = classSelect.closest('.assign-row');
            var subjectSelect = row.querySelector('.assign-row__subjects select');
            if (!subjectSelect) { return; }

            var chosen = Array.prototype.filter.call(classSelect.options, function (o) { return o.selected; })
                .map(function (o) { return educbtClassMeta[o.value]; })
                .filter(Boolean);

            var visible = 0;

            Array.prototype.forEach.call(subjectSelect.options, function (opt) {
                if (!chosen.length) { opt.hidden = false; visible++; return; }

                var stage = opt.dataset.stage || 'both';
                var dept = parseInt(opt.dataset.department || '0', 10);

                var fits = chosen.some(function (meta) {
                    var stageOk = stage === 'both' || stage === meta.stage;
                    var deptOk = dept === 0 || meta.department === 0 || dept === meta.department;
                    return stageOk && deptOk;
                });

                opt.hidden = !fits;
                if (fits) { visible++; }
                if (!fits) { opt.selected = false; }
            });

            var label = row.querySelector('.assign-row__subjects label');
            if (label) {
                label.innerHTML = 'Subjects <span class="educbt-muted">('
                    + (chosen.length ? visible + ' offered by the chosen classes' : 'choose the classes first')
                    + ')</span>';
            }
        }

        /* Class teachers take a class, not a subject. Leaving the subject box on
           screen invites someone to fill it in and wonder why it was ignored. */
        function educbtAssignKind(kind) {
            var hide = kind === 'class_teacher';
            document.querySelectorAll('.assign-row__subjects').forEach(function (el) {
                el.hidden = hide;
                el.querySelector('select').disabled = hide;
            });
        }

        educbtAddRow();
        </script>
    </section>
    <?php endif; ?>

    <section class="educbt-card">
        <h2>Staff <span class="educbt-muted">(<?php echo esc_html( (string) count( $staff ) ); ?>)</span></h2>
        <?php if ( empty( $staff ) ) : ?>
            <p class="educbt-muted">No staff added yet.</p>
        <?php else : ?>
            <table class="educbt-table">
                <thead><tr><th>Staff no.</th><th>Name</th><th>Role</th><th>Holds</th><th></th></tr></thead>
                <tbody>
                <?php foreach ( $staff as $member ) :
                    $sid  = (int) $member['id'];
                    $held = $holdings[ $sid ] ?? [];
                    ?>
                    <tr>
                        <td><code><?php echo esc_html( (string) $member['staff_number'] ); ?></code></td>
                        <td><?php echo esc_html( trim( $member['title'] . ' ' . $member['first_name'] . ' ' . $member['last_name'] ) ); ?></td>
                        <td><?php echo esc_html( \EduCBTPro\Core\Capabilities::roles()[ $member['role_slug'] ] ?? $member['role_slug'] ); ?></td>
                        <td>
                            <?php if ( empty( $held ) ) : ?>
                                <span class="educbt-muted">none yet</span>
                            <?php else : ?>
                                <?php foreach ( (array) ( $held['class_teacher'] ?? [] ) as $cls ) : ?>
                                    <div style="font-size:13px">
                                        <span class="educbt-pill educbt-pill--approved">Class teacher</span>
                                        <?php echo esc_html( $cls ); ?>
                                    </div>
                                <?php endforeach; ?>
                                <?php foreach ( (array) ( $held['subjects'] ?? [] ) as $subject_name => $class_list ) : ?>
                                    <div style="font-size:13px;margin-top:3px">
                                        <strong><?php echo esc_html( (string) $subject_name ); ?></strong>
                                        <span class="educbt-muted">— <?php echo esc_html( implode( ', ', array_filter( $class_list ) ) ); ?></span>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </td>
                        <td style="white-space:nowrap">
                            <button type="button" class="educbt-btn" onclick="document.getElementById('edit-<?php echo esc_attr( (string) $sid ); ?>').toggleAttribute('hidden')">Edit</button>
                        </td>
                    </tr>
                    <tr id="edit-<?php echo esc_attr( (string) $sid ); ?>" hidden>
                        <td colspan="5" style="background:var(--edu-bg)">
                            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="educbt-form" style="padding:12px 0">
                                <input type="hidden" name="action" value="educbt_update_staff">
                                <input type="hidden" name="staff_id" value="<?php echo esc_attr( (string) $sid ); ?>">
                                <?php wp_nonce_field( 'educbt_update_staff' ); ?>
                                <div class="educbt-grid">
                                    <div><label>Title</label><input name="title" type="text" value="<?php echo esc_attr( (string) $member['title'] ); ?>"></div>
                                    <div><label>First name</label><input name="first_name" type="text" value="<?php echo esc_attr( (string) $member['first_name'] ); ?>" required></div>
                                    <div><label>Surname</label><input name="last_name" type="text" value="<?php echo esc_attr( (string) $member['last_name'] ); ?>" required></div>
                                    <div><label>Email</label><input name="email" type="email" value="<?php echo esc_attr( (string) $member['email'] ); ?>"></div>
                                    <div><label>Phone</label><input name="phone" type="text" value="<?php echo esc_attr( (string) $member['phone'] ); ?>"></div>
                                    <div>
                                        <label>Role</label>
                                        <select name="role_slug">
                                            <?php foreach ( \EduCBTPro\Core\Capabilities::roles() as $slug => $label ) : ?>
                                                <?php if ( in_array( $slug, [ \EduCBTPro\Core\Capabilities::ROLE_PLATFORM_ADMIN, \EduCBTPro\Core\Capabilities::ROLE_STUDENT, \EduCBTPro\Core\Capabilities::ROLE_GUARDIAN ], true ) ) { continue; } ?>
                                                <option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $slug, (string) $member['role_slug'] ); ?>>
                                                    <?php echo esc_html( $label ); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <label style="display:flex;align-items:center;gap:8px;margin-top:10px;font-weight:400;font-size:13px">
                                    <input type="checkbox" name="confirm_transfer" value="1" style="width:auto">
                                    Transfer the principal role to this person if it is taken
                                </label>
                                <button type="submit" class="educbt-btn educbt-btn--primary" style="margin-top:12px">Save changes</button>
                            </form>

                            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
                                  style="border-top:1px solid var(--edu-line);padding-top:12px;margin-top:4px"
                                  onsubmit="return confirm('Reset this password? The current one stops working immediately.');">
                                <input type="hidden" name="action" value="educbt_reset_staff_password">
                                <input type="hidden" name="staff_id" value="<?php echo esc_attr( (string) $sid ); ?>">
                                <?php wp_nonce_field( 'educbt_reset_staff_password' ); ?>
                                <button type="submit" class="educbt-btn">Reset their password</button>
                                <span class="educbt-muted" style="margin-left:8px">Shown once; they must change it at next sign-in.</span>
                            </form>

                            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="educbt-form"
                                  style="border-top:1px solid var(--edu-line);padding:12px 0"
                                  onsubmit="return confirm('Stand this staff member down? Their record and history are kept.');">
                                <input type="hidden" name="action" value="educbt_remove_staff">
                                <input type="hidden" name="staff_id" value="<?php echo esc_attr( (string) $sid ); ?>">
                                <?php wp_nonce_field( 'educbt_remove_staff' ); ?>

                                <?php if ( ! empty( $held ) ) : ?>
                                    <p class="educbt-note educbt-note--warn" style="margin-bottom:8px">
                                        This person still holds:
                                        <?php
                                        $held_parts = [];
                                        foreach ( (array) ( $held['class_teacher'] ?? [] ) as $cls ) {
                                            $held_parts[] = 'Class teacher: ' . $cls;
                                        }
                                        foreach ( (array) ( $held['subjects'] ?? [] ) as $subject_name => $class_list ) {
                                            $held_parts[] = $subject_name . ' (' . implode( ', ', array_filter( (array) $class_list ) ) . ')';
                                        }
                                        echo esc_html( implode( '; ', $held_parts ) );
                                    ?>.
                                        Reassign these under &ldquo;Assign a class or subject&rdquo; above first —
                                        a class left with no teacher has nobody responsible for its remarks or promotion.
                                    </p>
                                    <label style="display:flex;align-items:center;gap:8px;font-weight:400;font-size:13.5px">
                                        <input type="checkbox" name="confirm_reassign" value="1" style="width:auto">
                                        Stand them down anyway and end these assignments
                                    </label>
                                <?php endif; ?>

                                <button type="submit" class="educbt-btn" style="margin-top:10px;color:#b91c1c;border-color:#f3c9c9">Remove staff member</button>
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
