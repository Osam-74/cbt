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

// Group classes by level so the picker can offer "all arms of SS 1" in one click, AND each arm on its own.
// Schools differ: sometimes one teacher takes a subject across every arm, sometimes each arm has its own
// teacher, and several teachers may share the same subject in the same arm. All of that must be possible.
$class_levels_list = (array) $wpdb->get_results(
    $wpdb->prepare(
        'SELECT l.id, l.name, l.code, l.stage, l.level_order FROM ' . \EduCBTPro\Core\Schema::table( 'class_levels' ) . ' l
         WHERE l.school_id = %d ORDER BY l.level_order ASC',
        $school_id
    ),
    ARRAY_A
);

// Build level → [class_ids] map and level → stage/department map.
$level_class_ids = [];
$level_meta      = [];
foreach ( $class_levels_list as $lvl ) {
    $lid = (int) $lvl['id'];
    $level_class_ids[ $lid ] = [];
    $level_meta[ $lid ] = [
        'stage'      => (string) $lvl['stage'],
        'name'       => (string) $lvl['name'],
        'code'       => (string) $lvl['code'],
        'department' => 0,
    ];
}
$level_arms = [];
foreach ( $classes as $cls ) {
    $lid = (int) $cls['level_id'];
    if ( isset( $level_class_ids[ $lid ] ) ) {
        $level_class_ids[ $lid ][] = (int) $cls['id'];
        $level_arms[ $lid ][]      = [
            'id'   => (int) $cls['id'],
            'name' => (string) ( $cls['display_name'] ?? '' ) !== '' ? (string) $cls['display_name'] : trim( (string) ( $cls['class_name'] ?? '' ) . ' ' . (string) ( $cls['arm'] ?? '' ) ),
        ];
    }
    // Inherit department from the first class of that level (science, arts, etc.).
    if ( isset( $level_meta[ $lid ] ) && $level_meta[ $lid ]['department'] === 0 ) {
        $level_meta[ $lid ]['department'] = (int) ( $cls['department_id'] ?? 0 );
    }
}

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

// What each person actually holds, class by class. Shown before removal so a principal is never asked to
// confirm something they cannot see the consequences of.
$staff_service = new \EduCBTPro\Services\StaffService();
$duties        = $staff_service->duties_for_school( $school_id, $session_id );

$holdings = [];   // staff_id => [ class_teacher => [ [id,class_id,class_name] ], subjects => [ subject_id => [ name, classes => [ [id,class_id,class_name,others] ] ] ] ]
$by_slot  = [];   // "class:subject" => [ staff_id => true ], to name co-teachers without extra queries

foreach ( $duties as $d ) {
    if ( (string) $d['assignment_type'] === 'subject_teacher' ) {
        $by_slot[ (int) $d['class_id'] . ':' . (int) $d['subject_id'] ][ (int) $d['staff_id'] ] = true;
    }
}

$staff_names = [];
foreach ( $staff as $m ) {
    $staff_names[ (int) $m['id'] ] = trim( (string) $m['first_name'] . ' ' . (string) $m['last_name'] );
}

foreach ( $duties as $d ) {
    $sid_key = (int) $d['staff_id'];

    if ( (string) $d['assignment_type'] === 'class_teacher' ) {
        $holdings[ $sid_key ]['class_teacher'][] = [
            'id'         => (int) $d['id'],
            'class_id'   => (int) $d['class_id'],
            'class_name' => (string) $d['class_name'],
        ];
        continue;
    }

    $sub_id = (int) $d['subject_id'];
    $others = [];
    foreach ( array_keys( $by_slot[ (int) $d['class_id'] . ':' . $sub_id ] ?? [] ) as $other ) {
        if ( $other !== $sid_key && isset( $staff_names[ $other ] ) ) {
            $others[] = $staff_names[ $other ];
        }
    }

    $holdings[ $sid_key ]['subjects'][ $sub_id ]['name']      = (string) $d['subject_name'];
    $holdings[ $sid_key ]['subjects'][ $sub_id ]['classes'][] = [
        'id'         => (int) $d['id'],
        'class_id'   => (int) $d['class_id'],
        'class_name' => (string) $d['class_name'],
        'others'     => $others,
    ];
}

$educbt_title = 'Staff';

