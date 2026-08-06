<?php
/**
 * The invigilator's live board.
 *
 * Answers the four questions an invigilator actually has: who has not started, who
 * looks disconnected, who is flagged, and how long each student has left.
 *
 * Subject teachers only see live sessions for their assigned subjects.
 * School-wide roles (exam officer, principal) see all sessions.
 *
 * @var array<string,mixed> $educbt
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

global $wpdb;
$school_id = (int) $educbt['school_id'];
$paper_id  = (int) $educbt['id'];
$actor     = $educbt['scope']->actor();
$staff_id  = (int) $actor['id'];
$is_wide   = $educbt['scope']->is_school_wide();

$papers_table   = \EduCBTPro\Core\Schema::table( 'exam_papers' );
$subjects_table = \EduCBTPro\Core\Schema::table( 'subjects_v2' );
$classes_table  = \EduCBTPro\Core\Schema::table( 'classes' );
$assign_table   = \EduCBTPro\Core\Schema::table( 'staff_assignments' );

// --- Determine which subjects this teacher can see ---

$filter_subject = absint( $_GET['subject'] ?? 0 );

if ( $is_wide ) {
    // School-wide: see all subjects.
    $my_subjects = (array) $wpdb->get_results(
        $wpdb->prepare(
            "SELECT id, name FROM {$subjects_table} WHERE school_id = %d AND status = 'active' ORDER BY name ASC",
            $school_id
        ),
        ARRAY_A
    );
} else {
    // Subject teacher: only assigned subjects.
    $my_subjects = (array) $wpdb->get_results(
        $wpdb->prepare(
            "SELECT DISTINCT s.id, s.name FROM {$assign_table} a
             INNER JOIN {$subjects_table} s ON s.id = a.subject_id
             WHERE a.school_id = %d AND a.staff_id = %d AND a.status = 'active' AND s.status = 'active'
             ORDER BY s.name ASC",
            $school_id, $staff_id
        ),
        ARRAY_A
    );
}

$my_subject_ids = array_map( static function( $s ) { return (int) $s['id']; }, $my_subjects );

// --- Fetch live papers, filtered by subject ---

$live = [];
if ( ! empty( $my_subject_ids ) ) {
    $holder = implode( ',', array_fill( 0, count( $my_subject_ids ), '%d' ) );
    $params = array_merge( [ $school_id ], $my_subject_ids, [ current_time( 'mysql', true ), current_time( 'mysql', true ) ] );

    $live = (array) $wpdb->get_results(
        $wpdb->prepare(
            "SELECT p.id, p.scheduled_at, p.subject_id, s.name AS subject_name, c.display_name AS class_name
             FROM {$papers_table} p
             INNER JOIN {$subjects_table} s ON s.id = p.subject_id
             LEFT JOIN {$classes_table} c ON c.id = p.class_id
             WHERE p.school_id = %d
               AND p.status = 'published'
               AND p.subject_id IN ({$holder})
               AND p.scheduled_at <= DATE_ADD(%s, INTERVAL 1 DAY)
               AND DATE_ADD(p.scheduled_at, INTERVAL p.duration_seconds + 86400 SECOND) > %s
             ORDER BY p.scheduled_at ASC",
            $params
        ),
        ARRAY_A
    );

    // Further filter by selected subject dropdown.
    if ( $filter_subject > 0 && in_array( $filter_subject, $my_subject_ids, true ) ) {
        $live = array_filter( $live, static function( $p ) use ( $filter_subject ) {
            return (int) $p['subject_id'] === $filter_subject;
        } );
    }
}

$board = $paper_id > 0 ? ( new \EduCBTPro\Services\InvigilatorService() )->board( $school_id, $paper_id ) : null;

$educbt_title = 'Live Exam Sessions';

$educbt_body = static function () use ( $live, $board, $paper_id, $my_subjects, $filter_subject, $is_wide ): void {
    ?>
    <section class="educbt-card">
        <?php if ( ! $is_wide && count( $my_subjects ) > 1 ): ?>
            <div style="margin-bottom:12px">
                <label class="educbt-muted" style="font-size:.8rem;display:block;margin-bottom:3px">Filter by subject</label>
                <select id="live-subject-filter" class="educbt-input" style="max-width:280px" onchange="window.location.href='?subject='+this.value">
                    <option value="0">All my subjects</option>
                    <?php foreach ( $my_subjects as $s ): ?>
                        <option value="<?php echo (int) $s['id']; ?>" <?php selected( $filter_subject, (int) $s['id'] ); ?>>
                            <?php echo esc_html( $s['name'] ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endif; ?>

        <h2><?php echo $is_wide ? 'Active examination sessions' : 'Live sessions'; ?></h2>
        <?php if ( empty( $live ) ): ?>
            <?php
            // A principal or exam officer does not teach a subject, so telling them
            // nothing is running "for your subjects" described a relationship they
            // do not have. They are watching the whole school.
            ?>
            <p class="educbt-muted">
                <?php echo $is_wide
                    ? 'No examination is running right now.'
                    : 'No published paper is running right now for your subjects.'; ?>
            </p>
        <?php else: ?>
            <ul class="educbt-list">
            <?php foreach ( $live as $p ): ?>
                <li>
                    <span><strong><?php echo esc_html( (string) $p['subject_name'] ); ?></strong> — <?php echo esc_html( (string) $p['class_name'] ); ?></span>
                    <span class="educbt-muted"><?php echo esc_html( mysql2date( 'g:ia', (string) $p['scheduled_at'] ) ); ?></span>
                    <a class="educbt-btn <?php echo (int) $p['id'] === $paper_id ? 'educbt-btn--primary' : ''; ?>"
                       href="<?php echo esc_url( home_url( '/portal/exams/invigilate/' . (int) $p['id'] ) ); ?>">Watch</a>
                </li>
            <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

    <?php if ( $board && ! empty( $board['paper'] ) ): ?>
        <section class="educbt-card">
            <h2>Access code</h2>
            <p style="font-size:26px;font-weight:700;letter-spacing:6px"><?php echo esc_html( (string) $board['paper']['access_code'] ); ?></p>
            <p class="educbt-muted">Read this out when the paper starts. Students cannot open it without the code.</p>
        </section>

        <section class="educbt-stats">
            <div class="educbt-stat"><b><?php echo esc_html( (string) $board['summary']['not_started'] ); ?></b><span>Not started</span></div>
            <div class="educbt-stat"><b><?php echo esc_html( (string) $board['summary']['in_progress'] ); ?></b><span>Writing</span></div>
            <div class="educbt-stat"><b><?php echo esc_html( (string) $board['summary']['submitted'] ); ?></b><span>Submitted</span></div>
            <div class="educbt-stat"><b><?php echo esc_html( (string) $board['summary']['disconnected'] ); ?></b><span>Quiet</span></div>
        </section>

        <section class="educbt-card">
            <h2>Students <span class="educbt-muted">(refresh to update)</span></h2>
            <table class="educbt-table">
                <thead><tr><th>Student</th><th>State</th><th>Answered</th><th>Time left</th><th>Flags</th></tr></thead>
                <tbody>
                <?php foreach ( $board['students'] as $row ): ?>
                    <tr>
                        <td><?php echo esc_html( (string) $row['name'] ); ?><br>
                            <span class="educbt-muted"><?php echo esc_html( (string) $row['admission_number'] ); ?></span></td>
                        <td>
                            <span class="educbt-pill"><?php echo esc_html( str_replace( '_', ' ', (string) $row['state'] ) ); ?></span>
                            <?php if ( ! empty( $row['disconnected'] ) ): ?>
                                <br><span class="educbt-muted">no activity for 2 min</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html( $row['answered'] . '/' . $row['total'] ); ?></td>
                        <td>
                            <?php echo $row['remaining_seconds'] === null
                                ? '—'
                                : esc_html( (string) floor( (int) $row['remaining_seconds'] / 60 ) . ' min' ); ?>
                        </td>
                        <td><?php echo (int) $row['flags'] > 0 ? esc_html( (string) $row['flags'] ) : '<span class="educbt-muted">—</span>'; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </section>
    <?php endif; ?>
    <?php
};

require EDUCBT_PRO_PATH . 'templates/portal/shell.php';
