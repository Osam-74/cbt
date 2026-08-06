<?php
/**
 * A class teacher's view of their class.
 *
 * The question a class teacher actually has at the end of term is not "what did this
 * student score" but "is everything in yet" — which subjects are still being marked,
 * and by whom. That is what this shows, per student and per subject.
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

$reachable = $educbt['scope']->reachable_class_ids();
$structure = new \EduCBTPro\Services\AcademicStructureService();
$classes   = $structure->list_classes( $school_id );

if ( ! empty( $reachable ) ) {
    $classes = array_values(
        array_filter( $classes, static fn( array $c ): bool => in_array( (int) $c['id'], $reachable, true ) )
    );
}

$class_id = (int) ( $_GET['class'] ?? ( $classes[0]['id'] ?? 0 ) );

$students   = [];
$subjects   = [];
$completion = [];
$expected   = 0;

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
}

$educbt_title = 'Class Results';

$educbt_body = static function () use ( $flash, $classes, $class_id, $students, $subjects, $completion, $expected, $term, $session ): void {
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
};

require EDUCBT_PRO_PATH . 'templates/portal/shell.php';
