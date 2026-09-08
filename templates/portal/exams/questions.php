<?php
/**
 * Question Bank — Teacher Submission Interface.
 *
 * Rebuilt per the functional specification. The unit of work is a Question Set,
 * not an individual question. Four stacked regions:
 *
 *   A — Scope Selector (sticky)     Subject · Class Level · Exam Type · Marks · Method
 *   B — Input Surface               Content swaps based on Method + Exam Type
 *   C — Live Preview / Question List Everything already in the draft, editable
 *   D — Progress + Submit Bar (sticky)  Count / Marks / Submit
 *
 * @var array<string,mixed> $educbt
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

global $wpdb;

$school_id   = (int) $educbt['school_id'];
$flash       = \EduCBTPro\Frontend\PortalActions::flash();
$exam_prep_open = ( new \EduCBTPro\Services\SchoolService() )->is_exam_prep_enabled( $school_id );
$actor       = $educbt['scope']->actor();
$is_reviewer = $educbt['scope']->is_school_wide();

$subjects_table = \EduCBTPro\Core\Schema::table( 'subjects_v2' );
$assign         = \EduCBTPro\Core\Schema::table( 'staff_assignments' );
$classes_table  = \EduCBTPro\Core\Schema::table( 'classes' );

// --- Data: subjects and classes for the scope selector ---

if ( $is_reviewer ) {
    $subjects = (array) $wpdb->get_results(
        $wpdb->prepare( "SELECT id, name, code, stage FROM {$subjects_table} WHERE school_id = %d AND status = 'active' ORDER BY stage ASC, name ASC", $school_id ),
        ARRAY_A
    );
} else {
    $subjects = (array) $wpdb->get_results(
        $wpdb->prepare(
            "SELECT DISTINCT s.id, s.name, s.code, s.stage FROM {$assign} a
             INNER JOIN {$subjects_table} s ON s.id = a.subject_id
             WHERE a.school_id = %d AND a.staff_id = %d AND a.status = 'active' AND s.status = 'active'
             ORDER BY s.name ASC",
            $school_id,
            (int) $actor['id']
        ),
        ARRAY_A
    );
}

// Build subject → LEVEL mapping for the cascading dropdown.
//
// JS1 A and JS1 B sit the same paper, so offering arms here made a teacher enter
// the same questions once per arm. The second dropdown now lists the level, with
// the department appended for senior classes (SS1 Science, SS1 Commercial), which
// is exactly how the Create Class screen describes them. The option value carries
// both ids as "levelId:departmentId".
$levels_table = \EduCBTPro\Core\Schema::table( 'class_levels' );
$depts_table  = \EduCBTPro\Core\Schema::table( 'departments' );

$subject_classes = [];
if ( ! empty( $subjects ) ) {
    $subject_ids = array_map( static function( $s ) { return (int) $s['id']; }, $subjects );
    $holder = implode( ',', array_fill( 0, count( $subject_ids ), '%d' ) );

    if ( $is_reviewer ) {
        // A reviewer is not assigned to classes, so their levels come from the
        // SUBJECT rather than from a teaching allocation. Offering every level for
        // every subject let a principal pick "Biology — JS1", which the school does
        // not teach and which composes into a paper no class can sit.
        //
        // Two rules do the filtering, and both come from the subject's own record:
        //
        //   stage         junior subjects offer junior levels only, senior offer
        //                 senior only, 'both' offers everything
        //   department_id a senior subject belonging to Science appears only against
        //                 classes in the Science department
        $rows = (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT DISTINCT s.id AS subject_id, l.id AS level_id, l.name AS level_name,
                        l.level_order, COALESCE(c.department_id,0) AS department_id,
                        COALESCE(d.name,'') AS department_name
                 FROM {$subjects_table} s
                 INNER JOIN {$classes_table} c ON c.school_id = s.school_id AND c.status = 'active'
                 INNER JOIN {$levels_table} l ON l.id = c.level_id
                 LEFT JOIN {$depts_table} d ON d.id = c.department_id
                 WHERE s.school_id = %d AND s.status = 'active'
                   AND s.id IN ($holder)
                   AND ( s.stage = 'both' OR s.stage = '' OR s.stage IS NULL OR s.stage = l.stage )
                   AND ( s.department_id IS NULL OR s.department_id = 0
                         OR s.department_id = COALESCE(c.department_id, 0) )
                 ORDER BY l.level_order ASC, department_name ASC",
                array_merge( [ $school_id ], $subject_ids )
            ),
            ARRAY_A
        );
    } else {
        $rows = (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT DISTINCT a.subject_id, l.id AS level_id, l.name AS level_name,
                        l.level_order, COALESCE(c.department_id,0) AS department_id,
                        COALESCE(d.name,'') AS department_name
                 FROM {$assign} a
                 INNER JOIN {$classes_table} c ON c.id = a.class_id
                 INNER JOIN {$levels_table} l ON l.id = c.level_id
                 LEFT JOIN {$depts_table} d ON d.id = c.department_id
                 WHERE a.school_id = %d AND a.staff_id = %d AND a.status = 'active'
                   AND a.subject_id IN ($holder)
                 ORDER BY l.level_order ASC, department_name ASC",
                array_merge( [ $school_id, (int) $actor['id'] ], $subject_ids )
            ),
            ARRAY_A
        );
    }

    // LEVEL ONLY — no department, no arm.
    //
    // The list used to read SS1 Art, SS1 Science, SS1 Commercial, so a teacher
    // setting one SS1 Chemistry paper saw three SS1 entries and had to guess which
    // to write into, or wrote the same paper three times.
    //
    // The department was never doing the work anyway. What a student sits is
    // decided by subject REGISTRATION: an SS1 Commercial student never registered
    // Chemistry, so the Chemistry paper never reaches them whatever level it was
    // set against. Splitting the level by department only duplicated the teacher's
    // work to enforce something registration already enforces.
    //
    // The id keeps its "level:department" shape with department fixed at 0, so
    // every existing link, saved set and query continues to resolve.
    $build = static function( array $r ): array {
        return [
            'id'   => (int) $r['level_id'] . ':0',
            'name' => (string) $r['level_name'],
        ];
    };

    foreach ( $rows as $r ) {
        // Every row now names its own subject, reviewer or not.
        $targets = [ (int) $r['subject_id'] ];

        foreach ( $targets as $sid ) {
            if ( ! isset( $subject_classes[ $sid ] ) ) {
                $subject_classes[ $sid ] = [];
            }

            $entry = $build( $r );

            foreach ( $subject_classes[ $sid ] as $existing ) {
                if ( $existing['id'] === $entry['id'] ) {
                    continue 2;
                }
            }

            $subject_classes[ $sid ][] = $entry;
        }
    }
}

// Session and term (read-only).
$ay_service = new \EduCBTPro\Services\AcademicYearService();
$session    = $ay_service->current_session( $school_id );
$session_id = absint( $session['id'] ?? 0 );
// `current_term_id` is not a column on academic_sessions — reading it here always
// produced 0, so the header silently dropped the term. Resolve it the same way
// the REST endpoints do, so the label and the saved rows cannot disagree.
$term       = $ay_service->resolve_current_term( $school_id, $session_id );
$term_id    = absint( $term['id'] ?? 0 );
// Is the school currently running a continuous assessment window? If so, questions
// written now go into that test rather than into the terminal examination, and the
// teacher must be told plainly — otherwise they write twenty CA items believing
// they are building the end-of-term paper.
// What is the bank open for? One window at a time, chosen by the school office.
$window_service = new \EduCBTPro\Services\QuestionWindowService();
$open_window    = $window_service->current( $school_id );
$practice       = $window_service->practice( $school_id );

// Practice requires an explicit opt-in when the official window is closed.
// The teacher must click the "Set Practice Questions" button to enter practice
// mode, so they are never confused into writing practice questions when they
// meant to write for a live exam or assessment.
$opt_in_practice  = ! empty( $_GET['opt_in_practice'] ) || ! empty( $_GET['practice_opt_in'] );
$writing_practice = empty( $open_window ) && ! empty( $practice ) && $opt_in_practice;

if ( $writing_practice ) {
    $open_window = $practice;
}
$ca_window   = ( $open_window && $open_window['is_ca'] ) ? $open_window : null;

// If the bank is closed and practice was not opted into, show the closed notice
// with a button to opt in to practice exam question setting.
if ( empty( $open_window ) ) {
    ?>
    <div class="educbt-card" style="text-align:center;padding:40px 24px">
        <div style="margin:0 auto 12px;width:48px;height:48px;border-radius:50%;background:var(--edu-muted-bg,#f3f4f6);display:flex;align-items:center;justify-content:center">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color:var(--edu-muted,#6b7280)">
                <rect x="3" y="11" width="18" height="11" rx="2"/>
                <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
            </svg>
        </div>
        <h2 style="margin:0 0 6px">Question Bank is Closed</h2>
        <p class="educbt-muted" style="margin:0 0 20px;max-width:420px;margin-left:auto;margin-right:auto">
            The question bank is currently closed for official examinations and continuous assessments.
            You will be notified when it opens for the next assessment or examination.
        </p>
        <?php if ( ! empty( $practice ) ) : ?>
            <div style="margin:20px auto 0;padding:20px;background:var(--edu-bg-subtle,#f8f9fa);border:1px solid var(--edu-line,#e5e7eb);border-radius:10px;max-width:480px;text-align:left">
                <strong style="display:block;margin-bottom:6px;font-size:1rem">Set Practice Exam Questions</strong>
                <p class="educbt-muted" style="font-size:.88rem;margin:0 0 14px">
                    Practice questions are available to students immediately, do not require approval,
                    and do not count towards official results. Click below if you would like to set
                    practice questions for your students.
                </p>
                <a href="<?php echo esc_url( add_query_arg( 'opt_in_practice', '1' ) ); ?>" class="educbt-btn educbt-btn--primary" style="text-decoration:none">
                    Set Practice Questions
                </a>
            </div>
        <?php endif; ?>
    </div>
    <?php
    return;
}


$session_label = '';
if ( ! empty( $session['title'] ) ) {
    $term_label = '';
    if ( $term_id > 0 ) {
        $term_row = $wpdb->get_row(
            $wpdb->prepare( "SELECT title FROM " . \EduCBTPro\Core\Schema::table( 'terms' ) . " WHERE id = %d", $term_id ),
            ARRAY_A
        );
        if ( $term_row ) {
            $term_label = ' · ' . $term_row['title'];
        }
    }
    $session_label = esc_html( $session['title'] . $term_label );
}

// Quotas / minimums.
$approval_svc   = new \EduCBTPro\Services\QuestionApprovalService();
$quota_info     = $approval_svc->quotas( $school_id );
$min_objective  = absint( $quota_info['objective'] ?? 20 );
$min_theory     = absint( $quota_info['theory'] ?? 4 );

// Existing submissions status for this teacher.
$my_submissions = [];
if ( ! $is_reviewer && ! empty( $subjects ) ) {
    $my_submissions = $approval_svc->submissions( $school_id, (int) $actor['id'] );
}

// Passages for the passage selector.
$passages = (array) $wpdb->get_results(
    $wpdb->prepare(
        'SELECT id, title FROM ' . \EduCBTPro\Core\Schema::table( 'passages' ) . " WHERE school_id = %d AND status = 'active' ORDER BY id DESC LIMIT 50",
        $school_id
    ),
    ARRAY_A
);

$educbt_title = 'Question Bank';

$educbt_body = static function () use (
    $flash, $subjects, $subject_classes, $session_label, $session_id, $term_id, $ca_window, $open_window, $writing_practice,
    $exam_prep_open, $is_reviewer, $actor, $min_objective, $min_theory,
    $my_submissions, $passages, $school_id
): void {
    require EDUCBT_PRO_PATH . 'templates/portal/partials/flash.php';
    ?>
<script>
window.EduCBTQS = {
    root: <?php echo wp_json_encode( esc_url_raw( rest_url( 'educbt/v1/' ) ) ); ?>,
    nonce: <?php echo wp_json_encode( wp_create_nonce( 'wp_rest' ) ); ?>,
    schoolId: <?php echo (int) $school_id; ?>,
    actorId: <?php echo (int) $actor['id']; ?>,
    isReviewer: <?php echo $is_reviewer ? 'true' : 'false'; ?>,
    examPrepOpen: <?php echo $exam_prep_open ? 'true' : 'false'; ?>,
    sessionId: <?php echo (int) $session_id; ?>,
    termId: <?php echo (int) $term_id; ?>,
    minObjective: <?php echo (int) $min_objective; ?>,
    minTheory: <?php echo (int) $min_theory; ?>,
    // CA Test specific info — used by the submit dialog to show the right prompt
    isCaTest: <?php echo ! empty( $ca_window ) ? 'true' : 'false'; ?>,
    caMinQuestions: <?php echo (int) ( $ca_window['per_student'] ?? 15 ); ?>,
    caMaxMarks: <?php echo (int) ( $ca_window['per_student'] ?? 15 ); ?>,
    caMarksPerQuestion: 1,
    caPerStudent: <?php echo (int) ( $ca_window['per_student'] ?? 15 ); ?>,
    subjects: <?php echo wp_json_encode( $subjects ); ?>,
    subjectClasses: <?php echo wp_json_encode( $subject_classes ); ?>,
    passages: <?php echo wp_json_encode( $passages ); ?>,
    // Set by the "Review" button on a notification, so the reviewer lands on the
    // exact subject/class that was submitted instead of an empty selector.
    initialSubjectId: <?php echo absint( $_GET['subject_id'] ?? 0 ); ?>,
    initialClassId: <?php echo absint( $_GET['class_id'] ?? 0 ); ?>,
    initialLevelId: <?php echo absint( $_GET['level_id'] ?? 0 ); ?>,
    // The WAEC blueprint is fixed reference data, not something a set owns. It has
    // to be here BEFORE any set exists, because a teacher ticks "WAEC Standard" on
    // an empty scope and must see the sections immediately — a set is not created
    // until the first question is saved.
    waecBlueprint: <?php
        $waec_bp = [];
        foreach ( [ 'cbt', 'written' ] as $bp_mode ) {
            foreach ( [ 'objective', 'theory' ] as $bp_type ) {
                $waec_bp[ $bp_mode . ':' . $bp_type ] =
                    \EduCBTPro\Services\WaecBlueprintService::sections_for( $school_id, $bp_mode, $bp_type );
            }
        }
        echo wp_json_encode( $waec_bp );
    ?>,
    initialDepartmentId: <?php echo absint( $_GET['department_id'] ?? 0 ); ?>,
    initialExamType: <?php echo wp_json_encode( sanitize_key( (string) ( $_GET['exam_type'] ?? '' ) ) ); ?>,
    // Written mode: schools without CBT facilities use paper-based exams.
    // Teachers confirm the requirements (20 objective, 40 theory marks, 60 total)
    // before the system records the paper as a written submission.
    writtenObjectiveMin: 20,
    writtenTheoryMarks: 40,
    writtenTotalMarks: 60,
    optInPractice: <?php echo $writing_practice ? 'true' : 'false'; ?>,
};
</script>

<?php if ( ! $exam_prep_open && ! $is_reviewer ): ?>
<div class="educbt-card" style="border-left:4px solid var(--edu-warn)">
    <p style="margin:0"><strong>Exam preparation is closed.</strong> Question submission is locked. Your Exam Officer controls this.</p>
</div>
<?php return; ?>
<?php endif; ?>

<?php if ( ! empty( $my_submissions ) ): ?>
<div class="educbt-card" style="margin-bottom:14px">
    <h2 style="margin:0 0 8px;font-size:1.05rem">My Submissions</h2>
    <?php foreach ( $my_submissions as $sub ): ?>
        <div style="display:flex;align-items:center;flex-wrap:wrap;gap:8px;padding:5px 0;border-bottom:1px solid var(--edu-line)">
            <span style="font-weight:600"><?php echo esc_html( (string) ( $sub['subject_name'] ?? 'Subject' ) ); ?>
                <?php if ( ! empty( $sub['level_name'] ) ): ?>
                    <span style="font-weight:400;color:var(--edu-muted)"> — <?php echo esc_html( (string) $sub['level_name'] ); ?></span>
                <?php endif; ?>
            </span>
            <?php
            $exam_type_label = (string) ( $sub['exam_type_label'] ?? 'Examination' );
            $type_pill_class = $exam_type_label === 'CA Test' ? 'educbt-pill--submitted' : ( $exam_type_label === 'Practice Exam' ? 'educbt-pill--draft' : 'educbt-pill--approved' );
            ?>
            <span class="educbt-pill <?php echo esc_attr( $type_pill_class ); ?>" style="font-size:.72rem"><?php echo esc_html( $exam_type_label ); ?></span>
            <span class="educbt-pill educbt-pill--draft">Obj: <?php echo (int) ( $sub['objective'] ?? 0 ); ?>/<?php echo (int) $min_objective; ?></span>
            <?php if ( $exam_type_label !== 'CA Test' && $exam_type_label !== 'Practice Exam' ): ?>
            <span class="educbt-pill educbt-pill--draft">Theory: <?php echo (int) ( $sub['theory'] ?? 0 ); ?>/<?php echo (int) $min_theory; ?></span>
            <?php endif; ?>
            <?php
            // Determine overall status from objective_status and theory_status.
            $obj_st = strtolower( trim( (string) ( $sub['objective_status'] ?? '' ) ) );
            $thy_st = strtolower( trim( (string) ( $sub['theory_status'] ?? '' ) ) );
            $status_pill = 'educbt-pill--draft';
            $status_text = 'in progress';
            if ( $obj_st === 'approved' && ( $thy_st === 'approved' || $thy_st === '' ) ) {
                $status_pill = 'educbt-pill--approved';
                $status_text = 'all approved';
            } elseif ( $obj_st === 'returned' || $thy_st === 'returned' ) {
                $status_pill = 'educbt-pill--draft';
                $status_text = 'returned for revision';
            } elseif ( $obj_st === 'submitted' || $obj_st === 'under_review' || $thy_st === 'submitted' || $thy_st === 'under_review' ) {
                $status_pill = 'educbt-pill--submitted';
                $status_text = 'awaiting review';
            } elseif ( $obj_st === 'draft' || $thy_st === 'draft' ) {
                $status_pill = 'educbt-pill--draft';
                $status_text = 'in progress (not submitted)';
            }
            ?>
            <span class="educbt-pill <?php echo esc_attr( $status_pill ); ?>"><?php echo esc_html( $status_text ); ?></span>
            <?php if ( ! empty( $sub['submitted_at'] ) && $sub['submitted_at'] !== '0000-00-00 00:00:00' ): ?>
                <span style="font-size:.75rem;color:var(--edu-muted)">submitted <?php echo esc_html( mysql2date( 'M j, g:i A', (string) $sub['submitted_at'] ) ); ?></span>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- =========== REGION A — SCOPE SELECTOR (sticky) =========== -->
<div id="qs-scope" class="educbt-card" style="position:sticky;top:0;z-index:50;background:var(--edu-surface,#fff);border-bottom:2px solid var(--edu-line)">
    <?php // What the bank is open for — or that it is shut. A teacher must never
          // have to guess which assessment their questions are going into. ?>
    <?php if ( empty( $open_window ) ) : ?>
        <div class="educbt-card" style="text-align:center;padding:30px 20px">
            <h2 style="margin:0 0 6px">Closed</h2>
            <p class="educbt-muted" style="margin:0">You&rsquo;ll be notified when it&rsquo;s open.</p>
        </div>
        <?php return; ?>
    <?php endif; ?>

    <div style="background:#0F2818;color:#CBEB6E;border-radius:10px;padding:12px 16px;margin-bottom:14px">
        <strong style="display:block;font-size:.95rem;margin-bottom:3px">
            Writing for: <?php echo esc_html( (string) $open_window['title'] ); ?>
        </strong>
        <span style="font-size:.83rem;color:rgba(203,235,110,.8)">
            <?php if ( ! empty( $open_window['is_ca'] ) ) : ?>
                Objective questions only.
                <?php if ( (int) $open_window['per_student'] > 0 ) : ?>
                    Each student answers <?php echo esc_html( (string) (int) $open_window['per_student'] ); ?>
                    in <?php echo esc_html( (string) (int) $open_window['duration'] ); ?> minutes.
                <?php endif; ?>
                <?php if ( (string) $open_window['closes_on'] !== '' ) : ?>
                    Closes <?php echo esc_html( mysql2date( 'j M Y', (string) $open_window['closes_on'] ) ); ?>.
                <?php endif; ?>
                <?php if ( (string) $open_window['component'] !== '' ) : ?>
                    Marks count towards <?php echo esc_html( (string) $open_window['component'] ); ?>.
                <?php endif; ?>
                These questions stay in the bank and can be reused in the terminal paper.
            <?php elseif ( ! empty( $writing_practice ) ) : ?>
                Practice questions. No approval, no timetable — they are available to students as
                soon as you save them, and the marks do not count towards results.
            <?php else : ?>
                Terminal examination. Objective and theory are submitted together as one paper,
                and go to the examination officer for review.
            <?php endif; ?>
        </span>
    </div>

    <?php // Context row: what term this is for, and how it will be delivered. Both
          // apply to the whole set, so they belong together and above the
          // per-question choices rather than mixed in among them. ?>
    <div style="display:flex;flex-wrap:wrap;gap:18px;align-items:flex-end;padding-bottom:9px;margin-bottom:9px;border-bottom:1px solid var(--edu-line)">
        <div>
            <span class="educbt-muted" style="font-size:.8rem;display:block;margin-bottom:3px">Session / Term</span>
            <span style="font-weight:600;font-size:.95rem"><?php echo $session_label ?: 'No session set'; ?></span>
        </div>
        <div style="min-width:180px">
            <label class="educbt-muted" style="font-size:.8rem;display:block;margin-bottom:3px">Delivery Mode</label>
            <div id="qs-delivery-mode" style="display:flex;gap:0;border:1px solid var(--edu-line);border-radius:8px;overflow:hidden">
                <button type="button" data-mode="cbt" class="qs-mode-btn" style="flex:1;padding:7px 12px;border:0;background:var(--edu-primary,#3b82f6);color:#fff;font-weight:600;cursor:pointer">CBT</button>
                <button type="button" data-mode="written" class="qs-mode-btn" style="flex:1;padding:7px 12px;border:0;background:transparent;color:inherit;font-weight:500;cursor:pointer">Written</button>
            </div>
        </div>
    </div>
    <!-- Selection row — Subject, Class, Delivery Mode, Exam Type, Default Marks, Method -->
    <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end">
        <div style="flex:1;min-width:160px">
            <label class="educbt-muted" style="font-size:.8rem;display:block;margin-bottom:3px">Subject</label>
            <select id="qs-subject" class="educbt-input" style="width:100%">
                <option value="">Choose subject…</option>
                <?php foreach ( $subjects as $s ): ?>
                    <?php
                    // Show the code beside the name. A school runs Mathematics at
                    // junior and senior level as two separate subjects with two
                    // separate syllabuses; without the code they are two identical
                    // lines in this list and a teacher cannot tell which is which.
                    $s_label = (string) $s['name'];

                    if ( ! empty( $s['code'] ) ) {
                        $s_label .= ' (' . $s['code'] . ')';
                    } elseif ( ! empty( $s['stage'] ) && $s['stage'] !== 'both' ) {
                        $s_label .= ' (' . ucfirst( (string) $s['stage'] ) . ')';
                    }
                    ?>
                    <option value="<?php echo (int) $s['id']; ?>"><?php echo esc_html( $s_label ); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div style="flex:1;min-width:140px">
            <label class="educbt-muted" style="font-size:.8rem;display:block;margin-bottom:3px">Class Level</label>
            <select id="qs-class" class="educbt-input" style="width:100%" disabled>
                <option value="">Choose subject first…</option>
            </select>
        </div>
        <div style="min-width:180px">
            <label class="educbt-muted" style="font-size:.8rem;display:block;margin-bottom:3px">Exam Type</label>
            <div id="qs-exam-type" style="display:flex;gap:0;border:1px solid var(--edu-line);border-radius:8px;overflow:hidden">
                <button type="button" data-type="objective" class="qs-type-btn" style="flex:1;padding:7px 12px;border:0;background:var(--edu-primary,#3b82f6);color:#fff;font-weight:600;cursor:pointer">Objective</button>
                <button type="button" data-type="theory" class="qs-type-btn" style="flex:1;padding:7px 12px;border:0;background:transparent;color:inherit;font-weight:500;cursor:pointer">Theory</button>
            </div>
        </div>
        <div id="qs-marks-wrap" style="min-width:80px">
            <label class="educbt-muted" style="font-size:.8rem;display:block;margin-bottom:3px">Default Marks</label>
            <input type="number" id="qs-marks" class="educbt-input" value="1" min="0.5" step="0.5" style="width:70px">
        </div>
        <div style="min-width:200px">
            <label class="educbt-muted" style="font-size:.8rem;display:block;margin-bottom:3px">Method</label>
            <select id="qs-method" class="educbt-input" style="width:100%">
                <option value="manual">Manual Entry</option>
                <option value="paste">Paste in Format</option>
                <option value="csv">CSV / Excel Import</option>
            </select>
        </div>
        <div id="qs-waec-wrap" style="min-width:140px;display:none;flex-direction:column;justify-content:flex-end;padding-bottom:2px">
            <label style="display:flex;align-items:center;gap:6px;font-size:.85rem;cursor:pointer;font-weight:500">
                <input type="checkbox" id="qs-waec-mode" style="width:auto;accent-color:var(--edu-primary,#3b82f6)">
                WAEC Standard
            </label>
            <span class="educbt-muted" style="font-size:.7rem;margin-left:22px">60-obj English structure</span>
        </div>
    </div>
</div>

<!-- =========== WRITTEN MODE PANEL (hidden by default) =========== -->
<div id="qs-written-panel" class="educbt-card" style="display:none;margin-top:12px;border-left:4px solid var(--edu-warn,#f59e0b)">
    <h3 style="margin:0 0 8px;font-size:1.05rem">Written Examination Intent</h3>
    <p class="educbt-muted" style="font-size:.9rem;margin:0 0 16px">
        This subject will be examined on paper, not as a CBT. Management will print
        question papers and arrange invigilation. No questions need to be entered here —
        submit this intent so the exam office can include it in the timetable.
    </p>
    <div id="qs-written-summary" style="font-size:.85rem;margin-bottom:16px;padding:10px;background:var(--edu-bg,#f9fafb);border-radius:8px"></div>
    <button type="button" id="qs-written-submit-btn" class="educbt-btn educbt-btn--primary">
        Submit Written Examination Intent
    </button>
    <span id="qs-written-status" class="educbt-muted" style="margin-left:12px;font-size:.85rem"></span>
</div>

<!-- =========== STATUS BANNER =========== -->
<div id="qs-status-banner" style="display:none;margin-top:12px"></div>

<!-- =========== REGION B — INPUT SURFACE =========== -->
<div id="qs-waec-panel" style="display:none"></div>
<div id="qs-ca-pool" style="display:none"></div>
<div id="qs-input" class="educbt-card" style="margin-top:12px;display:none">
    <!-- Content injected by JS based on method + exam type -->
</div>

<!-- =========== REGION C — LIVE PREVIEW =========== -->
<div id="qs-preview" class="educbt-card" style="margin-top:12px;min-height:200px">
    <div id="qs-empty-state">
        <p class="educbt-muted" style="text-align:center;padding:40px 20px">
            Choose a subject and class to start writing questions.<br>
            You can enter questions manually, paste them in, or import from CSV — all three methods add to the same set.
        </p>
    </div>
    <div id="qs-question-list" style="display:none"></div>
</div>

<!-- =========== REGION D — PROGRESS + SUBMIT BAR (sticky) =========== -->
<div id="qs-submit-bar" style="position:sticky;bottom:0;z-index:50;background:var(--edu-surface,#fff);border-top:2px solid var(--edu-line);padding:10px 16px;display:flex;align-items:center;gap:16px">
    <div id="qs-progress" style="flex:1;display:none">
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px">
            <span id="qs-count-label" class="educbt-muted" style="font-size:.85rem">0 questions</span>
            <span id="qs-marks-label" class="educbt-muted" style="font-size:.85rem">0 marks</span>
            <span id="qs-sibling-label" class="educbt-muted" style="font-size:.85rem"></span>
        </div>
        <div style="height:6px;background:var(--edu-line);border-radius:3px;overflow:hidden">
            <div id="qs-progress-bar" style="height:100%;width:0%;background:var(--edu-primary,#3b82f6);transition:width .3s"></div>
        </div>
    </div>
    <div id="qs-saved-indicator" class="educbt-muted" style="font-size:.8rem"></div>
    <?php if ( ! $is_reviewer ): ?>
    <button type="button" id="qs-submit-btn" class="educbt-btn educbt-btn--primary" disabled style="display:none">Submit for Review</button>
    <?php endif; ?>
</div>

<!-- Written Mode Confirmation Modal -->
<div id="qs-written-modal" style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,.5);align-items:center;justify-content:center">
    <div class="educbt-card" style="max-width:480px;width:90%;padding:24px;border-radius:12px;box-shadow:0 8px 32px rgba(0,0,0,.2)">
        <h3 style="margin:0 0 12px;font-size:1.1rem">Written Examination — Confirm Requirements</h3>
        <p style="color:var(--edu-muted);font-size:.9rem;margin:0 0 16px">
            You are switching to <strong>Written (paper-based)</strong> mode. The school will print
            question papers for students to answer on paper, and teachers will mark and submit results.
            Please confirm you understand the requirements:
        </p>
        <ul style="margin:0 0 16px;padding-left:20px;font-size:.9rem;line-height:1.8">
            <li>Minimum <strong>20 objective questions</strong></li>
            <li>Theory questions should total <strong>40 marks</strong></li>
            <li>Total examination score = <strong>60 marks</strong> (20 objective + 40 theory)</li>
            <li>The paper will be recorded as <strong>Written</strong> and included in the timetable and invigilation schedule</li>
        </ul>
        <div style="display:flex;gap:10px;justify-content:flex-end">
            <button type="button" id="qs-written-cancel" class="educbt-btn" style="background:transparent;border:1px solid var(--edu-line)">Cancel</button>
            <button type="button" id="qs-written-confirm" class="educbt-btn educbt-btn--primary">I Understand — Continue</button>
        </div>
    </div>
</div>

<script>
(function(){
    'use strict';

    const API = window.EduCBTQS;
    let currentSet = null;
    let currentExamType = 'objective';
    let currentMethod = 'manual';
    let currentQuestions = [];
    let unsavedInput = false;
    let currentDeliveryMode = 'cbt';

    // ---- Helpers ----

    // rest_url() returns EITHER https://site/wp-json/educbt/v1/ (pretty permalinks)
    // OR https://site/?rest_route=/educbt/v1/ (plain permalinks). Gluing
    // "question-sets?subject_id=3" onto the second form produces a URL with two
    // "?" in it, so WordPress reads the route as "question-sets?subject_id=3",
    // matches nothing, and returns 404. That is why every POST worked while the
    // two GETs that load Region C — the only calls carrying a query string —
    // silently returned nothing. Build the URL properly instead of concatenating.
    // The second selector now carries a LEVEL, encoded "levelId:departmentId",
    // because JS1 A and JS1 B sit the same paper and share one question set.
    function currentScope() {
        const raw = String(classSel.value || '');
        if (!raw) return { level_id: 0, department_id: 0 };
        const parts = raw.split(':');
        return {
            level_id: parseInt(parts[0]) || 0,
            department_id: parseInt(parts[1]) || 0,
        };
    }

    // ---- Image picking, shared by the entry form and the inline editor ----
    //
    // Opens the device picker, uploads through the plugin's own endpoint, and hands
    // back a URL. Kept in one place because the entry form and the edit form need
    // identical behaviour and used to have none.
    function educbtPickImage(onDone, btn) {
        var input = document.createElement('input');
        input.type = 'file';
        // `accept` is what makes a phone offer Gallery and Camera directly.
        input.accept = 'image/jpeg,image/png,image/gif,image/webp';

        input.addEventListener('change', function() {
            var file = input.files && input.files[0];
            if (!file) return;

            if (file.size > 5 * 1024 * 1024) {
                alert('That image is larger than 5MB. Please choose a smaller one.');
                return;
            }

            var original = btn ? btn.textContent : '';
            if (btn) { btn.disabled = true; btn.textContent = 'Uploading…'; }

            var form = new FormData();
            form.append('file', file);

            fetch(apiUrl('question-image', {}), {
                method: 'POST',
                headers: { 'X-WP-Nonce': API.nonce },
                credentials: 'same-origin',
                body: form,
            })
                .then(function(r) { return r.json(); })
                .then(function(r) {
                    if (btn) { btn.disabled = false; btn.textContent = original; }
                    if (r && r.success && r.url) { onDone(r.url); return; }
                    alert((r && r.message) ? r.message : 'That image could not be uploaded.');
                })
                .catch(function() {
                    if (btn) { btn.disabled = false; btn.textContent = original; }
                    alert('That image could not be uploaded. Check your connection and try again.');
                });
        });

        input.click();
    }

    function apiUrl(path, params) {
        const root = API.root;
        let url = root + path;

        if (!params) return url;

        const pairs = [];
        Object.keys(params).forEach(function(k) {
            if (params[k] === undefined || params[k] === null || params[k] === '') return;
            pairs.push(encodeURIComponent(k) + '=' + encodeURIComponent(params[k]));
        });
        // Defeat any intermediate cache that ignores no-store on a plain GET.
        pairs.push('_ts=' + Date.now());
        if (!pairs.length) return url;

        url += (url.indexOf('?') === -1 ? '?' : '&') + pairs.join('&');
        return url;
    }

    function apiCall(method, path, body, params) {
        // When in practice mode, append opt_in_practice to every request so the
        // API knows to resolve to the practice window.
        if (API.optInPractice) {
            params = params || {};
            if (!params.opt_in_practice) params.opt_in_practice = 1;
            if (body && typeof body === 'object') body.opt_in_practice = 1;
        }
        const opts = {
            method: method,
            headers: {
                'X-WP-Nonce': API.nonce,
                'Content-Type': 'application/json',
            },
            credentials: 'same-origin',
            cache: 'no-store',
        };
        if (body) opts.body = JSON.stringify(body);
        // A WP_Error comes back as {code, message, data:{status}} — no `success`
        // and no `error`. Reading only those two keys is why every server-side
        // refusal, whatever its cause, surfaced as the same bare "Save failed"
        // and left nothing to diagnose. Flatten both shapes into one.
        return fetch(apiUrl(path, params), opts)
            .then(function(res) {
                return res.json()
                    .catch(function() { return null; })
                    .then(function(data) {
                        if (data && typeof data === 'object' && typeof data.success !== 'undefined') {
                            return data;
                        }
                        if (data && data.message) {
                            return { success: false, error: data.message, code: data.code || '' };
                        }
                        if (!res.ok) {
                            return { success: false, error: 'Server returned ' + res.status + '.' };
                        }
                        return data || { success: false, error: 'Empty response from the server.' };
                    });
            })
            .catch(function() {
                return { success: false, error: 'Could not reach the server. Check your connection and try again.' };
            });
    }

    function el(id) { return document.getElementById(id); }
    function esc(s) { const d = document.createElement('div'); d.textContent = s || ''; return d.innerHTML; }

    function showSaved(msg) {
        const ind = el('qs-saved-indicator');
        ind.textContent = msg || 'All changes saved';
        ind.style.color = '';
    }
    function showSaving() { el('qs-saved-indicator').textContent = 'Saving…'; }
    function showSaveError(msg) { el('qs-saved-indicator').textContent = msg || 'Save failed — retry'; el('qs-saved-indicator').style.color = 'red'; }

    // ---- Scope selector ----

    const subjectSel = el('qs-subject');
    const classSel = el('qs-class');
    const marksInput = el('qs-marks');
    const methodSel = el('qs-method');

    // Registered ONCE. This used to sit inside the subject handler, so every
    // subject change stacked another listener and a single class change fired
    // loadSet three or four times in parallel.
    classSel.addEventListener('change', function() { refreshWaecVisibility(); loadSet(); });

    // Honour a deep link from a notification's "Review" button.
    function applyInitialScope() {
        const sid = parseInt(API.initialSubjectId) || 0;
        const cid = parseInt(API.initialClassId) || 0;
        if (!sid) return;

        if (API.initialExamType === 'theory' || API.initialExamType === 'objective') {
            currentExamType = API.initialExamType;
            // Hide Default Marks if starting in theory mode
            if (currentExamType === 'theory') {
                var mw = document.getElementById('qs-marks-wrap');
                if (mw) mw.style.display = 'none';
            }
            document.querySelectorAll('.qs-type-btn').forEach(function(b) {
                b.classList.toggle('educbt-btn--primary', b.dataset.type === currentExamType);
            });
        }

        subjectSel.value = String(sid);
        subjectSel.dispatchEvent(new Event('change'));

        // Deep links may name a level directly, or a class arm we resolve server-side.
        const lvl = parseInt(API.initialLevelId) || 0;
        const dept = parseInt(API.initialDepartmentId) || 0;

        if (lvl) {
            classSel.value = lvl + ':' + dept;
            classSel.dispatchEvent(new Event('change'));
        }
    }

    subjectSel.addEventListener('change', function() {
        const sid = parseInt(this.value);
        classSel.innerHTML = '<option value="">Choose class level…</option>';
        classSel.disabled = !sid;

        if (sid && API.subjectClasses[sid]) {
            API.subjectClasses[sid].forEach(function(c) {
                const opt = document.createElement('option');
                opt.value = c.id;
                opt.textContent = c.name;
                classSel.appendChild(opt);
            });
        }
        loadSet();
    });

    document.querySelectorAll('.qs-type-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            if (unsavedInput && !confirm('You have an unfinished question. Discard it and switch?')) return;
            unsavedInput = false;
            currentExamType = this.dataset.type;
            document.querySelectorAll('.qs-type-btn').forEach(function(b) {
                b.style.background = b.dataset.type === currentExamType ? 'var(--edu-primary,#3b82f6)' : 'transparent';
                b.style.color = b.dataset.type === currentExamType ? '#fff' : 'inherit';
                b.style.fontWeight = b.dataset.type === currentExamType ? '600' : '500';
            });
            // Default Marks only applies to objective — hide for theory
            var marksWrap = document.getElementById('qs-marks-wrap');
            if (marksWrap) marksWrap.style.display = currentExamType === 'theory' ? 'none' : 'flex';
            refreshWaecVisibility();
            if (subjectSel.value && classSel.value) loadSet();
            renderInput();
        });
    });

    methodSel.addEventListener('change', function() {
        currentMethod = this.value;
        renderInput();
    });

    // ---- Delivery Mode (CBT vs Written) ----

    var writtenPanel = el('qs-written-panel');
    var waecCheckbox = el('qs-waec-mode');
    var currentWaecMode = false;
    var writtenModal = el('qs-written-modal');
    var pendingModeSwitch = null;

    function setDeliveryModeUI(mode) {
        document.querySelectorAll('.qs-mode-btn').forEach(function(b) {
            b.style.background = b.dataset.mode === mode ? 'var(--edu-primary,#3b82f6)' : 'transparent';
            b.style.color = b.dataset.mode === mode ? '#fff' : 'inherit';
            b.style.fontWeight = b.dataset.mode === mode ? '600' : '500';
        });
    }

    function applyWrittenMode(isWritten) {
        // In written mode, hide question entry, preview, progress bar, exam type,
        // method selector, marks, and WAEC checkbox. Show the written intent panel.
        var inputDiv = el('qs-input');
        var previewDiv = el('qs-preview');
        var progressDiv = el('qs-progress');
        var submitBtn = el('qs-submit-btn');
        var marksWrap = el('qs-marks-wrap');
        var methodDiv = el('qs-method') ? el('qs-method').closest('div') : null;
        var examTypeDiv = document.getElementById('qs-exam-type') ? document.getElementById('qs-exam-type').closest('div') : null;
        var waecWrap = el('qs-waec-wrap');

        if (isWritten) {
            if (inputDiv) inputDiv.style.display = 'none';
            if (previewDiv) previewDiv.style.display = 'none';
            if (progressDiv) progressDiv.style.display = 'none';
            if (submitBtn) submitBtn.style.display = 'none';
            if (marksWrap) marksWrap.style.display = 'none';
            if (methodDiv) methodDiv.style.display = 'none';
            if (examTypeDiv) examTypeDiv.style.display = 'none';
            if (waecWrap) waecWrap.style.display = 'none';
            if (writtenPanel) writtenPanel.style.display = 'block';

            // Build the summary text
            var subName = subjectSel.options[subjectSel.selectedIndex] ? subjectSel.options[subjectSel.selectedIndex].textContent : '';
            var clsName = classSel.options[classSel.selectedIndex] ? classSel.options[classSel.selectedIndex].textContent : '';
            var summary = el('qs-written-summary');
            if (summary) {
                summary.innerHTML = '<strong>Subject:</strong> ' + esc(subName) + '<br>' +
                    '<strong>Class Level:</strong> ' + esc(clsName) + '<br>' +
                    '<strong>Exam Type:</strong> ' + capitalize(currentExamType) + '<br>' +
                    '<strong>Delivery:</strong> Written (paper-based)<br>' +
                    '<strong>Session:</strong> ' + esc(API.sessionLabel || 'Current session');
            }
        } else {
            if (marksWrap) marksWrap.style.display = '';
            if (methodDiv) methodDiv.style.display = '';
            if (examTypeDiv) examTypeDiv.style.display = '';
            refreshWaecVisibility();
            if (writtenPanel) writtenPanel.style.display = 'none';
            if (previewDiv) previewDiv.style.display = '';
        }
    }

    document.querySelectorAll('.qs-mode-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var mode = this.dataset.mode;
            if (mode === currentDeliveryMode) return;

            if (mode === 'written') {
                // Show confirmation modal before switching to Written
                pendingModeSwitch = mode;
                writtenModal.style.display = 'flex';
            } else {
                // Switching back to CBT — no modal needed
                currentDeliveryMode = mode;
                setDeliveryModeUI(mode);
                applyWrittenMode(false);
                refreshWaecVisibility();
            if (subjectSel.value && classSel.value) loadSet();
            }
        });
    });

    // Modal: Cancel
    el('qs-written-cancel').addEventListener('click', function() {
        writtenModal.style.display = 'none';
        pendingModeSwitch = null;
    });

    // Modal: Confirm
    el('qs-written-confirm').addEventListener('click', function() {
        writtenModal.style.display = 'none';
        if (pendingModeSwitch) {
            currentDeliveryMode = pendingModeSwitch;
            setDeliveryModeUI(currentDeliveryMode);
            pendingModeSwitch = null;
            applyWrittenMode(true);
            refreshWaecVisibility();
            if (subjectSel.value && classSel.value) loadSet();
        }
    });

    marksInput.addEventListener('change', function() {
        if (currentSet) {
            apiCall('POST', 'question-sets', {
                subject_id: parseInt(subjectSel.value),
                level_id: currentScope().level_id,
                department_id: currentScope().department_id,
                exam_type: currentExamType,
                default_marks: parseFloat(marksInput.value),
                delivery_mode: currentDeliveryMode,
                waec_mode: currentWaecMode,
            }).then(function(r) {
                if (r.success) currentSet = r.set;
            });
        }
    });

    // ---- WAEC structure: only where it actually applies ----
    //
    // The WAEC English blueprint is a SENIOR SCHOOL ENGLISH paper. Offering the
    // option on JSS Basic Science invites a teacher to tick something that cannot
    // be composed, and the failure would only surface at paper assembly. So the
    // control is shown when — and only when — the chosen subject is English and the
    // chosen level is senior.
    // English Language applies to ALL class levels — not just senior.
    // The passage / comprehension passage selector should only appear for English.
    function isEnglishSubject() {
        var subOpt = subjectSel.options[subjectSel.selectedIndex];
        if (!subOpt || !subjectSel.value) return false;
        var subject = (subOpt.textContent || '').toLowerCase();
        // "English Language", "English Studies", "Use of English" all qualify.
        return subject.indexOf('english') !== -1;
    }

    function waecEligible() {
        var subOpt = subjectSel.options[subjectSel.selectedIndex];
        var clsOpt = classSel.options[classSel.selectedIndex];

        if (!subOpt || !clsOpt || !subjectSel.value || !classSel.value) return false;

        var subject = (subOpt.textContent || '').toLowerCase();
        var level = (clsOpt.textContent || '').toLowerCase();

        // "English Language", "English Studies", "Use of English" all qualify.
        var isEnglish = subject.indexOf('english') !== -1;

        // Senior classes are SS1-SS3 / SSS1-SSS3. Junior levels must not match, so
        // test for the senior prefix rather than merely for the letter S.
        var isSenior = /\bss?s?\s*[123]\b/.test(level) || level.indexOf('senior') !== -1;

        return isEnglish && isSenior;
    }

    function refreshWaecVisibility() {
        var wrap = el('qs-waec-wrap');
        if (!wrap) return;

        if (currentDeliveryMode === 'written' || !waecEligible()) {
            wrap.style.display = 'none';

            // Never leave it silently ticked for a scope it cannot apply to.
            if (waecCheckbox && waecCheckbox.checked) {
                waecCheckbox.checked = false;
                currentWaecMode = false;
            }
            return;
        }

        wrap.style.display = '';
    }

    // WAEC mode checkbox
    if (waecCheckbox) {
        waecCheckbox.addEventListener('change', function() {
            currentWaecMode = this.checked;

            // Show the structure straight away. Previously this only spoke to the
            // server when a set already existed, and never re-rendered — so ticking
            // the box on a fresh subject did nothing visible at all.
            currentWaecSection = '';

            // The tick SWITCHES BANK, it does not convert a set.
            //
            // A WAEC paper and the school's own paper for the same subject are two
            // separate sets now, so this simply loads the other one. Previously it
            // tried to flip the flag on the set in front of you, which is why
            // unticking appeared to do nothing — and why finishing a WAEC paper then
            // starting the school's examination handed you the WAEC draft back,
            // sections and all.
            //
            // Nothing is lost either way: both sets stay where they are, and ticking
            // the box again brings the WAEC paper straight back.
            if (subjectSel.value && classSel.value) {
                loadSet();
                return;
            }

            renderWaecPanel();
            renderInput();
        });
    }

    // Written mode submit intent button
    var writtenSubmitBtn = el('qs-written-submit-btn');
    if (writtenSubmitBtn) {
        writtenSubmitBtn.addEventListener('click', function() {
            if (!subjectSel.value || !classSel.value) {
                alert('Choose a subject and class first.');
                return;
            }
            writtenSubmitBtn.disabled = true;
            writtenSubmitBtn.textContent = 'Submitting…';
            var statusEl = el('qs-written-status');
            if (statusEl) statusEl.textContent = '';

            apiCall('POST', 'question-sets', {
                subject_id: parseInt(subjectSel.value),
                level_id: currentScope().level_id,
                department_id: currentScope().department_id,
                exam_type: currentExamType,
                default_marks: parseFloat(marksInput.value) || 1,
                delivery_mode: 'written',
                waec_mode: currentWaecMode,
            }).then(function(r) {
                if (r.success && r.set) {
                    currentSet = r.set;
                    // Now submit the written intent (server skips min-question check for written mode)
                    return apiCall('POST', 'question-sets/' + r.set.id + '/submit');
                }
                throw new Error(r.error || 'Could not create set');
            }).then(function(r) {
                if (r.success) {
                    if (statusEl) {
                        statusEl.textContent = '\u2713 Written examination intent submitted for ' +
                            (subjectSel.options[subjectSel.selectedIndex] ? subjectSel.options[subjectSel.selectedIndex].textContent : '') +
                            ' \u2014 ' + (classSel.options[classSel.selectedIndex] ? classSel.options[classSel.selectedIndex].textContent : '');
                        statusEl.style.color = '#16a34a';
                    }
                    writtenSubmitBtn.textContent = 'Update Intent';
                } else {
                    if (statusEl) {
                        statusEl.textContent = '\u2717 ' + (r.error || r.message || 'Could not submit');
                        statusEl.style.color = '#dc2626';
                    }
                    writtenSubmitBtn.textContent = 'Submit Written Examination Intent';
                }
                writtenSubmitBtn.disabled = false;
            }).catch(function(err) {
                if (statusEl) {
                    statusEl.textContent = '\u2717 ' + (err.message || 'Network error');
                    statusEl.style.color = '#dc2626';
                }
                writtenSubmitBtn.disabled = false;
                writtenSubmitBtn.textContent = 'Submit Written Examination Intent';
            });
        });
    }

    // ---- Load / create set ----

    function loadSet() {
        pendingPassageId = 0;
        pendingInstruction = '';
        const sid = parseInt(subjectSel.value);
        const scope = currentScope();
        const cid = scope.level_id;

        // Clear the previous scope's draft before loading the next one, so a
        // half-selected scope can never leave another subject's questions on screen.
        currentSet = null;
        currentQuestions = [];

        if (!sid || !cid) {
            renderPreview();
            renderProgress();
            renderWaecPanel();
            renderStatusBanner();
            renderInput();
            if (writtenPanel) writtenPanel.style.display = 'none';
            return;
        }

        showSaving();
        apiCall('GET', 'question-sets', null, {
            subject_id: sid,
            level_id: scope.level_id,
            department_id: scope.department_id,
            exam_type: currentExamType,
            // Ask for the right bank: WAEC-structured, or the school's own.
            waec_mode: currentWaecMode ? 1 : 0,
        })
            .then(function(r) {
                if (!r.success) { showSaveError(r.error || 'Could not load this set'); return; }
                // Update live quotas from the API so the frontend always uses the
                // current school's configured minimums, not a stale page-load value.
                if (r.quotas) {
                    API.minObjective = parseInt(r.quotas.objective) || API.minObjective;
                    API.minTheory = parseInt(r.quotas.theory) || API.minTheory;
                }
                currentSet = r.set;
                currentQuestions = r.questions || [];
                if (r.set && r.set.delivery_mode) {
                    currentDeliveryMode = r.set.delivery_mode;
                    setDeliveryModeUI(currentDeliveryMode);
                }
                // Do NOT take the mode back from the set.
                //
                // The tick is the teacher's CHOICE of which bank to work in, and the
                // lookup already used it. Reading it back from whatever came down
                // was what re-ticked the box a moment after it was cleared.
                if (waecCheckbox) { waecCheckbox.checked = currentWaecMode; }
                renderPreview();
                renderProgress();
                renderWaecPanel();
                renderStatusBanner();
                // Apply written mode UI after loading — the set may have been saved as written
                applyWrittenMode(currentDeliveryMode === 'written');
                // Resume affordance: coming back to a scope that already has work
                // should say so, not look like a blank slate.
                if (r.set && currentQuestions.length > 0) {
                    showSaved('Resumed draft — ' + currentQuestions.length + ' question' + (currentQuestions.length === 1 ? '' : 's') + ' already saved');
                } else {
                    showSaved(r.set ? 'Set loaded' : 'Ready');
                }
                renderInput();
            });
    }

    // Returns a promise AND still honours the callback form used elsewhere.
    //
    // It previously took a callback only and returned undefined, so the composers
    // that called `ensureSet().then(...)` were calling `.then` on nothing. The
    // save button sat on "Saving…" for ever because the chain after it never ran
    // and nothing threw. Rejecting loudly is better than hanging silently, so a
    // failure now settles the promise instead of leaving it pending.
    function ensureSet(cb) {
        cb = cb || function() {};

        if (currentSet) { cb(currentSet); return Promise.resolve(currentSet); }

        showSaving();

        return apiCall('POST', 'question-sets', {
            subject_id: parseInt(subjectSel.value),
            level_id: currentScope().level_id,
            department_id: currentScope().department_id,
            exam_type: currentExamType,
            default_marks: parseFloat(marksInput.value),
            delivery_mode: currentDeliveryMode,
            waec_mode: currentWaecMode,
        }).then(function(r) {
            if (r && r.success) { currentSet = r.set; cb(currentSet); return currentSet; }

            var msg = (r && r.error) || 'Could not start a question set for this subject and class.';
            showSaveError(msg);
            alert(msg);
            cb(null);
            return null;
        }).catch(function(e) {
            showSaveError('Could not start a question set');
            alert('Could not start a question set. Check your connection and try again.');
            cb(null);
            return null;
        });
    }

    // ---- Status banner ----

    function renderStatusBanner() {
        const banner = el('qs-status-banner');
        if (!currentSet) { banner.style.display = 'none'; return; }
        const status = currentSet.status;
        if (status === 'draft') { banner.style.display = 'none'; return; }
        // A teacher and a reviewer are looking at the same set from opposite sides.
        // Telling a principal their own submission is "awaiting Exam Officer review"
        // described the teacher's position, not theirs — they ARE the review.
        const teacherMessages = {
            submitted: { text: 'Submitted — awaiting Exam Officer review.', bg: '#e0e7ff', color: '#3730a3' },
            under_review: { text: 'Under review by Exam Officer.', bg: '#fef3c7', color: '#92400e' },
            returned: { text: 'Returned for revision: ' + (currentSet.reviewer_comment || 'See comments below.'), bg: '#fee2e2', color: '#991b1b' },
            approved: { text: 'Approved — ready for paper assembly.', bg: '#d1fae5', color: '#065f46' },
            published: { text: 'Published — attached to a live exam paper.', bg: '#e0e7ff', color: '#3730a3' },
        };

        const reviewerMessages = {
            submitted: { text: 'Awaiting your review — ' + currentQuestions.length + ' question(s) submitted.', bg: '#fef3c7', color: '#92400e' },
            under_review: { text: 'You have this set open for review.', bg: '#fef3c7', color: '#92400e' },
            returned: { text: 'You sent this back for revision: ' + (currentSet.reviewer_comment || 'no comment recorded.'), bg: '#fee2e2', color: '#991b1b' },
            approved: { text: 'You approved this set — ready for paper assembly.', bg: '#d1fae5', color: '#065f46' },
            published: { text: 'Published — attached to a live exam paper.', bg: '#e0e7ff', color: '#3730a3' },
        };

        const messages = API.isReviewer ? reviewerMessages : teacherMessages;
        const m = messages[status] || messages.submitted;
        banner.style.display = 'flex';
        banner.style.background = m.bg;
        banner.style.color = m.color;
        banner.style.padding = '10px 14px';
        banner.style.borderRadius = '8px';
        banner.style.fontWeight = '600';
        banner.style.alignItems = 'center';
        banner.style.justifyContent = 'space-between';
        banner.style.gap = '12px';

        let html = '<span>' + esc(m.text) + '</span>';

        // Withdraw button: visible when submitted or under_review
        if ((status === 'submitted' || status === 'under_review') && !API.isReviewer) {
            html += '<button type="button" id="qs-withdraw-btn" class="educbt-btn" style="background:rgba(255,255,255,.7);color:inherit;border:1px solid currentColor;font-size:.85rem;padding:6px 14px;border-radius:6px;cursor:pointer;white-space:nowrap">Withdraw</button>';
        }
        // A reviewer decides here, without having to leave for the approvals page.
        if (API.isReviewer && (status === 'submitted' || status === 'under_review')) {
            html += '<span style="display:flex;gap:8px;white-space:nowrap">';
            html += '<button type="button" id="qs-approve-btn" class="educbt-btn" style="background:#16a34a;color:#fff;border:0;font-size:.85rem;padding:6px 14px;border-radius:6px;cursor:pointer">Approve set</button>';
            html += '<button type="button" id="qs-return-btn" class="educbt-btn" style="background:rgba(255,255,255,.8);color:#991b1b;border:1px solid #991b1b;font-size:.85rem;padding:6px 14px;border-radius:6px;cursor:pointer">Send back</button>';
            html += '</span>';
        }

        // Delete button: visible when returned (draft-like, can be deleted entirely)
        if (status === 'returned') {
            html += '<button type="button" id="qs-delete-set-btn" class="educbt-btn" style="background:rgba(255,255,255,.7);color:#991b1b;border:1px solid #991b1b;font-size:.85rem;padding:6px 14px;border-radius:6px;cursor:pointer;white-space:nowrap">Delete Set</button>';
        }

        banner.innerHTML = html;

        const approveBtn = el('qs-approve-btn');
        if (approveBtn) {
            approveBtn.addEventListener('click', function() {
                if (!currentSet || !confirm('Approve all ' + currentQuestions.length + ' question(s) in this set?')) return;
                approveBtn.disabled = true;
                approveBtn.textContent = 'Approving…';
                apiCall('POST', 'questions/decide', {
                    subject_id: currentSet.subject_id,
                    staff_id: currentSet.teacher_id,
                    decision: 'approve',
                    note: '',
                    set_ids: String(currentSet.id) + (currentSet._sibling ? ',' + currentSet._sibling.id : ''),
                })
                    .then(function(r) {
                        if (r.success) { showSaved('Set approved'); loadSet(); return; }
                        approveBtn.disabled = false;
                        approveBtn.textContent = 'Approve set';
                        alert(r.error || 'Could not approve this set.');
                    });
            });
        }

        const returnBtn = el('qs-return-btn');
        if (returnBtn) {
            returnBtn.addEventListener('click', function() {
                if (!currentSet) return;
                const note = prompt('What needs changing? The teacher sees this.');
                if (note === null) return;
                if (!note.trim()) { alert('Say what needs changing — sending work back without a reason is not a review.'); return; }
                returnBtn.disabled = true;
                apiCall('POST', 'questions/decide', {
                    subject_id: currentSet.subject_id,
                    staff_id: currentSet.teacher_id,
                    decision: 'revision',
                    note: note,
                    set_ids: String(currentSet.id) + (currentSet._sibling ? ',' + currentSet._sibling.id : ''),
                })
                    .then(function(r) {
                        if (r.success) { showSaved('Sent back for revision'); loadSet(); return; }
                        returnBtn.disabled = false;
                        alert(r.error || 'Could not send this set back.');
                    });
            });
        }

        // Wire up withdraw button
        const withdrawBtn = el('qs-withdraw-btn');
        if (withdrawBtn) {
            withdrawBtn.addEventListener('click', function() {
                if (!currentSet) return;
                if (!confirm('Withdraw this submission? The set will return to draft so you can edit your questions.')) return;
                apiCall('POST', 'question-sets/' + currentSet.id + '/withdraw')
                    .then(function(r) {
                        if (r.success) {
                            alert('Submission withdrawn. You can now edit your questions.');
                            loadSet();
                        } else {
                            alert('Could not withdraw: ' + (r.message || r.error || 'unknown error'));
                        }
                    })
                    .catch(function() { alert('Network error — could not withdraw.'); });
            });
        }

        // Wire up delete button
        const deleteBtn = el('qs-delete-set-btn');
        if (deleteBtn) {
            deleteBtn.addEventListener('click', function() {
                if (!currentSet) return;
                if (!confirm('Delete this entire set? All questions in this draft will be permanently removed. This cannot be undone.')) return;
                apiCall('DELETE', 'question-sets/' + currentSet.id)
                    .then(function(r) {
                        if (r.success) {
                            alert('Draft set deleted.');
                            currentSet = null;
                            currentQuestions = [];
                            renderPreview();
                            renderProgress();
                            renderStatusBanner();
                            renderInput();
                            el('qs-submit-btn').style.display = 'none';
                        } else {
                            alert('Could not delete: ' + (r.message || r.error || 'unknown error'));
                        }
                    })
                    .catch(function() { alert('Network error — could not delete.'); });
            });
        }
    }

    // ---- Region B: Input surface ----

    // ---- WAEC paper structure ----
    //
    // In WAEC mode the paper is not a flat list of items, it is a set of named
    // sections each needing a fixed number of questions. A teacher writing forty
    // items with nothing in the orals section has not written a WAEC paper, and a
    // single total cannot show that. So every question is filed under a section,
    // and the progress of each is visible while writing.
    var currentWaecSection = '';

    function waecSections() {
        // EVERY section of ALL THREE papers, always.
        //
        // This used to return only the sections matching the exam type currently
        // toggled, so Paper 1 — which is theory — simply did not exist while the
        // toggle sat on Objective. A teacher looking for the essay section found
        // Papers 2 and 3 and concluded the feature was missing.
        //
        // The section is the thing being chosen. The exam type follows FROM it, and
        // is switched automatically when a section from another paper is picked.
        // Asking the teacher to know that "essay" means "set the toggle to theory
        // first" is asking them to work around the software.
        //
        // Progress counts come from the server for whichever paper the current set
        // belongs to; the rest show zero until their own set exists.
        // Count from the questions the client already holds, not the server snapshot.
        //
        // Progress used to come from currentSet.waec, which is built when the SET is
        // loaded. Adding a question updates currentQuestions but not that snapshot,
        // so every counter stayed at whatever it was when the page opened. Counting
        // locally is immediate and works across all three papers, not only the one
        // the current set belongs to.
        var progress = {};

        (currentQuestions || []).forEach(function(q) {
            var sk = q.section || '';
            if (!sk) { return; }
            progress[sk] = (progress[sk] || 0) + 1;
        });

        var out = [];

        Object.keys(API.waecBlueprint || {}).forEach(function(key) {
            var parts = key.split(':');

            (API.waecBlueprint[key] || []).forEach(function(sec, secIndex) {
                var have = progress[sec.key] || 0;

                out.push({
                    key: sec.key,
                    paper: sec.paper,
                    label: sec.label,
                    guide: sec.guide,
                    required: sec.count,
                    present: have,
                    short: Math.max(0, sec.count - have),
                    needs_passage: sec.needs_passage,
                    // What this section has to be authored as.
                    mode: parts[0],
                    type: parts[1],
                    // Position within the paper, and which paper — so the panel can
                    // present them in the order they are actually sat.
                    order: secIndex,
                    paperOrder: parseInt((sec.key.match(/^paper(\d+)/) || [0, 99])[1], 10)
                });
            });
        });

        // Paper order, then the order within each paper.
        // Blueprint order, not alphabetical.
        //
        // Sorting on the key put Paper 1 in the order antonyms, cloze, grammar,
        // idioms, synonyms — so the panel read Section 1, Section 5, Section 4,
        // Section 3, Section 2. The blueprint already lists them in the order WAEC
        // sets them; that order is what the teacher is working through, so it is
        // captured as each section is flattened and used here.
        out.sort(function(a, b) {
            if (a.paperOrder !== b.paperOrder) { return a.paperOrder - b.paperOrder; }
            return a.order - b.order;
        });

        return out;
    }

    // Move the editor to whatever a section requires, then carry on.
    function switchScopeForSection(sec, done) {
        var changed = false;

        if (sec.type && sec.type !== currentExamType) {
            currentExamType = sec.type;
            changed = true;

            document.querySelectorAll('.qs-type-btn').forEach(function(b) {
                var on = b.dataset.type === currentExamType;
                b.style.background = on ? 'var(--edu-primary,#3b82f6)' : 'transparent';
                b.style.color = on ? '#fff' : 'inherit';
                b.style.fontWeight = on ? '600' : '500';
            });

            var marksWrap = document.getElementById('qs-marks-wrap');
            if (marksWrap) { marksWrap.style.display = currentExamType === 'theory' ? 'none' : 'flex'; }
        }

        if (sec.mode && sec.mode !== currentDeliveryMode) {
            currentDeliveryMode = sec.mode;
            setDeliveryModeUI(currentDeliveryMode);
            changed = true;
        }

        // A different type means a different SET, so it has to be reloaded before
        // the section can be written into.
        if (changed && subjectSel.value && classSel.value) {
            loadSet();
            return;
        }

        done();
    }

    // ---- How each WAEC section is actually set ----
    //
    // The sections are not variations of one form. A synonym item is a sentence
    // with one word underlined; a cloze item is one numbered gap in a shared
    // passage; an orals item is a single word tested for its vowel. Presenting the
    // same generic "question text + four stacked options" for all of them makes a
    // teacher reconstruct WAEC's conventions from memory every time.
    //
    // So each section carries its own placeholder, default instruction, option
    // layout, and the entry methods that make sense for it.
    //
    //   layout 'grid'  short options sit side by side, as WAEC prints them
    //   layout 'list'  full sentences or phrases stack vertically
    var WAEC_SECTIONS = {
        // ---- Paper 1: Objective Test ----
        'paper1:antonyms': {
            stem: 'The report was unusually CONCISE.',
            hint: 'Type the sentence with the tested word in CAPITALS. Students see it underlined.',
            instruction: 'Choose the option opposite in meaning to the word in capital letters.',
            layout: 'grid', options: 4, methods: ['manual','paste','csv']
        },
        'paper1:synonyms': {
            stem: 'Wole was AMBIVALENT about the proposal.',
            hint: 'Type the sentence with the tested word in CAPITALS. Students see it underlined.',
            instruction: 'Choose the option nearest in meaning to the word in capital letters.',
            layout: 'grid', options: 4, methods: ['manual','paste','csv']
        },
        'paper1:idioms': {
            stem: 'The chairman threw in the towel.',
            hint: 'Give the sentence containing the idiom or phrase. Options are full explanations, so they stack.',
            instruction: 'Choose the option that best explains the meaning of the idiom or phrase.',
            layout: 'list', options: 4, methods: ['manual','paste']
        },
        'paper1:grammar': {
            stem: 'The teacher asked the pupils to ______ their books.',
            hint: 'Use a run of underscores where the gap falls. Tests concord, tenses, prepositions, phrasal verbs, clauses.',
            instruction: 'Choose the option that best completes the sentence.',
            layout: 'grid', options: 4, methods: ['manual','paste','csv']
        },
        'paper1:cloze': {
            stem: 'Gap 1',
            hint: 'Attach the cloze passage below, then add ONE item per numbered gap. Keep the numbering in step with the passage.',
            instruction: 'Choose the option that best fills the numbered gap in the passage.',
            layout: 'grid', options: 4, methods: ['manual'], needsPassage: true, numbered: true
        },
        // ---- Paper 2: Essay, Comprehension and Summary ----
        'paper2:essay': {
            stem: 'Write a letter to your principal on the state of the school library.',
            hint: 'One topic per entry. WAEC sets five; the candidate answers one.',
            instruction: 'Answer ONE question from this section, in not less than 450 words.',
            layout: 'none', methods: ['manual']
        },
        'paper2:comprehension': {
            stem: 'From the passage, explain the writer\u2019s attitude to \u2026',
            hint: 'Attach the passage below, then set the questions answered in writing.',
            instruction: 'Read the passage and answer the questions that follow.',
            layout: 'none', methods: ['manual'], needsPassage: true
        },
        'paper2:summary': {
            stem: 'In three sentences, one for each, state the writer\u2019s main arguments.',
            hint: 'Attach the passage below. State clearly how many sentences are required.',
            instruction: 'Answer in your own words, in the number of sentences stated.',
            layout: 'none', methods: ['manual'], needsPassage: true
        },
        // ---- Paper 3: Test of Orals ----
        'paper3:vowels': {
            stem: 'bead',
            hint: 'One word per item. Students choose the option containing the same vowel sound.',
            instruction: 'Choose the option that has the same vowel sound as the given word.',
            layout: 'grid', options: 4, methods: ['manual','paste','csv']
        },
        'paper3:consonants': {
            stem: 'thigh',
            hint: 'One word per item, testing a consonant or cluster.',
            instruction: 'Choose the option that has the same consonant sound as the given word.',
            layout: 'grid', options: 4, methods: ['manual','paste','csv']
        },
        'paper3:rhymes': {
            stem: 'height',
            hint: 'One word per item. Options are single words, so they sit side by side.',
            instruction: 'Choose the option that rhymes with the given word.',
            layout: 'grid', options: 4, methods: ['manual','paste','csv']
        },
        'paper3:word_stress': {
            stem: 'ex-am-i-NA-tion',
            hint: 'Write the word in syllables and mark the stressed one in CAPITALS.',
            instruction: 'Choose the option that has the correct stress pattern.',
            layout: 'grid', options: 4, methods: ['manual','paste']
        },
        'paper3:emphatic_stress': {
            stem: 'I AM going to the market.',
            hint: 'Give the utterance with the emphatic word in CAPITALS. Options are questions, so they stack.',
            instruction: 'Choose the question that prompts the given sentence as an answer.',
            layout: 'list', options: 4, methods: ['manual','paste']
        },
        'paper3:phonetic_symbols': {
            stem: '/\u0259/',
            hint: 'Give the phonetic symbol. Students choose the word that matches it.',
            instruction: 'Choose the word that matches the given phonetic symbol.',
            layout: 'grid', options: 4, methods: ['manual','paste','csv']
        }
    };

    function waecConfig() {
        if (!currentWaecMode || !currentWaecSection) { return null; }
        return WAEC_SECTIONS[currentWaecSection] || null;
    }

    // Restrict the method dropdown to what the section supports. A cloze passage
    // cannot be pasted as a block of numbered questions, and an essay topic has no
    // CSV shape at all — leaving those options visible invites an import that
    // quietly produces nonsense.
    function applyMethodsForSection() {
        var conf = waecConfig();
        if (!methodSel) { return; }

        Array.prototype.forEach.call(methodSel.options, function(opt) {
            var ok = !conf || conf.methods.indexOf(opt.value) !== -1;
            opt.hidden = !ok;
            opt.disabled = !ok;
        });

        if (conf && conf.methods.indexOf(currentMethod) === -1) {
            currentMethod = conf.methods[0];
            methodSel.value = currentMethod;
        }
    }

    function renderWaecPanel() {
        var host = el('qs-waec-panel');
        if (!host) return;

        var sections = waecSections();

        if (!currentWaecMode || !sections.length) {
            host.style.display = 'none';
            host.innerHTML = '';
            return;
        }

        host.style.display = 'block';

        var done = 0;
        var need = 0;

        sections.forEach(function(sec) {
            done += Math.min(sec.present, sec.required);
            need += sec.required;
        });

        var html = '<div style="background:#0F2818;color:#CBEB6E;border-radius:10px 10px 0 0;padding:10px 14px;display:flex;justify-content:space-between;flex-wrap:wrap;gap:8px">';
        html += '<strong style="font-size:.9rem">WAEC paper structure</strong>';
        html += '<span style="font-size:.82rem">' + done + ' of ' + need + ' questions written</span>';
        html += '</div>';

        // Weight summary bar — shows how the 170 raw marks scale to 100%.
        html += '<div style="background:#f8fafc;border:1px solid var(--edu-line);border-bottom:0;padding:8px 14px;display:flex;gap:16px;flex-wrap:wrap;font-size:.78rem">';
        html += '<span style="font-weight:600">Total: 170 marks \u2192 100%</span>';
        html += '<span style="color:var(--edu-muted)">Paper 1: 40 marks (40%)</span>';
        html += '<span style="color:var(--edu-muted)">Paper 2: 100 marks (50%)</span>';
        html += '<span style="color:var(--edu-muted)">Paper 3: 30 marks (10%)</span>';
        html += '</div>';

        html += '<div style="border:1px solid var(--edu-line);border-top:0;border-radius:0 0 10px 10px;padding:10px 14px;margin-bottom:14px">';

        var currentPaper = '';

        sections.forEach(function(sec) {
            if (sec.paper !== currentPaper) {
                currentPaper = sec.paper;
                html += '<div class="educbt-muted" style="font-size:.76rem;text-transform:uppercase;letter-spacing:.05em;margin:8px 0 5px">' + esc(currentPaper) + '</div>';
            }

            var full = sec.present >= sec.required;
            var pct = sec.required ? Math.min(100, Math.round((sec.present / sec.required) * 100)) : 0;
            var active = currentWaecSection === sec.key;

            html += '<button type="button" class="qs-waec-sec" data-key="' + esc(sec.key) + '" ';
            html += 'style="display:flex;width:100%;align-items:center;gap:10px;text-align:left;border:1px solid ' + (active ? 'var(--edu-primary,#3b82f6)' : 'var(--edu-line)') + ';';
            html += 'background:' + (active ? 'var(--edu-accent-soft,#eff6ff)' : 'transparent') + ';border-radius:8px;padding:7px 10px;margin-bottom:5px;cursor:pointer">';
            html += '<span style="flex:1;font-size:.85rem;font-weight:' + (active ? '600' : '500') + '">' + esc(sec.label) + '</span>';
            html += '<span style="font-size:.78rem;color:' + (full ? '#16a34a' : 'var(--edu-muted)') + ';white-space:nowrap">' + sec.present + '/' + sec.required + (full ? ' \u2713' : '') + '</span>';
            html += '<span style="width:52px;height:5px;background:var(--edu-bg);border-radius:3px;overflow:hidden;flex-shrink:0">';
            html += '<span style="display:block;height:100%;width:' + pct + '%;background:' + (full ? '#16a34a' : '#CBEB6E') + '"></span></span>';
            html += '</button>';
        });

        html += '<p class="educbt-muted" style="font-size:.78rem;margin:8px 0 0" id="qs-waec-guide"></p>';

        html += '</div>';

        host.innerHTML = html;

        host.querySelectorAll('.qs-waec-sec').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var picked = sections.filter(function(x) { return x.key === btn.dataset.key; })[0];

                currentWaecSection = btn.dataset.key;
                // Leaving a section abandons its in-progress passage handover;
                // otherwise the next section would silently inherit it.
                pendingPassageId = 0;
                pendingInstruction = '';

                switchScopeForSection(picked || {}, function() {
                    applyMethodsForSection();
                    renderWaecPanel();
                    renderInput();
                });
            });
        });

        var guide = el('qs-waec-guide');
        if (guide) {
            var sel = sections.filter(function(x) { return x.key === currentWaecSection; })[0];
            guide.textContent = sel
                ? sel.guide + (sel.needs_passage ? ' This section needs a passage — attach one below.' : '')
                : 'Choose a section, then write its questions.';
        }
    }

    // ---- Reusing this term's CA questions ----
    //
    // A teacher who wrote twenty items for the first CA and twenty for the second
    // already has forty questions for this subject. Offering them here means the
    // term's work compounds instead of being written a third time.
    //
    // Copied, not moved: the CA tests have been sat and their results rest on those
    // questions. Copies arrive unreviewed, because approval for a CA test is not
    // approval for the terminal paper.
    function renderCaPool() {
        var host = el('qs-ca-pool');
        if (!host) return;

        var scope = currentScope();

        if (!currentSet || currentWaecMode || !subjectSel.value || !scope.level_id || currentExamType !== 'objective') {
            host.style.display = 'none';
            return;
        }

        apiCall('GET', 'ca-pool', null, { subject_id: parseInt(subjectSel.value), level_id: scope.level_id })
            .then(function(r) {
                if (!r || !r.questions || !r.questions.length) { host.style.display = 'none'; return; }

                host.style.display = 'block';

                var html = '<details style="border:1px solid var(--edu-line);border-radius:10px;padding:10px 14px;margin-bottom:12px">';
                html += '<summary style="cursor:pointer;font-weight:600;font-size:.9rem">'
                     +  'Reuse questions from this term\u2019s CA tests (' + r.questions.length + ' available)</summary>';
                html += '<p class="educbt-muted" style="font-size:.8rem;margin:8px 0">'
                     +  'These were written for the continuous assessment tests. Bringing one across copies it \u2014 '
                     +  'the CA test keeps its own questions. Copies arrive unapproved and go through review with the rest.</p>';
                html += '<div style="max-height:260px;overflow-y:auto;border:1px solid var(--edu-line);border-radius:8px">';

                r.questions.forEach(function(q) {
                    html += '<label style="display:flex;gap:9px;padding:8px 10px;border-bottom:1px solid var(--edu-line);cursor:pointer;align-items:flex-start">';
                    html += '<input type="checkbox" class="qs-ca-pick" value="' + q.id + '" style="width:auto;margin-top:3px">';
                    html += '<span style="flex:1"><span style="font-size:.85rem">' + esc(q.question_text || '') + '</span>';
                    html += '<br><span class="educbt-muted" style="font-size:.74rem">from ' + esc(q.from_test || 'a CA test') + '</span></span>';
                    html += '</label>';
                });

                html += '</div>';
                html += '<button type="button" id="qs-ca-import" class="educbt-btn educbt-btn--primary" style="margin-top:10px;font-size:.85rem">Bring selected across</button>';
                html += '</details>';

                host.innerHTML = html;

                el('qs-ca-import').addEventListener('click', function() {
                    var picked = Array.prototype.slice.call(host.querySelectorAll('.qs-ca-pick:checked'))
                        .map(function(c) { return parseInt(c.value); });

                    if (!picked.length) { alert('Choose at least one question first.'); return; }

                    var btn = this;
                    btn.disabled = true;
                    btn.textContent = 'Copying\u2026';

                    apiCall('POST', 'ca-pool', { set_id: currentSet.id, question_ids: picked })
                        .then(function(res) {
                            btn.disabled = false;
                            btn.textContent = 'Bring selected across';

                            if (res && res.success) { showSaved(res.copied + ' question(s) copied'); loadSet(); return; }
                            alert((res && res.message) ? res.message : 'Those questions could not be copied.');
                        });
                });
            });
    }

    function renderInput() {
        const container = el('qs-input');
        // Need a subject and class selected.
        if (!subjectSel.value || !classSel.value) {
            container.style.display = 'none';
            return;
        }
        // If a set exists but is locked (submitted/approved/published), hide input.
        if (currentSet && !isEditable()) {
            container.style.display = 'none';
            return;
        }
        // No set yet (currentSet === null) is fine — ensureSet() will create
        // one lazily when the first question is saved.
        container.style.display = 'block';

        // In WAEC mode a question has to belong to a section. Writing first and
        // filing afterwards is how a paper ends up with sixty items and no idea
        // which are orals.
        if (currentWaecMode && waecSections().length && !currentWaecSection) {
            container.innerHTML = '<div class="educbt-card" style="text-align:center;padding:22px">'
                + '<p class="educbt-muted" style="margin:0">Choose a section above to start writing.</p></div>';
            return;
        }

        // A cloze needs its own surface: one passage, then a row per gap.
        var wc = waecConfig();

        if (wc && wc.numbered) {
            renderClozeComposer(container);
            return;
        }

        // Paper 2 (theory) sections get their own composers until a passage exists.
        if (currentWaecSection && currentWaecSection.indexOf('paper2:') === 0 && !pendingPassageId) {
            renderTheoryComposer(container);
            return;
        }

        if (currentMethod === 'manual') {
            renderManualEntry(container);
        } else if (currentMethod === 'paste') {
            renderPasteEntry(container);
        } else if (currentMethod === 'csv') {
            renderCSVImport(container);
        }
    }

    function isEditable() {
        return currentSet && (currentSet.status === 'draft' || currentSet.status === 'returned');
    }

    // ---- Manual Entry ----

    // ---- Cloze passage composer ----
    //
    // A cloze is ONE passage with numbered gaps, not a series of unrelated items.
    // Setting it as five separate questions loses the thing that makes it a cloze:
    // the gaps share a text, and a candidate reads the whole passage to answer any
    // of them.
    //
    // So the teacher pastes the passage with _1_, _2_ … where the gaps fall. The
    // number of markers determines the number of items — the system counts them
    // rather than asking, because a mismatch between the passage and the item count
    // is exactly the error that reaches the exam room unnoticed.
    function renderClozeComposer(container) {
        var conf = waecConfig();
        var required = 0;

        waecSections().forEach(function(sec) {
            if (sec.key === currentWaecSection) { required = sec.required; }
        });

        var html = '<div class="educbt-card" style="margin:0">';
        html += '<h3 style="margin:0 0 4px;font-size:.98rem">Cloze passage</h3>';
        html += '<p class="educbt-muted" style="font-size:.8rem;margin:0 0 10px">'
             +  'Paste the passage and mark each gap <code>_1_</code>, <code>_2_</code> and so on. '
             +  'The gaps you mark become the questions \u2014 this section needs <strong>' + required + '</strong>.</p>';

        html += '<textarea id="cz-passage" class="educbt-input" rows="8" style="width:100%" '
             +  'placeholder="Education is the _1_ of any nation. Without it, progress becomes _2_ ..."></textarea>';

        html += '<div style="display:flex;gap:10px;align-items:center;margin-top:8px;flex-wrap:wrap">';
        html += '<input type="text" id="cz-title" class="educbt-input" placeholder="Passage title (for your own reference)" style="flex:1;min-width:200px">';
        html += '<button type="button" id="cz-parse" class="educbt-btn educbt-btn--primary">Read the gaps</button>';
        html += '<span id="cz-count" class="educbt-muted" style="font-size:.82rem"></span>';
        html += '</div>';

        html += '<div id="cz-gaps" style="margin-top:14px"></div>';
        html += '</div>';

        container.innerHTML = html;

        var passageBox = el('cz-passage');
        var countLabel = el('cz-count');

        function countGaps(text) {
            var found = text.match(/_\s*(\d+)\s*_/g) || [];
            var nums = found.map(function(m) { return parseInt(m.replace(/[^0-9]/g, ''), 10); });
            // Duplicates are a genuine authoring error, not something to silently
            // tolerate: two gaps numbered 3 cannot both be item 3.
            var unique = nums.filter(function(v, i) { return nums.indexOf(v) === i; });
            return { all: nums, unique: unique.sort(function(a, b) { return a - b; }) };
        }

        passageBox.addEventListener('input', function() {
            var g = countGaps(passageBox.value);
            countLabel.textContent = g.unique.length
                ? g.unique.length + ' gap(s) marked' + (required ? ' of ' + required + ' needed' : '')
                : '';
        });

        el('cz-parse').addEventListener('click', function() {
            var text = passageBox.value.trim();

            if (!text) { alert('Paste the passage first.'); return; }

            var g = countGaps(text);

            if (!g.unique.length) {
                alert('No gaps found. Mark each gap as _1_, _2_ and so on.');
                return;
            }

            if (g.all.length !== g.unique.length) {
                alert('Two gaps share the same number. Each gap must be numbered once.');
                return;
            }

            var expected = g.unique[g.unique.length - 1];
            if (expected !== g.unique.length) {
                alert('Gap numbers must run 1 to ' + g.unique.length + ' without skipping.');
                return;
            }

            if (required && g.unique.length !== required) {
                if (!confirm('This section expects ' + required + ' gaps and the passage has '
                    + g.unique.length + '. Continue anyway?')) { return; }
            }

            renderGapRows(g.unique, text);
        });

        function renderGapRows(gaps, passageText) {
            var host = el('cz-gaps');
            var h = '<p class="educbt-muted" style="font-size:.8rem;margin:0 0 8px">'
                  + 'Fill the four options for each gap and mark the correct one. '
                  + 'Gaps are never shuffled \u2014 they must follow the passage.</p>';

            gaps.forEach(function(n) {
                h += '<div class="cz-gap" data-gap="' + n + '" style="border:1px solid var(--edu-line);border-radius:9px;padding:10px;margin-bottom:8px">';
                h += '<strong style="font-size:.88rem">Gap ' + n + '</strong>';
                h += '<div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:6px;margin-top:6px">';

                ['A', 'B', 'C', 'D'].forEach(function(letter, i) {
                    h += '<label style="display:flex;align-items:center;gap:6px;font-weight:400">';
                    h += '<input type="radio" name="cz-correct-' + n + '" value="' + i + '" style="width:auto"' + (i === 0 ? '' : '') + '>';
                    h += '<span style="font-weight:600;width:14px">' + letter + '</span>';
                    h += '<input type="text" class="educbt-input cz-opt" data-idx="' + i + '" placeholder="Option ' + letter + '" style="flex:1">';
                    h += '</label>';
                });

                h += '</div></div>';
            });

            h += '<button type="button" id="cz-save" class="educbt-btn educbt-btn--primary" style="margin-top:6px">Save passage and all gaps</button>';
            host.innerHTML = h;

            el('cz-save').addEventListener('click', function() {
                var rows = Array.prototype.slice.call(host.querySelectorAll('.cz-gap'));
                var payload = [];

                for (var i = 0; i < rows.length; i++) {
                    var row = rows[i];
                    var gapNo = row.dataset.gap;
                    var opts = Array.prototype.slice.call(row.querySelectorAll('.cz-opt'))
                        .map(function(inp) { return inp.value.trim(); });

                    if (opts.filter(function(o) { return o !== ''; }).length < 2) {
                        alert('Gap ' + gapNo + ' needs at least two options.');
                        return;
                    }

                    var picked = row.querySelector('input[type=radio]:checked');

                    if (!picked) { alert('Mark the correct option for gap ' + gapNo + '.'); return; }

                    payload.push({ gap: parseInt(gapNo, 10), options: opts, correct: parseInt(picked.value, 10) });
                }

                var btn = this;
                btn.disabled = true;
                btn.textContent = 'Saving\u2026';

                saveClozeSet(passageText, el('cz-title').value.trim(), payload, btn);
            });
        }
    }

    // Create the passage, then one question per gap bound to it, in order.
    function saveClozeSet(passageText, title, gaps, btn) {
        apiCall('POST', 'passages', {
            title: title || 'Cloze passage',
            passage_type: 'cloze',
            body: passageText,
            subject_id: parseInt(subjectSel.value) || 0
        }).then(function(pr) {
            if (!pr || !pr.success) {
                btn.disabled = false;
                btn.textContent = 'Save passage and all gaps';
                alert((pr && pr.message) ? pr.message : 'The passage could not be saved.');
                return;
            }

            ensureSet().then(function(setOk) {
                if (!setOk || !currentSet) {
                    btn.disabled = false;
                    btn.textContent = 'Save passage and all gaps';
                    return;
                }

                var chain = Promise.resolve();

                gaps.forEach(function(g) {
                    chain = chain.then(function() {
                        return apiCall('POST', 'question-sets/' + currentSet.id + '/questions', {
                            stem: 'Gap ' + g.gap,
                            marks: 1,
                            source_method: 'manual',
                            section: currentWaecSection,
                            passage_id: pr.id,
                            // Sequence follows the passage. A cloze read out of order
                            // is not a cloze.
                            sequence: g.gap,
                            no_shuffle: 1,
                            options: g.options.map(function(text, i) {
                                return { text: text, is_correct: i === g.correct ? 1 : 0 };
                            })
                        });
                    });
                });

                // A failure anywhere in the chain must still release the button and
                // say what happened. Silently stopping is what "Saving…" for ever
                // looks like from the outside.
                chain.then(function() {
                    btn.disabled = false;
                    btn.textContent = 'Save passage and all gaps';
                    showSaved(gaps.length + ' gap(s) saved');
                    loadSet();
                }).catch(function() {
                    btn.disabled = false;
                    btn.textContent = 'Save passage and all gaps';
                    alert('Some gaps could not be saved. Reload and check which are present before adding more.');
                    loadSet();
                });
            });
        });
    }

    // ---- Paper 1 composer: essay, comprehension, summary ----
    //
    // Three different jobs that the generic theory form handles badly:
    //
    //   Essay          five topics entered together; the candidate answers ONE.
    //                  Setting them one at a time hides the fact that they are
    //                  alternatives rather than five questions to be answered.
    //   Comprehension  one passage, then lettered questions, several of which have
    //                  (i)/(ii) parts.
    //   Summary        one passage, then a small number of questions each stating
    //                  how many sentences the answer must run to.
    //
    // Comprehension and summary share a shape, so they share a form. Essay does not.
    function renderTheoryComposer(container) {
        var key = currentWaecSection;
        var conf = waecConfig();

        if (key === 'paper2:essay') { renderEssayComposer(container, conf); return; }

        renderPassagePaperComposer(container, conf, key === 'paper2:summary');
    }

    function renderEssayComposer(container, conf) {
        var required = 0;
        waecSections().forEach(function(sec) { if (sec.key === currentWaecSection) { required = sec.required; } });

        var html = '<div class="educbt-card" style="margin:0">';
        html += '<h3 style="margin:0 0 4px;font-size:.98rem">Essay topics</h3>';
        html += '<p class="educbt-muted" style="font-size:.8rem;margin:0 0 10px">'
             +  'Enter the topics the candidate chooses between. WAEC sets <strong>' + required + '</strong>; '
             +  'the candidate answers <strong>one</strong>. They are alternatives, not separate questions.</p>';

        html += '<div><label class="educbt-muted" style="font-size:.8rem">Instruction shown above the topics</label>';
        html += '<textarea id="e1-instruction" class="educbt-input" rows="2" style="width:100%;margin-top:3px">'
             +  esc(conf ? conf.instruction : '') + '</textarea></div>';

        html += '<div style="display:flex;gap:10px;align-items:flex-end;margin:10px 0">';
        html += '<div><label class="educbt-muted" style="font-size:.8rem">Marks for the essay</label>';
        html += '<input type="number" id="e1-marks" class="educbt-input" value="50" min="1" step="1" style="width:90px"></div>';
        html += '<div><label class="educbt-muted" style="font-size:.8rem">Minimum words</label>';
        html += '<input type="number" id="e1-words" class="educbt-input" value="450" min="50" step="50" style="width:100px"></div>';
        html += '</div>';

        html += '<div id="e1-topics"></div>';
        html += '<button type="button" id="e1-add" class="educbt-btn" style="font-size:.82rem;padding:4px 10px;margin-top:4px">+ Add another topic</button>';
        html += '<div style="margin-top:12px"><button type="button" id="e1-save" class="educbt-btn educbt-btn--primary">Save essay topics</button></div>';
        html += '</div>';

        container.innerHTML = html;

        var topics = [];
        for (var i = 0; i < Math.max(1, required); i++) { topics.push(''); }

        function drawTopics() {
            var host = el('e1-topics');
            host.innerHTML = '';

            topics.forEach(function(text, i) {
                var row = document.createElement('div');
                row.style.cssText = 'display:flex;gap:8px;align-items:flex-start;margin-bottom:6px';
                row.innerHTML = '<span style="font-weight:600;padding-top:8px;width:18px">' + (i + 1) + '.</span>'
                    + '<textarea class="educbt-input e1-topic" rows="2" style="flex:1" placeholder="'
                    + esc(conf ? conf.stem : 'Essay topic') + '"></textarea>'
                    + (topics.length > 1 ? '<button type="button" class="e1-rm" data-i="' + i + '" style="border:0;background:transparent;color:#b91c1c;cursor:pointer;padding:6px 4px">\u00d7</button>' : '');
                host.appendChild(row);

                var box = row.querySelector('.e1-topic');
                box.value = text;
                box.addEventListener('input', function() { topics[i] = this.value; });

                var rm = row.querySelector('.e1-rm');
                if (rm) { rm.addEventListener('click', function() { topics.splice(i, 1); drawTopics(); }); }
            });
        }

        drawTopics();

        el('e1-add').addEventListener('click', function() { topics.push(''); drawTopics(); });

        el('e1-save').addEventListener('click', function() {
            var filled = topics.map(function(t) { return t.trim(); }).filter(function(t) { return t !== ''; });

            if (filled.length < 2) { alert('Enter at least two topics for the candidate to choose between.'); return; }

            if (required && filled.length !== required) {
                if (!confirm('This section expects ' + required + ' topics and you have entered '
                    + filled.length + '. Continue anyway?')) { return; }
            }

            var btn = this;
            btn.disabled = true;
            btn.textContent = 'Saving\u2026';

            var instruction = el('e1-instruction').value.trim();
            var marks = parseFloat(el('e1-marks').value) || 50;
            var words = parseInt(el('e1-words').value) || 450;

            ensureSet().then(function(ok) {
                if (!ok || !currentSet) { btn.disabled = false; btn.textContent = 'Save essay topics'; return; }

                var chain = Promise.resolve();

                filled.forEach(function(topic, i) {
                    chain = chain.then(function() {
                        return apiCall('POST', 'question-sets/' + currentSet.id + '/questions', {
                            stem: topic,
                            marks: marks,
                            source_method: 'manual',
                            section: currentWaecSection,
                            question_type: 'theory',
                            sequence: i + 1,
                            no_shuffle: 1,
                            instructions: instruction + ' Your answer should not be less than ' + words + ' words.'
                        });
                    });
                });

                chain.then(function() {
                    btn.disabled = false;
                    btn.textContent = 'Save essay topics';
                    showSaved(filled.length + ' topic(s) saved');
                    loadSet();
                }).catch(function() {
                    btn.disabled = false;
                    btn.textContent = 'Save essay topics';
                    alert('Some topics could not be saved. Reload and check which are present before adding more.');
                    loadSet();
                });
            });
        });
    }

    // Comprehension and summary: one passage, then its questions.
    function renderPassagePaperComposer(container, conf, isSummary) {
        var html = '<div class="educbt-card" style="margin:0">';
        html += '<h3 style="margin:0 0 4px;font-size:.98rem">' + (isSummary ? 'Summary passage' : 'Comprehension passage') + '</h3>';
        html += '<p class="educbt-muted" style="font-size:.8rem;margin:0 0 10px">'
             +  'Paste the passage, then set the questions beneath it. Every question you add here is '
             +  'bound to this passage, so the candidate reads it once and answers all of them.</p>';

        html += '<div><label class="educbt-muted" style="font-size:.8rem">Instruction</label>';
        html += '<textarea id="p1-instruction" class="educbt-input" rows="2" style="width:100%;margin-top:3px">'
             +  esc(conf ? conf.instruction : '') + '</textarea></div>';

        html += '<div style="margin-top:8px"><label class="educbt-muted" style="font-size:.8rem">Passage title</label>';
        html += '<input type="text" id="p1-title" class="educbt-input" style="width:100%;margin-top:3px" placeholder="For your own reference"></div>';

        html += '<div style="margin-top:8px"><label class="educbt-muted" style="font-size:.8rem">Passage</label>';
        html += '<textarea id="p1-passage" class="educbt-input" rows="10" style="width:100%;margin-top:3px" placeholder="Paste the passage here\u2026"></textarea>';
        html += '<p class="educbt-muted" style="font-size:.76rem;margin:4px 0 0" id="p1-words"></p></div>';

        html += '<div style="margin-top:10px"><button type="button" id="p1-save-passage" class="educbt-btn educbt-btn--primary">Save passage and add questions</button></div>';
        html += '<p class="educbt-muted" style="font-size:.78rem;margin-top:8px">'
             +  (isSummary
                 ? 'Then add each question, stating how many sentences the answer must run to \u2014 for example \u201cIn three sentences, one for each, summarise the advantages\u201d.'
                 : 'Then add each lettered question. Where a question has (i) and (ii) parts, add them as sub-questions so each carries its own mark.')
             +  '</p>';
        html += '</div>';

        container.innerHTML = html;

        var passageBox = el('p1-passage');

        passageBox.addEventListener('input', function() {
            var words = this.value.trim().split(/\s+/).filter(Boolean).length;
            el('p1-words').textContent = words ? words + ' words' : '';
        });

        el('p1-save-passage').addEventListener('click', function() {
            var body = passageBox.value.trim();

            if (!body) { alert('Paste the passage first.'); return; }

            var btn = this;
            btn.disabled = true;
            btn.textContent = 'Saving\u2026';

            apiCall('POST', 'passages', {
                title: el('p1-title').value.trim() || (isSummary ? 'Summary passage' : 'Comprehension passage'),
                passage_type: isSummary ? 'summary' : 'comprehension',
                body: body,
                subject_id: parseInt(subjectSel.value) || 0
            }).then(function(r) {
                btn.disabled = false;
                btn.textContent = 'Save passage and add questions';

                if (!r || !r.success) {
                    alert((r && r.message) ? r.message : 'The passage could not be saved.');
                    return;
                }

                // Hand over to the normal theory form with the passage preselected,
                // so sub-questions and marking guides work exactly as elsewhere.
                pendingPassageId = r.id;
                pendingInstruction = el('p1-instruction').value.trim();
                showSaved('Passage saved \u2014 now add its questions');
                renderManualEntry(container);
            });
        });
    }

    var pendingPassageId = 0;
    var pendingInstruction = '';

    function renderManualEntry(container) {
        const isTheory = currentExamType === 'theory';
        const marks = parseFloat(marksInput.value) || 1;

        let html = '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">';
        html += '<h3 style="margin:0;font-size:1rem">Add ' + (isTheory ? 'Theory' : 'Objective') + ' Question</h3>';
        html += '<span class="educbt-muted" style="font-size:.8rem">Manual Entry</span>';
        html += '</div>';

        var wconf = waecConfig();

        html += '<div style="display:flex;flex-direction:column;gap:12px">';

        // Instructions come FIRST, because that is the order the student reads them
        // in and the order the author thinks in: set the task, then write the item.
        html += '<div><label class="educbt-muted" style="font-size:.8rem">Instructions (optional, shown to students)</label>';
        // Prefilled with WAEC's own wording for the section. The teacher can change
        // it, but the default is the rubric candidates actually meet in the exam.
        html += '<textarea id="qe-instructions" class="educbt-input" rows="1" style="width:100%;margin-top:3px" placeholder="e.g. Choose the option that best completes the sentence…">'
             +  (wconf && wconf.instruction ? esc(wconf.instruction) : '') + '</textarea></div>';

        // Comprehension passage selector — English Language only (all class levels).
        // For other subjects, the passage/stimulus dropdown must not appear.
        if (isEnglishSubject() && API.passages && API.passages.length) {
            html += '<div style="margin-top:8px;margin-bottom:8px;padding:10px;background:var(--edu-muted-bg,#f8fafc);border-radius:6px;border:1px solid var(--edu-line,#e2e8f0)">';
            html += '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px">';
            html += '<label class="educbt-muted" style="font-size:.8rem;font-weight:600">Comprehension Passage (Optional)</label>';
            html += '<button type="button" id="qe-new-passage" class="educbt-btn" style="font-size:.78rem;padding:3px 8px">+ Comprehension Passage</button>';
            html += '</div>';
            html += '<div style="display:flex;gap:6px">';
            html += '<select id="qe-passage" class="educbt-input" style="flex:1"><option value="0">No passage selected (Standalone question)</option></select>';
            html += '</div>';
            html += '<p class="educbt-muted" style="font-size:.75rem;margin:4px 0 0">Attach or create a passage to group 5-7 questions under a comprehension passage.</p>';
            html += '</div>';
        }



        html += '<div><label class="educbt-muted" style="font-size:.8rem">'
             +  (wconf && wconf.numbered ? 'Gap number and cue' : 'Question Text') + '</label>';
        html += '<textarea id="qe-stem" class="educbt-input" rows="3" style="width:100%;margin-top:3px" placeholder="'
             +  esc(wconf ? wconf.stem : 'Type your question…') + '"></textarea>';
        if (wconf && wconf.hint) {
            html += '<p class="educbt-muted" style="font-size:.76rem;margin:4px 0 0">' + esc(wconf.hint) + '</p>';
        }
        html += '</div>';

        // Image upload (WP media picker)
        html += '<div style="margin-top:8px">';
        html += '<label class="educbt-muted" style="font-size:.8rem">Question Image (optional)</label><br>';
        html += '<div style="display:flex;align-items:center;gap:8px;margin-top:4px">';
        html += '<button type="button" id="qe-img-btn" class="educbt-btn" style="font-size:.8rem;padding:4px 10px">📷 Pick Image</button>';
        html += '<input type="hidden" id="qe-img-url">';
        html += '<img id="qe-img-preview" style="max-height:60px;max-width:120px;border-radius:4px;display:none">';
        html += '<button type="button" id="qe-img-remove" class="educbt-btn" style="font-size:.8rem;padding:4px 8px;display:none">Remove</button>';
        html += '</div></div>';

        if (isTheory) {
            // Order: Question Text → Marks → Sub-questions (optional) → Marking Guide
            // Marks belongs to the question text, sub-questions have their own mark fields
            html += '<div style="display:flex;gap:10px">';
            html += '<div><label class="educbt-muted" style="font-size:.8rem">Marks</label>';
            html += '<input type="number" id="qe-marks" class="educbt-input" value="' + marks + '" min="0.5" step="0.5" style="width:80px"></div>';
            html += '</div>';
            html += '<div id="qe-sub-toggle" style="text-align:right;margin-top:2px"><button type="button" id="qe-toggle-sub" class="educbt-btn" style="font-size:.8rem;padding:3px 8px">+ Sub-questions</button></div>';
            html += '<div id="qe-sub-items" style="display:none;border:1px solid var(--edu-line);border-radius:8px;padding:10px">';
            html += '<label class="educbt-muted" style="font-size:.8rem">Sub-questions (optional)</label>';
            html += '<div id="qe-sub-list"></div>';
            html += '<div id="qe-sub-total" class="educbt-muted" style="font-size:.8rem;margin-top:4px"></div>';
            html += '<button type="button" id="qe-add-sub" class="educbt-btn" style="font-size:.8rem;padding:4px 10px;margin-top:6px">+ Add sub-question</button>';
            html += '</div>';
            html += '<div><label class="educbt-muted" style="font-size:.8rem">Marking Guide / Model Answer (markers only)</label>';
            html += '<textarea id="qe-guide" class="educbt-input" rows="2" style="width:100%;margin-top:3px" placeholder="What a correct answer should contain…"></textarea></div>';
        } else {
            // Short options sit side by side, the way WAEC prints them; full
            // sentences stack. A four-across grid of one-word options is far quicker
            // to check than four stacked rows of mostly empty space.
            var optStyle = (wconf && wconf.layout === 'grid')
                ? 'display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:6px'
                : 'display:flex;flex-direction:column;gap:6px';

            html += '<div id="qe-options" style="' + optStyle + '"></div>';
            html += '<div style="margin-top:4px"><button type="button" id="qe-add-option" class="educbt-btn" style="font-size:.8rem;padding:3px 8px">+ Add option</button></div>';
            html += '<div><label class="educbt-muted" style="font-size:.8rem">Explanation (optional, shown to students in review)</label>';
            html += '<textarea id="qe-explanation" class="educbt-input" rows="1" style="width:100%;margin-top:3px"></textarea></div>';
            html += '<div style="display:flex;gap:10px"><div><label class="educbt-muted" style="font-size:.8rem">Marks</label>';
            html += '<input type="number" id="qe-marks" class="educbt-input" value="' + marks + '" min="0.5" step="0.5" style="width:80px"></div></div>';
        }

        html += '<div style="display:flex;gap:8px;margin-top:6px">';
        html += '<button type="button" id="qe-save" class="educbt-btn educbt-btn--primary">Add Question</button>';
        html += '<button type="button" id="qe-dup" class="educbt-btn">Add & Duplicate</button>';
        html += '<button type="button" id="qe-clear" class="educbt-btn" style="margin-left:auto">Clear</button>';
        html += '</div>';
        html += '</div>';

        container.innerHTML = html;

        // Populate passage dropdown if present
        var passSel = el('qe-passage');
        if (passSel && API.passages) {
            API.passages.forEach(function(p) {
                var opt = document.createElement('option');
                opt.value = p.id;
                opt.textContent = p.title + ' (' + (p.passage_type || 'passage') + ')';
                passSel.appendChild(opt);
            });
        }

        // A passage just created by the Paper 1 composer will not be in API.passages
        // yet — that list was rendered with the page. Add it and select it, so the
        // teacher goes straight from saving the passage to writing its questions
        // without hunting for it in a dropdown that does not list it.
        if (passSel && pendingPassageId) {
            if (!passSel.querySelector('option[value="' + pendingPassageId + '"]')) {
                var justSaved = document.createElement('option');
                justSaved.value = pendingPassageId;
                justSaved.textContent = 'The passage you just saved';
                passSel.appendChild(justSaved);
            }

            passSel.value = String(pendingPassageId);
        }

        if (pendingInstruction) {
            var instBox = el('qe-instructions');
            if (instBox && !instBox.value.trim()) { instBox.value = pendingInstruction; }
        }

        // Image picker: the author's OWN DEVICE, not the WordPress media library.
        //
        // The library was the wrong tool. It needs an upload capability a subject
        // teacher should not hold, and it shows every file in the installation to
        // somebody who only wants to attach one diagram. A file input opens the
        // phone's gallery or camera and the desktop file browser, which is what an
        // author actually expects.
        var imgBtn = el('qe-img-btn');
        if (imgBtn) {
            imgBtn.addEventListener('click', function() {
                educbtPickImage(function(url) {
                    var hidden = el('qe-img-url');
                    var preview = el('qe-img-preview');
                    var removeBtn = el('qe-img-remove');
                    if (hidden) hidden.value = url;
                    if (preview) { preview.src = url; preview.style.display = 'block'; }
                    if (removeBtn) removeBtn.style.display = 'inline-block';
                    imgBtn.textContent = 'Change image';
                }, imgBtn);
            });
        }

        var removeImgBtn = el('qe-img-remove');
        if (removeImgBtn) {
            removeImgBtn.addEventListener('click', function() {
                var hidden = el('qe-img-url');
                var preview = el('qe-img-preview');
                if (hidden) hidden.value = '';
                if (preview) { preview.src = ''; preview.style.display = 'none'; }
                removeImgBtn.style.display = 'none';
                var btn = el('qe-img-btn');
                if (btn) btn.textContent = '📷 Pick Image';
            });
        }

        // New passage button
        var newPassBtn = el('qe-new-passage');
        if (newPassBtn) {
            newPassBtn.addEventListener('click', function() { showPassageModal(); });
        }

        if (!isTheory) {
            // Initialize 4 options A-D
            let optCount = 4;
            function renderOptions() {
                const optsContainer = el('qe-options');

                // Carry the teacher's typing across a re-render. Rebuilding blindly
                // wiped every option the moment "+ Add option" or "×" was clicked.
                const kept = [];
                let keptCorrect = -1;
                optsContainer.querySelectorAll('.qe-opt').forEach(function(inp, i) {
                    kept[i] = inp.value;
                    const row = inp.closest('div');
                    const radio = row ? row.querySelector('input[name="qe-correct"]') : null;
                    if (radio && radio.checked) keptCorrect = i;
                });

                optsContainer.innerHTML = '';
                for (let i = 0; i < optCount; i++) {
                    const letter = String.fromCharCode(65 + i);
                    const row = document.createElement('div');
                    row.style.cssText = 'display:flex;align-items:center;gap:6px';
                    row.innerHTML =
                        '<input type="radio" name="qe-correct" value="' + i + '"' + (i === keptCorrect ? ' checked' : '') + ' style="margin:0" title="Correct answer">' +
                        '<span style="font-weight:600;width:20px">' + letter + '.</span>' +
                        '<input type="text" class="educbt-input qe-opt" data-idx="' + i + '" placeholder="Option ' + letter + '" style="flex:1">' +
                        (optCount > 2 ? '<button type="button" class="qe-rm-opt" data-idx="' + i + '" style="border:0;background:transparent;color:red;cursor:pointer;padding:0 4px">×</button>' : '');
                    optsContainer.appendChild(row);
                    if (kept[i] !== undefined) {
                        row.querySelector('.qe-opt').value = kept[i];
                    }
                }
                optsContainer.querySelectorAll('.qe-rm-opt').forEach(function(btn) {
                    btn.addEventListener('click', function() {
                        optCount--;
                        renderOptions();
                    });
                });
            }
            renderOptions();
            el('qe-add-option').addEventListener('click', function() {
                if (optCount < 8) { optCount++; renderOptions(); }
            });
        } else {
            // Theory: sub-questions toggle
            let subVisible = false;
            let subItems = [];
            el('qe-toggle-sub').addEventListener('click', function() {
                subVisible = !subVisible;
                el('qe-sub-items').style.display = subVisible ? 'block' : 'none';
                el('qe-toggle-sub').textContent = subVisible ? '− Sub-questions' : '+ Sub-questions';
                if (subVisible && subItems.length === 0) {
                    subItems.push({ text: '', marks: 1 });
                    renderSubItems();
                }
            });
            function renderSubItems() {
                const list = el('qe-sub-list');
                list.innerHTML = '';
                subItems.forEach(function(item, i) {
                    const letter = String.fromCharCode(97 + i);
                    const row = document.createElement('div');
                    row.style.cssText = 'display:flex;gap:6px;margin-bottom:4px;align-items:flex-start';
                    row.innerHTML =
                        '<span style="font-weight:600;width:20px;padding-top:4px">(' + letter + ')</span>' +
                        '<input type="text" class="educbt-input qe-sub-text" data-idx="' + i + '" placeholder="Sub-question ' + letter + '" style="flex:1">' +
                        '<input type="number" class="educbt-input qe-sub-marks" data-idx="' + i + '" value="' + item.marks + '" min="0.5" step="0.5" style="width:65px">' +
                        (subItems.length > 1 ? '<button type="button" class="qe-rm-sub" data-idx="' + i + '" style="border:0;background:transparent;color:red;cursor:pointer;padding:0 4px">×</button>' : '');
                    list.appendChild(row);
                    // The text was held in subItems but never written back into the
                    // rebuilt input, so every "+ Add sub-question" click looked like
                    // it had erased everything already typed.
                    row.querySelector('.qe-sub-text').value = item.text || '';
                });
                list.querySelectorAll('.qe-sub-text').forEach(function(inp) {
                    inp.addEventListener('input', function() {
                        subItems[parseInt(this.dataset.idx)].text = this.value;
                    });
                });
                list.querySelectorAll('.qe-sub-marks').forEach(function(inp) {
                    inp.addEventListener('input', function() {
                        subItems[parseInt(this.dataset.idx)].marks = parseFloat(this.value) || 0;
                        updateSubTotal();
                    });
                });
                list.querySelectorAll('.qe-rm-sub').forEach(function(btn) {
                    btn.addEventListener('click', function() {
                        subItems.splice(parseInt(this.dataset.idx), 1);
                        renderSubItems();
                        updateSubTotal();
                    });
                });
            }
            function updateSubTotal() {
                // The main question carries its own marks; sub-questions carry
                // theirs. This used to overwrite qe-marks with the sub-total, so
                // adding a sub-question silently destroyed the mark the teacher
                // had just entered for the question itself. Report the total
                // alongside instead of writing over anything.
                const total = subItems.reduce(function(s, i) { return s + (i.marks || 0); }, 0);
                const label = el('qe-sub-total');
                if (label) {
                    label.textContent = subItems.length
                        ? 'Sub-questions total: ' + total + ' marks'
                        : '';
                }
            }
            el('qe-add-sub').addEventListener('click', function() {
                subItems.push({ text: '', marks: 1 });
                renderSubItems();
            });
            el('qe-save')._subItems = function() { return subItems; };
        }

        // Detect unsaved input
        container.querySelectorAll('input, textarea').forEach(function(inp) {
            inp.addEventListener('input', function() { unsavedInput = true; });
        });

        // Save
        el('qe-save').addEventListener('click', function() {
            saveQuestion(false);
        });
        el('qe-dup').addEventListener('click', function() {
            saveQuestion(true);
        });
        el('qe-clear').addEventListener('click', function() {
            clearForm();
            unsavedInput = false;
        });
    }

    function saveQuestion(keepOptions) {
        const stem = el('qe-stem').value.trim();
        if (!stem) { alert('Question text is required.'); return; }

        const marks = parseFloat(el('qe-marks').value) || 0;
        if (marks <= 0) { alert('Marks must be greater than zero.'); return; }

        const data = {
            stem: stem,
            marks: marks,
            source_method: 'manual',
        };

        // File the question under the WAEC section being written, so the paper can
        // be assembled in the right order and each section counted separately.
        if (currentWaecMode && currentWaecSection) {
            data.section = currentWaecSection;
        }

        // Image reference (WP media URL)
        var imgUrl = el('qe-img-url');
        if (imgUrl && imgUrl.value) { data.stem_image_id = imgUrl.value; }

        // Passage ID
        var passSel = el('qe-passage');
        if (passSel && parseInt(passSel.value) > 0) { data.passage_id = parseInt(passSel.value); }

        // Instructions
        var instField = el('qe-instructions');
        if (instField && instField.value.trim()) { data.instructions = instField.value.trim(); }

        if (currentExamType === 'objective') {
            // Correctness is read from each option ROW, not from a position in a
            // filtered array. The old code pushed only non-empty options but kept
            // the radio's original index, so leaving any option blank shifted the
            // answer key onto the wrong option — or off the end, which produced
            // "Mark which option is correct" on a form that clearly had one marked.
            const opts = [];
            let sawCorrect = false;

            document.querySelectorAll('#qe-options .qe-opt').forEach(function(inp) {
                const text = inp.value.trim();
                if (!text) return;

                const row = inp.closest('div');
                const radio = row ? row.querySelector('input[name="qe-correct"]') : null;
                const isCorrect = !!(radio && radio.checked) && !sawCorrect;
                if (isCorrect) sawCorrect = true;

                opts.push({ text: text, is_correct: isCorrect });
            });

            if (opts.length < 2) { alert('Give at least two options with text.'); return; }
            if (!sawCorrect) { alert('Mark which option is correct.'); return; }

            data.options = opts;
        } else {
            const guide = el('qe-guide');
            if (guide) data.marking_guide = guide.value.trim();
            // Sub-questions
            const subContainer = el('qe-sub-items');
            if (subContainer && subContainer.style.display !== 'none') {
                const subTexts = document.querySelectorAll('.qe-sub-text');
                const subMarks = document.querySelectorAll('.qe-sub-marks');
                const subs = [];
                subTexts.forEach(function(inp, i) {
                    const text = inp.value.trim();
                    if (text) {
                        subs.push({ text: text, marks: parseFloat(subMarks[i].value) || 0 });
                    }
                });
                if (subs.length > 0) data.sub_items = subs;
            }
        }

        ensureSet(function(set) {
            showSaving();
            apiCall('POST', 'question-sets/' + set.id + '/questions', data)
                .then(function(r) {
                    if (r.success) {
                        unsavedInput = false;
                        refreshQuestions();
                        if (!keepOptions) {
                            clearForm();
                            el('qe-stem').focus();
                        }
                        showSaved('Question saved');
                    } else {
                        showSaveError(r.error || 'Save failed');
                        alert('Could not save: ' + (r.error || 'unknown error'));
                    }
                })
                .catch(function() { showSaveError(); });
        });
    }

    function clearForm() {
        const container = el('qs-input');
        const stem = el('qe-stem');
        if (stem) stem.value = '';
        const explanation = el('qe-explanation');
        if (explanation) explanation.value = '';
        const guide = el('qe-guide');
        if (guide) guide.value = '';
        document.querySelectorAll('.qe-opt').forEach(function(inp) { inp.value = ''; });
        document.querySelectorAll('input[name="qe-correct"]').forEach(function(r) { r.checked = false; });

        // Reset image picker
        var imgUrl = el('qe-img-url');
        var imgPreview = el('qe-img-preview');
        var imgRemove = el('qe-img-remove');
        var imgBtn = el('qe-img-btn');
        if (imgUrl) imgUrl.value = '';
        if (imgPreview) { imgPreview.src = ''; imgPreview.style.display = 'none'; }
        if (imgRemove) imgRemove.style.display = 'none';
        if (imgBtn) imgBtn.textContent = '📷 Pick Image';

        // Reset passage selector
        var passSel = el('qe-passage');
        if (passSel) passSel.value = '0';

        // Reset instructions
        var inst = el('qe-instructions');
        if (inst) inst.value = '';
    }

    // ---- Paste in Format ----

    function renderPasteEntry(container) {
        const isTheory = currentExamType === 'theory';
        let guide = '';
        if (!isTheory) {
            guide = '<div style="background:var(--edu-muted-bg,#f5f5f5);border-radius:8px;padding:10px;margin-bottom:10px;font-family:monospace;font-size:.85rem;white-space:pre-wrap">1. What is the capital of Nigeria?\nA) Lagos\nB) Abuja\nC) Kano\nD) Port Harcourt\nANSWER: B\nMARKS: 2\n\n2. Which gas do plants absorb?\nA) Oxygen\nB) Nitrogen\nC) Carbon dioxide\nD) Hydrogen\nANSWER: C</div>';
        } else {
            guide = '<div style="background:var(--edu-muted-bg,#f5f5f5);border-radius:8px;padding:10px;margin-bottom:10px;font-family:monospace;font-size:.85rem;white-space:pre-wrap">1. Explain three causes of the Nigerian Civil War.\nMARKS: 9\n\n2. With a labelled diagram, describe the structure of a plant cell.\nMARKS: 12\n\n--- With sub-questions ---\n\n1. Explain three causes of the Nigerian Civil War.\nMARKS: 9\n1a. Explain economic factors\nMARK: 3\n1b. Explain ethnic tensions\nMARK: 3\n1c. Explain political factors\nMARK: 3\n\n2. Define photosynthesis.\nMARKS: 5</div>';
        }

        container.innerHTML =
            '<h3 style="margin:0 0 8px;font-size:1rem">Paste Questions — ' + (isTheory ? 'Theory' : 'Objective') + '</h3>' +
            '<details style="margin-bottom:10px"><summary style="cursor:pointer;font-size:.85rem;color:var(--edu-muted)">Format Guide</summary>' + guide + '</details>' +
            '<textarea id="qs-paste-area" class="educbt-input" rows="12" style="width:100%;font-family:monospace" placeholder="Paste your questions here…"></textarea>' +
            '<div style="display:flex;gap:8px;margin-top:8px">' +
            '<button type="button" id="qs-parse-paste" class="educbt-btn educbt-btn--primary">Parse & Preview</button>' +
            '<button type="button" id="qs-paste-cancel" class="educbt-btn" style="margin-left:auto">Cancel</button>' +
            '</div>' +
            '<div id="qs-staging" style="margin-top:12px"></div>';

        el('qs-parse-paste').addEventListener('click', function() {
            const text = el('qs-paste-area').value.trim();
            if (!text) { alert('Paste some questions first.'); return; }
            const parsed = isTheory ? parseTheoryPaste(text) : parseObjectivePaste(text);
            renderStagingTable(parsed);
        });
        el('qs-paste-cancel').addEventListener('click', function() {
            container.style.display = 'none';
        });
    }

    function parseObjectivePaste(text) {
        // Normalize Word artifacts: smart quotes, nbsp, etc.
        text = text.replace(/\u2018|\u2019/g, "'").replace(/\u201c|\u201d/g, '"').replace(/\u00a0/g, ' ').replace(/\u2013|\u2014/g, '-');
        const blocks = text.split(/\n\s*\n/).filter(b => b.trim());
        const results = [];

        blocks.forEach(function(block) {
            const lines = block.trim().split('\n').map(l => l.trim()).filter(l => l);
            let q = { stem: '', options: [], correct: -1, marks: 0, status: 'valid', errors: [] };

            for (let line of lines) {
                // Question stem (starts with number+dot or number+paren)
                let m = line.match(/^(\d+)[.)]\s*(.*)/);
                if (m && !q.stem) { q.stem = m[2]; continue; }

                // Option line: A) B. C- D) etc
                m = line.match(/^([A-Fa-f])\s*[.)\-]\s*(.*)/);
                if (m) {
                    q.options.push({ text: m[2], label: m[1].toUpperCase() });
                    continue;
                }

                // Answer line
                m = line.match(/^ANSWER\s*[:\.]\s*([A-Fa-f])/i);
                if (m) {
                    const letter = m[1].toUpperCase();
                    q.correct = q.options.findIndex(function(o) { return o.label === letter; });
                    if (q.correct < 0) q.correct = letter.charCodeAt(0) - 65;
                    continue;
                }

                // Marks line
                m = line.match(/^MARKS\s*[:\.]\s*(\d+(?:\.\d+)?)/i);
                if (m) { q.marks = parseFloat(m[1]); continue; }

                // Continuation of stem (multi-line)
                if (q.stem && q.options.length === 0) {
                    q.stem += ' ' + line;
                }
            }

            // Validate
            if (!q.stem) { q.status = 'error'; q.errors.push('Missing question text'); }
            if (q.options.length < 2) { q.status = 'error'; q.errors.push('Need at least 2 options'); }
            // The server refuses an objective question with no answer key, so a
            // row missing one is an error, not a warning. Ticking it by default
            // guaranteed a rejection the teacher could not explain.
            if (q.correct < 0 || q.correct >= q.options.length) {
                q.status = 'error';
                q.errors.push('No correct answer — add an ANSWER: line (e.g. ANSWER: B)');
            }
            if (!q.marks) q.marks = parseFloat(marksInput.value) || 1;

            results.push(q);
        });

        return results;
    }

    function parseTheoryPaste(text) {
        text = text.replace(/\u2018|\u2019/g, "'").replace(/\u201c|\u201d/g, '"').replace(/\u00a0/g, ' ').replace(/\u2013|\u2014/g, '-');
        const lines = text.split('\n');
        const results = [];
        let q = null;

        for (let line of lines) {
            line = line.trim();
            if (!line) continue;

            // Main question: starts with a number (1. 2. 3. etc.)
            let m = line.match(/^(\d+)[.)]\s*(.*)/);
            if (m) {
                if (q) results.push(q);
                q = { stem: m[2], marks: 0, sub_questions: [], status: 'valid', errors: [] };
                continue;
            }

            // Sub-question: starts with number + letter (1a. 1b. 2a. etc.)
            m = line.match(/^(\d+)([a-z])[.)]\s*(.*)/i);
            if (m && q) {
                q.sub_questions.push({ text: m[3], marks: 0 });
                continue;
            }

            // Marks for the main question (MARKS: 9)
            m = line.match(/^MARKS\s*[:\.]\s*(\d+(?:\.\d+)?)/i);
            if (m && q) {
                if (q.sub_questions.length > 0) {
                    // If sub-questions exist, this MARKS belongs to the last sub-question
                    q.sub_questions[q.sub_questions.length - 1].marks = parseFloat(m[1]);
                } else {
                    q.marks = parseFloat(m[1]);
                }
                continue;
            }

            // Marks for sub-questions (MARK: 3 — singular, lowercase)
            m = line.match(/^MARK\s*[:\.]\s*(\d+(?:\.\d+)?)/i);
            if (m && q && q.sub_questions.length > 0) {
                q.sub_questions[q.sub_questions.length - 1].marks = parseFloat(m[1]);
                continue;
            }

            // Continuation of stem (multi-line question text)
            if (q && q.stem && q.sub_questions.length === 0) q.stem += ' ' + line;
            else if (q && q.sub_questions.length > 0) {
                // Continuation of last sub-question text
                q.sub_questions[q.sub_questions.length - 1].text += ' ' + line;
            }
        }
        if (q) results.push(q);

        results.forEach(function(r) {
            if (!r.stem) { r.status = 'error'; r.errors.push('Missing question text'); }
            // Main marks default only if no sub-questions
            if (!r.marks && r.sub_questions.length === 0) r.marks = parseFloat(marksInput.value) || 1;
            // Sub-questions without marks get default 1
            r.sub_questions.forEach(function(sq) {
                if (!sq.marks) sq.marks = 1;
            });
        });

        return results;
    }

    // ---- CSV Import ----

    function renderCSVImport(container) {
        const isTheory = currentExamType === 'theory';
        const cols = isTheory
            ? 'question, marks, marking_guide, sub_questions'
            : 'question, option_a, option_b, option_c, option_d, correct_option, marks, passage_ref, explanation';

        const example = isTheory
            ? 'question,marks,marking_guide,sub_questions\n"Explain photosynthesis",10,"Light energy → chemical energy...","a. Light reaction|b. Dark reaction"\n"Define gravity",5,"Force of attraction between masses...",""'
            : 'question,option_a,option_b,option_c,option_d,correct_option,marks,passage_ref,explanation\n"What is 2+2?","1","2","3","4","D","2","",""\n"Capital of Nigeria?","Lagos","Abuja","Kano","PH","B","1","",""';

        container.innerHTML =
            '<h3 style="margin:0 0 8px;font-size:1rem">CSV / Excel Import — ' + (isTheory ? 'Theory' : 'Objective') + '</h3>' +
            '<div style="margin-bottom:10px">' +
            '<button type="button" id="qs-download-template" class="educbt-btn" style="font-size:.8rem">Download Template</button>' +
            '</div>' +
            '<input type="file" id="qs-csv-file" accept=".csv,.xlsx,.xls" class="educbt-input" style="margin-bottom:8px">' +
            '<div style="font-size:.8rem;color:var(--edu-muted);margin-bottom:8px">Columns: <code>' + esc(cols) + '</code></div>' +
            '<div id="qs-staging" style="margin-top:12px"></div>';

        el('qs-download-template').addEventListener('click', function() {
            const blob = new Blob([example], { type: 'text/csv' });
            const a = document.createElement('a');
            a.href = URL.createObjectURL(blob);
            a.download = 'template_' + currentExamType + '.csv';
            a.click();
        });

        el('qs-csv-file').addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (!file) return;
            const reader = new FileReader();
            reader.onload = function(ev) {
                const text = ev.target.result;
                const parsed = parseCSV(text, isTheory);
                renderStagingTable(parsed);
            };
            reader.readAsText(file);
        });
    }

    function parseCSV(text, isTheory) {
        const lines = text.split('\n').filter(l => l.trim());
        if (lines.length < 2) return [];
        const headers = lines[0].split(',').map(h => h.trim().toLowerCase());
        const results = [];

        for (let i = 1; i < lines.length; i++) {
            const cols = parseCSVLine(lines[i]);
            const row = {};
            headers.forEach(function(h, j) { row[h] = (cols[j] || '').trim(); });

            if (isTheory) {
                const q = { stem: row.question || '', marks: parseFloat(row.marks) || 0, marking_guide: row.marking_guide || '', sub_questions: [], status: 'valid', errors: [] };
                // Parse sub_questions: "a. Light reaction|b. Dark reaction" or "1a. explain|mark:2|1b. define|mark:2"
                if (row.sub_questions) {
                    const parts = row.sub_questions.split('|');
                    parts.forEach(function(part) {
                        part = part.trim();
                        if (!part) return;
                        // Format: "a. text" or "1a. text, mark: 2"
                        var sm = part.match(/^([0-9]*[a-z])[.)]\s*(.*)/i);
                        if (sm) {
                            var subText = sm[2];
                            var subMarks = 1;
                            // Check if marks are embedded: "text, mark: 2" or "text | mark: 2"
                            var mk = subText.match(/,?\s*mark\s*[:.]\s*(\d+(?:\.\d+)?)\s*$/i);
                            if (mk) {
                                subMarks = parseFloat(mk[1]);
                                subText = subText.replace(/,?\s*mark\s*[:.]\s*\d+(?:\.\d+)?\s*$/i, '').trim();
                            }
                            q.sub_questions.push({ text: subText, marks: subMarks });
                        } else {
                            q.sub_questions.push({ text: part, marks: 1 });
                        }
                    });
                }
                if (!q.stem) { q.status = 'error'; q.errors.push('Missing question text'); }
                if (!q.marks && q.sub_questions.length === 0) q.marks = parseFloat(marksInput.value) || 1;
                q.sub_questions.forEach(function(sq) { if (!sq.marks) sq.marks = 1; });
                results.push(q);
            } else {
                const options = [];
                if (row.option_a) options.push({ text: row.option_a, label: 'A' });
                if (row.option_b) options.push({ text: row.option_b, label: 'B' });
                if (row.option_c) options.push({ text: row.option_c, label: 'C' });
                if (row.option_d) options.push({ text: row.option_d, label: 'D' });
                if (row.option_e) options.push({ text: row.option_e, label: 'E' });

                let correct = -1;
                if (row.correct_option) {
                    const letter = row.correct_option.toUpperCase().charAt(0);
                    correct = options.findIndex(function(o) { return o.label === letter; });
                    if (correct < 0) correct = letter.charCodeAt(0) - 65;
                }

                const q = {
                    stem: row.question || '',
                    options: options,
                    correct: correct,
                    marks: parseFloat(row.marks) || 0,
                    status: 'valid',
                    errors: []
                };
                if (!q.stem) { q.status = 'error'; q.errors.push('Missing question text'); }
                if (q.options.length < 2) { q.status = 'error'; q.errors.push('Need at least 2 options'); }
                if (q.correct < 0 || q.correct >= q.options.length) {
                    q.status = 'error';
                    q.errors.push('No correct answer — set correct_option to a letter (A, B, C…)');
                }
                if (!q.marks) q.marks = parseFloat(marksInput.value) || 1;
                results.push(q);
            }
        }
        return results;
    }

    function parseCSVLine(line) {
        const cols = [];
        let cur = '';
        let inQuotes = false;
        for (let i = 0; i < line.length; i++) {
            const c = line[i];
            if (c === '"') { inQuotes = !inQuotes; continue; }
            if (c === ',' && !inQuotes) { cols.push(cur); cur = ''; continue; }
            cur += c;
        }
        cols.push(cur);
        return cols;
    }

    // ---- Staging Table (shared by Paste and CSV) ----

    function renderStagingTable(rows) {
        const container = el('qs-staging');
        if (!rows || rows.length === 0) {
            container.innerHTML = '<p class="educbt-muted">No questions parsed.</p>';
            return;
        }

        const valid = rows.filter(r => r.status === 'valid').length;
        const warnings = rows.filter(r => r.status === 'warning').length;
        const errors = rows.filter(r => r.status === 'error').length;

        let html = '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">';
        html += '<span style="font-size:.85rem">' + rows.length + ' rows read · ' + valid + ' valid · ' + warnings + ' warnings · ' + errors + ' errors</span>';
        html += '<div style="display:flex;gap:6px"><button type="button" id="qs-commit-staging" class="educbt-btn educbt-btn--primary" style="font-size:.85rem">Add Valid Questions to Draft</button>';
        html += '<button type="button" id="qs-cancel-staging" class="educbt-btn" style="font-size:.85rem">Cancel</button></div>';
        html += '</div>';

        html += '<table style="width:100%;border-collapse:collapse;font-size:.85rem">';
        html += '<thead><tr style="border-bottom:2px solid var(--edu-line)"><th style="text-align:left;padding:4px">✓</th><th style="text-align:left;padding:4px">Status</th><th style="text-align:left;padding:4px">Question</th>';
        if (currentExamType === 'objective') {
            html += '<th style="text-align:left;padding:4px">Options</th><th style="text-align:left;padding:4px">Answer</th>';
        }
        html += '<th style="text-align:left;padding:4px">Marks</th></tr></thead><tbody>';

        rows.forEach(function(r, i) {
            const icon = r.status === 'valid' ? '✅' : (r.status === 'warning' ? '⚠' : '❌');
            const checked = r.status !== 'error' ? 'checked' : '';
            html += '<tr style="border-bottom:1px solid var(--edu-line)">';
            html += '<td style="padding:4px"><input type="checkbox" class="qs-staging-check" data-idx="' + i + '" ' + checked + ' ' + (r.status === 'error' ? 'disabled' : '') + '></td>';
            html += '<td style="padding:4px">' + icon + '</td>';
            html += '<td style="padding:4px;max-width:300px;overflow:hidden;text-overflow:ellipsis">' + esc(r.stem || '(empty)') + '</td>';
            if (currentExamType === 'objective') {
                html += '<td style="padding:4px">' + (r.options ? r.options.length : 0) + '</td>';
                html += '<td style="padding:4px">' + (r.correct >= 0 ? String.fromCharCode(65 + r.correct) : '?') + '</td>';
            }
            var subCount = r.sub_questions ? r.sub_questions.length : 0;
            html += '<td style="padding:4px">' + r.marks + (subCount ? ' <span style="color:var(--edu-muted)">+' + subCount + ' sub</span>' : '') + '</td>';
            html += '</tr>';
            // Show sub-questions if any
            if (subCount > 0) {
                r.sub_questions.forEach(function(sq) {
                    html += '<tr style="border-bottom:1px solid var(--edu-line);background:#fafafa">';
                    html += '<td></td><td></td>';
                    html += '<td style="padding:2px 4px 2px 24px;font-size:.8rem;color:var(--edu-muted)">' + esc(sq.text || '') + ' <span style="font-weight:600">(' + sq.marks + ' marks)</span></td>';
                    if (currentExamType === 'objective') { html += '<td></td><td></td>'; }
                    html += '<td></td>';
                    html += '</tr>';
                });
            }
            if (r.errors && r.errors.length) {
                html += '<tr style="background:#fef2f2"><td colspan="' + (currentExamType === 'objective' ? 7 : 5) + '" style="padding:2px 4px 6px 24px;font-size:.8rem;color:red">' + esc(r.errors.join('; ')) + '</td></tr>';
            }
        });
        html += '</tbody></table>';

        container.innerHTML = html;

        el('qs-commit-staging').addEventListener('click', function() {
            const checked = document.querySelectorAll('.qs-staging-check:checked');
            const toCommit = [];
            checked.forEach(function(cb) {
                const idx = parseInt(cb.dataset.idx);
                const r = rows[idx];
                if (r && r.status !== 'error') {
                    const q = { stem: r.stem, marks: r.marks, source_method: currentMethod === 'csv' ? 'import' : 'paste' };
                    if (currentExamType === 'objective' && r.options) {
                        q.options = r.options.map(function(o, i) { return { text: o.text, is_correct: i === r.correct }; });
                    }
                    if (currentExamType === 'theory' && r.marking_guide) {
                        q.marking_guide = r.marking_guide;
                    }
                    // Pass sub-questions from paste/CSV import (API expects sub_items)
                    if (currentExamType === 'theory' && r.sub_questions && r.sub_questions.length > 0) {
                        q.sub_items = r.sub_questions.map(function(sq) {
                            return { text: sq.text, marks: sq.marks || 1 };
                        });
                    }
                    toCommit.push(q);
                }
            });

            if (toCommit.length === 0) { alert('No valid rows selected.'); return; }

            ensureSet(function(set) {
                // `cursor` advances on EVERY response; `saved` and `failed` only
                // count outcomes. Advancing the cursor only on success meant a
                // single rejected row re-posted itself forever, hammering the
                // server and hanging on "Saving…" with no way out.
                let cursor = 0;
                let saved = 0;
                let failed = 0;
                let firstError = '';
                showSaving();

                function commitNext() {
                    if (cursor >= toCommit.length) {
                        if (failed === 0) {
                            showSaved(saved + ' question' + (saved === 1 ? '' : 's') + ' added');
                            el('qs-staging').innerHTML = '';
                        } else {
                            showSaveError(saved + ' added · ' + failed + ' failed');
                            alert(
                                saved + ' question' + (saved === 1 ? '' : 's') + ' added, ' +
                                failed + ' could not be saved.' +
                                (firstError ? '\n\nFirst error: ' + firstError : '')
                            );
                        }
                        refreshQuestions();
                        return;
                    }

                    const payload = toCommit[cursor];
                    cursor++;

                    apiCall('POST', 'question-sets/' + set.id + '/questions', payload)
                        .then(function(r) {
                            if (r.success) {
                                saved++;
                            } else {
                                failed++;
                                if (!firstError) firstError = r.error || 'unknown error';
                            }
                            commitNext();
                        });
                }
                commitNext();
            });
        });

        el('qs-cancel-staging').addEventListener('click', function() {
            container.innerHTML = '';
        });
    }

    // ---- Region C: Live Preview ----

    function renderPreview() {
        const emptyState = el('qs-empty-state');
        const list = el('qs-question-list');

        if (!currentSet || currentQuestions.length === 0) {
            emptyState.style.display = 'block';
            list.style.display = 'none';
            const p = emptyState.querySelector('p');
            if (!subjectSel.value || !classSel.value) {
                p.innerHTML = 'Choose a subject and class to start writing questions.<br>' +
                    'You can enter questions manually, paste them in, or import from CSV — all three methods add to the same set.';
            } else {
                p.innerHTML = 'No questions yet for this subject, class and exam type.<br>' +
                    'Start writing above — your questions appear here the moment they save.';
            }
            return;
        }

        emptyState.style.display = 'none';
        list.style.display = 'block';

        const editable = isEditable();
        let html = '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">';
        html += '<h3 style="margin:0;font-size:1rem">Questions in this set (' + currentQuestions.length + ')</h3>';
        html += '<div style="display:flex;gap:6px;align-items:center">';
        if (API.isReviewer && currentSet && (currentSet.status === 'submitted' || currentSet.status === 'under_review' || currentSet.status === 'returned')) {
            html += '<button type="button" class="educbt-btn" onclick="qsSelectAll(this)" style="font-size:.8rem">Select All</button>';
            html += '<button type="button" class="educbt-btn" onclick="qsApproveSelected(this)" style="font-size:.8rem;background:#16a34a;color:#fff">Approve Selected</button>';
            html += '<button type="button" class="educbt-btn" onclick="qsSendBack(this)" style="font-size:.8rem;color:#dc2626;border-color:#dc2626">Send Back</button>';
        }
        html += '<input type="text" id="qs-search" class="educbt-input" placeholder="Search..." style="font-size:.85rem;width:200px">';
        html += '</div></div>';

        currentQuestions.forEach(function(q, i) {
            const isObj = q.question_type === 'single_choice' || q.question_type === 'objective';
            const src = q.source_method || 'manual';
            const srcIcon = src === 'paste' ? '📋' : (src === 'import' ? '📥' : '✏');
            const reviewerNote = q.reviewer_comment || '';

            var showReviewCheck = API.isReviewer && currentSet && (currentSet.status === 'submitted' || currentSet.status === 'under_review' || currentSet.status === 'returned');
            var approvalPill = '';
            if (API.isReviewer && q.approval_status) {
                var ap = q.approval_status;
                var apClass = ap === 'approved' ? 'educbt-pill--approved' : (ap === 'revision' ? 'educbt-pill--draft' : 'educbt-pill--submitted');
                var apText = ap === 'approved' ? 'approved' : (ap === 'revision' ? 'sent back' : 'pending');
                approvalPill = '<span class="educbt-pill ' + apClass + '" style="font-size:.7rem">' + apText + '</span>';
            }
            html += '<div class="qs-card" data-qid="' + q.id + '" style="border:1px solid var(--edu-line);border-radius:8px;padding:12px;margin-bottom:8px' + (reviewerNote ? ';border-color:#f59e0b' : '') + '">';
            html += '<div style="display:flex;justify-content:space-between;align-items:flex-start">';
            if (showReviewCheck) {
                html += '<input type="checkbox" class="qs-review-check" value="' + q.id + '" style="margin-top:4px;margin-right:8px">';
            }
            html += '<div style="flex:1">';
            html += '<div style="display:flex;align-items:center;gap:6px;margin-bottom:4px">';
            html += '<span style="font-weight:700;color:var(--edu-muted)">' + (i + 1) + '.</span>';
            if (approvalPill) html += approvalPill;
            html += '<span class="educbt-pill educbt-pill--draft" style="font-size:.7rem">' + srcIcon + ' ' + src + '</span>';
            var totalMarks = q.marks;
            if (q.sub_items && q.sub_items.length) {
                totalMarks += q.sub_items.reduce(function(s, sub) { return s + (parseFloat(sub.marks) || 0); }, 0);
            }
            html += '<span class="educbt-pill" style="font-size:.7rem">' + totalMarks + ' marks</span>';
            html += '</div>';
            html += '<p style="margin:0 0 6px">' + esc(q.question_text || q.stem || '') + '</p>';

            // Show image if present
            if (q.image_reference) {
                html += '<div style="margin-bottom:6px"><img src="' + esc(q.image_reference) + '" style="max-height:80px;border-radius:4px;border:1px solid var(--edu-line)"></div>';
            }

            // Show passage badge if present
            if (q.passage_id) {
                var passName = 'Passage #' + q.passage_id;
                if (API.passages) {
                    var pm = API.passages.find(function(p) { return parseInt(p.id) === parseInt(q.passage_id); });
                    if (pm) passName = pm.title;
                }
                html += '<div style="margin-bottom:6px"><span class="educbt-pill" style="font-size:.7rem;background:#e0e7ff;color:#3730a3">📄 ' + esc(passName) + '</span></div>';
            }

            // Show instructions if present
            if (q.instructions) {
                html += '<div style="margin-bottom:6px;font-size:.8rem;color:var(--edu-muted);font-style:italic">📋 ' + esc(q.instructions) + '</div>';
            }

            if (isObj && q.options && q.options.length) {
                // Guard the display too: never show the same option twice, and
                // never show a second answer key even if the data still holds one.
                const seen = {};
                const shown = [];
                let markedCorrect = false;
                q.options.forEach(function(opt) {
                    const key = (opt.option_text || '').trim().toLowerCase();
                    if (!key || seen[key]) return;
                    seen[key] = true;
                    const isCorrect = parseInt(opt.is_correct) === 1 && !markedCorrect;
                    if (isCorrect) markedCorrect = true;
                    shown.push({ text: opt.option_text || '', correct: isCorrect });
                });

                html += '<div style="display:flex;flex-direction:column;gap:3px;margin-left:12px">';
                shown.forEach(function(opt, oi) {
                    html += '<div style="font-size:.85rem;' + (opt.correct ? 'color:#16a34a;font-weight:600' : '') + '">';
                    html += String.fromCharCode(65 + oi) + '. ' + esc(opt.text);
                    if (opt.correct) html += ' ✓';
                    html += '</div>';
                });
                html += '</div>';
            }

            if (!isObj && q.sub_items && q.sub_items.length) {
                html += '<div style="margin-left:12px;margin-top:4px">';
                q.sub_items.forEach(function(sub) {
                    html += '<div style="font-size:.85rem;margin-bottom:2px">(' + (sub.label || '?') + ') ' + esc(sub.text || '') + ' <span class="educbt-muted">' + sub.marks + ' marks</span></div>';
                });
                html += '</div>';
            }

            if (reviewerNote) {
                html += '<div style="margin-top:6px;padding:6px 8px;background:#fef3c7;border-radius:6px;font-size:.8rem"><strong>Reviewer:</strong> ' + esc(reviewerNote) + '</div>';
            }

            html += '</div>';

            if (editable) {
                html += '<div style="display:flex;flex-direction:column;gap:3px">';
                html += '<button type="button" class="educbt-btn qs-edit-btn" data-qid="' + q.id + '" style="font-size:.8rem;padding:2px 8px">Edit</button>';
                html += '<button type="button" class="educbt-btn qs-dup-btn" data-qid="' + q.id + '" style="font-size:.8rem;padding:2px 8px">Copy</button>';
                html += '<button type="button" class="educbt-btn qs-del-btn" data-qid="' + q.id + '" style="font-size:.8rem;padding:2px 8px;color:red">Del</button>';
                html += '</div>';
            }
            html += '</div></div>';
        });

        list.innerHTML = html;

        // Wire up actions
        if (editable) {
            list.querySelectorAll('.qs-del-btn').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    if (!confirm('Delete this question?')) return;
                    const qid = parseInt(this.dataset.qid);
                    apiCall('DELETE', 'question-sets/' + currentSet.id + '/questions/' + qid)
                        .then(function(r) { if (r.success) refreshQuestions(); });
                });
            });
            list.querySelectorAll('.qs-dup-btn').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    const qid = parseInt(this.dataset.qid);
                    apiCall('POST', 'question-sets/' + currentSet.id + '/questions/' + qid + '/duplicate')
                        .then(function(r) { if (r.success) refreshQuestions(); });
                });
            });
            list.querySelectorAll('.qs-edit-btn').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    const qid = parseInt(this.dataset.qid);
                    const q = currentQuestions.find(function(x) { return parseInt(x.id) === qid; });
                    if (q) openInlineEdit(q);
                });
            });
        }

        // Search
        const search = el('qs-search');
        if (search) {
            search.addEventListener('input', function() {
                const term = this.value.toLowerCase();
                list.querySelectorAll('.qs-card').forEach(function(card) {
                    const text = card.textContent.toLowerCase();
                    card.style.display = text.includes(term) ? '' : 'none';
                });
            });
        }
    }

    // ---- Reviewer actions on Question Bank ----

    window.qsSelectAll = function(btn) {
        var list = el('qs-question-list');
        var checks = list.querySelectorAll('.qs-review-check');
        if (!checks.length) return;
        var allChecked = Array.prototype.every.call(checks, function(c) { return c.checked; });
        Array.prototype.forEach.call(checks, function(c) { c.checked = !allChecked; });
        btn.textContent = allChecked ? 'Select All' : 'Deselect All';
    };

    window.qsApproveSelected = function(btn) {
        qsDecide(btn, 'approve');
    };

    window.qsSendBack = function(btn) {
        var list = el('qs-question-list');
        var checked = list.querySelectorAll('.qs-review-check:checked');
        if (!checked.length) {
            alert('Select at least one question to send back.');
            return;
        }
        // Show inline modal instead of browser prompt
        showSendBackModal(btn);
    };

    function showSendBackModal(triggerBtn) {
        // Remove any existing modal
        var existing = document.getElementById('qs-sendback-modal');
        if (existing) existing.remove();

        var modal = document.createElement('div');
        modal.id = 'qs-sendback-modal';
        modal.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:9999;display:flex;align-items:center;justify-content:center';
        modal.innerHTML = ''
            + '<div style="background:var(--edu-surface,#fff);border-radius:12px;box-shadow:0 8px 32px rgba(0,0,0,.15);max-width:480px;width:90%;padding:24px">'
            + '<h3 style="margin:0 0 8px;font-size:1.1rem">Send Back for Revision</h3>'
            + '<p class="educbt-muted" style="margin:0 0 12px;font-size:.85rem">The teacher will be able to edit and resubmit. Explain what needs fixing — this is required.</p>'
            + '<textarea id="qs-sendback-note" class="educbt-input" style="width:100%;min-height:80px;font-size:.9rem" placeholder="e.g. Question 3 has no correct answer marked, Question 5 stem is incomplete..."></textarea>'
            + '<div style="display:flex;gap:8px;margin-top:16px;justify-content:flex-end">'
            + '<button type="button" id="qs-sendback-cancel" class="educbt-btn">Cancel</button>'
            + '<button type="button" id="qs-sendback-confirm" class="educbt-btn" style="background:#dc2626;color:#fff;border-color:#dc2626">Send Back</button>'
            + '</div></div>';

        document.body.appendChild(modal);
        var textarea = document.getElementById('qs-sendback-note');
        textarea.focus();

        document.getElementById('qs-sendback-cancel').addEventListener('click', function() {
            modal.remove();
        });

        document.getElementById('qs-sendback-confirm').addEventListener('click', function() {
            var note = textarea.value || '';
            if (!note.trim()) {
                textarea.style.borderColor = '#dc2626';
                textarea.focus();
                alert('You must explain what needs fixing before sending back.');
                return;
            }
            modal.remove();
            qsDecide(triggerBtn, 'revision', note);
        });

        // Close on backdrop click
        modal.addEventListener('click', function(e) {
            if (e.target === modal) modal.remove();
        });

        // Submit on Ctrl+Enter
        textarea.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
                document.getElementById('qs-sendback-confirm').click();
            }
        });
    }

    function qsDecide(btn, decision, note) {
        var list = el('qs-question-list');
        var checks = list.querySelectorAll('.qs-review-check:checked');
        var questionIds = [];
        checks.forEach(function(c) { questionIds.push(parseInt(c.value)); });

        if (decision === 'approve' && !questionIds.length) {
            alert('Select at least one question to approve.');
            return;
        }

        // Find staff_id from the current set's author
        if (!currentSet) return;
        var staffId = currentSet.staff_id || currentSet.teacher_id || 0;
        if (!staffId) {
            alert('Cannot determine the teacher for this submission.');
            return;
        }

        btn.disabled = true;
        btn.textContent = 'Working...';

        apiCall('POST', 'questions/decide', {
            subject_id: parseInt(currentSet.subject_id || subjectSel.value),
            staff_id: parseInt(staffId),
            decision: decision,
            note: note || '',
            question_ids: questionIds
        }).then(function(data) {
            if (data.success) {
                alert('Done - ' + (data.changed || 0) + ' question(s) updated.');
                refreshQuestions();
            } else {
                alert(data.error || data.message || 'Something went wrong.');
                btn.disabled = false;
                btn.textContent = decision === 'approve' ? 'Approve Selected' : 'Send Back';
            }
        });
    }

    function openInlineEdit(q) {
        const card = document.querySelector('.qs-card[data-qid="' + q.id + '"]');
        if (!card) return;
        const isObj = q.question_type === 'single_choice' || q.question_type === 'objective';

        let html = '<div style="background:var(--edu-muted-bg,#f9fafb);border-radius:8px;padding:12px">';
        html += '<textarea class="educbt-input qs-edit-stem" rows="2" style="width:100%;margin-bottom:6px">' + esc(q.question_text || '') + '</textarea>';

        // Image (inline edit)
        if (q.image_reference) {
            html += '<div style="margin-bottom:6px"><img src="' + esc(q.image_reference) + '" style="max-height:50px;border-radius:4px"><br>';
            html += '<button type="button" class="educbt-btn qs-edit-img-btn" style="font-size:.8rem;padding:2px 8px;margin-top:2px">📷 Change</button>';
            html += '<button type="button" class="educbt-btn qs-edit-img-remove" style="font-size:.8rem;padding:2px 8px;margin-top:2px">Remove</button>';
            html += '<input type="hidden" class="qs-edit-img-url" value="' + esc(q.image_reference) + '"></div>';
        } else {
            html += '<div style="margin-bottom:6px"><button type="button" class="educbt-btn qs-edit-img-btn" style="font-size:.8rem;padding:2px 8px">📷 Add Image</button>';
            html += '<input type="hidden" class="qs-edit-img-url" value=""></div>';
        }

        // Passage (inline edit)
        if (isEnglishSubject() && API.passages && API.passages.length) {
            html += '<div style="margin-bottom:6px"><select class="educbt-input qs-edit-passage" style="width:100%"><option value="0">No passage</option>';
            API.passages.forEach(function(p) {
                html += '<option value="' + p.id + '"' + (parseInt(q.passage_id) === parseInt(p.id) ? ' selected' : '') + '>' + esc(p.title) + '</option>';
            });
            html += '</select></div>';
        }

        // Instructions (inline edit)
        html += '<textarea class="educbt-input qs-edit-instructions" rows="1" style="width:100%;margin-bottom:6px" placeholder="Instructions (optional)…">' + esc(q.instructions || '') + '</textarea>';

        if (isObj) {
            q.options.forEach(function(opt, i) {
                html += '<div style="display:flex;gap:6px;margin-bottom:3px;align-items:center">';
                html += '<input type="radio" name="qe-edit-correct-' + q.id + '" value="' + i + '" ' + (parseInt(opt.is_correct) === 1 ? 'checked' : '') + ' style="margin:0">';
                html += '<span style="font-weight:600;width:20px">' + (opt.option_key || String.fromCharCode(65 + i)) + '.</span>';
                html += '<input type="text" class="educbt-input qs-edit-opt" data-idx="' + i + '" value="' + esc(opt.option_text || '') + '" style="flex:1;font-size:.85rem">';
                html += '</div>';
            });
        } else {
            // Theory edit: Question Text → Marks → Sub-questions → Marking Guide
            html += '<div style="margin-bottom:6px"><label class="educbt-muted" style="font-size:.8rem">Marks</label>';
            html += '<input type="number" class="educbt-input qs-edit-marks" value="' + q.marks + '" min="0.5" step="0.5" style="width:65px"></div>';
            html += '<div style="border:1px solid var(--edu-line);border-radius:8px;padding:10px;margin-bottom:6px">';
            html += '<label class="educbt-muted" style="font-size:.8rem">Sub-questions (optional)</label>';
            html += '<div class="qs-edit-sub-list"></div>';
            html += '<button type="button" class="educbt-btn qs-edit-add-sub" style="font-size:.8rem;padding:3px 8px;margin-top:6px">+ Add sub-question</button>';
            html += '<div class="qs-edit-sub-total educbt-muted" style="font-size:.8rem;margin-top:4px"></div>';
            html += '</div>';
            html += '<label class="educbt-muted" style="font-size:.8rem">Marking Guide / Model Answer (markers only)</label>';
            html += '<textarea class="educbt-input qs-edit-guide" rows="2" style="width:100%;margin-bottom:6px">' + esc(q.marking_guide || '') + '</textarea>';
        }

        // For objective: Marks + Save/Cancel at the bottom
        if (isObj) {
            html += '<div style="display:flex;gap:6px;align-items:center;margin-top:6px">';
            html += '<label class="educbt-muted" style="font-size:.8rem">Marks</label>';
            html += '<input type="number" class="educbt-input qs-edit-marks" value="' + q.marks + '" min="0.5" step="0.5" style="width:65px">';
            html += '<button type="button" class="educbt-btn educbt-btn--primary qs-save-edit" data-qid="' + q.id + '" style="font-size:.85rem;margin-left:auto">Save</button>';
            html += '<button type="button" class="educbt-btn qs-cancel-edit" style="font-size:.85rem">Cancel</button>';
            html += '</div>';
        } else {
            html += '<div style="display:flex;gap:6px;align-items:center;margin-top:6px">';
            html += '<button type="button" class="educbt-btn educbt-btn--primary qs-save-edit" data-qid="' + q.id + '" style="font-size:.85rem;margin-left:auto">Save</button>';
            html += '<button type="button" class="educbt-btn qs-cancel-edit" style="font-size:.85rem">Cancel</button>';
            html += '</div>';
        }
        html += '</div>';

        card.innerHTML = html;

        // Theory sub-questions, editable exactly as they are on the entry form.
        let editSubs = [];
        if (!isObj) {
            editSubs = (q.sub_items || []).map(function(s) {
                return { text: s.text || '', marks: parseFloat(s.marks) || 0 };
            });

            const subList = card.querySelector('.qs-edit-sub-list');
            const subTotal = card.querySelector('.qs-edit-sub-total');

            function renderEditSubs() {
                subList.innerHTML = '';
                editSubs.forEach(function(item, i) {
                    const row = document.createElement('div');
                    row.style.cssText = 'display:flex;gap:6px;margin-bottom:4px;align-items:center';
                    row.innerHTML =
                        '<span style="font-weight:600;width:20px">(' + String.fromCharCode(97 + i) + ')</span>' +
                        '<input type="text" class="educbt-input qs-edit-sub-text" style="flex:1;font-size:.85rem">' +
                        '<input type="number" class="educbt-input qs-edit-sub-marks" min="0.5" step="0.5" style="width:65px;font-size:.85rem">' +
                        '<button type="button" class="qs-edit-rm-sub" style="border:0;background:transparent;color:red;cursor:pointer;padding:0 4px">×</button>';
                    subList.appendChild(row);
                    row.querySelector('.qs-edit-sub-text').value = item.text;
                    row.querySelector('.qs-edit-sub-marks').value = item.marks;
                    row.querySelector('.qs-edit-sub-text').addEventListener('input', function() { editSubs[i].text = this.value; });
                    row.querySelector('.qs-edit-sub-marks').addEventListener('input', function() {
                        editSubs[i].marks = parseFloat(this.value) || 0;
                        updateEditSubTotal();
                    });
                    row.querySelector('.qs-edit-rm-sub').addEventListener('click', function() {
                        editSubs.splice(i, 1);
                        renderEditSubs();
                        updateEditSubTotal();
                    });
                });
            }

            function updateEditSubTotal() {
                // Reported, never written over the main mark — the parent question
                // keeps its own marks.
                const total = editSubs.reduce(function(s, i) { return s + (i.marks || 0); }, 0);
                subTotal.textContent = editSubs.length ? 'Sub-questions total: ' + total + ' marks' : '';
            }

            card.querySelector('.qs-edit-add-sub').addEventListener('click', function() {
                editSubs.push({ text: '', marks: 1 });
                renderEditSubs();
                updateEditSubTotal();
            });

            renderEditSubs();
            updateEditSubTotal();
        }


        // Image controls on the edit card, driven by the device picker.
        const editImgBtn = card.querySelector('.qs-edit-img-btn');
        const editImgDel = card.querySelector('.qs-edit-img-remove');
        const editImgUrl = card.querySelector('.qs-edit-img-url');

        if (editImgBtn) {
            editImgBtn.addEventListener('click', function() {
                educbtPickImage(function(url) {
                    if (editImgUrl) editImgUrl.value = url;

                    let prev = editImgBtn.parentNode.querySelector('img');
                    if (!prev) {
                        prev = document.createElement('img');
                        prev.style.cssText = 'max-height:50px;border-radius:4px;display:block;margin-bottom:4px';
                        editImgBtn.parentNode.insertBefore(prev, editImgBtn);
                    }
                    prev.src = url;
                    editImgBtn.textContent = 'Change';
                }, editImgBtn);
            });
        }

        if (editImgDel) {
            editImgDel.addEventListener('click', function() {
                if (editImgUrl) editImgUrl.value = '';
                const prev = editImgDel.parentNode.querySelector('img');
                if (prev) prev.remove();
                editImgDel.style.display = 'none';
                if (editImgBtn) editImgBtn.textContent = 'Add Image';
            });
        }

        card.querySelector('.qs-save-edit').addEventListener('click', function() {
            const data = { stem: card.querySelector('.qs-edit-stem').value.trim(), marks: parseFloat(card.querySelector('.qs-edit-marks').value) || 0 };
            // Always sent, so clearing an image actually clears it rather than
            // leaving the previous one attached.
            data.image_reference = editImgUrl ? editImgUrl.value : '';


            // Image
            var editImgUrl = card.querySelector('.qs-edit-img-url');
            if (editImgUrl && editImgUrl.value) { data.image_reference = editImgUrl.value; }

            // Passage
            var editPass = card.querySelector('.qs-edit-passage');
            if (editPass) { data.passage_id = parseInt(editPass.value) || 0; }

            // Instructions
            var editInst = card.querySelector('.qs-edit-instructions');
            if (editInst && editInst.value.trim()) { data.instructions = editInst.value.trim(); }

            if (!isObj) {
                data.marking_guide = card.querySelector('.qs-edit-guide').value;
                data.sub_items = editSubs.filter(function(s) { return s.text.trim() !== ''; });
            }
            if (isObj) {
                const opts = [];
                card.querySelectorAll('.qs-edit-opt').forEach(function(inp) {
                    if (inp.value.trim()) opts.push({ text: inp.value.trim(), is_correct: card.querySelector('input[name="qe-edit-correct-' + q.id + '"]:checked') && parseInt(card.querySelector('input[name="qe-edit-correct-' + q.id + '"]:checked').value) === parseInt(inp.dataset.idx) });
                });
                data.options = opts;
            }
            apiCall('PUT', 'question-sets/' + currentSet.id + '/questions/' + q.id, data)
                .then(function(r) {
                    if (r.success) {
                        showSaved('Question updated');
                        refreshQuestions();
                        return;
                    }
                    // Previously this branch did nothing at all: a rejected edit
                    // left the card open with no message, looking like a dead button.
                    showSaveError(r.error || 'Could not update the question');
                    alert(r.error || 'Could not update the question.');
                });
        });

        card.querySelector('.qs-cancel-edit').addEventListener('click', function() {
            refreshQuestions();
        });
    }

    // ---- Region D: Progress + Submit ----

    function renderProgress() {
        const bar = el('qs-progress');
        const btn = el('qs-submit-btn');

        if (!currentSet) {
            bar.style.display = 'none';
            if (btn) btn.style.display = 'none';
            return;
        }

        // Principals and exam officers can review but not submit.
        if (API.isReviewer && btn) {
            btn.style.display = 'none';
            return;
        }

        if (bar) bar.style.display = 'block';
        const count = currentQuestions.length;
        const marks = currentQuestions.reduce(function(s, q) {
            var m = parseFloat(q.marks) || 0;
            if (q.sub_items && q.sub_items.length) {
                m += q.sub_items.reduce(function(ss, sub) { return ss + (parseFloat(sub.marks) || 0); }, 0);
            }
            return s + m;
        }, 0);
        const min = currentExamType === 'objective' ? API.minObjective : API.minTheory;

        el('qs-count-label').textContent = count + ' / ' + min + ' ' + currentExamType + ' questions';
        el('qs-marks-label').textContent = marks + ' marks total';

        const pct = min > 0 ? Math.min(100, (count / min) * 100) : 100;
        el('qs-progress-bar').style.width = pct + '%';

        // Sibling set indicator with question count
        var siblingShort = false;
        var siblingMsg = '';
        if (currentSet._sibling) {
            var sibCount = parseInt(currentSet._sibling.question_count || 0);
            var sibMin = parseInt(currentSet._sibling.min_required || (currentSet._sibling.exam_type === 'objective' ? API.minObjective : API.minTheory));
            var sibLabel = capitalize(currentSet._sibling.exam_type) + ': ' + sibCount + '/' + sibMin + ' (' + currentSet._sibling.status + ')';
            el('qs-sibling-label').textContent = sibLabel;
            if (sibCount < sibMin && (currentSet._sibling.status === 'draft' || currentSet._sibling.status === 'returned')) {
                siblingShort = true;
                siblingMsg = 'Theory and Objective submit together. ' + capitalize(currentSet._sibling.exam_type) + ' needs ' + (sibMin - sibCount) + ' more question' + ((sibMin - sibCount) > 1 ? 's' : '') + '.';
            }
        } else {
            // No sibling set exists yet — the other exam type has not been started.
            // CA tests and practice exams are objective-only by design: they do NOT
            // require a theory sibling. Only terminal examinations submit obj+theory together.
            var sibType = currentExamType === 'objective' ? 'theory' : 'objective';
            var sibMin = sibType === 'objective' ? API.minObjective : API.minTheory;
            var hasSeriesId = currentSet.series_id && parseInt(currentSet.series_id) > 0;
            if (hasSeriesId || sibMin === 0) {
                // CA test or practice exam — objective-only, no sibling needed.
                el('qs-sibling-label').textContent = '';
                siblingShort = false;
            } else {
                el('qs-sibling-label').textContent = capitalize(sibType) + ': 0/' + sibMin + ' (not started)';
                siblingShort = true;
                siblingMsg = 'Theory and Objective submit together. Create the ' + sibType + ' set and add at least ' + sibMin + ' question' + (sibMin > 1 ? 's' : '') + '.';
            }
        }

        // Submit button — enabled only when BOTH types meet their minimums.
        if (!btn) return;
        if (isEditable()) {
            btn.style.display = 'inline-flex';
            if (count < min) {
                btn.disabled = true;
                btn.title = 'Add ' + (min - count) + ' more ' + currentExamType + ' question' + (min - count > 1 ? 's' : '') + ' to submit.';
            } else if (siblingShort) {
                btn.disabled = true;
                btn.title = siblingMsg;
            } else {
                btn.disabled = false;
                btn.title = '';
            }
            btn.textContent = currentSet.status === 'returned' ? 'Resubmit for Review' : 'Submit for Review';
        } else {
            btn.style.display = 'none';
        }
    }

    function capitalize(s) { return s ? s.charAt(0).toUpperCase() + s.slice(1) : ''; }

    var _btn = el('qs-submit-btn');
    if (_btn) _btn.addEventListener('click', function() {
        if (!currentSet) return;
        const count = currentQuestions.length;
        const marks = currentQuestions.reduce(function(s, q) {
            var m = parseFloat(q.marks) || 0;
            if (q.sub_items && q.sub_items.length) {
                m += q.sub_items.reduce(function(ss, sub) { return ss + (parseFloat(sub.marks) || 0); }, 0);
            }
            return s + m;
        }, 0);

        // Build the submit confirmation. CA tests are objective-only with
        // a fixed minimum, marks per question, and per-student count — the
        // generic Objective+Theory prompt was misleading for CA tests.
        var summary;
        if (API.isCaTest) {
            summary = 'Subject: ' + (subjectSel.options[subjectSel.selectedIndex] ? subjectSel.options[subjectSel.selectedIndex].text : '?') +
                '\nClass Level: ' + (classSel.options[classSel.selectedIndex] ? classSel.options[classSel.selectedIndex].text : '?') +
                '\n\nCA Test — Objective questions only' +
                '\nQuestions: ' + count + ' / ' + API.caMinQuestions + ' required (minimum)' +
                '\nTotal marks: ' + marks + ' / ' + API.caMaxMarks + ' (1 mark per question)' +
                '\nEach student answers: ' + API.caPerStudent + ' questions' +
                '\n\nDelivery Mode: ' + (currentDeliveryMode === 'written' ? 'Written (paper-based)' : 'CBT (computer-based)') +
                '\nOnce submitted, you will not be able to edit these questions unless the Exam Officer returns them to you.';
        } else {
            summary = 'Subject: ' + (subjectSel.options[subjectSel.selectedIndex] ? subjectSel.options[subjectSel.selectedIndex].text : '?') +
                '\nClass Level: ' + (classSel.options[classSel.selectedIndex] ? classSel.options[classSel.selectedIndex].text : '?') +
                '\n\nObjective: ' + (currentExamType === 'objective' ? count + ' questions / ' + API.minObjective + ' required' : (currentSet._sibling ? (currentSet._sibling.question_count || 0) + ' questions / ' + (currentSet._sibling.min_required || API.minObjective) + ' required' : '0 questions / ' + API.minObjective + ' required (not started)')) +
                '\nTheory: ' + (currentExamType === 'theory' ? count + ' questions / ' + API.minTheory + ' required' : (currentSet._sibling ? (currentSet._sibling.question_count || 0) + ' questions / ' + (currentSet._sibling.min_required || API.minTheory) + ' required' : '0 questions / ' + API.minTheory + ' required (not started)')) +
                '\n\nTotal marks: ' + marks +
                '\nDelivery Mode: ' + (currentDeliveryMode === 'written' ? 'Written (paper-based)' : 'CBT (computer-based)') +
                '\n\nBoth Objective and Theory will be submitted together for review.' +
                '\nOnce submitted, you will not be able to edit these questions unless the Exam Officer returns them to you.';
        }

        if (!confirm(summary)) return;

        apiCall('POST', 'question-sets/' + currentSet.id + '/submit')
            .then(function(r) {
                if (r.success) {
                    if (API.isCaTest) {
                        alert('Submitted for review.\n\nYour CA Test questions have been submitted for approval.');
                    } else {
                        alert('Submitted for review.\n\nBoth Objective and Theory sets have been submitted together.');
                    }
                    loadSet();
                } else if (r.error === 'below_minimum' && r.shortfall) {
                    var msgs = r.shortfall.map(function(s) {
                        return capitalize(s.exam_type) + ': ' + s.count + '/' + s.min + ' questions (add ' + (s.min - s.count) + ' more)';
                    });
                    alert('Cannot submit yet. Theory and Objective submit together:\n\n' + msgs.join('\n'));
                } else {
                    alert('Could not submit: ' + (r.error || 'unknown error'));
                }
            });
    });

    // ---- Refresh questions after add/edit/delete ----

    function refreshQuestions() {
        if (!currentSet) return;
        apiCall('GET', 'question-sets', null, {
            subject_id: subjectSel.value,
            level_id: currentScope().level_id,
            department_id: currentScope().department_id,
            exam_type: currentExamType,
            // Ask for the right bank: WAEC-structured, or the school's own.
            waec_mode: currentWaecMode ? 1 : 0,
        })
            .then(function(r) {
                // A silent return here was why Region C froze on the empty state:
                // if the reload failed for any reason, nothing updated and nothing
                // was reported, so the page looked stuck rather than broken.
                if (!r.success) {
                    showSaveError(r.error || 'Saved, but the list could not be reloaded — refresh the page');
                    return;
                }
                if (r.quotas) {
                    API.minObjective = parseInt(r.quotas.objective) || API.minObjective;
                    API.minTheory = parseInt(r.quotas.theory) || API.minTheory;
                }
                currentSet = r.set;
                currentQuestions = r.questions || [];
                if (r.set && r.set.delivery_mode) {
                    currentDeliveryMode = r.set.delivery_mode;
                    setDeliveryModeUI(currentDeliveryMode);
                }
                // Do NOT take the mode back from the set.
                //
                // The tick is the teacher's CHOICE of which bank to work in, and the
                // lookup already used it. Reading it back from whatever came down
                // was what re-ticked the box a moment after it was cleared.
                if (waecCheckbox) { waecCheckbox.checked = currentWaecMode; }
                renderPreview();
                renderProgress();
                renderStatusBanner();
                renderWaecPanel();
                renderCaPool();
                renderInput();
            });
    }

    // Warn before leaving with unsaved input
    window.addEventListener('beforeunload', function(e) {
        if (unsavedInput) {
            e.preventDefault();
            e.returnValue = '';
        }
    });

    // ---- Passage Creation Modal ----

    function showPassageModal() {
        var overlay = document.createElement('div');
        overlay.id = 'passage-modal';
        overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:99999;display:flex;align-items:center;justify-content:center;padding:20px';
        overlay.innerHTML =
            '<div style="background:#fff;border-radius:12px;padding:24px;max-width:560px;width:100%;max-height:80vh;overflow-y:auto">' +
            '<h3 style="margin:0 0 16px;font-size:1.1rem">New Passage / Stimulus</h3>' +
            '<div style="display:flex;flex-direction:column;gap:12px">' +
            '<div><label style="font-size:.8rem;color:var(--edu-muted)">Title</label>' +
            '<input type="text" id="pm-title" class="educbt-input" style="width:100%;margin-top:3px" placeholder="e.g. Comprehension Passage 1"></div>' +
            '<div><label style="font-size:.8rem;color:var(--edu-muted)">Type</label>' +
            '<select id="pm-type" class="educbt-input" style="width:100%;margin-top:3px">' +
            '<option value="comprehension">Comprehension</option>' +
            '<option value="cloze">Cloze / Fill-in-the-gap</option>' +
            '<option value="instructions">Instructions / Directions</option>' +
            '<option value="reading">Reading Text</option>' +
            '</select></div>' +
            '<div><label style="font-size:.8rem;color:var(--edu-muted)">Passage Body</label>' +
            '<textarea id="pm-body" class="educbt-input" rows="6" style="width:100%;margin-top:3px" placeholder="Type or paste the passage text here…"></textarea></div>' +
            '<div><label style="font-size:.8rem;color:var(--edu-muted)">Passage Image (optional)</label><br>' +
            '<div style="display:flex;align-items:center;gap:8px;margin-top:4px">' +
            '<button type="button" id="pm-img-btn" class="educbt-btn" style="font-size:.8rem;padding:4px 10px">📷 Pick Image</button>' +
            '<input type="hidden" id="pm-img-url">' +
            '<img id="pm-img-preview" style="max-height:60px;max-width:120px;border-radius:4px;display:none">' +
            '</div></div>' +
            '<div style="display:flex;gap:8px;margin-top:8px">' +
            '<button type="button" id="pm-save" class="educbt-btn educbt-btn--primary">Create Passage</button>' +
            '<button type="button" id="pm-cancel" class="educbt-btn" style="margin-left:auto">Cancel</button>' +
            '</div>' +
            '</div></div>';
        document.body.appendChild(overlay);

        // Device picker, not the media library — same reasoning as the question image.
        var pmImgBtn = document.getElementById('pm-img-btn');
        pmImgBtn.addEventListener('click', function() {
            educbtPickImage(function(url) {
                document.getElementById('pm-img-url').value = url;
                var prev = document.getElementById('pm-img-preview');
                prev.src = url;
                prev.style.display = 'block';
            }, pmImgBtn);
        });

        document.getElementById('pm-cancel').addEventListener('click', function() { overlay.remove(); });

        document.getElementById('pm-save').addEventListener('click', function() {
            var title = document.getElementById('pm-title').value.trim();
            var type = document.getElementById('pm-type').value;
            var body = document.getElementById('pm-body').value.trim();
            var img = document.getElementById('pm-img-url').value;

            if (!title) { alert('Passage title is required.'); return; }
            if (!body && !img) { alert('Passage body or image is required.'); return; }

            apiCall('POST', 'passages', { title: title, passage_type: type, body: body, image: img })
                .then(function(r) {
                    if (r.success) {
                        var newPassage = { id: r.id, title: title, passage_type: type, body: body, image: img };
                        if (!API.passages) API.passages = [];
                        API.passages.unshift(newPassage);

                        var passSel = el('qe-passage');
                        if (passSel) {
                            var opt = document.createElement('option');
                            opt.value = r.id;
                            opt.textContent = title + ' (' + type + ')';
                            opt.selected = true;
                            passSel.appendChild(opt);
                        }

                        overlay.remove();
                        showSaved('Passage created');
                    } else {
                        alert('Failed: ' + (r.error || 'unknown'));
                    }
                })
                .catch(function() { alert('Failed to create passage.'); });
        });
    }

    refreshWaecVisibility();
    applyInitialScope();

})();
</script>

<?php
};

require EDUCBT_PRO_PATH . 'templates/portal/shell.php';