$educbt_body = static function () use ( $flash, $staff, $classes, $subjects, $session, $session_id, $holdings, $class_meta, $class_levels_list, $level_class_ids, $level_meta, $level_arms ): void {
    require EDUCBT_PRO_PATH . 'templates/portal/partials/flash.php';
    ?>
    <section class="educbt-card">
        <h2>Add a staff member</h2>
        <p class="educbt-muted" style="margin-top:-6px">The staff number and password are generated. Give them a class or subject below once added.</p>

        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="educbt-form" enctype="multipart/form-data">
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
                <div>
                    <label for="s_photo">Passport Photo</label>
                    <input id="s_photo" name="photo" type="file" accept="image/*" onchange="educbtPreviewPhoto(this)">
                    <div id="photo-preview" style="margin-top:8px; display:none;">
                        <img id="photo-preview-img" src="" alt="Passport preview" style="max-width:80px; max-height:80px; object-fit:cover; border-radius:6px; border:1px solid var(--line,#ccc);">
                    </div>
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
                    <select name="row[__i__][staff_id]" required onchange="educbtPrefillAssignments(this)">
                        <option value="">Choose a teacher</option>
                        <?php foreach ( $staff as $member ) : ?>
                            <option value="<?php echo esc_attr( (string) $member['id'] ); ?>">
                                <?php echo esc_html( trim( $member['title'] . ' ' . $member['first_name'] . ' ' . $member['last_name'] ) ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="assign-row__field assign-row__classes">
                    <label>Classes <span class="educbt-muted">(choose all that apply)</span></label>
                    <div class="chip-select" data-name="row[__i__][class_ids][]" data-required="1" data-onchange="educbtNarrowSubjects">
                        <div class="chip-select__input">
                            <div class="chip-select__chips"></div>
                            <div class="chip-select__placeholder">Click to select classes or arms…</div>
                            <svg class="chip-select__arrow" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
                        </div>
                        <div class="chip-select__dropdown" hidden>
                            <?php foreach ( $class_levels_list as $lvl ) :
                                $lid  = (int) $lvl['id'];
                                $arms = (array) ( $level_arms[ $lid ] ?? [] );
                                $meta = [
                                    'stage'      => (string) $lvl['stage'],
                                    'department' => (int) ( $level_meta[ $lid ]['department'] ?? 0 ),
                                ];
                            ?>
                                <?php if ( count( $arms ) > 1 ) : ?>
                                    <div class="chip-select__option chip-select__option--all"
                                         data-value="<?php echo esc_attr( 'L' . $lid ); ?>"
                                         data-class-ids="<?php echo esc_attr( wp_json_encode( array_column( $arms, 'id' ) ) ); ?>"
                                         data-stage="<?php echo esc_attr( $meta['stage'] ); ?>"
                                         data-department="<?php echo esc_attr( (string) $meta['department'] ); ?>">
                                        <strong><?php echo esc_html( (string) $lvl['name'] ); ?></strong>
                                        <span class="educbt-muted" style="font-size:.8rem">— every arm (<?php echo (int) count( $arms ); ?>)</span>
                                    </div>
                                <?php endif; ?>
                                <?php foreach ( $arms as $arm ) : ?>
                                    <div class="chip-select__option chip-select__option--arm"
                                         data-value="<?php echo esc_attr( (string) $arm['id'] ); ?>"
                                         data-level="<?php echo esc_attr( (string) $lid ); ?>"
                                         data-stage="<?php echo esc_attr( $meta['stage'] ); ?>"
                                         data-department="<?php echo esc_attr( (string) $meta['department'] ); ?>">
                                        <?php echo esc_html( (string) $arm['name'] ); ?>
                                    </div>
                                <?php endforeach; ?>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <div class="assign-row__field assign-row__subjects">
                    <label>Subjects <span class="educbt-muted">(choose the classes first)</span></label>
                    <div class="chip-select" data-name="row[__i__][subject_ids][]">
                        <div class="chip-select__input">
                            <div class="chip-select__chips"></div>
                            <div class="chip-select__placeholder">Click to select subjects…</div>
                            <svg class="chip-select__arrow" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
                        </div>
                        <div class="chip-select__dropdown" hidden>
                            <?php foreach ( $subjects as $subject ) : ?>
                                <div class="chip-select__option"
                                     data-value="<?php echo esc_attr( (string) $subject['id'] ); ?>"
                                     data-stage="<?php echo esc_attr( (string) $subject['stage'] ); ?>"
                                     data-department="<?php echo esc_attr( (string) (int) $subject['department_id'] ); ?>">
                                    <?php echo esc_html( (string) $subject['name'] ); ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <button type="button" class="educbt-btn educbt-btn--ghost assign-row__remove"
                        onclick="this.closest('.assign-row').remove()" aria-label="Remove this row">Remove</button>
            </div>
        </template>

        <script>
        function educbtPreviewPhoto(input) {
            var previewWrap = document.getElementById('photo-preview');
            var previewImg = document.getElementById('photo-preview-img');
            if (input && input.files && input.files[0]) {
                var reader = new FileReader();
                reader.onload = function(e) {
                    if (previewImg) previewImg.src = e.target.result;
                    if (previewWrap) previewWrap.style.display = 'block';
                };
                reader.readAsDataURL(input.files[0]);
            } else {
                if (previewImg) previewImg.src = '';
                if (previewWrap) previewWrap.style.display = 'none';
            }
        }

        // Existing assignments per staff member, so the teacher dropdown can
        // pre-fill the classes and subjects a teacher already holds when they
        // are selected — instead of starting from blank every time.
        var educbtExistingAssignments = <?php
            $existing = [];
            foreach ( $holdings as $staff_id_held => $held_data ) {
                $entry = [ 'class_teacher' => [], 'subjects' => [] ];
                foreach ( (array) ( $held_data['class_teacher'] ?? [] ) as $ct ) {
                    $entry['class_teacher'][] = (int) $ct['class_id'];
                }
                foreach ( (array) ( $held_data['subjects'] ?? [] ) as $sub_id => $sub ) {
                    $entry['subjects'][ (string) $sub_id ] = array_values( array_map( static fn ( $c ) => (int) $c['class_id'], (array) $sub['classes'] ) );
                }
                $existing[ (int) $staff_id_held ] = $entry;
            }
            echo wp_json_encode( $existing );
        ?>;

        var educbtRowIndex = 0;

        function educbtAddRow() {
            var tpl = document.getElementById('assign-row-template').innerHTML;
            var wrap = document.createElement('div');
            wrap.innerHTML = tpl.replace(/__i__/g, educbtRowIndex++);
            var row = wrap.firstElementChild;
            document.getElementById('assign-rows').appendChild(row);
            educbtAssignKind(document.getElementById('bulk_type').value);
        }

        var educbtLevelMeta = <?php echo wp_json_encode( $level_meta ); ?>;
        var educbtLevelClassIds = <?php echo wp_json_encode( $level_class_ids ); ?>;

        /* When a teacher is selected, pre-fill exactly what they already hold: the same classes and arms and
           the same subjects, no more. (Matching by level name used to tick every arm of the level, so saving an
           edit quietly gave the teacher arms they never had.) */
        function educbtSelectOption(chipSelect, selector) {
            var opt = chipSelect && chipSelect.querySelector(selector);
            if (opt && !opt.classList.contains('is-selected')) { educbtChipToggle(chipSelect, opt); }
        }

        function educbtPrefillAssignments(staffSelect) {
            var row = staffSelect.closest('.assign-row');
            if (!row) return;

            var noteEl = row.querySelector('.assign-row__prefill-note');
            var staffId = parseInt(staffSelect.value, 10);

            // Clear anything chosen before. Toggle a copy: toggling removes chips as it goes.
            row.querySelectorAll('.chip-select').forEach(function(cs) {
                Array.prototype.slice.call(cs.querySelectorAll('.chip-select__option.is-selected')).forEach(function(opt) {
                    educbtChipToggle(cs, opt);
                });
            });

            var existing = staffId ? educbtExistingAssignments[staffId] : null;
            if (!existing) { if (noteEl) noteEl.style.display = 'none'; return; }

            var assignmentType = document.getElementById('bulk_type').value;
            var classChipSelect = row.querySelector('.assign-row__classes .chip-select');
            var subjectChipSelect = row.querySelector('.assign-row__subjects .chip-select');
            var classIds = [];
            var subjectIds = [];

            if (assignmentType === 'class_teacher') {
                classIds = existing.class_teacher.slice();
            } else {
                Object.keys(existing.subjects).forEach(function(sid) {
                    subjectIds.push(sid);
                    existing.subjects[sid].forEach(function(cid) { if (classIds.indexOf(cid) === -1) classIds.push(cid); });
                });
            }

            classIds.forEach(function(cid) { educbtSelectOption(classChipSelect, '.chip-select__option--arm[data-value="' + cid + '"]'); });
            subjectIds.forEach(function(sid) { educbtSelectOption(subjectChipSelect, '.chip-select__option[data-value="' + sid + '"]'); });

            if (noteEl && (classIds.length || subjectIds.length)) {
                noteEl.textContent = 'Already holds ' + subjectIds.length + ' subject(s) in ' + classIds.length + ' class(es). Saving adds to this; use Remove in the staff list to take a duty away.';
                noteEl.style.display = 'block';
            }
        }

        /* Classes drive the subject list.
           Choosing JSS1 and JSS2 should leave only subjects those year groups
           actually offer — showing all 42 invites a Mathematics teacher to be filed
           under Further Mathematics in a junior class, and nothing downstream would
           catch it. Selecting several classes shows the union of their subjects. */
        function educbtNarrowSubjects(chipSelectEl) {
            var row = chipSelectEl.closest('.assign-row');
            var subjectSelect = row.querySelector('.assign-row__subjects .chip-select');
            if (!subjectSelect) { return; }

            // The stage (junior/senior) of every chosen option, whether it is a whole level or a single arm.
            var chosen = [];
            chipSelectEl.querySelectorAll('.chip-select__option--arm.is-selected').forEach(function(opt) {
                chosen.push({ stage: opt.getAttribute('data-stage') || 'both' });
            });

            var visible = 0;
            var options = subjectSelect.querySelectorAll('.chip-select__option');

            options.forEach(function(opt) {
                if (!chosen.length) { opt.style.display = ''; visible++; return; }

                var stage = opt.dataset.stage || 'both';
                var dept = parseInt(opt.dataset.department || '0', 10);

                var fits = chosen.some(function (meta) {
                    var stageOk = stage === 'both' || stage === meta.stage;
                    var deptOk = true; // Show all subjects regardless of department
                    return stageOk && deptOk;
                });

                opt.style.display = fits ? '' : 'none';
                if (fits) { visible++; }

                // Deselect hidden options
                if (!fits && opt.classList.contains('is-selected')) {
                    educbtChipToggle(subjectSelect, opt);
                }
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
                el.querySelectorAll('input[type="hidden"]').forEach(function(h) { h.disabled = hide; });
            });
        }

        // ── Chip-Select Component ───────────────────────────────────
        // A modern multi-select that shows selected items as removable chips.
        // Uses hidden inputs for form submission, no <select multiple> needed.

        function educbtChipToggle(chipSelect, option) {
            var val = option.getAttribute('data-value');
            var text = option.textContent.replace(/\s+/g, ' ').trim();
            var chips = chipSelect.querySelector('.chip-select__chips');
            var placeholder = chipSelect.querySelector('.chip-select__placeholder');
            var name = chipSelect.dataset.name;

            // "Every arm of SS 1" is a shortcut: it selects or clears each arm option, so what is submitted
            // is always one hidden input per class id, and the arms always show what is really chosen.
            var allIdsJson = option.getAttribute('data-class-ids');
            if (allIdsJson && val.charAt(0) === 'L') {
                var ids;
                try { ids = JSON.parse(allIdsJson); } catch (e) { ids = []; }
                var selectedArms = ids.filter(function (cid) {
                    var o = chipSelect.querySelector('.chip-select__option--arm[data-value="' + cid + '"]');
                    return o && o.classList.contains('is-selected');
                });
                var makeSelected = selectedArms.length !== ids.length;   // all chosen already -> clear them
                ids.forEach(function (cid) {
                    var o = chipSelect.querySelector('.chip-select__option--arm[data-value="' + cid + '"]');
                    if (o && o.classList.contains('is-selected') !== makeSelected) { educbtChipToggle(chipSelect, o); }
                });
                option.classList.toggle('is-selected', makeSelected);
                return;
            }

            if (option.classList.contains('is-selected')) {
                option.classList.remove('is-selected');
                var chip = chips.querySelector('[data-chip-value="' + val + '"]');
                if (chip) chip.remove();
                var hidden = chipSelect.querySelector('input[type="hidden"][value="' + val + '"]');
                if (hidden) hidden.remove();
            } else {
                option.classList.add('is-selected');
                var newChip = document.createElement('span');
                newChip.className = 'chip-select__chip';
                newChip.setAttribute('data-chip-value', val);
                newChip.innerHTML = esc_html(text) + '<button type="button" class="chip-select__remove" aria-label="Remove">×</button>';
                newChip.querySelector('.chip-select__remove').addEventListener('click', function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                    educbtChipToggle(chipSelect, option);
                });
                chips.appendChild(newChip);

                var input = document.createElement('input');
                input.type = 'hidden';
                input.name = name;
                input.value = val;
                chipSelect.appendChild(input);
            }

            // Keep each level's "every arm" option ticked only while all of its arms are.
            chipSelect.querySelectorAll('.chip-select__option--all').forEach(function (allOpt) {
                var list;
                try { list = JSON.parse(allOpt.getAttribute('data-class-ids') || '[]'); } catch (e) { list = []; }
                var every = list.length > 0 && list.every(function (cid) {
                    var o = chipSelect.querySelector('.chip-select__option--arm[data-value="' + cid + '"]');
                    return o && o.classList.contains('is-selected');
                });
                allOpt.classList.toggle('is-selected', every);
            });

            var hasChips = chips.children.length > 0;
            if (placeholder) placeholder.style.display = hasChips ? 'none' : '';

            if (chipSelect.dataset.onchange) {
                window[chipSelect.dataset.onchange](chipSelect);
            }
        }

        function esc_html(s) {
            var d = document.createElement('div');
            d.textContent = s;
            return d.innerHTML;
        }

        function educbtInitChipSelect(chipSelect) {
            if (!chipSelect || chipSelect.dataset.initialized === 'true') return;
            chipSelect.dataset.initialized = 'true';

            var input = chipSelect.querySelector('.chip-select__input');
            var dropdown = chipSelect.querySelector('.chip-select__dropdown');
            if (!input || !dropdown) return;

            input.addEventListener('click', function(e) {
                if (e.target.closest('.chip-select__remove')) return;
                e.stopPropagation();
                var isOpen = !dropdown.hidden;
                // Close all other dropdowns
                document.querySelectorAll('.chip-select__dropdown:not([hidden])').forEach(function(d) {
                    d.hidden = true;
                });
                dropdown.hidden = isOpen;
            });

            chipSelect.querySelectorAll('.chip-select__option').forEach(function(opt) {
                opt.addEventListener('click', function(e) {
                    e.stopPropagation();
                    educbtChipToggle(chipSelect, opt);
                });
            });

            // Close on outside click
            document.addEventListener('click', function(e) {
                if (!chipSelect.contains(e.target)) {
                    dropdown.hidden = true;
                }
            });
        }

        // Initialize existing chip-selects and any added dynamically
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('.chip-select').forEach(educbtInitChipSelect);
        });

        // Also init when a new row is added
        var origAddRow = educbtAddRow;
        educbtAddRow = function() {
            origAddRow();
            var rows = document.getElementById('assign-rows');
            var lastRow = rows.lastElementChild;
            if (lastRow) {
                lastRow.querySelectorAll('.chip-select').forEach(educbtInitChipSelect);
            }
        };

        educbtAddRow();
        </script>

        <style>
        .chip-select { position: relative; }
        .chip-select__input {
            min-height: 42px; border: 1.5px solid var(--line); border-radius: 10px;
            padding: 6px 36px 6px 8px; background: #fff; cursor: pointer; position: relative;
            display: flex; flex-wrap: wrap; gap: 4px; align-items: center;
        }
        .chip-select__input:hover { border-color: var(--moss); }
        .chip-select__chips { display: contents; }
        .chip-select__placeholder { color: var(--muted); font-size: 13px; }
        .chip-select__arrow { position: absolute; right: 10px; top: 50%; transform: translateY(-50%); color: var(--muted); pointer-events: none; }
        .chip-select__dropdown {
            position: absolute; top: 100%; left: 0; right: 0; z-index: 100;
            max-height: 200px; overflow-y: auto; border: 1px solid var(--line);
            border-radius: 10px; background: #fff; box-shadow: 0 8px 24px rgba(0,0,0,.12);
            margin-top: 4px;
        }
        .chip-select__dropdown[hidden] { display: none !important; }
        .chip-select__option { padding: 8px 12px; font-size: 13px; cursor: pointer; }
        .chip-select__option:hover { background: var(--lemon-soft, #F3F7DC); }
        .chip-select__option.is-selected { background: #d1fae5; color: #065f46; font-weight: 600; }
        .chip-select__option--all { border-top: 1px solid var(--line); background: #f8faf3; }
        .chip-select__option--arm { padding-left: 26px; }
        .chip-select__chip {
            display: inline-flex; align-items: center; gap: 4px;
            background: #ecfdf5; border: 1px solid #a7f3d0; border-radius: 999px;
            padding: 3px 8px 3px 10px; font-size: 12px; font-weight: 600; color: #065f46;
        }
        .chip-select__remove {
            background: none; border: none; cursor: pointer; font-size: 14px;
            line-height: 1; color: #6b7280; padding: 0 2px; font-family: inherit;
        }
        .chip-select__remove:hover { color: #b91c1c; }
        </style>
    </section>
    <?php endif; ?>

    <section class="educbt-card">
        <h2>Staff <span class="educbt-muted">(<?php echo esc_html( (string) count( $staff ) ); ?>)</span></h2>
        <?php if ( \EduCBTPro\Core\Gate::allows( \EduCBTPro\Core\Capabilities::VIEW_STAFF ) ) : ?>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin:0 0 12px">
                <input type="hidden" name="action" value="educbt_export_assignments">
                <?php wp_nonce_field( 'educbt_export_assignments' ); ?>
                <span class="educbt-muted" style="font-size:.85rem">Download as a spreadsheet:</span>
                <button type="submit" name="type" value="subject" class="educbt-btn">Subject teachers</button>
                <button type="submit" name="type" value="class" class="educbt-btn">Class teachers</button>
            </form>
        <?php endif; ?>
        <?php if ( empty( $staff ) ) : ?>
            <p class="educbt-muted">No staff added yet.</p>
        <?php else : ?>
            <table class="educbt-table">
                <thead><tr><th>Staff no.</th><th>Name</th><th>Role</th><th>Assignments</th><th></th></tr></thead>
                <tbody>
                <?php foreach ( $staff as $member ) :
                    $sid  = (int) $member['id'];
                    $held = $holdings[ $sid ] ?? [];
                    ?>
                    <tr>
                        <td><code><?php echo esc_html( (string) $member['staff_number'] ); ?></code></td>
                        <td>
                            <?php if ( ! empty( $member['photo'] ) ) : ?>
                                <img src="<?php echo esc_url( (string) $member['photo'] ); ?>" alt="" style="width:32px;height:32px;border-radius:50%;object-fit:cover;vertical-align:middle;margin-right:8px;border:1px solid var(--line,#ccc);">
                            <?php endif; ?>
                            <?php echo esc_html( trim( $member['title'] . ' ' . $member['first_name'] . ' ' . $member['last_name'] ) ); ?>
                        </td>
                        <td><?php echo esc_html( \EduCBTPro\Core\Capabilities::roles()[ $member['role_slug'] ] ?? $member['role_slug'] ); ?></td>
                        <td>
                            <?php if ( empty( $held ) ) : ?>
                                <span class="educbt-muted">none yet</span>
                            <?php else : ?>
                                <?php if ( ! empty( $held['class_teacher'] ) ) : ?>
                                    <div style="font-size:13px;margin-bottom:2px">
                                        <span class="educbt-pill educbt-pill--approved">Class teacher</span>
                                        <?php echo esc_html( implode( ', ', array_column( (array) $held['class_teacher'], 'class_name' ) ) ); ?>
                                    </div>
                                <?php endif; ?>
                                <?php foreach ( (array) ( $held['subjects'] ?? [] ) as $sub ) : ?>
                                    <div style="font-size:13px;margin-bottom:2px">
                                        <strong><?php echo esc_html( (string) $sub['name'] ); ?></strong>
                                        <span class="educbt-muted">— <?php echo esc_html( implode( ', ', array_column( (array) $sub['classes'], 'class_name' ) ) ); ?></span>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </td>
                        <td style="white-space:nowrap">
                            <button type="button" class="educbt-btn" onclick="var r=document.getElementById('edit-<?php echo esc_attr( (string) $sid ); ?>');r.style.display=(r.style.display==='none'?'':'none')">Edit</button>
                        </td>
                    </tr>
                    <tr id="edit-<?php echo esc_attr( (string) $sid ); ?>" style="display:none">
                        <td colspan="5" style="background:var(--edu-bg)">
                            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="educbt-form" style="padding:12px 0" enctype="multipart/form-data">
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
                                    <div>
                                        <label>Passport Photo</label>
                                        <input name="photo" type="file" accept="image/*" onchange="educbtEditPhotoPreview(this)">
                                        <?php if ( ! empty( $member['photo'] ) ) : ?>
                                            <div style="margin-top:6px">
                                                <img src="<?php echo esc_url( (string) $member['photo'] ); ?>" alt="" style="width:60px;height:72px;object-fit:cover;border-radius:6px;border:1px solid var(--line,#ccc)">
                                                <span class="educbt-muted" style="font-size:.8rem;margin-left:4px">Current photo</span>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <label style="display:flex;align-items:center;gap:8px;margin-top:10px;font-weight:400;font-size:13px">
                                    <input type="checkbox" name="confirm_transfer" value="1" style="width:auto">
                                    Transfer the principal role to this person if it is taken
                                </label>
                                <button type="submit" class="educbt-btn educbt-btn--primary" style="margin-top:12px">Save changes</button>
                            </form>

                            <?php if ( ! empty( $held['subjects'] ) || ! empty( $held['class_teacher'] ) ) : ?>
                            <div class="staff-duties" style="border-top:1px solid var(--edu-line);padding-top:12px;margin-top:4px">
                                <h4 style="margin:0 0 8px;font-size:.9rem">Duties this person holds</h4>
                                <p class="educbt-muted" style="margin:0 0 8px;font-size:.82rem">Each line is one class or arm. Removing one leaves every other duty, and every other teacher, as it is.</p>

                                <?php foreach ( (array) ( $held['class_teacher'] ?? [] ) as $ct ) : ?>
                                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="staff-duty"
                                      style="display:flex;align-items:center;gap:8px;margin-bottom:6px"
                                      onsubmit="return confirm('Remove this person as class teacher of <?php echo esc_attr( (string) $ct['class_name'] ); ?>?');">
                                    <input type="hidden" name="action" value="educbt_drop_assignment">
                                    <input type="hidden" name="assignment_id" value="<?php echo esc_attr( (string) $ct['id'] ); ?>">
                                    <?php wp_nonce_field( 'educbt_drop_assignment' ); ?>
                                    <span style="font-size:.85rem;flex:1"><span class="educbt-pill educbt-pill--approved">Class teacher</span> <?php echo esc_html( (string) $ct['class_name'] ); ?></span>
                                    <button type="submit" class="educbt-btn" style="font-size:.78rem;padding:3px 10px;color:#b91c1c;border-color:#f3c9c9">Remove</button>
                                </form>
                                <?php endforeach; ?>

                                <?php foreach ( (array) ( $held['subjects'] ?? [] ) as $sub ) : ?>
                                    <div style="font-size:.85rem;font-weight:600;margin:10px 0 4px"><?php echo esc_html( (string) $sub['name'] ); ?></div>
                                    <?php foreach ( (array) $sub['classes'] as $duty ) : ?>
                                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="staff-duty"
                                          style="display:flex;align-items:center;gap:8px;margin:0 0 4px 12px"
                                          onsubmit="return confirm('Remove <?php echo esc_attr( (string) $sub['name'] ); ?> in <?php echo esc_attr( (string) $duty['class_name'] ); ?> from this teacher?');">
                                        <input type="hidden" name="action" value="educbt_drop_assignment">
                                        <input type="hidden" name="assignment_id" value="<?php echo esc_attr( (string) $duty['id'] ); ?>">
                                        <?php wp_nonce_field( 'educbt_drop_assignment' ); ?>
                                        <span style="font-size:.85rem;flex:1">
                                            <?php echo esc_html( (string) $duty['class_name'] ); ?>
                                            <?php if ( ! empty( $duty['others'] ) ) : ?>
                                                <span class="educbt-muted">· also taught by <?php echo esc_html( implode( ', ', (array) $duty['others'] ) ); ?></span>
                                            <?php endif; ?>
                                        </span>
                                        <button type="submit" class="educbt-btn" style="font-size:.78rem;padding:3px 10px;color:#b91c1c;border-color:#f3c9c9">Remove</button>
                                    </form>
                                    <?php endforeach; ?>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>

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
                                        if ( ! empty( $held['class_teacher'] ) ) {
                                            $held_parts[] = 'Class teacher: ' . implode( ', ', array_column( (array) $held['class_teacher'], 'class_name' ) );
                                        }
                                        foreach ( (array) ( $held['subjects'] ?? [] ) as $sub ) {
                                            $held_parts[] = (string) $sub['name'] . ' (' . implode( ', ', array_column( (array) $sub['classes'], 'class_name' ) ) . ')';
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
