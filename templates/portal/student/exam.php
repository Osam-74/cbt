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

$blocked = '';

if ( ! $gate['allowed'] && $gate['reason'] !== 'invalid_access_code' ) {
    $blocked = [
        'too_early'              => 'This paper has not opened yet. Please wait for the scheduled time.',
        'window_closed'          => 'This test has been closed. If you think this is an error, please contact your teacher.',
        'already_submitted'      => 'You have already submitted this paper.',
        'not_in_this_class'      => 'This paper is not set for your class.',
        'subject_not_registered' => 'You are not registered for this subject.',
        'paper_not_published'    => 'This test is not currently available. Please check with your teacher.',
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

$candidate_name   = trim( (string) ( $candidate['first_name'] ?? '' ) . ' ' . (string) ( $candidate['last_name'] ?? '' ) );
$candidate_id     = (string) ( $candidate['admission_number'] ?? '' );
$candidate_photo  = (string) ( $candidate['passport_photo'] ?? '' );
$candidate_gender = (string) ( $candidate['gender'] ?? '' );
$candidate_class  = (string) ( $candidate['class'] ?? '' );

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

$exam_session = '';
$exam_term    = '';
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

$is_practice = ! empty( $paper['is_practice'] );

wp_enqueue_style( 'educbt-portal', EDUCBT_PRO_URL . 'assets/css/educbt-portal.css', [], (string) filemtime( EDUCBT_PRO_DIR . 'assets/css/educbt-portal.css' ) );
wp_enqueue_script( 'educbt-exam', EDUCBT_PRO_URL . 'assets/js/educbt-exam.js', [], (string) filemtime( EDUCBT_PRO_DIR . 'assets/js/educbt-exam.js' ), true );

wp_localize_script(
    'educbt-exam',
    'EduCBTExam',
    [
        'root'    => esc_url_raw( rest_url( 'educbt/v1/' ) ),
        'nonce'   => wp_create_nonce( 'wp_rest' ),
        'paperId'             => $paper_id,
        'requiresAccessCode'  => ! empty( $paper['requires_access_code'] ),
        'theoryMin'           => 0,
    ]
);
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo esc_html( $subject . ' — Examination' ); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<?php wp_head(); ?>
<style>
:root{
    --forest:#173D26; --forest-dark:#0E2718; --moss:#3F6B4A; --sage:#7C9473;
    --lemon:#D3E64B; --lemon-deep:#A9C21E; --lemon-soft:#F3F7DC;
    --cream:#FBFBF5; --ink:#152018; --muted:#63715F; --line:#E4E8DA;
    --white:#FFFFFF; --danger:#C4562B;
    --radius-lg:20px; --radius-md:14px; --radius-sm:10px;
    --shadow-sm:0 1px 3px rgba(20,40,25,.06);
    --shadow-md:0 8px 24px rgba(15,40,25,.09);
    --shadow-lg:0 20px 48px rgba(15,40,25,.14);
}
*{box-sizing:border-box;margin:0;padding:0;}
[hidden]{display:none!important;}
body.educbt-portal.educbt-exam{
    font-family:'Inter',-apple-system,sans-serif;
    background:var(--cream); color:var(--ink);
    -webkit-font-smoothing:antialiased; min-height:100vh;
}
h1,h2,h3{font-family:'Space Grotesk',sans-serif;letter-spacing:-0.01em;}
a{color:inherit;text-decoration:none;}
::selection{background:var(--lemon);color:var(--forest-dark);}
.exam-bar{
    display:flex;align-items:center;justify-content:space-between;
    padding:16px 32px;background:var(--white);
    border-bottom:1px solid var(--line);position:sticky;top:0;z-index:20;
    box-shadow:var(--shadow-sm);
}
.exam-bar__subject{font-family:'Space Grotesk',sans-serif;font-weight:700;font-size:18px;color:var(--forest-dark);}
.exam-timer{
    font-family:'Space Grotesk',sans-serif;font-weight:700;font-size:24px;
    color:var(--forest-dark);padding:8px 20px;background:var(--lemon-soft);
    border:1px solid var(--line);border-radius:999px;min-width:120px;text-align:center;
    transition:background .2s,color .2s;
}
.exam-timer.is-low{background:var(--danger);color:var(--white);border-color:var(--danger);animation:blink 1s ease infinite;}
@keyframes blink{50%{opacity:.5;}}
.exam-bar__progress{font-size:13px;color:var(--muted);font-weight:500;min-width:160px;text-align:right;}
.exam-main{max-width:1180px;margin:0 auto;padding:32px 24px 60px;}
.educbt-card{background:var(--white);border:1px solid var(--line);border-radius:var(--radius-lg);box-shadow:var(--shadow-md);padding:36px;}
.educbt-card--narrow{max-width:480px;}
.educbt-card h2{font-size:22px;color:var(--forest-dark);margin-bottom:14px;}
.educbt-card p{line-height:1.6;}
.educbt-muted{color:var(--muted);font-size:14px;line-height:1.6;}
.educbt-note{padding:12px 16px;border-radius:var(--radius-sm);font-size:14px;margin-bottom:14px;}
.educbt-note--warn{background:#FEF3C7;color:#92400E;}
.educbt-btn{
    display:inline-flex;align-items:center;justify-content:center;gap:8px;
    border-radius:999px;font-weight:600;font-size:14.5px;padding:12px 22px;
    border:1.5px solid var(--line);background:var(--white);color:var(--forest-dark);
    transition:transform .15s,box-shadow .15s,background .15s,border-color .15s;
    text-decoration:none;cursor:pointer;font-family:inherit;
}
.educbt-btn:hover{border-color:var(--lemon-deep);background:var(--lemon-soft);}
.educbt-btn--primary{background:var(--forest);color:var(--white);border-color:var(--forest);box-shadow:var(--shadow-sm);}
.educbt-btn--primary:hover{background:var(--forest-dark);border-color:var(--forest-dark);box-shadow:var(--shadow-md);transform:translateY(-1px);}
.exam-candidate{display:flex;flex-direction:column;align-items:center;text-align:center;}
.exam-candidate--gate{margin-bottom:20px;}
.exam-candidate--full{margin-bottom:16px;}
.exam-candidate__photo{
    width:56px;height:56px;border-radius:50%;background:var(--forest);color:var(--white);
    display:flex;align-items:center;justify-content:center;
    font-family:'Space Grotesk',sans-serif;font-weight:700;font-size:22px;
    margin-bottom:12px;overflow:hidden;flex:none;
}
.exam-candidate__photo--circle{border-radius:50%;}
.exam-candidate__photo--blank{background:var(--forest);color:var(--white);}
.exam-candidate__photo img{width:100%;height:100%;object-fit:cover;}
.exam-candidate__id{text-align:center;}
.exam-candidate__id strong{display:block;font-size:15px;color:var(--forest-dark);font-weight:600;}
.exam-candidate__id code{font-size:13px;color:var(--muted);font-family:'Space Grotesk',monospace;}
.exam-candidate__details{display:flex;flex-direction:column;gap:8px;padding:16px 0;border-top:1px solid var(--line);border-bottom:1px solid var(--line);margin-bottom:16px;}
.exam-candidate__detail-row{display:flex;justify-content:space-between;font-size:13px;}
.exam-candidate__detail-label{color:var(--muted);}
.exam-candidate__detail-value{font-weight:600;color:var(--forest-dark);}
#exam-code{width:100%;padding:14px;font-size:20px;text-align:center;letter-spacing:4px;text-transform:uppercase;border:1.5px solid var(--line);border-radius:var(--radius-sm);background:var(--white);font-family:'Space Grotesk',monospace;}
#exam-code:focus{border-color:var(--forest);outline:none;box-shadow:0 0 0 3px rgba(23,61,38,.08);}
label[for="exam-code"]{font-size:13px;font-weight:600;color:var(--forest-dark);display:block;margin-bottom:8px;}
.exam-layout{display:grid;grid-template-columns:1fr 300px;gap:40px;}
@media(max-width:900px){.exam-layout{grid-template-columns:1fr;}}
.exam-question{min-width:0;}
#exam-number{display:inline-block;background:var(--forest-dark);color:var(--white);font-size:12.5px;font-weight:600;padding:6px 14px;border-radius:999px;margin-bottom:18px;}
.exam-passage{padding:24px;background:var(--lemon-soft);border:1px solid var(--line);border-radius:var(--radius-md);margin-bottom:24px;font-size:15px;line-height:1.8;color:var(--ink);font-style:italic;}
.exam-passage h3{font-size:14px;color:var(--forest-dark);margin-bottom:10px;font-style:normal;}
.exam-passage img{max-width:100%;border-radius:var(--radius-sm);margin:12px 0;}
.exam-text{font-size:19px;line-height:1.5;color:var(--ink);font-weight:500;margin-bottom:24px;}
.exam-text u{text-decoration-color:var(--lemon-deep);text-decoration-thickness:2.5px;}
.exam-image{max-width:100%;border-radius:var(--radius-md);margin-bottom:24px;}
.exam-options{display:flex;flex-direction:column;gap:11px;}
/* Essay alternatives. The candidate answers one, so the unchosen topics fade
   rather than disappear — they can still be read before committing. */
.exam-essay__lead{font-size:14px;color:var(--muted);margin:0 0 12px;}
.exam-essay__topic{border:1.5px solid var(--line);border-radius:var(--radius-sm);padding:14px 16px;margin-bottom:11px;background:var(--white);transition:border-color .15s,opacity .15s;}
.exam-essay__topic.is-chosen{border-color:var(--forest);background:var(--lemon-soft);}
.exam-essay__topic.is-dimmed{opacity:.55;}
.exam-essay__head{display:flex;gap:12px;align-items:flex-start;}
.exam-essay__num{flex:0 0 26px;height:26px;border-radius:50%;background:var(--lemon-soft);color:var(--forest);display:flex;align-items:center;justify-content:center;font-weight:700;font-size:13px;}
.exam-essay__text{flex:1;line-height:1.6;}
.exam-essay__pick{margin-top:10px;border:0;border-radius:8px;padding:8px 16px;font-weight:600;font-size:13.5px;cursor:pointer;background:var(--forest);color:#fff;}
.exam-essay__pick:disabled{background:var(--lemon-soft);color:var(--forest);cursor:default;}
/* Short options (synonyms, rhymes, cloze gaps) sit two-up so the whole set is
   visible without scrolling. Falls back to one column on a narrow phone, where two
   columns would be worse than none. */
.exam-options--grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:11px;}
@media (max-width:520px){.exam-options--grid{grid-template-columns:1fr;}}
.exam-option{display:flex;align-items:center;gap:14px;border:1.5px solid var(--line);border-radius:var(--radius-sm);padding:14px 16px;background:var(--white);transition:border-color .15s,background .15s,transform .1s;cursor:pointer;user-select:none;}
.exam-option:hover{border-color:var(--sage);background:var(--lemon-soft);}
.exam-option.is-chosen{border-color:var(--forest);background:var(--lemon-soft);}
.exam-option input{display:none;}
.exam-option__key{width:28px;height:28px;border-radius:50%;flex:none;border:1.5px solid var(--line);background:var(--white);display:flex;align-items:center;justify-content:center;font-weight:700;font-size:12.5px;color:var(--muted);transition:background .15s,border-color .15s,color .15s;}
.exam-option.is-chosen .exam-option__key{background:var(--forest);border-color:var(--forest);color:var(--white);}
.exam-option__body{font-size:15px;color:var(--ink);flex:1;line-height:1.5;}
.exam-option__body img{max-width:100%;border-radius:var(--radius-sm);}
.exam-written{width:100%;min-height:200px;padding:16px;border:1.5px solid var(--line);border-radius:var(--radius-sm);background:var(--white);font-family:'Inter',sans-serif;font-size:15px;line-height:1.6;color:var(--ink);resize:vertical;}
.exam-written:focus{border-color:var(--forest);outline:none;box-shadow:0 0 0 3px rgba(23,61,38,.08);}
.exam-nav{display:flex;justify-content:space-between;align-items:center;margin-top:28px;padding-top:24px;border-top:1px solid var(--line);}
#exam-prev,#exam-next{display:inline-flex;align-items:center;gap:6px;border-radius:999px;font-weight:600;font-size:14px;padding:10px 20px;border:1.5px solid var(--line);background:var(--white);color:var(--forest-dark);cursor:pointer;font-family:inherit;transition:transform .15s,box-shadow .15s,background .15s,border-color .15s;}
#exam-prev:hover,#exam-next:hover{border-color:var(--lemon-deep);background:var(--lemon-soft);}
#exam-prev:disabled,#exam-next:disabled{opacity:.35;cursor:not-allowed;transform:none;}
.exam-status{font-size:13px;color:var(--muted);padding:6px 14px;border-radius:999px;transition:background .15s,color .15s;}
.exam-status.is-busy{background:var(--lemon-soft);color:var(--moss);}
.exam-status.is-ok{background:rgba(63,107,74,.1);color:var(--moss);}
.exam-status.is-warn{background:#FEF3C7;color:#92400E;}
.exam-status.is-error{background:rgba(196,86,43,.1);color:var(--danger);}
.educbt-sidebar__label{font-size:13px;font-weight:700;color:var(--forest-dark);margin-bottom:12px;text-transform:uppercase;letter-spacing:.05em;}
.exam-grid{display:grid;grid-template-columns:repeat(5,1fr);gap:8px;margin-bottom:20px;}
.exam-grid__cell{aspect-ratio:1;border-radius:50%;border:1.5px solid var(--line);background:var(--white);font-weight:700;font-size:13px;color:var(--muted);display:flex;align-items:center;justify-content:center;cursor:pointer;font-family:inherit;transition:transform .12s,border-color .15s,background .15s,color .15s;}
.exam-grid__cell.is-done{background:var(--forest);border-color:var(--forest);color:var(--white);}
.exam-grid__cell.is-current{border-color:var(--moss);box-shadow:0 0 0 3px rgba(63,107,74,.15);}
.exam-grid__cell.is-done.is-current{background:var(--moss);border-color:var(--moss);}
.exam-grid__cell:hover{transform:scale(1.08);}
.exam-grid__cell.is-flagged{background:#fef3c7;border-color:#f59e0b;color:#92400e;}
.exam-grid__cell.is-flagged.is-done{background:#f59e0b;border-color:#f59e0b;color:#fff;}
.educbt-btn--flag{background:transparent;border:1.5px solid var(--line);color:var(--muted);font-size:13px;padding:8px 14px;border-radius:999px;cursor:pointer;font-family:inherit;transition:all .15s;}
.educbt-btn--flag:hover{border-color:#f59e0b;color:#92400e;background:#fffbeb;}
.educbt-btn--flag.is-flagged{background:#fef3c7;border-color:#f59e0b;color:#92400e;font-weight:600;}
.educbt-btn--flag.is-flagged:hover{background:#fde68a;}
#exam-submit{width:100%;padding:12px;border:none;border-radius:999px;background:var(--lemon);color:var(--forest-dark);font-weight:700;font-size:15px;cursor:pointer;font-family:inherit;transition:background .15s,transform .15s;}
#exam-submit:hover{background:var(--lemon-deep);transform:translateY(-1px);}
#exam-done{text-align:center;}
#exam-done h2{margin-bottom:12px;}
#exam-done p{margin-bottom:8px;}
.educbt-card a.educbt-btn{margin-top:16px;}
@media(max-width:900px){
    .exam-bar{padding:14px 16px;flex-wrap:wrap;gap:10px;}
    .exam-bar__progress{width:100%;text-align:center;order:3;}
    .exam-main{padding:20px 16px 40px;}
    .educbt-card--narrow{margin:20px auto;}
    .exam-layout{gap:24px;}
}
@media(max-width:520px){
    .exam-bar__subject{font-size:15px;}
    .exam-timer{font-size:18px;padding:6px 14px;min-width:90px;}
    .exam-text{font-size:17px;}
    .exam-grid{grid-template-columns:repeat(4,1fr);}
}
/* ── Objective / Theory toggle ── */
.exam-toggle{display:flex;gap:6px;margin-bottom:16px;}
.exam-toggle__btn{
    flex:1;padding:10px 14px;border:1.5px solid var(--line);border-radius:999px;
    font-family:'Space Grotesk',sans-serif;font-weight:600;font-size:13px;
    background:var(--white);color:var(--muted);cursor:pointer;transition:all .18s;
    text-align:center;
}
.exam-toggle__btn:hover{border-color:var(--lemon-deep);background:var(--lemon-soft);}
.exam-toggle__btn.is-active{
    background:var(--forest);color:var(--white);border-color:var(--forest);
    box-shadow:var(--shadow-sm);
}
.exam-toggle__count{
    display:inline-block;font-size:11px;opacity:.7;margin-left:4px;
}
/* ── Theory sub-question parts ── */
.exam-theory-part{
    border:1px solid var(--line);border-radius:var(--radius-md);
    padding:20px;margin-bottom:16px;background:var(--cream);
}
.exam-theory-part__label{
    font-family:'Space Grotesk',sans-serif;font-weight:700;font-size:16px;
    color:var(--forest-dark);margin-bottom:8px;
}
.exam-theory-part__text{line-height:1.6;margin-bottom:10px;color:var(--ink);}
.exam-grid__cell.is-partial{
    background:var(--lemon-soft);border-color:var(--lemon-deep);color:var(--forest-dark);
}
.exam-grid__cell.is-locked{
    background:var(--ep-surface-3,#e9ecef);border-color:var(--line);color:var(--dim,#adb5bd);
    cursor:not-allowed;opacity:.45;
}
.exam-grid__cell.is-locked:hover{transform:none;}
.exam-written:disabled{
    background:var(--surface,#f8f9fa);color:var(--muted,#6c757d);cursor:not-allowed;
    border-style:dashed;opacity:.6;
}
.exam-theory-part.is-locked{
    background:var(--surface,#f8f9fa);opacity:.55;
}
.exam-lock-notice{
    background:#fef3c7;border:1px solid #f59e0b;border-radius:var(--radius-sm,8px);
    padding:12px 16px;margin-bottom:16px;font-size:13px;color:#92400e;
    display:flex;align-items:center;gap:8px;
}
.exam-lock-notice__icon{font-size:16px;}

/* Integrity warning banner shown when student right-clicks or switches tabs */
.exam-integrity-warning{
    position:fixed;top:0;left:0;right:0;z-index:99999;
    background:#dc2626;color:#fff;padding:10px 20px;
    font-size:14px;font-weight:600;text-align:center;
    box-shadow:0 2px 8px rgba(0,0,0,.2);
    animation:educbt-fade .2s ease;
}
</style>
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
                    <div class="exam-candidate__photo exam-candidate__photo--circle">
                        <img src="<?php echo esc_url( $candidate_photo ); ?>" alt="">
                    </div>
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
            <p class="educbt-muted" style="font-size:12.5px;margin:0 0 14px;text-align:center">
                If this is not you, do not start. Tell the invigilator.
            </p>

            <h2 style="text-align:center"><?php echo esc_html( $subject ); ?></h2>
            <p class="educbt-muted" style="text-align:center">
                <?php echo esc_html( (int) ( $paper['question_count'] ?? 0 ) ); ?> questions ·
                <?php echo esc_html( (string) round( (int) ( $paper['duration_seconds'] ?? 0 ) / 60 ) ); ?> minutes
                <?php if ( $is_practice ) : ?>
                    <br><span style="font-size:12px;color:var(--moss);font-weight:600">Class Test</span>
                <?php endif; ?>
            </p>

            <ul class="educbt-muted" style="text-align:left;margin:16px 0;padding-left:18px">
                <li>Every answer is saved as you choose it.</li>
                <li>If your connection drops, sign back in and continue where you stopped.</li>
                <li>The paper submits itself when the time runs out.</li>
            </ul>

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
                        <button id="exam-flag" class="educbt-btn educbt-btn--flag" type="button" title="Flag this question to review later">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right:4px;vertical-align:middle"><path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"/><line x1="4" y1="22" x2="4" y2="15"/></svg>
                            <span id="exam-flag-text">Flag</span>
                        </button>
                        <span id="exam-status" class="exam-status"></span>
                        <button id="exam-next" class="educbt-btn" type="button">Next</button>
                    </div>
                </div>

                <aside class="exam-side">
                    <div class="educbt-card" style="padding:24px">
                        <div class="exam-candidate exam-candidate--full">
                            <?php if ( $candidate_photo !== '' ) : ?>
                                <div class="exam-candidate__photo exam-candidate__photo--circle">
                                    <img src="<?php echo esc_url( $candidate_photo ); ?>" alt="">
                                </div>
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
                            <?php if ( $is_practice ) : ?>
                                <div class="exam-candidate__detail-row"><span class="exam-candidate__detail-label">Mode</span><span class="exam-candidate__detail-value">Class Test</span></div>
                            <?php endif; ?>
                            <?php if ( $exam_session !== '' ) : ?>
                                <div class="exam-candidate__detail-row"><span class="exam-candidate__detail-label">Session</span><span class="exam-candidate__detail-value"><?php echo esc_html( $exam_session ); ?></span></div>
                            <?php endif; ?>
                            <?php if ( $exam_term !== '' ) : ?>
                                <div class="exam-candidate__detail-row"><span class="exam-candidate__detail-label">Term</span><span class="exam-candidate__detail-value"><?php echo esc_html( $exam_term ); ?></span></div>
                            <?php endif; ?>
                        </div>

                        <div class="exam-toggle">
                            <button id="exam-toggle-objective" class="exam-toggle__btn is-active" type="button">Objective</button>
                            <button id="exam-toggle-theory" class="exam-toggle__btn" type="button">Theory</button>
                        </div>
                        <p class="educbt-sidebar__label">Questions</p>
                        <div id="exam-grid" class="exam-grid"></div>
                        <button id="exam-submit" class="educbt-btn educbt-btn--primary" type="button">Submit paper</button>
                    </div>
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
