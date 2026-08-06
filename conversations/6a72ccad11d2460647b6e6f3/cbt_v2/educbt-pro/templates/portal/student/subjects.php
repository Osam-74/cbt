<?php
/**
 * A student's registered subjects, and their elective choices.
 *
 * Core subjects are set by the school and cannot be dropped here — the teacher
 * decided them. Only electives are the student's to choose.
 *
 * @var array<string,mixed> $educbt
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

$school_id  = (int) $educbt['school_id'];
$actor      = $educbt['scope']->actor();
$student_id = (int) $actor['id'];

$session    = ( new \EduCBTPro\Services\AcademicYearService() )->current_session( $school_id );
$session_id = (int) ( $session['id'] ?? 0 );

$view = $session_id > 0
    ? ( new \EduCBTPro\Services\AcademicStructureService() )->student_registration_view( $school_id, $student_id, $session_id )
    : [ 'error' => 'no_session' ];

$educbt_title = 'My Subjects';

$educbt_body = static function () use ( $view ): void {
    if ( isset( $view['error'] ) ) {
        echo '<div class="educbt-card"><p class="educbt-muted">You are not enrolled in a class for the current session.</p></div>';
        return;
    }
    ?>
    <section class="educbt-card">
        <h2><?php echo esc_html( (string) $view['class'] ); ?></h2>
        <p class="educbt-muted">
            <?php echo esc_html( sprintf( '%d of %d–%d subjects registered.', (int) $view['selected_total'], (int) $view['minimum'], (int) $view['maximum'] ) ); ?>
            <?php if ( (int) $view['still_to_choose'] > 0 ) : ?>
                <strong>Choose <?php echo esc_html( (string) (int) $view['still_to_choose'] ); ?> more.</strong>
            <?php endif; ?>
        </p>
    </section>

    <section class="educbt-card">
        <h2>Compulsory</h2>
        <ul class="educbt-list">
        <?php foreach ( (array) $view['core'] as $s ) : ?>
            <li><span><?php echo esc_html( (string) $s['name'] ); ?></span><span class="educbt-muted">set by the school</span></li>
        <?php endforeach; ?>
        </ul>
    </section>

    <section class="educbt-card">
        <h2>Electives</h2>
        <?php if ( ! empty( $view['locked'] ) ) : ?>
            <p class="educbt-note">Subject registration is closed for this session.</p>
        <?php endif; ?>
        <ul class="educbt-list">
        <?php foreach ( (array) $view['electives'] as $s ) : ?>
            <li>
                <span><?php echo esc_html( (string) $s['name'] ); ?></span>
                <span class="<?php echo ! empty( $s['selected'] ) ? '' : 'educbt-muted'; ?>">
                    <?php echo ! empty( $s['selected'] ) ? 'Registered' : 'Not taken'; ?>
                </span>
            </li>
        <?php endforeach; ?>
        </ul>
        <p class="educbt-muted">To change these, speak to your class teacher.</p>
    </section>
    <?php
};

require EDUCBT_PRO_PATH . 'templates/portal/shell.php';
