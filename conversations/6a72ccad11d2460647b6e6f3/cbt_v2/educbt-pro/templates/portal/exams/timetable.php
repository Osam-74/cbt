<?php
/**
 * Timetable — papers grouped by date. Not a stored entity; a view over papers.
 *
 * Includes reschedule capability for each paper.
 *
 * @var array<string,mixed> $educbt
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

global $wpdb;
$school_id = (int) $educbt['id'];

// Handle reschedule submission
$flash = \EduCBTPro\Frontend\PortalActions::flash();

$series_table = \EduCBTPro\Core\Schema::table( 'exam_series' );
$series = (array) $wpdb->get_results(
    $wpdb->prepare( "SELECT id, title FROM {$series_table} WHERE school_id = %d ORDER BY id DESC", $school_id ),
    ARRAY_A
);

$series_id = (int) ( $_GET['series'] ?? ( $series[0]['id'] ?? 0 ) );
$grouped   = $series_id > 0 ? ( new \EduCBTPro\Services\TimetableService() )->for_series( $school_id, $series_id ) : [];

$educbt_title = 'Timetable';

$educbt_body = static function () use ( $series, $series_id, $grouped, $flash ): void {
    require EDUCBT_PRO_PATH . 'templates/portal/partials/flash.php';

    if ( empty( $series ) ) {
        echo '<div class="educbt-card"><p class="educbt-muted">No examination has been created yet.</p></div>';
        return;
    }
    ?>
    <section class="educbt-card no-print">
        <form method="get" class="educbt-form" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap">
            <div style="flex:1 1 240px">
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

    <?php
    // Generate first, adjust by hand, then send. Class teachers each receive only
    // their own class's schedule.
    if ( \EduCBTPro\Core\Gate::allows( \EduCBTPro\Core\Capabilities::MANAGE_PAPERS ) ) : ?>
    <section class="educbt-card no-print">
        <h2>Build and send</h2>
        <div style="display:flex;gap:20px;flex-wrap:wrap;align-items:flex-end">
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="educbt-form" style="display:flex;gap:10px;align-items:flex-end">
                <input type="hidden" name="action" value="educbt_generate_timetable">
                <input type="hidden" name="series_id" value="<?php echo esc_attr( (string) $series_id ); ?>">
                <?php wp_nonce_field( 'educbt_generate_timetable' ); ?>
                <div>
                    <label for="starts_on">First exam day</label>
                    <input id="starts_on" name="starts_on" type="date">
                </div>
                <button type="submit" class="educbt-btn educbt-btn--primary">Generate schedule</button>
            </form>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
                  onsubmit="return confirm('Send each class teacher their class timetable?');">
                <input type="hidden" name="action" value="educbt_notify_class_teachers">
                <input type="hidden" name="series_id" value="<?php echo esc_attr( (string) $series_id ); ?>">
                <?php wp_nonce_field( 'educbt_notify_class_teachers' ); ?>
                <button type="submit" class="educbt-btn" <?php disabled( empty( $grouped ) ); ?>>Notify class teachers</button>
            </form>
        </div>
        <p class="educbt-muted" style="margin-top:10px">
            Papers are created from the question sets already approved for this
            examination's session and term. Re-running adds anything newly approved
            and leaves existing entries exactly as you have set them.
        </p>
    </section>
    <?php endif; ?>

    <?php if ( empty( $grouped ) ) : ?>
        <div class="educbt-card"><p class="educbt-muted">No papers scheduled for this examination.</p></div>
    <?php else : ?>
        <?php foreach ( $grouped as $date => $papers ) : ?>
            <section class="educbt-card">
                <h2><?php echo esc_html( mysql2date( 'l, j F Y', $date ) ); ?></h2>
                <table class="educbt-table">
                    <thead><tr><th>Time</th><th>Subject</th><th>Class</th><th>Duration</th><th>Invigilator</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ( $papers as $p ) :
                        $paper_id = (int) $p['id'];
                        $current_dt = (string) $p['scheduled_at'];
                        $current_dt_local = substr( $current_dt, 0, 16 ); // YYYY-MM-DD HH:MM
                        ?>
                        <tr>
                            <td><?php echo esc_html( mysql2date( 'g:ia', $current_dt ) ); ?></td>
                            <td><?php echo esc_html( (string) $p['subject_name'] ); ?></td>
                            <td><?php echo esc_html( (string) $p['class_name'] ); ?></td>
                            <td><?php echo esc_html( (string) round( (int) $p['duration_seconds'] / 60 ) ); ?> min</td>
                            <td><?php echo esc_html( (string) ( $p['invigilators'] ?: '—' ) ); ?></td>
                            <td style="white-space:nowrap">
                                <button type="button" class="educbt-btn" style="padding:4px 10px;font-size:12.5px"
                                        onclick="var r=document.getElementById('resched-<?php echo esc_attr( (string) $paper_id ); ?>');r.toggleAttribute('hidden')">Reschedule</button>
                            </td>
                        </tr>
                        <tr id="resched-<?php echo esc_attr( (string) $paper_id ); ?>" hidden>
                            <td colspan="6" style="background:var(--edu-bg)">
                                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="educbt-form" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;padding:12px 0">
                                    <input type="hidden" name="action" value="educbt_reschedule_paper">
                                    <input type="hidden" name="paper_id" value="<?php echo esc_attr( (string) $paper_id ); ?>">
                                    <input type="hidden" name="series_id" value="<?php echo esc_attr( (string) $series_id ); ?>">
                                    <?php wp_nonce_field( 'educbt_reschedule_paper' ); ?>
                                    <div>
                                        <label for="new-date-<?php echo esc_attr( (string) $paper_id ); ?>">New date &amp; time</label>
                                        <input type="datetime-local" id="new-date-<?php echo esc_attr( (string) $paper_id ); ?>" name="new_scheduled_at"
                                               value="<?php echo esc_attr( $current_dt_local ); ?>" required>
                                    </div>
                                    <button type="submit" class="educbt-btn educbt-btn--primary">Save</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </section>
        <?php endforeach; ?>
    <?php endif; ?>
    <?php
};

require EDUCBT_PRO_PATH . 'templates/portal/shell.php';
