<?php
/**
 * Students — register, list, reset a password, link a guardian.
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
$year      = new \EduCBTPro\Services\AcademicYearService();

$session    = $year->current_session( $school_id );
$session_id = (int) ( $session['id'] ?? 0 );
$classes    = $structure->list_classes( $school_id );

// A class teacher only sees their own class in the dropdown; the office sees all.
$reachable = $educbt['scope']->reachable_class_ids();

if ( ! empty( $reachable ) ) {
    $classes = array_values( array_filter( $classes, static fn( array $c ): bool => in_array( (int) $c['id'], $reachable, true ) ) );
}

$filter_class  = (int) ( $_GET['class'] ?? 0 );
$filter_status = sanitize_text_field( (string) ( $_GET['status'] ?? 'active' ) );
$search        = trim( sanitize_text_field( (string) ( $_GET['q'] ?? '' ) ) );

$students = [];

if ( $session_id > 0 ) {
    $enrollments = \EduCBTPro\Core\Schema::table( 'enrollments' );
    $class_table = \EduCBTPro\Core\Schema::table( 'classes' );

    $where  = 'e.school_id = %d AND e.session_id = %d';
    $params = [ $school_id, $session_id ];

    if ( $filter_status !== 'all' ) {
        // Filter on the STUDENT's standing, which is what the status column
        // displays. This used to filter on the ENROLMENT status instead — two
        // different fields with overlapping values — so a student could be
        // counted under Active while their row showed Withdrawn, and the totals
        // never reconciled with the list beneath them.
        //
        // A student with no enrolment status of their own still counts as active
        // for this purpose; standing is the student's, not the class list's.
        $where   .= ' AND COALESCE(st.status, %s) = %s';
        $params[] = 'active';
        $params[] = $filter_status;
    }

    if ( $filter_class > 0 ) {
        $where   .= ' AND e.class_id = %d';
        $params[] = $filter_class;
    }

    // Search by name or student ID. A school with several hundred students cannot
    // find one by scrolling, and the class filter does not help when the searcher
    // does not know which class the student is in.
    if ( $search !== '' ) {
        $like     = '%' . $wpdb->esc_like( $search ) . '%';
        $where   .= ' AND (st.first_name LIKE %s OR st.last_name LIKE %s OR st.admission_number LIKE %s'
                  . " OR CONCAT(st.first_name, ' ', st.last_name) LIKE %s)";
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }

    // Scope enforced in SQL, not just in the dropdown — otherwise a crafted
    // ?class= would list another teacher's students.
    $clause = \EduCBTPro\Core\Gate::class_filter_clause( 'e.class_id' );

    $students = (array) $wpdb->get_results(
        $wpdb->prepare(
            "SELECT st.id, st.admission_number, st.first_name, st.last_name, st.gender, st.status,
                    st.passport_photo, st.parent_information, st.parent_phone, st.parent_email,
                    st.address, c.display_name AS class_name
             FROM {$enrollments} e
             INNER JOIN {$wpdb->prefix}educbt_students st ON st.id = e.student_id
             LEFT JOIN {$class_table} c ON c.id = e.class_id
             WHERE {$where} AND {$clause}
             ORDER BY st.last_name ASC, st.first_name ASC
             LIMIT 300",
            $params
        ),
        ARRAY_A
    );
}

// If the list looks empty when it should not, say why rather than showing nothing.
$integrity = new \EduCBTPro\Services\DataIntegrityService();
$problems  = $integrity->problems( $school_id );
$tallies   = $integrity->counts( $school_id );

// Count pending approval students for a notification badge
$pending_count = 0;
if ( $session_id > 0 ) {
    $pending_count = (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM {$enrollments} e
             INNER JOIN {$wpdb->prefix}educbt_students st ON st.id = e.student_id
             WHERE e.school_id = %d AND e.session_id = %d AND e.status = 'pending_approval'",
            $school_id, $session_id
        )
    );
}

$educbt_title = 'Students';

$educbt_body = static function () use ( $flash, $classes, $students, $session, $session_id, $filter_class, $filter_status, $search, $problems, $tallies, $pending_count ): void {
    require EDUCBT_PRO_PATH . 'templates/portal/partials/flash.php';

    if ( $pending_count > 0 && $filter_status !== 'pending_approval' ) : ?>
        <section class="educbt-card" style="border-left:4px solid #E2A33B;background:#FFF8E8">
            <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px">
                <div>
                    <strong><?php echo esc_html( (string) $pending_count ); ?> student(s) pending approval</strong>
                    <p class="educbt-muted" style="margin:4px 0 0;font-size:12px">
                        A teacher added these students. Review and approve them to activate their enrolment.
                    </p>
                </div>
                <a class="educbt-btn educbt-btn--primary" href="<?php echo esc_url( add_query_arg( 'status', 'pending_approval' ) ); ?>">Review pending</a>
            </div>
        </section>
    <?php endif;

    if ( $session_id === 0 ) {
        echo '<div class="educbt-card"><p class="educbt-note educbt-note--warn">No academic session is set yet, so students cannot be enrolled. Set one under Settings first.</p></div>';
        return;
    }

    if ( empty( $classes ) ) {
        echo '<div class="educbt-card"><p class="educbt-note educbt-note--warn">No classes exist yet. Create classes before registering students.</p></div>';
        return;
    }
    ?>
    <?php if ( ! empty( $problems ) ) : ?>
        <section class="educbt-card" style="border-left:4px solid var(--edu-warn)">
            <h2>Something does not add up</h2>
            <p class="educbt-muted" style="margin-top:-6px">
                <?php
                echo esc_html( sprintf(
                    '%d student record(s), %d active, %d enrolment(s) this session.',
                    (int) $tallies['student_records'],
                    (int) $tallies['active_students'],
                    (int) $tallies['enrolled_this_session']
                ) );
                ?>
            </p>

            <?php foreach ( $problems as $problem ) : ?>
                <div style="border:1px solid var(--edu-line);border-radius:9px;padding:13px;margin-bottom:10px">
                    <p style="margin:0 0 4px">
                        <strong><?php echo esc_html( (string) $problem['label'] ); ?></strong>
                        <span class="educbt-pill educbt-pill--draft"><?php echo esc_html( (string) $problem['count'] ); ?></span>
                    </p>
                    <p class="educbt-muted" style="margin:0"><?php echo esc_html( (string) $problem['detail'] ); ?></p>

                    <?php if ( ! empty( $problem['fixable'] ) ) : ?>
                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:10px"
                              onsubmit="return confirm('Apply this repair?');">
                            <input type="hidden" name="action" value="educbt_repair_data">
                            <input type="hidden" name="key" value="<?php echo esc_attr( (string) $problem['key'] ); ?>">
                            <?php wp_nonce_field( 'educbt_repair_data' ); ?>
                            <button type="submit" class="educbt-btn">Fix this</button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>

    <section class="educbt-card">
        <h2>Enrolled students <span class="educbt-muted">(<?php echo esc_html( (string) count( $students ) ); ?>)</span></h2>

        <form method="get" style="margin-bottom:12px;display:flex;gap:10px;flex-wrap:wrap;align-items:center">
            <input type="search" name="q" value="<?php echo esc_attr( $search ); ?>"
                   placeholder="Search name or student ID"
                   style="flex:1 1 230px;min-width:200px">
            <button type="submit" class="educbt-btn educbt-btn--primary">Search</button>
            <?php if ( $search !== '' ) : ?>
                <a class="educbt-btn" href="<?php echo esc_url( remove_query_arg( 'q' ) ); ?>">Clear</a>
            <?php endif; ?>
            <select name="class" onchange="this.form.submit()">
                <option value="0">All classes</option>
                <?php foreach ( $classes as $class ) : ?>
                    <option value="<?php echo esc_attr( (string) $class['id'] ); ?>" <?php selected( $filter_class, (int) $class['id'] ); ?>>
                        <?php echo esc_html( (string) $class['display_name'] ); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <select name="status" onchange="this.form.submit()">
                <option value="active" <?php selected( $filter_status, 'active' ); ?>>Active</option>
                <?php // "inactive" IS suspension — the value is historical, the label
                      // should say what the school actually did. ?>
                <option value="inactive" <?php selected( $filter_status, 'inactive' ); ?>>Suspended</option>
                <option value="pending_approval" <?php selected( $filter_status, 'pending_approval' ); ?>>Pending Approval</option>
                <option value="withdrawn" <?php selected( $filter_status, 'withdrawn' ); ?>>Withdrawn</option>
                <option value="expelled" <?php selected( $filter_status, 'expelled' ); ?>>Expelled</option>
                <option value="all" <?php selected( $filter_status, 'all' ); ?>>All</option>
            </select>
            <noscript><button class="educbt-btn" type="submit">Filter</button></noscript>
        </form>

        <?php if ( empty( $students ) ) : ?>
            <p class="educbt-muted">No students enrolled yet.</p>
        <?php else : ?>
            <table class="educbt-table">
                <thead><tr><th>Admission no.</th><th>Student</th><th>Class</th><th>Status</th><th></th></tr></thead>
                <tbody>
                <?php foreach ( $students as $student ) :
                    $sid = (int) $student['id']; ?>
                    <tr>
                        <td><code><?php echo esc_html( (string) $student['admission_number'] ); ?></code></td>
                        <td>
                            <?php if ( ! empty( $student['passport_photo'] ) ) : ?>
                                <img src="<?php echo esc_url( (string) $student['passport_photo'] ); ?>" alt=""
                                     style="width:26px;height:32px;object-fit:cover;border-radius:4px;vertical-align:middle;margin-right:7px">
                            <?php endif; ?>
                            <?php echo esc_html( $student['first_name'] . ' ' . $student['last_name'] ); ?>
                        </td>
                        <td><?php echo esc_html( (string) $student['class_name'] ); ?></td>
                        <td>
                            <?php $st_status = (string) ( $student['status'] ?? 'active' ); ?>
                            <?php if ( $st_status === 'pending_approval' ) : ?>
                                <span class="educbt-pill educbt-pill--draft">Pending</span>
                            <?php elseif ( $st_status === 'withdrawn' ) : ?>
                                <span class="educbt-pill" style="background:#fee2e2;color:#991b1b">Withdrawn</span>
                            <?php // Status is reported here, not changed here. Suspending,
                                  // withdrawing or expelling a student are decisions with
                                  // consequences, and they belong on the student's own
                                  // record next to the history they act on — not behind a
                                  // small button in a list of thirty names. ?>
                            <?php elseif ( $st_status === 'inactive' ) : ?>
                                <span class="educbt-pill" style="background:#fef3c7;color:#92400e">Suspended</span>
                            <?php elseif ( $st_status === 'expelled' ) : ?>
                                <span class="educbt-pill" style="background:#fee2e2;color:#991b1b">Expelled</span>
                            <?php else : ?>
                                <span class="educbt-pill educbt-pill--published">Active</span>
                            <?php endif; ?>
                        </td>
                        <td style="white-space:nowrap">
                            <?php if ( $st_status === 'pending_approval' ) : ?>
                                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline"
                                      onsubmit="return confirm('Approve this student? They will be activated and enrolled.');">
                                    <input type="hidden" name="action" value="educbt_approve_student">
                                    <input type="hidden" name="student_id" value="<?php echo esc_attr( (string) $sid ); ?>">
                                    <?php wp_nonce_field( 'educbt_approve_student' ); ?>
                                    <button type="submit" class="educbt-btn educbt-btn--primary" style="font-size:.82rem;padding:4px 12px">Approve</button>
                                </form>
                                <button type="button" class="educbt-btn"
                                        onclick="var r=document.getElementById('s-<?php echo esc_attr( (string) $sid ); ?>');r.toggleAttribute('hidden');">Edit</button>
                            <?php else : ?>
                                <button type="button" class="educbt-btn"
                                        onclick="var r=document.getElementById('s-<?php echo esc_attr( (string) $sid ); ?>');r.toggleAttribute('hidden');">Edit</button>
                            <?php endif; ?>
                            <?php // Read the record without being able to change it by accident. ?>
                            <a class="educbt-btn educbt-btn--primary" style="font-size:.82rem;padding:4px 12px"
                               href="<?php echo esc_url( home_url( '/portal/school/student/?id=' . $sid ) ); ?>">View</a>
                        </td>
                    </tr>
                    <tr id="s-<?php echo esc_attr( (string) $sid ); ?>" hidden>
                        <td colspan="5" style="background:var(--edu-bg)">
                            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="educbt-form" style="padding:12px 0" enctype="multipart/form-data">
                                <input type="hidden" name="action" value="educbt_update_student">
                                <input type="hidden" name="student_id" value="<?php echo esc_attr( (string) $sid ); ?>">
                                <?php wp_nonce_field( 'educbt_update_student' ); ?>
                                <input type="hidden" name="existing_photo" value="<?php echo esc_attr( (string) $student['passport_photo'] ); ?>">

                                <div style="margin-bottom:12px">
                                    <label>Passport photograph</label>
                                    <?php if ( ! empty( $student['passport_photo'] ) ) : ?>
                                        <div style="margin-bottom:6px;">
                                            <img src="<?php echo esc_url( (string) $student['passport_photo'] ); ?>" alt="" style="max-width:60px; max-height:75px; object-fit:cover; border-radius:6px; border:1px solid #ccc;">
                                        </div>
                                    <?php endif; ?>
                                    <input name="photo" type="file" accept="image/*" onchange="educbtPreviewStudentPhoto(this, 'edit-photo-preview-<?php echo esc_attr( (string) $sid ); ?>')">
                                    <div id="edit-photo-preview-<?php echo esc_attr( (string) $sid ); ?>" style="margin-top:8px; display:none;">
                                        <img src="" alt="Preview" style="max-width:60px; max-height:75px; object-fit:cover; border-radius:6px; border:1px solid #ccc;">
                                    </div>
                                </div>

                                <div class="educbt-grid">
                                    <div>
                                        <label>Student ID</label>
                                        <input name="admission_number" type="text" value="<?php echo esc_attr( (string) $student['admission_number'] ); ?>">
                                        <small class="educbt-muted">This is also the student's login username.</small>
                                    </div>
                                    <div><label>First name</label><input name="first_name" type="text" value="<?php echo esc_attr( (string) $student['first_name'] ); ?>" required></div>
                                    <div><label>Surname</label><input name="last_name" type="text" value="<?php echo esc_attr( (string) $student['last_name'] ); ?>" required></div>
                                    <div>
                                        <label>Sex</label>
                                        <select name="gender">
                                            <option value="">—</option>
                                            <option value="male" <?php selected( (string) $student['gender'], 'male' ); ?>>Male</option>
                                            <option value="female" <?php selected( (string) $student['gender'], 'female' ); ?>>Female</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label>Move to class</label>
                                        <select name="class_id">
                                            <?php foreach ( $classes as $c ) : ?>
                                                <option value="<?php echo esc_attr( (string) $c['id'] ); ?>" <?php selected( (string) $c['display_name'], (string) $student['class_name'] ); ?>>
                                                    <?php echo esc_html( (string) $c['display_name'] ); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>

                                <div class="educbt-grid" style="margin-top:12px">
                                    <div>
                                        <label>Parent / Guardian name</label>
                                        <input name="parent_information" type="text" value="<?php echo esc_attr( (string) ( $student['parent_information'] ?? '' ) ); ?>">
                                    </div>
                                    <div>
                                        <label>Parent phone</label>
                                        <input name="parent_phone" type="text" value="<?php echo esc_attr( (string) ( $student['parent_phone'] ?? '' ) ); ?>">
                                    </div>
                                    <div>
                                        <label>Parent email</label>
                                        <input name="parent_email" type="email" value="<?php echo esc_attr( (string) ( $student['parent_email'] ?? '' ) ); ?>">
                                    </div>
                                </div>
                                <label style="margin-top:8px">Address</label>
                                <textarea name="address" rows="2"><?php echo esc_textarea( (string) ( $student['address'] ?? '' ) ); ?></textarea>

                                <button type="submit" class="educbt-btn educbt-btn--primary" style="margin-top:12px">Save changes</button>
                            </form>

                            <div style="border-top:1px solid var(--edu-line);padding-top:12px;display:flex;gap:8px;flex-wrap:wrap">
                                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
                                      onsubmit="return confirm('Reset this password to the student\'s surname?');">
                                    <input type="hidden" name="action" value="educbt_reset_student_password">
                                    <input type="hidden" name="student_id" value="<?php echo esc_attr( (string) $sid ); ?>">
                                    <?php wp_nonce_field( 'educbt_reset_student_password' ); ?>
                                    <button type="submit" class="educbt-btn">Reset password</button>
                                </form>

                                <?php $st_stat = (string) ( $student['status'] ?? 'active' ); ?>
                                <?php if ( $st_stat === 'withdrawn' ) : ?>
                                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
                                          onsubmit="return confirm('Reactivate this withdrawn student?');">
                                        <input type="hidden" name="action" value="educbt_activate_student">
                                        <input type="hidden" name="student_id" value="<?php echo esc_attr( (string) $sid ); ?>">
                                        <?php wp_nonce_field( 'educbt_activate_student' ); ?>
                                        <button type="submit" class="educbt-btn educbt-btn--primary">Reinstate</button>
                                    </form>
                                <?php elseif ( $st_stat === 'inactive' ) : ?>
                                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
                                          onsubmit="return confirm('Reactivate this student? They will regain portal access.');">
                                        <input type="hidden" name="action" value="educbt_activate_student">
                                        <input type="hidden" name="student_id" value="<?php echo esc_attr( (string) $sid ); ?>">
                                        <?php wp_nonce_field( 'educbt_activate_student' ); ?>
                                        <button type="submit" class="educbt-btn educbt-btn--primary">Reactivate</button>
                                <?php else : ?>
                                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
                                          onsubmit="return confirm('Deactivate this student? They will lose portal access until reactivated. Use for suspension, unpaid fees, etc.');">
                                        <input type="hidden" name="action" value="educbt_deactivate_student">
                                        <input type="hidden" name="student_id" value="<?php echo esc_attr( (string) $sid ); ?>">
                                        <?php wp_nonce_field( 'educbt_deactivate_student' ); ?>
                                        <button type="submit" class="educbt-btn" style="color:#92400e;border-color:#fcd34d">Deactivate</button>
                                    </form>
                                <?php endif; ?>
                                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
                                      onsubmit="return confirm('Withdraw this student? Their results are kept. Use when they leave the school.');">
                                    <input type="hidden" name="action" value="educbt_withdraw_student">
                                    <input type="hidden" name="student_id" value="<?php echo esc_attr( (string) $sid ); ?>">
                                    <?php wp_nonce_field( 'educbt_withdraw_student' ); ?>
                                    <button type="submit" class="educbt-btn" style="color:#b91c1c;border-color:#f3c9c9">Withdraw</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>

    <details>
        <summary><h2>Register a student</h2></summary>
        <section class="educbt-card">
            <p class="educbt-muted" style="margin-top:-6px">
                Session <?php echo esc_html( (string) ( $session['title'] ?? '' ) ); ?>.
                The admission number and first password are generated automatically.
            </p>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="educbt-form" enctype="multipart/form-data">
                <input type="hidden" name="action" value="educbt_register_student">
                <?php wp_nonce_field( 'educbt_register_student' ); ?>

                <?php
                // Passport first: it is the thing the office has in their hand at intake,
                // and asking for it last means it is the thing that never gets added.
                ?>
                <div style="margin-bottom:16px">
                    <label>Passport photograph</label>
                    <input name="photo" type="file" accept="image/*" onchange="educbtPreviewStudentPhoto(this, 'reg-photo-preview')">
                    <div id="reg-photo-preview" style="margin-top:8px; display:none;">
                        <img src="" alt="Preview" style="max-width:80px; max-height:100px; object-fit:cover; border-radius:6px; border:1px solid #ccc;">
                    </div>
                </div>

                <div class="educbt-grid">
                    <div>
                        <label for="admission_number">Student ID</label>
                        <input id="admission_number" name="admission_number" type="text" placeholder="Leave blank to generate one">
                    </div>
                    <div>
                        <label for="first_name">First name *</label>
                        <input id="first_name" name="first_name" type="text" required>
                    </div>
                    <div>
                        <label for="last_name">Surname *</label>
                        <input id="last_name" name="last_name" type="text" required>
                    </div>
                    <div>
                        <label for="gender">Sex</label>
                        <select id="gender" name="gender">
                            <option value="">—</option>
                            <option value="male">Male</option>
                            <option value="female">Female</option>
                        </select>
                    </div>
                    <div>
                        <label for="date_of_birth">Date of birth</label>
                        <input id="date_of_birth" name="date_of_birth" type="date">
                    </div>
                    <div>
                        <label for="class_id">Class *</label>
                        <select id="class_id" name="class_id" required>
                            <option value="">Choose a class</option>
                            <?php foreach ( $classes as $class ) : ?>
                                <option value="<?php echo esc_attr( (string) $class['id'] ); ?>">
                                    <?php echo esc_html( (string) $class['display_name'] ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <details style="margin-top:14px">
                    <summary>Parent / guardian details (optional)</summary>
                    <p class="educbt-muted">Adding these now sends the parent an invitation to create their own portal account.</p>
                    <div class="educbt-grid">
                        <div><label for="g_first">Guardian first name</label><input id="g_first" name="guardian_first_name" type="text"></div>
                        <div><label for="g_last">Guardian surname</label><input id="g_last" name="guardian_last_name" type="text"></div>
                        <div><label for="g_email">Guardian email</label><input id="g_email" name="guardian_email" type="email"></div>
                        <div><label for="g_phone">Guardian phone</label><input id="g_phone" name="guardian_phone" type="text"></div>
                    </div>
                </details>

                <button type="submit" class="educbt-btn educbt-btn--primary" style="margin-top:16px">Register student</button>
            </form>
        </section>
    </details>

    <details>
        <summary><h2>Import many students</h2></summary>
        <section class="educbt-card">
            <p class="educbt-muted" style="margin-top:-6px">
                Download the template, fill it in, upload it back. Only four columns:
                <code>first_name</code>, <code>last_name</code>, <code>gender</code>, <code>date_of_birth</code>.
                Admission numbers and passwords are generated — do not add them.
            </p>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-right:10px">
                <input type="hidden" name="action" value="educbt_export_students">
                <?php wp_nonce_field( 'educbt_export_students' ); ?>
                <input type="hidden" name="class_id" value="<?php echo esc_attr( (string) $filter_class ); ?>">
                <button type="submit" class="educbt-btn">Download template / export</button>
            </form>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data"
                  class="educbt-form" style="margin-top:14px">
                <input type="hidden" name="action" value="educbt_import_students">
                <?php wp_nonce_field( 'educbt_import_students' ); ?>
                <div class="educbt-grid">
                    <div>
                        <label for="import_class">Into class *</label>
                        <select id="import_class" name="class_id" required>
                            <option value="">Choose a class</option>
                            <?php foreach ( $classes as $class ) : ?>
                                <option value="<?php echo esc_attr( (string) $class['id'] ); ?>"><?php echo esc_html( (string) $class['display_name'] ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="csv">CSV file *</label>
                        <input id="csv" name="csv" type="file" accept=".csv,text/csv" required>
                    </div>
                </div>
                <button type="submit" class="educbt-btn educbt-btn--primary" style="margin-top:14px">Import students</button>
            </form>
        </section>
    </details>
    <?php
};
?>
<script>
function educbtPreviewStudentPhoto(input, previewId) {
    var wrap = document.getElementById(previewId);
    if (!wrap) return;
    var img = wrap.querySelector('img');
    if (input && input.files && input.files[0]) {
        var reader = new FileReader();
        reader.onload = function(e) {
            if (img) img.src = e.target.result;
            wrap.style.display = 'block';
        };
        reader.readAsDataURL(input.files[0]);
    } else {
        if (img) img.src = '';
        wrap.style.display = 'none';
    }
}
</script>
<?php
require EDUCBT_PRO_PATH . 'templates/portal/shell.php';
