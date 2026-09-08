<?php
/**
 * A single student's record, for school leadership.
 *
 * The students list answers "who is in this school". This page answers "who is
 * this student" — the photograph and details, the subjects they are registered
 * for, and their results for any session and term, without the risk of editing
 * anything by accident.
 *
 * The status actions live here rather than in the list because suspending,
 * withdrawing or expelling a student are decisions that should be taken while
 * looking at that student's record, not from a row in a table of thirty names.
 *
 * @var array<string,mixed> $educbt
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * One status button. Kept as a function so each branch reads as the choice it
 * offers rather than as eight lines of form boilerplate repeated nine times.
 */
function educbt_student_status_button(
    int $student_id,
    string $new_status,
    string $label,
    string $name,
    string $confirm,
    string $style = '',
    bool $primary = false
): void {
    ?>
    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
          onsubmit="return confirm('<?php echo esc_js( $confirm ); ?>');">
        <input type="hidden" name="action" value="educbt_set_student_status">
        <input type="hidden" name="student_id" value="<?php echo esc_attr( (string) $student_id ); ?>">
        <input type="hidden" name="new_status" value="<?php echo esc_attr( $new_status ); ?>">
        <?php wp_nonce_field( 'educbt_set_student_status' ); ?>
        <button type="submit" class="educbt-btn<?php echo $primary ? ' educbt-btn--primary' : ''; ?>"
                <?php echo $style !== '' ? 'style="' . esc_attr( $style ) . '"' : ''; ?>>
            <?php echo esc_html( $label ); ?>
        </button>
    </form>
    <?php
}

global $wpdb;

$school_id  = (int) $educbt['school_id'];
$student_id = absint( $_GET['id'] ?? 0 );
$flash      = \EduCBTPro\Frontend\PortalActions::flash();

$students_tbl = $wpdb->prefix . 'educbt_students';
$enrol_tbl    = \EduCBTPro\Core\Schema::table( 'enrollments' );
$classes_tbl  = \EduCBTPro\Core\Schema::table( 'classes' );
$subjects_tbl = \EduCBTPro\Core\Schema::table( 'subjects_v2' );
$reg_tbl      = \EduCBTPro\Core\Schema::table( 'student_subjects' );
$sessions_tbl = \EduCBTPro\Core\Schema::table( 'academic_sessions' );
$terms_tbl    = \EduCBTPro\Core\Schema::table( 'terms' );

$ay         = new \EduCBTPro\Services\AcademicYearService();
$session    = $ay->current_session( $school_id );
$session_id = (int) ( $session['id'] ?? 0 );

// Scoped to this school, so an id from another school resolves to nothing.
$student = $student_id > 0
    ? (array) $wpdb->get_row(
        $wpdb->prepare(
            "SELECT st.*, c.display_name AS class_name, c.id AS class_id
             FROM {$students_tbl} st
             LEFT JOIN {$enrol_tbl} e
                    ON e.student_id = st.id AND e.session_id = %d AND e.status = 'active'
             LEFT JOIN {$classes_tbl} c ON c.id = e.class_id
             WHERE st.id = %d AND st.school_id = %d",
            $session_id,
            $student_id,
            $school_id
        ),
        ARRAY_A
    )
    : [];

// Which session and term of results to show. Defaults to the current one.
$view_session = absint( $_GET['session'] ?? $session_id );
$view_term    = absint( $_GET['term'] ?? 0 );

$sessions = (array) $wpdb->get_results(
    $wpdb->prepare( "SELECT id, title FROM {$sessions_tbl} WHERE school_id = %d ORDER BY id DESC", $school_id ),
    ARRAY_A
);

$terms = $view_session > 0
    ? (array) $wpdb->get_results(
        $wpdb->prepare(
            "SELECT id, title FROM {$terms_tbl} WHERE school_id = %d AND session_id = %d ORDER BY term_order ASC",
            $school_id,
            $view_session
        ),
        ARRAY_A
    )
    : [];

if ( $view_term <= 0 && ! empty( $terms ) ) {
    $current   = $ay->resolve_current_term( $school_id, $view_session );
    $view_term = absint( $current['id'] ?? $terms[0]['id'] );
}

