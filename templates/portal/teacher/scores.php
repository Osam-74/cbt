<?php
/**
 * Score entry — one class, one subject, one component, all students on a screen.
 *
 * An empty box means "not marked yet", which is NOT zero. Leaving it blank skips
 * the student rather than failing them.
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

$assessment = new \EduCBTPro\Services\AssessmentService();
$components = $assessment->components( $school_id );

// The examination is run and marked by the school — objectives automatically, theory
// through the marking screen. Offering it here invited a teacher to type over a mark
// the CBT had already produced.
if ( ! $educbt['scope']->is_school_wide() ) {
    $components = array_values(
        array_filter( $components, static fn( array $c ): bool => empty( $c['is_exam'] ) )
    );
}

$classes_table  = \EduCBTPro\Core\Schema::table( 'classes' );
$subjects_table = \EduCBTPro\Core\Schema::table( 'subjects_v2' );
$assign_table   = \EduCBTPro\Core\Schema::table( 'staff_assignments' );

$actor = $educbt['scope']->actor();

// Only the (subject, class) pairs this teacher actually holds. School management
// sees everything; a subject teacher sees their own pairs and nothing else.
if ( $educbt['scope']->is_school_wide() ) {
    $pairs = (array) $wpdb->get_results(
        $wpdb->prepare(
            "SELECT DISTINCT c.id AS class_id, c.display_name, s.id AS subject_id, s.name AS subject_name
             FROM {$classes_table} c CROSS JOIN {$subjects_table} s
             WHERE c.school_id = %d AND c.status = 'active' AND s.school_id = %d AND s.status = 'active'
             ORDER BY c.display_name ASC, s.name ASC",
            $school_id,
            $school_id
        ),
        ARRAY_A
    );
} else {
    $pairs = (array) $wpdb->get_results(
        $wpdb->prepare(
            "SELECT DISTINCT c.id AS class_id, c.display_name, s.id AS subject_id, s.name AS subject_name
             FROM {$assign_table} a
             INNER JOIN {$classes_table} c ON c.id = a.class_id
             INNER JOIN {$subjects_table} s ON s.id = a.subject_id
             WHERE a.school_id = %d AND a.staff_id = %d AND a.assignment_type = 'subject_teacher'
               AND a.status = 'active'
             ORDER BY c.display_name ASC, s.name ASC",
            $school_id,
            (int) $actor['id']
        ),
        ARRAY_A
    );
}

$class_id     = (int) ( $_GET['class'] ?? 0 );
$subject_id   = (int) ( $_GET['subject'] ?? 0 );
$component_id = (int) ( $_GET['component'] ?? ( $components[0]['id'] ?? 0 ) );

$sheet     = [];
$component = null;

foreach ( $components as $c ) {
    if ( (int) $c['id'] === $component_id ) { $component = $c; }
}

if ( $class_id > 0 && $subject_id > 0 && $component_id > 0 && $term_id > 0 ) {
    $sheet = $assessment->entry_sheet(
        $school_id,
        $component_id,
        [ 'subject_id' => $subject_id, 'class_id' => $class_id, 'session_id' => $session_id, 'term_id' => $term_id ]
    );
}

$educbt_title = 'Record Scores';

$educbt_body = static function () use ( $flash, $pairs, $components, $sheet, $class_id, $subject_id, $component_id, $component, $session_id, $term_id, $term, $session ): void {
    require EDUCBT_PRO_PATH . 'templates/portal/partials/flash.php';

    if ( $term_id === 0 ) {
        echo '<div class="educbt-card"><p class="educbt-note educbt-note--warn">No current term is set, so scores cannot be recorded.</p></div>';
        return;
    }

    if ( empty( $pairs ) ) {
        echo '<div class="educbt-card"><p class="educbt-note educbt-note--warn">You have not been assigned any subject to teach yet. The school office assigns these under Staff.</p></div>';
        return;
    }
    ?>
    <section class="educbt-card">
        <h2>Choose what to mark</h2>
        <p class="educbt-muted" style="margin-top:-6px">
            Continuous assessment only. Examination marks come from the CBT.
        </p>
        <p class="educbt-muted" style="margin-top:-6px">
            <?php echo esc_html( (string) ( $session['title'] ?? '' ) . ' · ' . (string) ( $term['title'] ?? '' ) ); ?>
        </p>

        <form method="get" class="educbt-form">
            <div class="educbt-grid">
                <div>
                    <label for="pair">Class and subject</label>
                    <select id="pair" name="pair" onchange="var v=this.value.split(':');document.getElementById('f_class').value=v[0];document.getElementById('f_subject').value=v[1];this.form.submit();">
                        <option value=":">Choose</option>
                        <?php foreach ( $pairs as $p ) : ?>
                            <option value="<?php echo esc_attr( $p['class_id'] . ':' . $p['subject_id'] ); ?>"
                                <?php selected( (int) $p['class_id'] === $class_id && (int) $p['subject_id'] === $subject_id ); ?>>
                                <?php echo esc_html( $p['display_name'] . ' — ' . $p['subject_name'] ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="component">Assessment</label>
                    <select id="component" name="component" onchange="this.form.submit()">
                        <?php foreach ( $components as $c ) : ?>
                            <option value="<?php echo esc_attr( (string) $c['id'] ); ?>" <?php selected( (int) $c['id'], $component_id ); ?>>
                                <?php echo esc_html( $c['name'] . ' (max ' . (float) $c['max_score'] . ')' ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <input type="hidden" name="class" id="f_class" value="<?php echo esc_attr( (string) $class_id ); ?>">
            <input type="hidden" name="subject" id="f_subject" value="<?php echo esc_attr( (string) $subject_id ); ?>">
            <noscript><button class="educbt-btn" type="submit">Load</button></noscript>
        </form>
    </section>

    <?php if ( ! empty( $sheet ) && $component ) : ?>
    <section class="educbt-card">
        <h2><?php echo esc_html( (string) $component['name'] ); ?> <span class="educbt-muted">— max <?php echo esc_html( (string) (float) $component['max_score'] ); ?></span></h2>

        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="educbt-form">
            <input type="hidden" name="action" value="educbt_save_scores">
            <input type="hidden" name="subject_id" value="<?php echo esc_attr( (string) $subject_id ); ?>">
            <input type="hidden" name="class_id" value="<?php echo esc_attr( (string) $class_id ); ?>">
            <input type="hidden" name="component_id" value="<?php echo esc_attr( (string) $component_id ); ?>">
            <input type="hidden" name="session_id" value="<?php echo esc_attr( (string) $session_id ); ?>">
            <input type="hidden" name="term_id" value="<?php echo esc_attr( (string) $term_id ); ?>">
            <?php wp_nonce_field( 'educbt_save_scores' ); ?>

            <table class="educbt-table">
                <thead><tr><th>Adm. no.</th><th>Student</th><th style="width:120px">Score</th><th>Source</th></tr></thead>
                <tbody>
                <?php foreach ( $sheet as $row ) : ?>
                    <tr>
                        <td><code><?php echo esc_html( (string) $row['admission_number'] ); ?></code></td>
                        <td><?php echo esc_html( $row['last_name'] . ', ' . $row['first_name'] ); ?></td>
                        <td>
                            <input type="number" step="0.5" min="0" max="<?php echo esc_attr( (string) (float) $component['max_score'] ); ?>"
                                   name="score[<?php echo esc_attr( (string) $row['student_id'] ); ?>]"
                                   value="<?php echo $row['score'] === null ? '' : esc_attr( (string) (float) $row['score'] ); ?>"
                                   style="padding:7px">
                        </td>
                        <td>
                            <?php if ( (string) ( $row['source'] ?? '' ) === 'cbt' ) : ?>
                                <span class="educbt-pill">from CBT</span>
                            <?php elseif ( $row['score'] !== null ) : ?>
                                <span class="educbt-muted">entered</span>
                            <?php else : ?>
                                <span class="educbt-muted">not marked</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <p class="educbt-muted" style="margin-top:10px">
                Leave a box empty for a student you have not marked yet — that is different from a zero.
            </p>

            <button type="submit" class="educbt-btn educbt-btn--primary" style="margin-top:8px">Save scores</button>
        </form>
    </section>
    <?php elseif ( $class_id > 0 && $subject_id > 0 ) : ?>
        <div class="educbt-card"><p class="educbt-muted">No students are registered for that subject in that class.</p></div>
    <?php endif; ?>
    <?php
};

require EDUCBT_PRO_PATH . 'templates/portal/shell.php';
