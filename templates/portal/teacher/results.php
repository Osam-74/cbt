<?php
/**
 * A class teacher's view of their class.
 *
 * Shows marking progress (which subjects are still being marked) and a class
 * results table. The results table is ALWAYS visible — before compilation it
 * shows student names with "Pending…" in all other columns. After compilation
 * it shows "Compiled… Awaiting Approval". After approval, teachers get a
 * "View" button to open the report sheet and append remarks. After publishing,
 * the button becomes "Download".
 *
 * @var array<string,mixed> $educbt
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

global $wpdb;

$school_id = (int) $educbt['school_id'];
$flash     = \EduCBTPro\Frontend\PortalActions::flash();

$year       = new \EduCBTPro\Services\AcademicYearService();
$session    = $year->current_session( $school_id );
$term       = $year->current_term( $school_id );
$session_id = (int) ( $session['id'] ?? 0 );
$term_id    = (int) ( $term['id'] ?? 0 );

$structure = new \EduCBTPro\Services\AcademicStructureService();
$classes   = $structure->list_classes( $school_id );

// Class results should only show classes the teacher HEADS (class_teacher),
// not classes where they merely teach a subject. A subject teacher who teaches
// Maths in JSS2 but heads JSS1 must not see JSS2's class results.
if ( ! $educbt['scope']->is_school_wide() ) {
    $headed = $educbt['scope']->assignments()['class_teacher'];
    $classes = array_values(
        array_filter( $classes, static fn( array $c ): bool => in_array( (int) $c['id'], $headed, true ) )
    );
}

$class_id = (int) ( $_GET['class'] ?? ( $classes[0]['id'] ?? 0 ) );

$students   = [];
$subjects   = [];
$completion = [];
$expected   = 0;
$class_status = '';

if ( $class_id > 0 && $term_id > 0 ) {
    $enrolments = \EduCBTPro\Core\Schema::table( 'enrollments' );
    $registered = \EduCBTPro\Core\Schema::table( 'student_subjects' );
    $subs_table = \EduCBTPro\Core\Schema::table( 'subjects_v2' );
    $scores     = \EduCBTPro\Core\Schema::table( 'assessment_scores' );
    $components = \EduCBTPro\Core\Schema::table( 'assessment_components' );
    $stu_table  = $wpdb->prefix . 'educbt_students';

    $students = (array) $wpdb->get_results(
        $wpdb->prepare(
            "SELECT st.id, st.admission_number, st.first_name, st.last_name
             FROM {$enrolments} e
             INNER JOIN {$stu_table} st ON st.id = e.student_id
             WHERE e.school_id = %d AND e.class_id = %d AND e.session_id = %d AND e.status = 'active'
             ORDER BY st.last_name ASC",
            $school_id, $class_id, $session_id
        ),
        ARRAY_A
    );

    $subjects = (array) $wpdb->get_results(
        $wpdb->prepare(
            "SELECT DISTINCT s.id, s.name, s.code FROM {$registered} rs
             INNER JOIN {$subs_table} s ON s.id = rs.subject_id
             INNER JOIN {$enrolments} e ON e.student_id = rs.student_id AND e.session_id = rs.session_id
             WHERE e.class_id = %d AND e.session_id = %d
             ORDER BY s.name ASC",
            $class_id, $session_id
        ),
        ARRAY_A
    );

    $expected = absint(
        $wpdb->get_var(
            $wpdb->prepare( "SELECT COUNT(*) FROM {$components} WHERE school_id = %d AND status = 'active'", $school_id )
        )
    );

    foreach ( (array) $wpdb->get_results(
        $wpdb->prepare(
            "SELECT student_id, subject_id, COUNT(*) AS entered
             FROM {$scores}
             WHERE school_id = %d AND class_id = %d AND term_id = %d
             GROUP BY student_id, subject_id",
            $school_id, $class_id, $term_id
        ),
        ARRAY_A
    ) as $row ) {
        $completion[ (int) $row['student_id'] ][ (int) $row['subject_id'] ] = (int) $row['entered'];
    }

    // Get the class result status
    $term_results_t = \EduCBTPro\Core\Schema::table( 'term_results' );
    $class_status = (string) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT status FROM {$term_results_t} WHERE school_id = %d AND class_id = %d AND term_id = %d LIMIT 1",
            $school_id, $class_id, $term_id
        )
    );
}

$educbt_title = 'Class Results';

$educbt_body = static function () use ( $flash, $classes, $class_id, $students, $subjects, $completion, $expected, $term, $session, $school_id, $session_id, $term_id, $class_status ): void {
    require EDUCBT_PRO_PATH . 'templates/portal/partials/flash.php';

    if ( empty( $classes ) ) {
        echo '<div class="educbt-card"><p class="educbt-muted">You are not the class teacher of any class.</p></div>';
        return;
    }
    ?>
    <section class="educbt-card no-print">
        <form method="get" class="educbt-form" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap">
            <div style="flex:1 1 220px">
                <label for="class">Class</label>
                <select id="class" name="class" onchange="this.form.submit()">
                    <?php foreach ( $classes as $c ) : ?>
                        <option value="<?php echo esc_attr( (string) $c['id'] ); ?>" <?php selected( (int) $c['id'], $class_id ); ?>>
                            <?php echo esc_html( (string) $c['display_name'] ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <span class="educbt-muted"><?php echo esc_html( (string) ( $session['title'] ?? '' ) . ' · ' . (string) ( $term['title'] ?? '' ) ); ?></span>
        </form>
    </section>

    <?php if ( empty( $students ) ) : ?>
        <div class="educbt-card"><p class="educbt-muted">No students enrolled in this class.</p></div>
        <?php return; ?>
    <?php endif; ?>

    <section class="educbt-card">
        <h2>Marking progress <span class="educbt-muted">(<?php echo esc_html( (string) count( $students ) ); ?> students)</span></h2>
        <p class="educbt-muted" style="margin-top:-6px">
            A tick means every assessment for that subject has been entered. A dash means
            the subject teacher is still working on it.
        </p>

        <div style="overflow-x:auto">
            <table class="educbt-table" style="min-width:640px">
                <thead>
                    <tr>
                        <th>Student</th>
                        <?php foreach ( $subjects as $s ) : ?>
                            <th title="<?php echo esc_attr( (string) $s['name'] ); ?>"><?php echo esc_html( (string) ( $s['code'] ?: $s['name'] ) ); ?></th>
                        <?php endforeach; ?>
                        <th>Done</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $students as $student ) :
                    $sid = (int) $student['id'];
                    $done = 0; ?>
                    <tr>
                        <td style="white-space:nowrap"><?php echo esc_html( $student['last_name'] . ', ' . $student['first_name'] ); ?></td>
                        <?php foreach ( $subjects as $s ) :
                            $entered = (int) ( $completion[ $sid ][ (int) $s['id'] ] ?? 0 );
                            $complete = $expected > 0 && $entered >= $expected;
                            if ( $complete ) { $done++; } ?>
                            <td>
                                <?php if ( $complete ) : ?>
                                    <span style="color:var(--edu-accent);font-weight:700">&#10003;</span>
                                <?php elseif ( $entered > 0 ) : ?>
                                    <span class="educbt-muted" title="partly entered"><?php echo esc_html( $entered . '/' . $expected ); ?></span>
                                <?php else : ?>
                                    <span class="educbt-muted">&ndash;</span>
                                <?php endif; ?>
                            </td>
                        <?php endforeach; ?>
                        <td><?php echo esc_html( $done . '/' . count( $subjects ) ); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <?php
    // Class Results table — ALWAYS visible.
    // - Before compiled: student names only, all other columns "Pending…"
    // - Compiled: "Compiled… Awaiting Approval" badge
    // - Approved: "Approved… Awaiting Publish" badge, View button (no download)
    // - Published: full results, Download button
    $show_results = in_array( $class_status, [ 'approved', 'published' ], true );
    $broadsheet = [];
    if ( $show_results ) {
        $broadsheet = ( new \EduCBTPro\Services\BroadsheetService() )->build( $school_id, $class_id, $session_id, $term_id );
    }

    // Status badge text
    $status_label = '';
    $status_class = '';
    if ( $class_status === '' ) {
        $status_label = 'Pending… Awaiting Compilation';
        $status_class = 'draft';
    } elseif ( $class_status === 'compiled' ) {
        $status_label = 'Compiled… Awaiting Approval';
        $status_class = 'draft';
    } elseif ( $class_status === 'approved' ) {
        $status_label = 'Approved… Awaiting Publish';
        $status_class = 'approved';
    } elseif ( $class_status === 'published' ) {
        $status_label = 'Published';
        $status_class = 'published';
    }
    ?>

    <section class="educbt-card">
        <h2 style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
            Class Results
            <?php if ( $status_label !== '' ) : ?>
                <span class="educbt-pill educbt-pill--<?php echo esc_attr( $status_class ); ?>"><?php echo esc_html( $status_label ); ?></span>
            <?php endif; ?>
            <?php if ( $class_status === 'published' ) : ?>
                <a class="educbt-btn educbt-btn--primary" style="font-size:.82rem;padding:5px 14px;margin-left:auto;text-decoration:none"
                   href="<?php echo esc_url( home_url( '/portal/teacher/bulk-report/?class_id=' . (int) $class_id . '&term_id=' . (int) $term_id ) ); ?>"
                   target="_blank">Download All Reports</a>
            <?php endif; ?>
        </h2>

        <?php if ( $class_status === 'compiled' ) : ?>
            <p class="educbt-note educbt-note--warn" style="margin-bottom:14px">
                Results have been compiled and sent to school management for approval.
                They will appear here once approved.
            </p>
        <?php endif; ?>

        <div style="overflow-x:auto">
            <table class="educbt-table" style="min-width:540px">
                <thead>
                    <tr>
                        <th>Student</th>
                        <?php foreach ( ( $show_results && ! empty( $broadsheet['subjects'] ) ? $broadsheet['subjects'] : $subjects ) as $subject ) : ?>
                            <th title="<?php echo esc_attr( (string) $subject['name'] ); ?>"><?php echo esc_html( (string) ( $subject['code'] ?: $subject['name'] ) ); ?></th>
                        <?php endforeach; ?>
                        <?php if ( $show_results ) : ?>
                            <th>Total</th><th>Avg</th><th>Pos</th>
                        <?php endif; ?>
                        <th>Report</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ( $show_results && ! empty( $broadsheet['rows'] ) ) : ?>
                    <?php foreach ( $broadsheet['rows'] as $row ) : ?>
                        <tr>
                            <td style="white-space:nowrap"><?php echo esc_html( (string) $row['name'] ); ?></td>
                            <?php foreach ( $broadsheet['subjects'] as $subject ) :
                                $cell = $row['cells'][ $subject['id'] ] ?? null; ?>
                                <td>
                                    <?php if ( $cell === null ) : ?>
                                        <span class="educbt-muted">&mdash;</span>
                                    <?php else : ?>
                                        <?php echo esc_html( (string) (float) $cell['total'] ); ?>
                                        <span class="educbt-muted" style="font-size:11px"><?php echo esc_html( (string) $cell['grade'] ); ?></span>
                                    <?php endif; ?>
                                </td>
                            <?php endforeach; ?>
                            <td><strong><?php echo $row['total'] > 0 ? esc_html( (string) (float) $row['total'] ) : '<span class="educbt-muted">&mdash;</span>'; ?></strong></td>
                            <td><?php echo $row['average'] > 0 ? esc_html( (string) (float) $row['average'] ) : '<span class="educbt-muted">&mdash;</span>'; ?></td>
                            <td><?php echo $row['position'] > 0 ? esc_html( \EduCBTPro\Services\ReportCardDocument::ordinal( (int) $row['position'] ) ) : '<span class="educbt-muted">&mdash;</span>'; ?></td>
                            <td>
                                <a class="educbt-btn" style="font-size:.78rem;padding:4px 10px"
                                   href="<?php echo esc_url( home_url( '/portal/teacher/report/?student_id=' . (int) $row['student_id'] . '&term_id=' . (int) $term_id ) ); ?>"
                                   target="_blank">View</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else : ?>
                    <?php foreach ( $students as $student ) : ?>
                        <tr>
                            <td style="white-space:nowrap"><?php echo esc_html( $student['last_name'] . ', ' . $student['first_name'] ); ?></td>
                            <?php foreach ( $subjects as $s ) : ?>
                                <td><span class="educbt-muted">&mdash;</span></td>
                            <?php endforeach; ?>
                            <?php if ( $show_results ) : ?>
                                <td><span class="educbt-muted">&mdash;</span></td>
                                <td><span class="educbt-muted">&mdash;</span></td>
                                <td><span class="educbt-muted">&mdash;</span></td>
                            <?php endif; ?>
                            <td><span class="educbt-muted">Pending…</span></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if ( $class_status === 'approved' ) : ?>
            <p class="educbt-muted" style="margin-top:8px">
                Results have been approved by school management. Click "View" to open each
                student's report sheet, append your remark, and save.
            </p>
        <?php elseif ( $class_status === 'published' ) : ?>
            <p class="educbt-muted" style="margin-top:8px">
                Results have been published. Click "Download All Reports" above to get a
                single printable PDF with every student's report sheet.
            </p>
        <?php endif; ?>
    </section>


    <?php
};

require EDUCBT_PRO_PATH . 'templates/portal/shell.php';