// Registered subjects for the session being viewed.
$registered = ( $student && $view_session > 0 )
    ? (array) $wpdb->get_results(
        $wpdb->prepare(
            "SELECT s.name, s.code
             FROM {$reg_tbl} r
             INNER JOIN {$subjects_tbl} s ON s.id = r.subject_id
             WHERE r.student_id = %d AND r.session_id = %d
             ORDER BY s.name ASC",
            $student_id,
            $view_session
        ),
        ARRAY_A
    )
    : [];

// Results, only when asked for — the page opens on the profile, not a report.
$show_results = ! empty( $_GET['results'] );
$results      = [];
$term_summary = [];

if ( $student && $show_results && $view_term > 0 ) {
    $results = (array) $wpdb->get_results(
        $wpdb->prepare(
            'SELECT sub.name AS subject_name, sr.ca_total, sr.exam_total, sr.total,
                    sr.grade, sr.subject_position, sr.remark
             FROM ' . \EduCBTPro\Core\Schema::table( 'subject_results' ) . ' sr
             INNER JOIN ' . $subjects_tbl . ' sub ON sub.id = sr.subject_id
             WHERE sr.school_id = %d AND sr.student_id = %d AND sr.term_id = %d
             ORDER BY sub.name ASC',
            $school_id,
            $student_id,
            $view_term
        ),
        ARRAY_A
    );

    $term_summary = (array) $wpdb->get_row(
        $wpdb->prepare(
            'SELECT total_score, average_score, class_position, class_size, status
             FROM ' . \EduCBTPro\Core\Schema::table( 'term_results' ) . '
             WHERE school_id = %d AND student_id = %d AND term_id = %d',
            $school_id,
            $student_id,
            $view_term
        ),
        ARRAY_A
    );
}

$educbt_title = $student
    ? trim( (string) $student['first_name'] . ' ' . (string) $student['last_name'] )
    : 'Student';

