<?php
/**
 * Teacher Timetable — shows the general exam timetable to all subject teachers.
 *
 * Class teachers and subject teachers can filter by their assigned class(es) to see
 * only the papers relevant to their class.
 *
 * Unlike the exam officer's timetable, teachers see a read-only view — no
 * rescheduling or invigilator assignment. They only see timetables that
 * have been released by the exam office.
 *
 * @var array<string,mixed> $educbt
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

global $wpdb;
$school_id = (int) $educbt['school_id'];
$scope     = $educbt['scope'] ?? new \EduCBTPro\Core\Scope();
$actor     = $scope->actor();

// Get exam series for this school
$series_table = \EduCBTPro\Core\Schema::table( 'exam_series' );
$series       = (array) $wpdb->get_results(
    $wpdb->prepare( "SELECT id, title FROM {$series_table} WHERE school_id = %d ORDER BY id DESC", $school_id ),
    ARRAY_A
);

$series_id = (int) ( $_GET['series'] ?? ( $series[0]['id'] ?? 0 ) );

// Check if timetable has been released
$released = $series_id > 0 && ( new \EduCBTPro\Services\ExamTimetableService() )->is_released( $school_id, $series_id );

// Check if the selected series is a practice series — practice exams are always
// available and do not require timetable release.
$is_practice_series = false;
if ( $series_id > 0 ) {
    $series_type_row = (array) $wpdb->get_row(
        $wpdb->prepare(
            'SELECT series_type FROM ' . \EduCBTPro\Core\Schema::table( 'exam_series' ) . ' WHERE id = %d AND school_id = %d',
            $series_id, $school_id
        ),
        ARRAY_A
    );
    $is_practice_series = (string) ( $series_type_row['series_type'] ?? '' ) === 'practice';
}

// Class filter
$can_manage   = \EduCBTPro\Core\Gate::allows( \EduCBTPro\Core\Capabilities::MANAGE_PAPERS );
$class_filter = isset( $_GET['class'] ) ? absint( $_GET['class'] ) : 0;

// Get classes this teacher is assigned to (subject teacher or class teacher),
// or all active classes if user has school-wide scope.
$classes_t     = \EduCBTPro\Core\Schema::table( 'classes' );
$assignments_t = \EduCBTPro\Core\Schema::table( 'staff_assignments' );

if ( $scope->is_school_wide() ) {
    $my_classes = (array) $wpdb->get_results(
        $wpdb->prepare(
            "SELECT id, display_name
             FROM {$classes_t}
             WHERE school_id = %d AND status = 'active'
             ORDER BY display_name ASC",
            $school_id
        ),
        ARRAY_A
    );
} else {
    $my_classes = (array) $wpdb->get_results(
        $wpdb->prepare(
            "SELECT DISTINCT c.id, c.display_name
             FROM {$classes_t} c
             INNER JOIN {$assignments_t} a ON a.class_id = c.id
             WHERE a.staff_id = %d AND a.school_id = %d
               AND a.assignment_type IN ('subject_teacher','class_teacher','form_teacher')
               AND a.status = 'active'
               AND c.status = 'active'
             ORDER BY c.display_name ASC",
            absint( $actor['id'] ?? 0 ),
            $school_id
        ),
        ARRAY_A
    );
}

// Get the timetable data
$timetable_svc = new \EduCBTPro\Services\TimetableService();
$grouped       = [];

if ( $released || $can_manage || $is_practice_series ) {
    if ( $class_filter > 0 ) {
        // Filtered by a specific class
        $grouped = $timetable_svc->for_class( $school_id, $class_filter, $series_id );
    } elseif ( ! isset( $_GET['class'] ) && ! $scope->is_school_wide() && count( $my_classes ) === 1 ) {
        // Teacher with exactly one class — show that class by default when no explicit filter set
        $class_filter = (int) $my_classes[0]['id'];
        $grouped      = $timetable_svc->for_class( $school_id, $class_filter, $series_id );
    } else {
        // Show the full school timetable
        $grouped = $series_id > 0 ? $timetable_svc->for_series( $school_id, $series_id ) : [];
    }
}

$series_row = $series_id > 0
    ? (array) $wpdb->get_row(
        $wpdb->prepare( "SELECT * FROM {$series_table} WHERE id = %d AND school_id = %d", $series_id, $school_id ),
        ARRAY_A
    )
    : [];

// Papers this teacher invigilates — only these should show access codes.
$invig_papers = [];
if ( $school_id > 0 ) {
    $_invig_t = \EduCBTPro\Core\Schema::table( 'paper_invigilators' );
    $_staff_id = absint( $actor['id'] ?? 0 );
    if ( $_staff_id > 0 ) {
        $_rows = (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT paper_id FROM {$_invig_t} WHERE school_id = %d AND staff_id = %d",
                $school_id, $_staff_id
            ),
            ARRAY_A
        );
        foreach ( $_rows as $_r ) {
            $invig_papers[] = (int) $_r['paper_id'];
        }
    }
}

$educbt_title = 'Exam Timetable';

$educbt_body = static function () use (
    $series, $series_id, $grouped, $series_row,
    $my_classes, $class_filter, $released, $can_manage, $school_id, $is_practice_series, $invig_papers
): void {
    ?>

    <?php if ( ! $released && ! $can_manage && ! $is_practice_series ): ?>
        <div class="educbt-note educbt-note--warn">
            The exam timetable for this series has not been released yet. You will see it here once the exam office publishes it.
        </div>
    <?php elseif ( $is_practice_series && ! $released && ! $can_manage ): ?>
        <div class="educbt-note educbt-note--info">
            Practice exam questions are available to students at any time. There is no scheduled timetable for practice exams.
        </div>
    <?php endif; ?>

    <section class="educbt-card">
        <form method="get" action="" class="educbt-filters" style="margin-bottom:20px">
            <div class="educbt-field">
                <label>Exam Series</label>
                <select name="series">
                    <?php foreach ( $series as $s ): ?>
                        <option value="<?php echo esc_attr( $s['id'] ); ?>" <?php selected( $series_id, (int) $s['id'] ); ?>>
                            <?php echo esc_html( $s['title'] ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <?php if ( ! empty( $my_classes ) ): ?>
            <div class="educbt-field">
                <label>Class filter</label>
                <select name="class">
                    <option value="0">All classes (general)</option>
                    <?php foreach ( $my_classes as $cl ): ?>
                        <option value="<?php echo esc_attr( $cl['id'] ); ?>" <?php selected( $class_filter, (int) $cl['id'] ); ?>>
                            <?php echo esc_html( $cl['display_name'] ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>

            <button type="submit" class="educbt-btn educbt-btn--primary">View</button>
            <?php if ( $class_filter > 0 ): ?>
                <a class="educbt-btn educbt-btn--ghost" href="<?php echo esc_url( add_query_arg( [ 'class' => 0 ] ) ); ?>">Show all classes</a>
            <?php endif; ?>
        </form>

        <?php if ( empty( $grouped ) ): ?>
            <p class="educbt-muted" style="padding:30px 0;text-align:center">
                No exam papers scheduled for this series yet.
            </p>
        <?php else: ?>
            <?php
            $current_label = '';
            foreach ( $grouped as $date => $papers ):
                $display_date = $date ? wp_date( 'l, j F Y', strtotime( $date ) ) : 'Unscheduled';
                if ( $display_date === $current_label ) { continue; }
                $current_label = $display_date;
            ?>
                <h3 style="margin:24px 0 10px;font-size:15px;color:var(--forest-dark)"><?php echo esc_html( $display_date ); ?></h3>
                <table class="educbt-table">
                    <thead>
                        <tr>
                            <th>Time</th>
                            <th>Subject</th>
                            <th>Duration</th>
                            <th>Venue</th>
                            <?php /* Access codes only shown for papers the teacher invigilates */ ?>
                            <th>Questions</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $papers as $p ):
                            $time = $p['scheduled_at'] ? wp_date( 'g:i A', strtotime( (string) $p['scheduled_at'] . ' UTC' ) ) : '—';
                            $end  = $p['scheduled_at'] ? wp_date( 'g:i A', strtotime( (string) $p['scheduled_at'] . ' UTC' ) + (int) $p['duration_seconds'] ) : '—';
                        ?>
                        <tr>
                            <td><?php echo esc_html( $time . ' – ' . $end ); ?></td>
                            <td><strong><?php echo esc_html( $p['subject_name'] ?? '—' ); ?></strong></td>
                            <td><?php echo esc_html( (string) ( $p['duration_minutes'] ?? round( (int) $p['duration_seconds'] / 60 ) ) . ' min' ); ?></td>
                            <td><?php echo esc_html( $p['venue'] ?: '—' ); ?></td>
                            <td>
                                <?php if ( ! empty( $p['access_code'] ) && (string) $p['status'] === 'published' && in_array( (int) $p['id'], $invig_papers, true ) ) : ?>
                                    <code style="font-size:13px;font-weight:700;letter-spacing:1px;background:#f0fdf4;padding:2px 8px;border-radius:4px;color:#166534"><?php echo esc_html( (string) $p['access_code'] ); ?></code>
                                <?php else : ?>
                                    <span class="educbt-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html( (string) ( $p['question_count'] ?? 0 ) ); ?></td>
                            <td>
                                <span class="educbt-pill educbt-pill--<?php echo esc_attr( (string) $p['status'] ); ?>">
                                    <?php echo esc_html( ucfirst( (string) $p['status'] ) ); ?>
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endforeach; ?>
        <?php endif; ?>
    </section>

    <style>
    .educbt-filters { display:flex; flex-wrap:wrap; gap:12px; align-items:flex-end; }
    .educbt-field label { display:block; font-size:12px; font-weight:600; margin-bottom:4px; color:var(--muted); }
    .educbt-field select { padding:8px 12px; border:1px solid var(--line); border-radius:8px; font-size:14px; background:#fff; }
    .educbt-btn--ghost { background:transparent; color:var(--muted); border:1px solid var(--line); }
    </style>
    <?php
};

require EDUCBT_PRO_PATH . 'templates/portal/shell.php';
