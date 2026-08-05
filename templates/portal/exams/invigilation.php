<?php
/**
 * Building and maintaining the invigilation schedule.
 *
 * Nothing exists until someone builds it, so this leads with that rather than
 * presenting an empty table and leaving the exam officer to work out what to do.
 *
 * @var array<string,mixed> $educbt
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

global $wpdb;

$school_id = (int) $educbt['school_id'];
$flash     = \EduCBTPro\Frontend\PortalActions::flash();

$service = new \EduCBTPro\Services\InvigilationScheduleService();

$series = (array) $wpdb->get_results(
    $wpdb->prepare(
        'SELECT id, title FROM ' . \EduCBTPro\Core\Schema::table( 'exam_series' ) . ' WHERE school_id = %d ORDER BY id DESC',
        $school_id
    ),
    ARRAY_A
);

$series_id = (int) ( $_GET['series'] ?? ( $series[0]['id'] ?? 0 ) );

$papers = $series_id > 0 ? $service->papers( $school_id, $series_id ) : [];
$staff  = $service->available_staff( $school_id );
$drift  = $series_id > 0 ? $service->drift( $school_id, $series_id ) : [];

$assigned = count( array_filter( $papers, static fn( array $p ): bool => ! empty( $p['invigilator_id'] ) ) );

$educbt_title = 'Invigilation Schedule';

$educbt_body = static function () use ( $flash, $series, $series_id, $papers, $staff, $drift, $assigned ): void {
    require EDUCBT_PRO_PATH . 'templates/portal/partials/flash.php';

    if ( empty( $series ) ) {
        echo '<div class="educbt-card"><p class="educbt-note educbt-note--warn">No examination has been created yet. Create one under Papers first — the schedule is built from its timetable.</p></div>';
        return;
    }
    ?>
    <section class="educbt-card no-print">
        <form method="get" class="educbt-form" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap">
            <div style="flex:1 1 260px">
                <label for="series">Examination</label>
                <select id="series" name="series" onchange="this.form.submit()">
                    <?php foreach ( $series as $s ) : ?>
                        <option value="<?php echo esc_attr( (string) $s['id'] ); ?>" <?php selected( (int) $s['id'], $series_id ); ?>>
                            <?php echo esc_html( (string) $s['title'] ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="button" class="educbt-btn" onclick="window.print()">Print</button>
        </form>
    </section>

    <?php if ( empty( $papers ) ) : ?>
        <div class="educbt-card">
            <p class="educbt-note educbt-note--warn">This examination has no papers scheduled, so there is nothing to invigilate yet.</p>
            <p><a class="educbt-btn educbt-btn--primary" href="<?php echo esc_url( home_url( '/portal/exams/papers/' ) ); ?>">Schedule papers</a></p>
        </div>
        <?php return; ?>
    <?php endif; ?>

    <?php if ( $assigned === 0 ) : ?>
        <section class="educbt-card" style="border-left:4px solid var(--edu-accent)">
            <h2>No invigilation schedule yet</h2>
            <p class="educbt-muted" style="margin-top:-6px">
                <?php echo esc_html( sprintf( '%d paper(s) are scheduled and none has an invigilator.', count( $papers ) ) ); ?>
                Building one assigns everybody automatically, spreading the load and keeping
                a teacher away from their own subject. You can change any of it afterwards.
            </p>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="educbt_build_invigilation">
                <input type="hidden" name="series_id" value="<?php echo esc_attr( (string) $series_id ); ?>">
                <?php wp_nonce_field( 'educbt_build_invigilation' ); ?>
                <button type="submit" class="educbt-btn educbt-btn--primary">Create invigilation schedule</button>
            </form>
        </section>
    <?php else : ?>
        <section class="educbt-card no-print" style="display:flex;align-items:center;gap:14px;flex-wrap:wrap">
            <span><strong><?php echo esc_html( (string) $assigned ); ?></strong> of <?php echo esc_html( (string) count( $papers ) ); ?> papers covered.</span>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-left:auto">
                <input type="hidden" name="action" value="educbt_build_invigilation">
                <input type="hidden" name="series_id" value="<?php echo esc_attr( (string) $series_id ); ?>">
                <?php wp_nonce_field( 'educbt_build_invigilation' ); ?>
                <button type="submit" class="educbt-btn">Fill any gaps automatically</button>
            </form>
        </section>
    <?php endif; ?>

    <?php if ( ! empty( $drift ) ) : ?>
        <section class="educbt-card" style="border-left:4px solid var(--edu-warn)">
            <h2>The schedule no longer matches the timetable</h2>
            <p class="educbt-muted" style="margin-top:-6px">
                Papers have moved since this was built. None of these announce themselves
                on the day, so they are worth settling now.
            </p>
            <ul class="educbt-list">
                <?php foreach ( $drift as $issue ) : ?>
                    <li><?php echo esc_html( $issue ); ?></li>
                <?php endforeach; ?>
            </ul>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:12px">
                <input type="hidden" name="action" value="educbt_build_invigilation">
                <input type="hidden" name="series_id" value="<?php echo esc_attr( (string) $series_id ); ?>">
                <input type="hidden" name="rebuild" value="1">
                <?php wp_nonce_field( 'educbt_build_invigilation' ); ?>
                <button type="submit" class="educbt-btn educbt-btn--primary">Apply the timetable changes</button>
            </form>
        </section>
    <?php endif; ?>

    <section class="educbt-card">
        <h2>Schedule</h2>
        <table class="educbt-table">
            <thead><tr><th>Date &amp; time</th><th>Subject</th><th>Class</th><th>Duration</th><th>Invigilator</th></tr></thead>
            <tbody>
            <?php foreach ( $papers as $paper ) : ?>
                <tr>
                    <td style="white-space:nowrap"><?php echo esc_html( mysql2date( 'D j M, g:ia', (string) $paper['scheduled_at'] ) ); ?></td>
                    <td><?php echo esc_html( (string) $paper['subject_name'] ); ?></td>
                    <td><?php echo esc_html( (string) $paper['class_name'] ); ?></td>
                    <td><?php echo esc_html( (string) round( (int) $paper['duration_seconds'] / 60 ) ); ?> min</td>
                    <td>
                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="educbt-form" style="display:flex;gap:6px;align-items:center">
                            <input type="hidden" name="action" value="educbt_reassign_invigilator">
                            <input type="hidden" name="paper_id" value="<?php echo esc_attr( (string) $paper['id'] ); ?>">
                            <input type="hidden" name="series_id" value="<?php echo esc_attr( (string) $series_id ); ?>">
                            <?php wp_nonce_field( 'educbt_reassign_invigilator' ); ?>
                            <select name="staff_id" onchange="this.form.submit()" style="min-width:190px">
                                <option value="0">— nobody —</option>
                                <?php foreach ( $staff as $member ) : ?>
                                    <option value="<?php echo esc_attr( (string) $member['id'] ); ?>" <?php selected( (int) $member['id'], (int) $paper['invigilator_id'] ); ?>>
                                        <?php echo esc_html( (string) $member['name'] ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <p class="educbt-muted" style="margin-top:10px">
            Change anyone at any time until the last paper is written. A swap is refused if
            the person teaches that subject or is already in another hall at the same time.
        </p>
    </section>
    <?php
};

require EDUCBT_PRO_PATH . 'templates/portal/shell.php';