$educbt_body = static function () use (
    $flash, $student, $student_id, $registered, $results, $term_summary,
    $sessions, $terms, $view_session, $view_term, $show_results
): void {
    require EDUCBT_PRO_PATH . 'templates/portal/partials/flash.php';

    if ( empty( $student ) ) {
        echo '<div class="educbt-card"><p class="educbt-muted">That student could not be found in this school.</p>'
            . '<p><a class="educbt-btn" href="' . esc_url( home_url( '/portal/school/students/' ) ) . '">Back to students</a></p></div>';
        return;
    }

    $status = (string) ( $student['status'] ?? 'active' );
    $name   = trim( (string) $student['first_name'] . ' ' . (string) $student['last_name'] );
    ?>
    <style>
    /* The portal stylesheet sets `background:#fff !important` on
       `body.educbt-portal .educbt-card`, which outranks any plain class rule —
       which is why this card stayed white with white text on it, unreadable.
       Matched at the same specificity and marked important so it actually wins. */
    body.educbt-portal .educbt-card.educbt-student-card {
        background: linear-gradient(155deg, #14532d 0%, #0F2818 100%) !important;
        border-color: #0F2818 !important;
        color: #fff !important;
    }
    body.educbt-portal .educbt-card.educbt-student-card h2 { color: #fff !important; }
    body.educbt-portal .educbt-student-card .educbt-muted { color: rgba(255,255,255,.72) !important; }
    .educbt-student-facts {
        display: grid;
        grid-template-columns: auto 1fr;
        gap: 8px 16px;
        margin: 0;
        font-size: .92rem;
        align-items: baseline;
    }
    .educbt-student-facts dt { white-space: nowrap; color: rgba(255,255,255,.68); }
    .educbt-student-facts dd { margin: 0; color: #fff; font-weight: 500; }
    </style>

    <p style="margin:0 0 14px">
        <a class="educbt-btn" href="<?php echo esc_url( home_url( '/portal/school/students/' ) ); ?>">&larr; All students</a>
    </p>

    <?php // ── Identity ──────────────────────────────────────────────────── ?>
    <?php
    // The identity block is the student, so it carries the school's colour and
    // reads as a record card rather than as another white panel in a stack. The
    // detail rows sit inside it, which is what was lost when the table came out.
    ?>
    <section class="educbt-card educbt-student-card">
        <div style="display:flex;gap:24px;flex-wrap:wrap;align-items:flex-start">
            <div style="flex:0 0 auto">
                <?php if ( ! empty( $student['passport_photo'] ) ) : ?>
                    <img src="<?php echo esc_url( (string) $student['passport_photo'] ); ?>" alt=""
                         style="width:118px;height:142px;object-fit:cover;border-radius:10px;border:2px solid rgba(203,235,110,.65)">
                <?php else : ?>
                    <div style="width:118px;height:142px;border-radius:10px;border:2px dashed rgba(203,235,110,.5);display:flex;align-items:center;justify-content:center;color:rgba(255,255,255,.75);font-size:.8rem;text-align:center;padding:8px">
                        No photograph
                    </div>
                <?php endif; ?>
            </div>

            <div style="flex:1 1 320px;min-width:260px">
                <h2 style="margin:0 0 4px;color:#fff"><?php echo esc_html( $name ); ?></h2>
                <p style="margin:0 0 14px;color:rgba(255,255,255,.72)">
                    <?php echo esc_html( (string) ( $student['admission_number'] ?? '' ) ); ?>
                    <?php if ( ! empty( $student['class_name'] ) ) : ?>
                        &nbsp;·&nbsp; <?php echo esc_html( (string) $student['class_name'] ); ?>
                    <?php endif; ?>
                    &nbsp;·&nbsp;
                    <?php if ( $status === 'active' ) : ?>
                        <span class="educbt-pill educbt-pill--published">Active</span>
                    <?php elseif ( $status === 'inactive' ) : ?>
                        <span class="educbt-pill" style="background:#fef3c7;color:#92400e">Suspended</span>
                    <?php elseif ( $status === 'withdrawn' ) : ?>
                        <span class="educbt-pill" style="background:#fee2e2;color:#991b1b">Withdrawn</span>
                    <?php elseif ( $status === 'expelled' ) : ?>
                        <span class="educbt-pill" style="background:#fee2e2;color:#991b1b">Expelled</span>
                    <?php else : ?>
                        <span class="educbt-pill educbt-pill--draft"><?php echo esc_html( ucfirst( $status ) ); ?></span>
                    <?php endif; ?>
                </p>

                <?php
                // A definition list, not a table. The table stretched its label
                // column to 40% of the card, leaving "Gender" marooned a hand's
                // width from "Female". Label and value belong next to each other.
                $facts = [
                    'Gender'         => ucfirst( (string) ( $student['gender'] ?? '' ) ),
                    'Date of birth'  => (string) ( $student['date_of_birth'] ?? '' ),
                    'Parent phone'   => (string) ( $student['parent_phone'] ?? '' ),
                    'Parent email'   => (string) ( $student['parent_email'] ?? '' ),
                    'Admitted'       => $student['created_at'] ? mysql2date( 'j M Y', (string) $student['created_at'] ) : '',
                ];
                ?>
                <dl class="educbt-student-facts">
                    <?php foreach ( $facts as $label => $value ) : ?>
                        <dt><?php echo esc_html( $label ); ?>:</dt>
                        <dd><?php echo esc_html( $value !== '' ? $value : '—' ); ?></dd>
                    <?php endforeach; ?>
                </dl>
            </div>
        </div>
    </section>

    <?php // ── Registered subjects ───────────────────────────────────────── ?>
    <section class="educbt-card">
        <h2>Registered subjects <span class="educbt-muted">(<?php echo esc_html( (string) count( $registered ) ); ?>)</span></h2>

        <?php if ( empty( $registered ) ) : ?>
            <p class="educbt-muted">
                Not registered for any subject in this session. Until they are, this student
                will not see tests or examinations on their portal.
            </p>
        <?php else : ?>
            <ul style="columns:2;column-gap:32px;margin:8px 0 0;padding-left:20px;list-style:disc">
                <?php foreach ( $registered as $s ) : ?>
                    <li style="break-inside:avoid;padding:3px 0">
                        <?php echo esc_html( (string) $s['name'] ); ?>
                        <?php if ( ! empty( $s['code'] ) ) : ?>
                            <span class="educbt-muted" style="font-size:.78rem">(<?php echo esc_html( (string) $s['code'] ); ?>)</span>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

    <?php // ── Results ───────────────────────────────────────────────────── ?>
    <section class="educbt-card">
        <h2>Results</h2>

        <form method="get" class="educbt-form" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap">
            <input type="hidden" name="id" value="<?php echo esc_attr( (string) $student_id ); ?>">
            <input type="hidden" name="results" value="1">
            <div>
                <label for="session">Session</label>
                <select id="session" name="session" onchange="this.form.submit()">
                    <?php foreach ( $sessions as $sess ) : ?>
                        <option value="<?php echo esc_attr( (string) $sess['id'] ); ?>" <?php selected( (int) $sess['id'], $view_session ); ?>>
                            <?php echo esc_html( (string) $sess['title'] ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="term">Term</label>
                <select id="term" name="term">
                    <?php foreach ( $terms as $t ) : ?>
                        <option value="<?php echo esc_attr( (string) $t['id'] ); ?>" <?php selected( (int) $t['id'], $view_term ); ?>>
                            <?php echo esc_html( (string) $t['title'] ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="educbt-btn educbt-btn--primary">View results</button>
        </form>

        <?php if ( ! $show_results ) : ?>
            <p class="educbt-muted" style="margin-top:12px">Choose a session and term, then view the results.</p>

        <?php elseif ( empty( $results ) ) : ?>
            <p class="educbt-muted" style="margin-top:12px">
                Nothing compiled for this student in that term. Results appear here once the
                term has been compiled.
            </p>

        <?php else : ?>
            <?php if ( ! empty( $term_summary ) ) : ?>
                <p class="educbt-muted" style="margin-top:12px">
                    Total <strong><?php echo esc_html( (string) $term_summary['total_score'] ); ?></strong>
                    &nbsp;·&nbsp; Average <strong><?php echo esc_html( (string) $term_summary['average_score'] ); ?>%</strong>
                    &nbsp;·&nbsp; Position <strong><?php echo esc_html( (string) $term_summary['class_position'] ); ?></strong>
                    of <?php echo esc_html( (string) $term_summary['class_size'] ); ?>
                    <?php if ( (string) ( $term_summary['status'] ?? '' ) !== 'published' ) : ?>
                        &nbsp;·&nbsp; <span class="educbt-pill educbt-pill--draft">Not yet published to parents</span>
                    <?php endif; ?>
                </p>
            <?php endif; ?>

            <table class="educbt-table" style="margin-top:10px">
                <thead><tr><th>Subject</th><th>CA</th><th>Exam</th><th>Total</th><th>Grade</th><th>Pos.</th><th>Remark</th></tr></thead>
                <tbody>
                <?php foreach ( $results as $r ) : ?>
                    <tr>
                        <td><?php echo esc_html( (string) $r['subject_name'] ); ?></td>
                        <td><?php echo esc_html( (string) $r['ca_total'] ); ?></td>
                        <td><?php echo esc_html( (string) $r['exam_total'] ); ?></td>
                        <td><strong><?php echo esc_html( (string) $r['total'] ); ?></strong></td>
                        <td><?php echo esc_html( (string) $r['grade'] ); ?></td>
                        <td><?php echo esc_html( (string) $r['subject_position'] ); ?></td>
                        <td><?php echo esc_html( (string) $r['remark'] ); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <p style="margin-top:12px">
                <a class="educbt-btn" target="_blank" rel="noopener"
                   href="<?php echo esc_url( home_url( '/portal/teacher/report/?student_id=' . $student_id . '&term_id=' . $view_term ) ); ?>">
                    Open printable report sheet
                </a>
            </p>
        <?php endif; ?>
    </section>

    <?php // ── Status ────────────────────────────────────────────────────── ?>
    <?php if ( \EduCBTPro\Core\Gate::allows( \EduCBTPro\Core\Capabilities::MANAGE_STUDENTS ) ) : ?>
    <?php
    // Only the moves that make sense from where the student actually stands.
    // Offering "Withdraw" to a student who is already withdrawn is not a choice,
    // it is a trap — and it was why the buttons looked identical whatever the
    // record said.
    $expelled_on = (string) ( $student['status_changed_at'] ?? '' );
    $grace_left  = 0;

    if ( $status === 'expelled' && $expelled_on !== '' ) {
        $elapsed    = ( time() - strtotime( $expelled_on ) ) / DAY_IN_SECONDS;
        $grace_left = max( 0, 30 - (int) floor( $elapsed ) );
    }
    ?>
    <section class="educbt-card">
        <h2>Standing</h2>

        <?php if ( $status === 'active' ) : ?>
            <p class="educbt-muted">
                This student is active. Suspension is temporary and reversible.
                Withdrawal and expulsion end their enrolment; the record and every
                result compiled for them is kept either way.
            </p>
            <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:12px">
                <?php
                educbt_student_status_button( $student_id, 'inactive', 'Suspend', $name,
                    'Suspend ' . $name . '? They lose portal access until the suspension is cancelled.',
                    'color:#92400e;border-color:#fcd34d' );
                educbt_student_status_button( $student_id, 'withdrawn', 'Withdraw', $name,
                    'Withdraw ' . $name . ' from the school? Their record is kept.',
                    'color:#991b1b;border-color:#fca5a5' );
                educbt_student_status_button( $student_id, 'expelled', 'Expel', $name,
                    'Expel ' . $name . '? You have 30 days to cancel this before it becomes final.',
                    'color:#991b1b;border-color:#fca5a5' );
                ?>
            </div>

        <?php elseif ( $status === 'inactive' ) : ?>
            <p class="educbt-muted">This student is suspended and cannot sign in.</p>
            <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:12px">
                <?php
                educbt_student_status_button( $student_id, 'active', 'Cancel suspension', $name,
                    'Return ' . $name . ' to active standing?', '', true );
                educbt_student_status_button( $student_id, 'withdrawn', 'Withdraw instead', $name,
                    'Withdraw ' . $name . ' from the school?', 'color:#991b1b;border-color:#fca5a5' );
                ?>
            </div>

        <?php elseif ( $status === 'withdrawn' ) : ?>
            <p class="educbt-muted">
                This student has been withdrawn and is no longer on any class register.
                Reinstating them restores their standing; you will need to place them
                in a class again.
            </p>
            <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:12px">
                <?php
                educbt_student_status_button( $student_id, 'active', 'Reinstate', $name,
                    'Reinstate ' . $name . ' as an active student?', '', true );
                ?>
            </div>

        <?php elseif ( $status === 'expelled' ) : ?>
            <?php if ( $grace_left > 0 ) : ?>
                <p class="educbt-note educbt-note--warn">
                    Expulsion pending — <strong><?php echo esc_html( (string) $grace_left ); ?> day(s)</strong>
                    left to reconsider. It becomes final after that, and this record
                    can no longer be reversed here.
                </p>
                <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:12px">
                    <?php
                    educbt_student_status_button( $student_id, 'active', 'Cancel expulsion', $name,
                        'Cancel the expulsion and return ' . $name . ' to active standing?', '', true );
                    ?>
                </div>
            <?php else : ?>
                <p class="educbt-muted">
                    This expulsion is final. The record is kept so results and
                    transcripts remain available.
                </p>
            <?php endif; ?>

        <?php else : ?>
            <p class="educbt-muted">Standing: <?php echo esc_html( ucfirst( $status ) ); ?>.</p>
            <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:12px">
                <?php
                educbt_student_status_button( $student_id, 'active', 'Set active', $name,
                    'Return ' . $name . ' to active standing?', '', true );
                ?>
            </div>
        <?php endif; ?>
    </section>
    <?php endif; ?>
    <?php
};

require EDUCBT_PRO_PATH . 'templates/portal/shell.php';
