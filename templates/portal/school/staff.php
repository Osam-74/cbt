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

// Group classes by level so the assignment picker shows JS1 once instead of
// JS1 A, JS1 B, JS1 C. A subject teacher covers the entire level, not one arm.
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
foreach ( $classes as $cls ) {
    $lid = (int) $cls['level_id'];
    if ( isset( $level_class_ids[ $lid ] ) ) {
        $level_class_ids[ $lid ][] = (int) $cls['id'];
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

// What each person actually holds. Shown before removal so a principal is never
// asked to confirm something they cannot see the consequences of.
$levels_table = \EduCBTPro\Core\Schema::table( 'class_levels' );

$holdings = [];

foreach ( (array) $wpdb->get_results(
    $wpdb->prepare(
        "SELECT a.staff_id, a.assignment_type, l.name AS level_name, sub.name AS subject_name
         FROM {$assignments} a
         LEFT JOIN {$classes_table} c ON c.id = a.class_id
         LEFT JOIN {$levels_table} l ON l.id = c.level_id
         LEFT JOIN {$subjects_tbl} sub ON sub.id = a.subject_id
         WHERE a.school_id = %d AND a.status = 'active'",
        $school_id
    ),
    ARRAY_A
) as $row ) {
    $staff_id_key = (int) $row['staff_id'];
    $level_name   = (string) $row['level_name'];

    if ( (string) $row['assignment_type'] === 'class_teacher' ) {
        // Deduplicate by level name — one entry per level, not per arm.
        if ( ! in_array( $level_name, $holdings[ $staff_id_key ]['class_teacher'] ?? [], true ) ) {
            $holdings[ $staff_id_key ]['class_teacher'][] = $level_name;
        }
    } else {
        // Group by subject so "Mathematics — JS1, JS2, JS3" reads as one duty.
        if ( ! in_array( $level_name, $holdings[ $staff_id_key ]['subjects'][ $row['subject_name'] ] ?? [], true ) ) {
            $holdings[ $staff_id_key ]['subjects'][ (string) $row['subject_name'] ][] = $level_name;
        }
    }
}


// Pre-compute assignment IDs for each staff member's subjects so the
// "Drop a subject" section inside the render closure doesn't need $wpdb
// (which is not in scope there — causing a fatal error).
$drop_assignments = [];
foreach ( $holdings as $staff_id_held => $held_data ) {
    if ( empty( $held_data['subjects'] ) ) {
        continue;
    }
    foreach ( (array) $held_data['subjects'] as $subject_name => $level_list ) {
        $sub_row = $wpdb->get_row( $wpdb->prepare(
            'SELECT a.id, a.subject_id FROM ' . \EduCBTPro\Core\Schema::table( 'staff_assignments' ) . ' a
             INNER JOIN ' . \EduCBTPro\Core\Schema::table( 'subjects_v2' ) . ' s ON s.id = a.subject_id
             WHERE a.staff_id = %d AND s.name = %s AND a.status = %s AND a.assignment_type = %s LIMIT 1',
            $staff_id_held, $subject_name, 'active', 'subject_teacher'
        ), ARRAY_A );
        if ( $sub_row ) {
            $drop_assignments[ $staff_id_held ][ $subject_name ] = (int) $sub_row['id'];
        }
    }
}

$educbt_title = 'Staff';

$educbt_body = static function () use ( $flash, $staff, $classes, $subjects, $session, $session_id, $holdings, $class_meta, $class_levels_list, $level_class_ids, $level_meta, $drop_assignments ): void {
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
                            <div class="chip-select__placeholder">Click to select class levels…</div>
                            <svg class="chip-select__arrow" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
                        </div>
                        <div class="chip-select__dropdown" hidden>
                            <?php foreach ( $class_levels_list as $lvl ) :
                                $lid = (int) $lvl['id'];
                                $arm_count = count( $level_class_ids[ $lid ] ?? [] );
                            ?>
                                <div class="chip-select__option"
                                     data-value="<?php echo esc_attr( 'L' . $lid ); ?>"
                                     data-class-ids="<?php echo esc_attr( wp_json_encode( $level_class_ids[ $lid ] ?? [] ) ); ?>"
                                     data-stage="<?php echo esc_attr( (string) $lvl['stage'] ); ?>"
                                     data-department="<?php echo esc_attr( (string) (int) ( $level_meta[ $lid ]['department'] ?? 0 ) ); ?>">
                                    <?php echo esc_html( (string) $lvl['name'] ); ?>
                                    <?php if ( $arm_count > 1 ) : ?>
                                        <span class="educbt-muted" style="font-size:.8rem">(<?php echo $arm_count; ?> arms)</span>
                                    <?php endif; ?>
                                </div>
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
                $existing[ (int) $staff_id_held ] = [
                    'class_teacher' => (array) ( $held_data['class_teacher'] ?? [] ),
                    'subjects'      => (array) ( $held_data['subjects'] ?? [] ),
                ];
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

        /* When a teacher is selected, pre-fill their existing assignments so the
           exam officer can update or add to them rather than starting from blank.
           The chip-select components are driven by hidden inputs, so we simulate
           selecting the matching options programmatically. */
        function educbtPrefillAssignments(staffSelect) {
            var row = staffSelect.closest('.assign-row');
            if (!row) return;

            var noteEl = row.querySelector('.assign-row__prefill-note');
            var staffId = parseInt(staffSelect.value, 10);

            // Clear any previously pre-filled selections
            row.querySelectorAll('.chip-select').forEach(function(cs) {
                cs.querySelectorAll('.chip-select__option.is-selected').forEach(function(opt) {
                    educbtChipToggle(cs, opt);
                });
            });

            if (!staffId || !educbtExistingAssignments[staffId]) {
                if (noteEl) noteEl.style.display = 'none';
                return;
            }

            var existing = educbtExistingAssignments[staffId];
            var assignmentType = document.getElementById('bulk_type').value;
            var classChipSelect = row.querySelector('.assign-row__classes .chip-select');
            var subjectChipSelect = row.querySelector('.assign-row__subjects .chip-select');

            if (assignmentType === 'class_teacher' && existing.class_teacher.length) {
                // Pre-select the class levels the teacher already holds
                existing.class_teacher.forEach(function(levelName) {
                    if (classChipSelect) {
                        classChipSelect.querySelectorAll('.chip-select__option').forEach(function(opt) {
                            if (opt.textContent.trim().indexOf(levelName) !== -1 && !opt.classList.contains('is-selected')) {
                                educbtChipToggle(classChipSelect, opt);
                            }
                        });
                    }
                });
                if (noteEl) {
                    noteEl.textContent = 'Pre-filled: ' + existing.class_teacher.join(', ');
                    noteEl.style.display = 'block';
                }
            } else if (assignmentType === 'subject_teacher' && existing.subjects) {
                var subjectNames = Object.keys(existing.subjects);
                var levelNames = [];
                subjectNames.forEach(function(sn) {
                    (existing.subjects[sn] || []).forEach(function(ln) {
                        if (levelNames.indexOf(ln) === -1) levelNames.push(ln);
                    });
                });

                // Pre-select class levels
                levelNames.forEach(function(levelName) {
                    if (classChipSelect) {
                        classChipSelect.querySelectorAll('.chip-select__option').forEach(function(opt) {
                            if (opt.textContent.trim().indexOf(levelName) !== -1 && !opt.classList.contains('is-selected')) {
                                educbtChipToggle(classChipSelect, opt);
                            }
                        });
                    }
                });

                // Pre-select subjects
                subjectNames.forEach(function(subjectName) {
                    if (subjectChipSelect) {
                        subjectChipSelect.querySelectorAll('.chip-select__option').forEach(function(opt) {
                            if (opt.textContent.trim() === subjectName && !opt.classList.contains('is-selected')) {
                                educbtChipToggle(subjectChipSelect, opt);
                            }
                        });
                    }
                });

                if (noteEl) {
                    noteEl.textContent = 'Pre-filled: ' + subjectNames.length + ' subject(s), ' + levelNames.length + ' class level(s)';
                    noteEl.style.display = 'block';
                }
            } else {
                if (noteEl) noteEl.style.display = 'none';
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

            // Get selected level values from the chip-select (e.g. "L5", "L6")
            var selectedVals = [];
            chipSelectEl.querySelectorAll('input[type="hidden"]').forEach(function(h) {
                selectedVals.push(h.value);
            });

            // Look up level metadata for each selected level.
            var chosen = selectedVals.map(function(v) {
                var lid = parseInt(String(v).replace('L', ''), 10);
                return educbtLevelMeta[lid];
            }).filter(Boolean);

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
                    + (chosen.length ? visible + ' offered by the chosen levels' : 'choose the levels first')
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
            var text = option.textContent.trim();
            var chips = chipSelect.querySelector('.chip-select__chips');
            var placeholder = chipSelect.querySelector('.chip-select__placeholder');

            // Level options (data-class-ids) expand to multiple class_ids on selection.
            var classIdsJson = option.getAttribute('data-class-ids');
            var expandsToClasses = classIdsJson && val.charAt(0) === 'L';

            if (option.classList.contains('is-selected')) {
                // Remove
                option.classList.remove('is-selected');
                var chip = chips.querySelector('[data-chip-value="' + val + '"]');
                if (chip) chip.remove();
                if (expandsToClasses) {
                    // Remove all expanded class_id hidden inputs for this level
                    var expandedIds;
                    try { expandedIds = JSON.parse(classIdsJson); } catch(e) { expandedIds = []; }
                    expandedIds.forEach(function(cid) {
                        var h = chipSelect.querySelector('input[type="hidden"][value="' + cid + '"]');
                        if (h) h.remove();
                    });
                } else {
                    var hidden = chipSelect.querySelector('input[type="hidden"][value="' + val + '"]');
                    if (hidden) hidden.remove();
                }
            } else {
                // Add
                option.classList.add('is-selected');
                var chip = document.createElement('span');
                chip.className = 'chip-select__chip';
                chip.setAttribute('data-chip-value', val);
                chip.innerHTML = esc_html(text) + '<button type="button" class="chip-select__remove" aria-label="Remove">×</button>';
                chip.querySelector('.chip-select__remove').addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    educbtChipToggle(chipSelect, option);
                });
                chips.appendChild(chip);

                if (expandsToClasses) {
                    // Create a hidden input for each class_id in the level
                    var expandedIds;
                    try { expandedIds = JSON.parse(classIdsJson); } catch(e) { expandedIds = []; }
                    expandedIds.forEach(function(cid) {
                        var hidden = document.createElement('input');
                        hidden.type = 'hidden';
                        hidden.name = chipSelect.dataset.name;
                        hidden.value = String(cid);
                        chipSelect.appendChild(hidden);
                    });
                } else {
                    var hidden = document.createElement('input');
                    hidden.type = 'hidden';
                    hidden.name = chipSelect.dataset.name;
                    hidden.value = val;
                    chipSelect.appendChild(hidden);
                }
            }

            // Update placeholder visibility
            var hasChips = chips.children.length > 0;
            if (placeholder) placeholder.style.display = hasChips ? 'none' : '';

            // Fire onchange callback
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
                                <?php foreach ( (array) ( $held['class_teacher'] ?? [] ) as $cls ) : ?>
                                    <div style="font-size:13px;margin-bottom:2px">
                                        <span class="educbt-pill educbt-pill--approved">Class teacher</span>
                                        <?php echo esc_html( $cls ); ?>
                                    </div>
                                <?php endforeach; ?>
                                <?php foreach ( (array) ( $held['subjects'] ?? [] ) as $subject_name => $level_list ) : ?>
                                    <div style="font-size:13px;margin-bottom:2px">
                                        <strong><?php echo esc_html( (string) $subject_name ); ?></strong>
                                        <span class="educbt-muted">— <?php echo esc_html( implode( ', ', array_filter( $level_list ) ) ); ?></span>
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

                            <?php
                            // Drop a subject from this teacher. Each assignment is a
                            // separate row in staff_assignments, so removing one is a
                            // simple status change.
                            if ( ! empty( $held ) && ! empty( $held['subjects'] ) ) :
                            ?>
                            <div style="border-top:1px solid var(--edu-line);padding-top:12px;margin-top:4px">
                                <h4 style="margin:0 0 8px;font-size:.9rem">Drop a subject</h4>
                                <?php foreach ( (array) ( $held['subjects'] ?? [] ) as $subject_name => $level_list ) :
                                    $drop_id = $drop_assignments[ $sid ][ $subject_name ] ?? 0;
                                ?>
                                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
                                      style="display:flex;align-items:center;gap:8px;margin-bottom:6px"
                                      onsubmit="return confirm('Drop <?php echo esc_attr( $subject_name ); ?> from this teacher?');">
                                    <input type="hidden" name="action" value="educbt_drop_assignment">
                                    <input type="hidden" name="assignment_id" value="<?php echo esc_attr( (string) $drop_id ); ?>">
                                    <?php wp_nonce_field( 'educbt_drop_assignment' ); ?>
                                    <span style="font-size:.85rem;flex:1"><?php echo esc_html( (string) $subject_name ); ?>
                                        <span class="educbt-muted"><?php echo esc_html( ' - ' . implode( ', ', array_filter( (array) $level_list ) ) ); ?></span>
                                    </span>
                                    <button type="submit" class="educbt-btn" style="font-size:.78rem;padding:3px 10px;color:#b91c1c;border-color:#f3c9c9">Drop</button>
                                </form>
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
                                        foreach ( (array) ( $held['class_teacher'] ?? [] ) as $cls ) {
                                            $held_parts[] = 'Class teacher: ' . $cls;
                                        }
                                        foreach ( (array) ( $held['subjects'] ?? [] ) as $subject_name => $level_list ) {
                                            $held_parts[] = $subject_name . ' (' . implode( ', ', array_filter( (array) $level_list ) ) . ')';
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
