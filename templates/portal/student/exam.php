<?php
/**
 * The exam screen.
 *
 * Rendered standalone, without the portal sidebar: a student sitting a paper should
 * have nothing on screen but the paper, the clock and the answer grid.
 *
 * @var array<string,mixed> $educbt
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

$school_id  = (int) $educbt['school_id'];
$paper_id   = (int) $educbt['id'];
$actor      = $educbt['scope']->actor();
$student_id = (int) $actor['id'];

$timetable = new \EduCBTPro\Services\TimetableService();
$gate      = $timetable->can_open( $school_id, $student_id, $paper_id, '' );

// Everything except the access code is checked before the page renders, so a
// student is not left staring at a code box for a paper that is not theirs.
$blocked = '';

if ( ! $gate['allowed'] && $gate['reason'] !== 'invalid_access_code' ) {
    $blocked = [
        'too_early'              => 'This paper has not opened yet. Please wait for the scheduled time.',
        'window_closed'          => 'The time for this paper has passed.',
        'already_submitted'      => 'You have already submitted this paper.',
        'not_in_this_class'      => 'This paper is not set for your class.',
        'subject_not_registered' => 'You are not registered for this subject.',
        'paper_not_published'    => 'This paper is not open yet.',
        'paper_not_found'        => 'That paper does not exist.',
    ][ $gate['reason'] ] ?? 'This paper cannot be opened.';
}

$paper = $gate['paper'] ?? [];

global $wpdb;

if ( empty( $paper ) && $paper_id > 0 ) {
    $paper = (array) $wpdb->get_row(
        $wpdb->prepare(
            'SELECT p.*, s.name AS subject_name FROM ' . \EduCBTPro\Core\Schema::table( 'exam_papers' ) . ' p
             INNER JOIN ' . \EduCBTPro\Core\Schema::table( 'subjects_v2' ) . ' s ON s.id = p.subject_id
             WHERE p.id = %d AND p.school_id = %d',
            $paper_id,
            $school_id
        ),
        ARRAY_A
    );
}

// The candidate's own details, shown on the exam screen. A student sitting a CBT
// paper should be able to confirm at a glance that they are writing as themselves —
// mis-seating happens, and finding out afterwards is too late.
$candidate = (array) $wpdb->get_row(
    $wpdb->prepare(
        'SELECT s.admission_number, s.first_name, s.last_name, s.passport_photo, s.gender, s.class
         FROM ' . $wpdb->prefix . 'educbt_students s
         WHERE s.id = %d AND s.school_id = %d',
        $student_id,
        $school_id
    ),
    ARRAY_A
);

$candidate_name  = trim( (string) ( $candidate['first_name'] ?? '' ) . ' ' . (string) ( $candidate['last_name'] ?? '' ) );
$candidate_id    = (string) ( $candidate['admission_number'] ?? '' );
$candidate_photo = (string) ( $candidate['passport_photo'] ?? '' );
$candidate_gender = (string) ( $candidate['gender'] ?? '' );
$candidate_class  = (string) ( $candidate['class'] ?? '' );

// Try to get the class display name from the paper's class_id
if ( ! empty( $paper['class_id'] ) ) {
    $class_name = (string) $wpdb->get_var(
        $wpdb->prepare(
            'SELECT display_name FROM ' . \EduCBTPro\Core\Schema::table( 'classes' ) . ' WHERE id = %d AND school_id = %d',
            absint( $paper['class_id'] ),
            $school_id
        )
    );
    if ( $class_name !== '' ) {
        $candidate_class = $class_name;
    }
}

// Fetch the exam series (session + term) for this paper
$exam_session = '';
$exam_term     = '';
if ( ! empty( $paper['series_id'] ) ) {
    $series = (array) $wpdb->get_row(
        $wpdb->prepare(
            'SELECT es.session_id, es.term_id,
                    sess.title AS session_title,
                    trm.title AS term_title
             FROM ' . \EduCBTPro\Core\Schema::table( 'exam_series' ) . ' es
             LEFT JOIN ' . \EduCBTPro\Core\Schema::table( 'academic_sessions' ) . ' sess ON sess.id = es.session_id
             LEFT JOIN ' . \EduCBTPro\Core\Schema::table( 'terms' ) . ' trm ON trm.id = es.term_id
             WHERE es.id = %d AND es.school_id = %d',
            absint( $paper['series_id'] ),
            $school_id
        ),
        ARRAY_A
    );
    $exam_session = (string) ( $series['session_title'] ?? '' );
    $exam_term    = (string) ( $series['term_title'] ?? '' );
}

// Gender display: M -> Male, F -> Female
$gender_display = '';
if ( $candidate_gender !== '' ) {
    $g = strtolower( $candidate_gender );
    $gender_display = ( $g === 'm' || $g === 'male' ) ? 'Male' : ( ( $g === 'f' || $g === 'female' ) ? 'Female' : ucfirst( $candidate_gender ) );
}

$subject = (string) ( $paper['subject_name'] ?? '' );

if ( $subject === '' && ! empty( $paper['subject_id'] ) ) {
    $subject = (string) $wpdb->get_var(
        $wpdb->prepare(
            'SELECT name FROM ' . \EduCBTPro\Core\Schema::table( 'subjects_v2' ) . ' WHERE id = %d',
            absint( $paper['subject_id'] )
        )
    );
}

wp_enqueue_style( 'educbt-portal', EDUCBT_PRO_URL . 'assets/css/educbt-portal.css', [], (string) filemtime( EDUCBT_PRO_DIR . 'assets/css/educbt-portal.css' ) );
wp_enqueue_script( 'educbt-exam', EDUCBT_PRO_URL . 'assets/js/educbt-exam.js', [], (string) filemtime( EDUCBT_PRO_DIR . 'assets/js/educbt-exam.js' ), true );

wp_localize_script(
    'educbt-exam',
    'EduCBTExam',
    [
        'root'    => esc_url_raw( rest_url( 'educbt/v1/' ) ),
        'nonce'   => wp_create_nonce( 'wp_rest' ),
        'paperId' => $paper_id,
    ]
);
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo esc_html( $subject . ' — Examination' ); ?></title>
<?php wp_head(); ?>
</head>
<body class="educbt-portal educbt-exam">

<header class="exam-bar">
    <div class="exam-bar__subject"><?php echo esc_html( $subject ); ?></div>
    <div id="exam-timer" class="exam-timer">--:--</div>
    <div id="exam-progress" class="exam-bar__progress"></div>
</header>

<main class="exam-main">

    <?php if ( $blocked !== '' ) : ?>
        <section class="educbt-card educbt-card--narrow" style="margin:40px auto">
            <h2>Cannot open this paper</h2>
            <p class="educbt-note educbt-note--warn"><?php echo esc_html( $blocked ); ?></p>
            <p><a class="educbt-btn" href="<?php echo esc_url( home_url( '/portal/student/' ) ); ?>">Back to my dashboard</a></p>
        </section>
    <?php else : ?>

        <section id="exam-gate" class="educbt-card educbt-card--narrow" style="margin:40px auto">
            <div class="exam-candidate exam-candidate--gate">
                <?php if ( $candidate_photo !== '' ) : ?>
                    <img class="exam-candidate__photo exam-candidate__photo--circle" src="<?php echo esc_url( $candidate_photo ); ?>" alt="">
                <?php else : ?>
                    <div class="exam-candidate__photo exam-candidate__photo--circle exam-candidate__photo--blank" aria-hidden="true">
                        <?php echo esc_html( strtoupper( substr( $candidate_name, 0, 1 ) ) ); ?>
                    </div>
                <?php endif; ?>
                <div class="exam-candidate__id">
                    <strong><?php echo esc_html( $candidate_name ); ?></strong>
                    <code><?php echo esc_html( $candidate_id ); ?></code>
                </div>
            </div>
            <p class="educbt-muted" style="font-size:12.5px;margin:0 0 14px">
                If this is not you, do not start. Tell the invigilator.
            </p>

            <h2><?php echo esc_html( $subject ); ?></h2>
            <p class="educbt-muted">
                <?php echo esc_html( (int) ( $paper['question_count'] ?? 0 ) ); ?> questions ·
                <?php echo esc_html( (string) round( (int) ( $paper['duration_seconds'] ?? 0 ) / 60 ) ); ?> minutes
            </p>

            <ul class="educbt-muted" style="text-align:left;margin:16px 0;padding-left:18px">
                <li>Every answer is saved as you choose it.</li>
                <li>If your connection drops, sign back in and continue where you stopped.</li>
                <li>The paper submits itself when the time runs out.</li>
            </ul>

            <?php if ( ! empty( $paper['requires_access_code'] ) ) : ?>
                <label for="exam-code">Access code</label>
                <input id="exam-code" type="text" autocomplete="off" autocapitalize="characters"
                       style="width:100%;padding:12px;font-size:20px;text-align:center;letter-spacing:4px;text-transform:uppercase;border:1px solid var(--edu-line);border-radius:9px">
                <p class="educbt-muted" style="margin-top:6px">The invigilator will read this out.</p>
            <?php endif; ?>

            <p id="exam-gate-error" class="educbt-note educbt-note--warn" style="min-height:0;padding:0;background:none"></p>

            <button id="exam-start" class="educbt-btn educbt-btn--primary" style="width:100%;margin-top:10px">Start the paper</button>
        </section>

        <section id="exam-sitting" hidden>
            <div class="exam-layout">
                <div class="exam-question">
                    <p id="exam-number" class="educbt-muted"></p>
                    <div id="exam-passage" class="exam-passage" hidden></div>
                    <div id="exam-text" class="exam-text"></div>
                    <img id="exam-image" class="exam-image" alt="" hidden>
                    <div id="exam-options" class="exam-options"></div>

                    <div class="exam-nav">
                        <button id="exam-prev" class="educbt-btn" type="button">Previous</button>
                        <span id="exam-status" class="exam-status"></span>
                        <button id="exam-next" class="educbt-btn" type="button">Next</button>
                    </div>
                </div>

                <aside class="exam-side">
                    <div class="exam-candidate exam-candidate--full">
                        <?php if ( $candidate_photo !== '' ) : ?>
                            <img class="exam-candidate__photo exam-candidate__photo--circle" src="<?php echo esc_url( $candidate_photo ); ?>" alt="">
                        <?php else : ?>
                            <div class="exam-candidate__photo exam-candidate__photo--circle exam-candidate__photo--blank" aria-hidden="true">
                                <?php echo esc_html( strtoupper( substr( $candidate_name, 0, 1 ) ) ); ?>
                            </div>
                        <?php endif; ?>
                        <div class="exam-candidate__id">
                            <strong><?php echo esc_html( $candidate_name ); ?></strong>
                            <code><?php echo esc_html( $candidate_id ); ?></code>
                        </div>
                    </div>
                    <div class="exam-candidate__details">
                        <?php if ( $gender_display !== '' ) : ?>
                            <div class="exam-candidate__detail-row"><span class="exam-candidate__detail-label">Sex</span><span class="exam-candidate__detail-value"><?php echo esc_html( $gender_display ); ?></span></div>
                        <?php endif; ?>
                        <?php if ( $candidate_class !== '' ) : ?>
                            <div class="exam-candidate__detail-row"><span class="exam-candidate__detail-label">Class</span><span class="exam-candidate__detail-value"><?php echo esc_html( $candidate_class ); ?></span></div>
                        <?php endif; ?>
                        <div class="exam-candidate__detail-row"><span class="exam-candidate__detail-label">Subject</span><span class="exam-candidate__detail-value"><?php echo esc_html( $subject ); ?></span></div>
                        <?php if ( $exam_session !== '' ) : ?>
                            <div class="exam-candidate__detail-row"><span class="exam-candidate__detail-label">Session</span><span class="exam-candidate__detail-value"><?php echo esc_html( $exam_session ); ?></span></div>
                        <?php endif; ?>
                        <?php if ( $exam_term !== '' ) : ?>
                            <div class="exam-candidate__detail-row"><span class="exam-candidate__detail-label">Term</span><span class="exam-candidate__detail-value"><?php echo esc_html( $exam_term ); ?></span></div>
                        <?php endif; ?>
                    </div>

                    <p class="educbt-sidebar__label">Questions</p>
                    <div id="exam-grid" class="exam-grid"></div>
                    <button id="exam-submit" class="educbt-btn educbt-btn--primary" type="button" style="width:100%;margin-top:16px">Submit paper</button>
                </aside>
            </div>
        </section>

        <section id="exam-done" class="educbt-card educbt-card--narrow" style="margin:40px auto;text-align:center" hidden>
            <h2 id="exam-done-title"></h2>
            <p id="exam-done-body"></p>
            <p class="educbt-muted">
                Results are not shown here. The school releases them when marking and
                moderation are complete.
            </p>
            <p><a class="educbt-btn educbt-btn--primary" href="<?php echo esc_url( home_url( '/portal/student/' ) ); ?>">Back to my dashboard</a></p>
        </section>

    <?php endif; ?>
</main>

<?php wp_footer(); ?>
</body>
</html>
