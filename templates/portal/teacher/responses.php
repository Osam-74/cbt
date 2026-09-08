<?php
/**
 * Preview a student's exam responses — what they picked, what was correct.
 *
 * Reached via /portal/teacher/responses/{attempt_id} from the Subject Results page.
 * Only shows for CBT attempts (exams and CA tests). Theory questions show the
 * student's typed answer and the teacher's grade if already marked.
 *
 * @var array<string,mixed> $educbt
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

global $wpdb;

$school_id   = (int) $educbt['school_id'];
$attempt_id  = (int) ( $educbt['id'] ?? 0 );

$attempts_t      = \EduCBTPro\Core\Schema::table( 'attempts' );
$answers_t       = \EduCBTPro\Core\Schema::table( 'attempt_answers' );
$papers_t        = \EduCBTPro\Core\Schema::table( 'exam_papers' );
$subjects_t      = \EduCBTPro\Core\Schema::table( 'subjects_v2' );
$questions_t     = $wpdb->prefix . 'educbt_questions';
$options_t       = \EduCBTPro\Core\Schema::table( 'question_options' );
$students_t      = $wpdb->prefix . 'educbt_students';
$passages_t      = \EduCBTPro\Core\Schema::table( 'passages' );

// Load the attempt with student, paper, and subject info.
$attempt = (array) $wpdb->get_row(
    $wpdb->prepare(
        "SELECT at.id, at.paper_id, at.student_id, at.status, at.raw_score, at.max_score,
                at.percentage, at.submitted_at, at.question_order,
                CONCAT(st.first_name, ' ', st.last_name) AS student_name,
                st.admission_number,
                p.subject_id, p.scheduled_at, p.duration_seconds, p.is_practice,
                p.question_count, p.status AS paper_status,
                s.name AS subject_name
         FROM {$attempts_t} at
         INNER JOIN {$students_t} st ON st.id = at.student_id
         INNER JOIN {$papers_t} p ON p.id = at.paper_id
         INNER JOIN {$subjects_t} s ON s.id = p.subject_id
         WHERE at.id = %d AND at.school_id = %d",
        $attempt_id,
        $school_id
    ),
    ARRAY_A
);

if ( empty( $attempt ) ) {
    $educbt_title = 'Responses';
    $educbt_body  = static function (): void {
        echo '<div class="educbt-card"><p class="educbt-muted">Attempt not found.</p></div>';
    };
    require EDUCBT_PRO_PATH . 'templates/portal/shell.php';
    return;
}

// Parse the question order.
$order_raw = json_decode( (string) ( $attempt['question_order'] ?? '' ), true );
$order     = is_array( $order_raw ) ? array_map( 'absint', $order_raw ) : [];

if ( empty( $order ) ) {
    // Fall back to all questions for the paper.
    $order = array_map( 'absint',
        (array) $wpdb->get_col(
            $wpdb->prepare(
                "SELECT question_id FROM " . \EduCBTPro\Core\Schema::table( 'paper_questions' ) . " WHERE paper_id = %d",
                (int) $attempt['paper_id']
            )
        )
    );
}

// Load questions.
$placeholders = implode( ',', array_fill( 0, count( $order ), '%d' ) );

$questions = (array) $wpdb->get_results(
    $wpdb->prepare(
        "SELECT id, question_text, image_reference, question_type, marks, passage_id
         FROM {$questions_t} WHERE id IN ({$placeholders})",
        $order
    ),
    ARRAY_A
);
$by_id = [];
foreach ( $questions as $q ) {
    $by_id[ (int) $q['id'] ] = $q;
}

// Load options for all questions.
$option_rows = (array) $wpdb->get_results(
    $wpdb->prepare(
        "SELECT id, question_id, option_key, option_text, option_image, sort_order, is_correct
         FROM {$options_t} WHERE question_id IN ({$placeholders}) ORDER BY sort_order ASC",
        $order
    ),
    ARRAY_A
);
$options_by_q = [];
$correct_by_q = [];
foreach ( $option_rows as $opt ) {
    $qid = (int) $opt['question_id'];
    $options_by_q[ $qid ][] = $opt;
    if ( (int) $opt['is_correct'] === 1 ) {
        $correct_by_q[ $qid ][] = (int) $opt['id'];
    }
}

// Load the student's answers.
$student_answers = (array) $wpdb->get_results(
    $wpdb->prepare(
        "SELECT question_id, option_id, answer_text, is_correct, marks_awarded
         FROM {$answers_t} WHERE attempt_id = %d",
        $attempt_id
    ),
    ARRAY_A
);
$answers_by_q = [];
foreach ( $student_answers as $ans ) {
    $answers_by_q[ (int) $ans['question_id'] ] = $ans;
}

// Load passages.
$passage_ids = array_unique( array_filter( array_map( 'absint', array_column( $questions, 'passage_id' ) ) ) );
$passages = [];
if ( ! empty( $passage_ids ) ) {
    $ph_p = implode( ',', array_fill( 0, count( $passage_ids ), '%d' ) );
    $passage_rows = (array) $wpdb->get_results(
        $wpdb->prepare(
            "SELECT id, title, body FROM {$passages_t} WHERE id IN ({$ph_p})",
            $passage_ids
        ),
        ARRAY_A
    );
    foreach ( $passage_rows as $p ) {
        $passages[ (int) $p['id'] ] = $p;
    }
}

$is_ca   = (int) $attempt['is_practice'] === 1;
$type_lb = $is_ca ? 'CA Test' : 'Examination';

$educbt_title = 'Responses — ' . (string) $attempt['student_name'];

$educbt_body = static function () use (
    $attempt, $order, $by_id, $options_by_q, $correct_by_q, $answers_by_q,
    $passages, $type_lb
): void {
    $student_name = (string) $attempt['student_name'];
    $admission    = (string) $attempt['admission_number'];
    $subject_name = (string) $attempt['subject_name'];
    $raw_score    = (float) $attempt['raw_score'];
    $max_score    = (float) $attempt['max_score'];
    $percentage   = (float) $attempt['percentage'];
    $status       = (string) $attempt['status'];
    $submitted    = (string) $attempt['submitted_at'];
    $q_count      = (int) $attempt['question_count'];
    ?>
    <section class="educbt-card">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:12px">
            <div>
                <h2 style="margin-bottom:4px"><?php echo esc_html( $student_name ); ?></h2>
                <p class="educbt-muted" style="margin:0">
                    <?php echo esc_html( $admission . ' · ' . $subject_name . ' · ' . $type_lb ); ?>
                </p>
            </div>
            <div style="text-align:right">
                <div style="font-size:28px;font-weight:700;color:<?php echo $percentage >= 40 ? '#166534' : '#991b1b'; ?>">
                    <?php echo esc_html( (string) round( $percentage, 1 ) ); ?>%
                </div>
                <div class="educbt-muted" style="font-size:.85rem">
                    <?php echo esc_html( (string) $raw_score . ' / ' . (string) $max_score ); ?>
                    <?php if ( $status === 'graded' ) : ?>· Graded<?php elseif ( $status === 'submitted' ) : ?>· Awaiting grading<?php endif; ?>
                </div>
            </div>
        </div>
        <?php if ( $submitted && $submitted !== '0000-00-00 00:00:00' ) : ?>
            <p class="educbt-muted" style="margin:6px 0 0;font-size:.8rem">
                Submitted <?php echo esc_html( mysql2date( 'j M Y, g:ia', $submitted ) ); ?>
            </p>
        <?php endif; ?>
    </section>

    <section class="educbt-card">
        <h2>Responses (<?php echo esc_html( (string) count( $order ) ); ?> questions)</h2>

        <?php if ( empty( $order ) ) : ?>
            <p class="educbt-muted">No questions found for this attempt.</p>
        <?php else : ?>
            <?php
            $shown_passages = [];
            $q_num = 0;
            foreach ( $order as $qid ) :
                if ( ! isset( $by_id[ $qid ] ) ) { continue; }
                $q       = $by_id[ $qid ];
                $q_num++;
                $q_type  = (string) $q['question_type'];
                $q_text  = (string) $q['question_text'];
                $q_marks = (float) $q['marks'];
                $p_id    = (int) $q['passage_id'];
                $ans     = $answers_by_q[ $qid ] ?? null;
                $ans_opt = isset( $ans['option_id'] ) ? (int) $ans['option_id'] : 0;
                $ans_txt = (string) ( $ans['answer_text'] ?? '' );
                $is_right = isset( $ans['is_correct'] ) ? (int) $ans['is_correct'] : null;
                $awarded  = isset( $ans['marks_awarded'] ) ? (float) $ans['marks_awarded'] : null;
                $opts     = $options_by_q[ $qid ] ?? [];
                $corrects = $correct_by_q[ $qid ] ?? [];
                ?>

                <?php
                // Show passage if not already displayed.
                if ( $p_id > 0 && ! in_array( $p_id, $shown_passages, true ) && isset( $passages[ $p_id ] ) ) :
                    $shown_passages[] = $p_id;
                    $passage = $passages[ $p_id ];
                ?>
                    <div style="background:#f8faf5;border:1px solid #d4e4c4;border-radius:8px;padding:14px 18px;margin:18px 0 10px">
                        <h3 style="font-size:.95rem;margin:0 0 6px;color:#3F6B4A"><?php echo esc_html( (string) $passage['title'] ); ?></h3>
                        <div style="font-size:.85rem;line-height:1.6;color:#444"><?php echo wp_kses_post( wpautop( (string) $passage['body'] ) ); ?></div>
                    </div>
                <?php endif; ?>

                <div style="border-bottom:1px solid #eee;padding:16px 0">
                    <div style="display:flex;gap:8px;align-items:flex-start">
                        <span style="font-weight:700;color:#666;min-width:28px"><?php echo esc_html( (string) $q_num ); ?>.</span>
                        <div style="flex:1">
                            <p style="margin:0 0 10px;font-weight:500"><?php echo wp_kses_post( wp_strip_all_tags( $q_text ) ); ?></p>

                            <?php if ( ! empty( $q['image_reference'] ) ) : ?>
                                <img src="<?php echo esc_url( (string) $q['image_reference'] ); ?>" alt="" style="max-width:300px;border-radius:6px;margin-bottom:10px">
                            <?php endif; ?>

                            <?php if ( $q_type === 'theory' ) : ?>
                                <!-- Theory answer -->
                                <div style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:6px;padding:10px 14px;margin-bottom:8px">
                                    <div style="font-size:.75rem;color:#666;margin-bottom:4px">Student's answer:</div>
                                    <div style="white-space:pre-wrap;font-size:.9rem"><?php echo esc_html( $ans_txt ?: '(not answered)' ); ?></div>
                                </div>
                                <?php if ( $awarded !== null ) : ?>
                                    <div style="font-size:.8rem;color:#666">
                                        Awarded: <strong><?php echo esc_html( (string) $awarded ); ?></strong> / <?php echo esc_html( (string) $q_marks ); ?> marks
                                        <?php if ( $is_right === 1 ) : ?><span style="color:#166534">✓</span><?php endif; ?>
                                    </div>
                                <?php else : ?>
                                    <div style="font-size:.8rem;color:#b45309">Not yet graded</div>
                                <?php endif; ?>
                            <?php else : ?>
                                <!-- Objective question — show all options -->
                                <?php foreach ( $opts as $opt ) :
                                    $opt_id = (int) $opt['id'];
                                    $is_student_pick = $opt_id === $ans_opt;
                                    $is_correct_opt  = in_array( $opt_id, $corrects, true );
                                    $bg = '#f9fafb';
                                    $border = '1px solid #e5e7eb';
                                    $label = '';
                                    if ( $is_correct_opt ) {
                                        $bg = '#f0fdf4';
                                        $border = '1px solid #bbf7d0';
                                        $label = 'Correct';
                                    }
                                    if ( $is_student_pick && ! $is_correct_opt ) {
                                        $bg = '#fef2f2';
                                        $border = '1px solid #fecaca';
                                        $label = 'Student picked';
                                    }
                                    if ( $is_student_pick && $is_correct_opt ) {
                                        $bg = '#f0fdf4';
                                        $border = '1px solid #86efac';
                                        $label = 'Correct ✓';
                                    }
                                ?>
                                    <div style="background:<?php echo esc_attr( $bg ); ?>;border:<?php echo esc_attr( $border ); ?>;border-radius:6px;padding:8px 12px;margin:4px 0;display:flex;justify-content:space-between;align-items:center">
                                        <span style="font-size:.9rem">
                                            <strong><?php echo esc_html( (string) $opt['option_key'] ); ?>.</strong>
                                            <?php echo esc_html( (string) $opt['option_text'] ); ?>
                                        </span>
                                        <?php if ( $label !== '' ) : ?>
                                            <span style="font-size:.7rem;font-weight:600;color:#666"><?php echo esc_html( $label ); ?></span>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>

                                <?php if ( empty( $opts ) ) : ?>
                                    <p class="educbt-muted" style="font-size:.85rem">(no options on record)</p>
                                <?php endif; ?>

                                <?php if ( $ans_opt === 0 && empty( $ans_txt ) ) : ?>
                                    <p style="font-size:.8rem;color:#b45309;margin-top:6px">Student did not answer this question.</p>
                                <?php endif; ?>

                                <div style="font-size:.75rem;margin-top:6px;color:#666">
                                    <?php echo esc_html( (string) $q_marks . ' mark' . ( $q_marks != 1 ? 's' : '' ) ); ?>
                                    <?php if ( $is_right !== null ) : ?>
                                        · <?php if ( $is_right === 1 ) : ?><span style="color:#166534;font-weight:600">Correct</span><?php else : ?><span style="color:#991b1b;font-weight:600">Wrong</span><?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </section>

    <div style="margin-top:16px">
        <a class="educbt-btn" href="<?php echo esc_url( wp_get_referer() ?: home_url( '/portal/teacher/analysis/' ) ); ?>">← Back</a>
    </div>
    <?php
};

require EDUCBT_PRO_PATH . 'templates/portal/shell.php';
