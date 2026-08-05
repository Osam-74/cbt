<?php
/**
 * A teacher's own dashboard: what they hold, what needs marking, what's coming up.
 *
 * @var array<string,mixed> $educbt
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

global $wpdb;
$school_id = (int) $educbt['school_id'];
$actor     = $educbt['scope']->actor();
$staff_id  = (int) $actor['id'];

$year    = new \EduCBTPro\Services\AcademicYearService();
$session = $year->current_session( $school_id );
$term    = $year->current_term( $school_id );
$session_id = (int) ( $session['id'] ?? 0 );
$term_id    = (int) ( $term['id'] ?? 0 );

$assignments = \EduCBTPro\Core\Schema::table( 'staff_assignments' );
$classes     = \EduCBTPro\Core\Schema::table( 'classes' );
$subjects    = \EduCBTPro\Core\Schema::table( 'subjects_v2' );
$enrolments  = \EduCBTPro\Core\Schema::table( 'enrollments' );

// What the teacher holds — class teacher and subject teacher assignments.
$held = (array) $wpdb->get_results(
    $wpdb->prepare(
        "SELECT a.assignment_type, a.class_id, a.subject_id, c.display_name AS class_name, s.name AS subject_name,
                (SELECT COUNT(*) FROM {$enrolments} e WHERE e.class_id = a.class_id AND e.status = 'active') AS students
         FROM {$assignments} a
         LEFT JOIN {$classes} c ON c.id = a.class_id
         LEFT JOIN {$subjects} s ON s.id = a.subject_id
         WHERE a.school_id = %d AND a.staff_id = %d AND a.status = 'active'
         ORDER BY a.assignment_type ASC, c.display_name ASC",
        $school_id,
        $staff_id
    ),
    ARRAY_A
);

// Separate class-teacher from subject-teacher for cleaner display.
$class_teacher_of = [];
$subject_teacher_of = [];
foreach ( $held as $h ) {
    if ( (string) $h['assignment_type'] === 'class_teacher' ) {
        $class_teacher_of[] = $h;
    } else {
        $subject_teacher_of[] = $h;
    }
}

// Total students across all classes the teacher heads.
$total_my_students = 0;
foreach ( $class_teacher_of as $c ) {
    $total_my_students += (int) $c['students'];
}

// Invigilation duties.
$papers = \EduCBTPro\Core\Schema::table( 'exam_papers' );
$invig  = \EduCBTPro\Core\Schema::table( 'paper_invigilators' );

$duties = (array) $wpdb->get_results(
    $wpdb->prepare(
        "SELECT p.id, p.scheduled_at, p.status, s.name AS subject_name, c.display_name AS class_name
         FROM {$invig} i
         INNER JOIN {$papers} p ON p.id = i.paper_id
         INNER JOIN {$subjects} s ON s.id = p.subject_id
         LEFT JOIN {$classes} c ON c.id = p.class_id
         WHERE i.school_id = %d AND i.staff_id = %d AND p.scheduled_at >= DATE_SUB(%s, INTERVAL 1 DAY)
         ORDER BY p.scheduled_at ASC LIMIT 8",
        $school_id,
        $staff_id,
        current_time( 'mysql', true )
    ),
    ARRAY_A
);

// Questions the teacher has written for their assigned subjects.
$questions_table = $wpdb->prefix . 'educbt_questions';

$my_subject_ids = array_map(
    'absint',
    (array) $wpdb->get_col(
        $wpdb->prepare(
            "SELECT DISTINCT subject_id FROM {$assignments}
             WHERE school_id = %d AND staff_id = %d AND status = 'active' AND subject_id IS NOT NULL",
            $school_id,
            $staff_id
        )
    )
);

$my_questions = 0;
$my_pending_questions = 0;

if ( ! empty( $my_subject_ids ) ) {
    $holder = implode( ',', array_fill( 0, count( $my_subject_ids ), '%d' ) );

    $my_questions = absint(
        $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$questions_table}
                 WHERE school_id = %d AND status = 'active' AND subject_id IN ({$holder})",
                array_merge( [ $school_id ], $my_subject_ids )
            )
        )
    );

    $my_pending_questions = absint(
        $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$questions_table}
                 WHERE school_id = %d AND status = 'active' AND approval_status = 'pending' AND subject_id IN ({$holder})",
                array_merge( [ $school_id ], $my_subject_ids )
            )
        )
    );
}

// CA tests the teacher has created.
$my_tests = (array) $wpdb->get_results(
    $wpdb->prepare(
        "SELECT p.id, p.scheduled_at, p.status, p.question_count, s.name AS subject_name, c.display_name AS class_name,
                (SELECT COUNT(*) FROM {$enrolments} e2 WHERE e2.class_id = p.class_id AND e2.status = 'active') AS class_size
         FROM {$papers} p
         INNER JOIN {$subjects} s ON s.id = p.subject_id
         LEFT JOIN {$classes} c ON c.id = p.class_id
         WHERE p.school_id = %d AND p.is_practice = 1 AND p.status <> 'cancelled'
           AND p.created_by_staff = %d
         ORDER BY p.scheduled_at DESC LIMIT 5",
        $school_id, $staff_id
    ),
    ARRAY_A
);

// Marking queue.
$marking = ( new \EduCBTPro\Services\TheoryService() )->papers_awaiting_marking( $school_id, $staff_id );

// Recent scores entered — show progress on CA recording.
$scores_table = \EduCBTPro\Core\Schema::table( 'assessment_scores' );
$recent_scores = 0;
if ( $term_id > 0 && ! empty( $my_subject_ids ) ) {
    $holder = implode( ',', array_fill( 0, count( $my_subject_ids ), '%d' ) );
    $recent_scores = absint(
        $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$scores_table}
                 WHERE school_id = %d AND term_id = %d AND subject_id IN ({$holder})",
                array_merge( [ $school_id, $term_id ], $my_subject_ids )
            )
        )
    );
}

// Exam prep status.
$exam_prep_open = ( new \EduCBTPro\Services\SchoolService() )->is_exam_prep_enabled( $school_id );

$educbt_title = 'Dashboard';

$educbt_body = static function () use ( $held, $class_teacher_of, $subject_teacher_of, $total_my_students, $duties, $session, $term, $my_questions, $my_pending_questions, $my_tests, $marking, $recent_scores, $exam_prep_open ): void {
    ?>
    <p class="educbt-muted" style="margin-top:-8px">
        <?php echo esc_html( (string) ( $session['title'] ?? '' ) . ' · ' . (string) ( $term['title'] ?? 'no current term' ) ); ?>
    </p>

    <section class="educbt-stats">
        <div class="educbt-stat"><b><?php echo esc_html( (string) count( $held ) ); ?></b><span>Assignments</span></div>
        <div class="educbt-stat"><b><?php echo esc_html( (string) count( $class_teacher_of ) ); ?></b><span>Classes I head</span></div>
        <div class="educbt-stat"><b><?php echo esc_html( (string) $total_my_students ); ?></b><span>My students</span></div>
        <div class="educbt-stat"><b><?php echo esc_html( (string) $my_questions ); ?></b><span>Questions written</span></div>
        <div class="educbt-stat"><b><?php echo esc_html( (string) count( $duties ) ); ?></b><span>Invigilation duties</span></div>
        <div class="educbt-stat"><b><?php echo esc_html( (string) array_sum( array_map( static fn( array $m ): int => (int) $m['outstanding'], $marking ) ) ); ?></b><span>Answers to mark</span></div>
    </section>

    <?php if ( ! $exam_prep_open ) : ?>
        <section class="educbt-card">
            <p class="educbt-note educbt-note--warn">
                <strong>Exam preparation is closed.</strong> You cannot submit new questions until the school office opens it.
            </p>
        </section>
    <?php endif; ?>

    <?php if ( ! empty( $marking ) ) : ?>
        <section class="educbt-card educbt-card--live">
            <h2>Written answers waiting</h2>
            <ul class="educbt-list">
            <?php foreach ( $marking as $m ) : ?>
                <li>
                    <span><?php echo esc_html( $m['subject_name'] . ' — ' . $m['class_name'] ); ?></span>
                    <span class="educbt-muted"><?php echo esc_html( (string) $m['outstanding'] ); ?> to mark</span>
                    <a class="educbt-btn educbt-btn--primary" href="<?php echo esc_url( home_url( '/portal/exams/marking/' . (int) $m['id'] ) ); ?>">Mark</a>
                </li>
            <?php endforeach; ?>
            </ul>
        </section>
    <?php endif; ?>

    <section class="educbt-card">
        <h2>What I hold</h2>
        <?php if ( empty( $held ) ) : ?>
            <p class="educbt-muted">You have not been assigned a class or subject yet. The school office does this under Staff.</p>
        <?php else : ?>
            <?php if ( ! empty( $class_teacher_of ) ) : ?>
                <h3 style="font-size:14px;margin-bottom:6px">Class teacher</h3>
                <ul class="educbt-list" style="margin-bottom:16px">
                <?php foreach ( $class_teacher_of as $h ) : ?>
                    <li>
                        <span><strong><?php echo esc_html( (string) $h['class_name'] ); ?></strong></span>
                        <span class="educbt-muted"><?php echo esc_html( (string) $h['students'] ); ?> students</span>
                        <a class="educbt-btn" href="<?php echo esc_url( add_query_arg( [ 'class' => (int) $h['class_id'] ], home_url( '/portal/teacher/students/' ) ) ); ?>">View students</a>
                    </li>
                <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <?php if ( ! empty( $subject_teacher_of ) ) : ?>
                <h3 style="font-size:14px;margin-bottom:6px">Subject teacher</h3>
                <ul class="educbt-list">
                <?php foreach ( $subject_teacher_of as $h ) : ?>
                    <li>
                        <span>
                            <strong><?php echo esc_html( (string) $h['subject_name'] ); ?></strong>
                            <span class="educbt-muted">in <?php echo esc_html( (string) $h['class_name'] ); ?></span>
                        </span>
                        <span class="educbt-muted"><?php echo esc_html( (string) $h['students'] ); ?> students</span>
                        <a class="educbt-btn" href="<?php echo esc_url( add_query_arg( [ 'class' => (int) $h['class_id'], 'subject' => (int) $h['subject_id'] ], home_url( '/portal/teacher/scores/' ) ) ); ?>">Record CA</a>
                    </li>
                <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        <?php endif; ?>
    </section>

    <?php if ( ! empty( $my_tests ) ) : ?>
        <section class="educbt-card">
            <h2>Recent CA tests</h2>
            <table class="educbt-table">
                <thead><tr><th>Subject</th><th>Class</th><th>Opens</th><th>Qns</th><th>Status</th><th></th></tr></thead>
                <tbody>
                <?php foreach ( $my_tests as $t ) : ?>
                    <tr>
                        <td><?php echo esc_html( (string) $t['subject_name'] ); ?></td>
                        <td><?php echo esc_html( (string) $t['class_name'] ); ?></td>
                        <td><?php echo esc_html( mysql2date( 'j M, g:ia', (string) $t['scheduled_at'] ) ); ?></td>
                        <td><?php echo esc_html( (string) (int) $t['question_count'] ); ?></td>
                        <td><span class="educbt-pill educbt-pill--<?php echo esc_attr( (string) $t['status'] ); ?>"><?php echo esc_html( ucfirst( (string) $t['status'] ) ); ?></span></td>
                        <td>
                            <?php if ( (string) $t['status'] !== 'published' ) : ?>
                                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
                                    <input type="hidden" name="action" value="educbt_publish_paper">
                                    <input type="hidden" name="paper_id" value="<?php echo esc_attr( (string) $t['id'] ); ?>">
                                    <?php wp_nonce_field( 'educbt_publish_paper' ); ?>
                                    <button type="submit" class="educbt-btn">Publish</button>
                                </form>
                            <?php else : ?>
                                <span class="educbt-muted">live</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </section>
    <?php endif; ?>

    <section class="educbt-card">
        <h2>Invigilation Schedule</h2>
        <?php if ( empty( $duties ) ) : ?>
            <p class="educbt-muted">Nothing assigned.</p>
        <?php else : ?>
            <ul class="educbt-list">
            <?php foreach ( $duties as $d ) : ?>
                <li>
                    <span><?php echo esc_html( $d['subject_name'] . ' — ' . $d['class_name'] ); ?></span>
                    <span class="educbt-muted"><?php echo esc_html( mysql2date( 'D j M, g:ia', (string) $d['scheduled_at'] ) ); ?></span>
                </li>
            <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

    <?php if ( $my_pending_questions > 0 ) : ?>
        <section class="educbt-card">
            <h2>Question bank</h2>
            <p class="educbt-muted">
                <?php echo esc_html( (string) $my_pending_questions ); ?> of your questions are awaiting approval by the exam officer.
            </p>
            <p><a class="educbt-btn" href="<?php echo esc_url( home_url( '/portal/exams/questions/' ) ); ?>">Go to question bank</a></p>
        </section>
    <?php endif; ?>

    <?php
};

require EDUCBT_PRO_PATH . 'templates/portal/shell.php';
