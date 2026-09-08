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
$school_id = (int) $educbt['school_id'];

// Handle reschedule submission
$flash = \EduCBTPro\Frontend\PortalActions::flash();

$series_table = \EduCBTPro\Core\Schema::table( 'exam_series' );
$series = (array) $wpdb->get_results(
    $wpdb->prepare( "SELECT id, title FROM {$series_table} WHERE school_id = %d ORDER BY id DESC", $school_id ),
    ARRAY_A
);

$series_id = (int) ( $_GET['series'] ?? ( $series[0]['id'] ?? 0 ) );
$grouped   = $series_id > 0 ? ( new \EduCBTPro\Services\TimetableService() )->for_series( $school_id, $series_id ) : [];

$series_row = $series_id > 0
    ? (array) $wpdb->get_row(
        $wpdb->prepare( "SELECT * FROM {$series_table} WHERE id = %d AND school_id = %d", $series_id, $school_id ),
        ARRAY_A
    )
    : [];

// Staff who can invigilate. Assigning one is part of scheduling, not a separate job.
$invigilator_pool = (array) $wpdb->get_results(
    $wpdb->prepare(
        "SELECT id, CONCAT(first_name, ' ', last_name) AS name
         FROM " . \EduCBTPro\Core\Schema::table( 'staff' ) . "
         WHERE school_id = %d AND status = 'active'
         ORDER BY first_name ASC",
        $school_id
    ),
    ARRAY_A
);

$branding = ( new \EduCBTPro\Services\DocumentBrandingService() )->letterhead( $school_id );

// A class teacher sees a timetable only once the exam office has released it.
// Before that it is a working draft that may still be reshuffled.
$can_manage = \EduCBTPro\Core\Gate::allows( \EduCBTPro\Core\Capabilities::MANAGE_PAPERS );
$released   = $series_id > 0 && ( new \EduCBTPro\Services\ExamTimetableService() )->is_released( $school_id, $series_id );

if ( ! $can_manage && ! $released ) {
    $grouped = [];
}

$educbt_title = 'Timetable';

$educbt_body = static function () use ( $series, $series_id, $grouped, $flash, $invigilator_pool, $branding, $series_row, $can_manage, $released ): void {
    require EDUCBT_PRO_PATH . 'templates/portal/partials/flash.php';

    if ( empty( $series ) ) {
        echo '<div class="educbt-card"><p class="educbt-muted">No examination has been created yet.</p></div>';
        return;
    }
    ?>
    <section class="educbt-card no-print">
        <form method="get" class="educbt-form" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap">
            <div style="flex:1 1 240px">
                <label for="series">Examination or assessment</label>
                <select id="series" name="series" onchange="this.form.submit()">
                    <?php foreach ( $series as $s ) : ?>
                        <option value="<?php echo esc_attr( (string) $s['id'] ); ?>" <?php selected( (int) $s['id'], $series_id ); ?>>
                            <?php echo esc_html( (string) $s['title'] ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

        </form>
    </section>

    <?php
    // Generate first, adjust by hand, then send. Class teachers each receive only
    // their own class's schedule.
    if ( $can_manage ) : ?>
    <section class="educbt-card no-print">
        <h2>Build and send</h2>
        <div style="display:flex;gap:20px;flex-wrap:wrap;align-items:flex-end">
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="educbt-form" style="display:flex;gap:10px;align-items:flex-end">
                <input type="hidden" name="action" value="educbt_generate_timetable">
                <input type="hidden" name="series_id" value="<?php echo esc_attr( (string) $series_id ); ?>">
                <?php wp_nonce_field( 'educbt_generate_timetable' ); ?>
                <?php if ( ! empty( $grouped ) ) : ?>
                <input type="hidden" name="regenerate" value="1">
                <?php endif; ?>
                <?php // Both ends of the sitting period. The generator lays papers out
                      // between them and reports anything that will not fit, rather
                      // than silently running past the day the school set aside. ?>
                <div>
                    <label for="starts_on">First day of sitting</label>
                    <input id="starts_on" name="starts_on" type="date"
                           value="<?php echo esc_attr( (string) ( $series_row['starts_on'] ?? '' ) ); ?>">
                </div>
                <div>
                    <label for="ends_on">Last day of sitting</label>
                    <input id="ends_on" name="ends_on" type="date"
                           value="<?php echo esc_attr( (string) ( $series_row['ends_on'] ?? '' ) ); ?>">
                    <small class="educbt-muted">Leave blank to allow up to two weeks.</small>
                </div>
                <button type="submit" class="educbt-btn educbt-btn--primary"<?php if ( ! empty( $grouped ) ) : ?> onclick="return confirm('This will delete all existing papers for this examination and rebuild from scratch. Any manual adjustments will be lost. Continue?')"<?php endif; ?>><?php echo ! empty( $grouped ) ? 'Regenerate schedule' : 'Generate schedule'; ?></button>
            </form>

            <?php
            // Notifying is the LAST step, so it does not appear until there is
            // something to send. A disabled button still invites a click and leaves
            // the reader wondering what they have done wrong; showing it only once
            // the schedule exists makes the order of work obvious.
            ?>
            <?php if ( ! empty( $grouped ) ) : ?>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
                  onsubmit="return confirm('Send each class teacher their class timetable?');">
                <input type="hidden" name="action" value="educbt_notify_class_teachers">
                <input type="hidden" name="series_id" value="<?php echo esc_attr( (string) $series_id ); ?>">
                <?php wp_nonce_field( 'educbt_notify_class_teachers' ); ?>
                <button type="submit" class="educbt-btn">Notify class teachers</button>
            </form>
                <?php // Nothing to download until a schedule exists, so the control
                      // appears with the schedule rather than beside the picker. ?>
            <button type="button" class="educbt-btn no-print" id="educbt-timetable-dl">Download timetable</button>
<script>
            (function(){
                var btn=document.getElementById("educbt-timetable-dl");if(!btn)return;
                btn.addEventListener("click",function(){
                    
                    btn.disabled=true;var o=btn.textContent;btn.textContent="Generating\u2026";
                    var el=document.querySelector(".educbt-print-doc")||document.body;
                    window.print();btn.disabled=false;btn.textContent=o;
                });
            })();
            </script>
            <?php else : ?>
                <span class="educbt-muted" style="font-size:.82rem;align-self:center">
                    Generate the schedule first — it can then be sent to class teachers and downloaded.
                </span>
            <?php endif; ?>
        </div>
        <p class="educbt-muted" style="margin-top:10px">
            Papers are created from the question sets already approved for this
            examination's session and term. Re-running adds anything newly approved
            and leaves existing entries exactly as you have set them.
        </p>
    </section>
    <?php endif; ?>

    <?php if ( empty( $grouped ) ) : ?>
        <div class="educbt-card"><p class="educbt-muted">
            <?php echo $can_manage
                ? 'No papers scheduled for this examination yet — generate the schedule above.'
                : 'The examination timetable has not been released yet. You will be notified when it is.'; ?>
        </p></div>
    <?php else : ?>
    <?php // Everything below is the printed document: letterhead, then the table. ?>
    <div class="educbt-print-doc">
        <div class="educbt-print-doc__header">
            <?php if ( ! empty( $branding['logo'] ) ) : ?>
                <img class="educbt-print-doc__crest" src="<?php echo esc_url( (string) $branding['logo'] ); ?>" alt="">
            <?php endif; ?>
            <div>
                <p class="educbt-print-doc__school"><?php echo esc_html( (string) $branding['name'] ); ?></p>
                <p class="educbt-print-doc__title">EXAMINATION TIMETABLE</p>
                <p class="educbt-print-doc__meta">
                    <?php echo esc_html( (string) ( $series_row['title'] ?? '' ) ); ?>
                    <?php if ( ! empty( $branding['address'] ) ) : ?>
                        · <?php echo esc_html( (string) $branding['address'] ); ?>
                    <?php endif; ?>
                </p>
            </div>
        </div>

        <?php
        // One table, with the date IN it. Grouping by day meant a printed timetable
        // arrived as a stack of separate little tables, and a reader scanning for
        // "when is Chemistry" had to check every heading. A single table sorts,
        // scans and prints as one document.
        ?>
        <table class="educbt-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Day</th>
                    <th>Time</th>
                    <th>Subject</th>
                    <th>Class</th>
                    <th class="no-print">Duration</th>
                    <th class="no-print">Access Code</th>
                    <th>Invigilator</th>
                    <th class="no-print"></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ( $grouped as $date => $papers ) : ?>
                <?php foreach ( $papers as $index => $p ) :
                    $paper_id         = (int) $p['id'];
                    $current_dt       = (string) $p['scheduled_at'];
                    $current_dt_local = (string) ( $p['scheduled_at_local'] ?? str_replace( ' ', 'T', substr( $current_dt, 0, 16 ) ) );
                    ?>
                    <tr>
                        <td><?php echo esc_html( wp_date( 'j M Y', strtotime( (string) $current_dt . ' UTC' ) ) ); ?></td>
                        <td><?php echo esc_html( wp_date( 'l', strtotime( (string) $current_dt . ' UTC' ) ) ); ?></td>
                        <td><?php
                            $time_start = wp_date( 'g:ia', strtotime( (string) $current_dt . ' UTC' ) );
                            $closes_raw = (string) ( $p['closes_at'] ?? '' );
                            if ( ! empty( $closes_raw ) && $closes_raw !== '0000-00-00 00:00:00' ) {
                                $time_end = wp_date( 'g:ia', strtotime( $closes_raw . ' UTC' ) );
                                echo esc_html( $time_start . ' – ' . $time_end );
                            } else {
                                echo esc_html( $time_start );
                            }
                        ?></td>
                        <td><strong><?php echo esc_html( (string) $p['subject_name'] ); ?></strong></td>
                        <td><?php echo esc_html( (string) $p['class_name'] ); ?></td>
                        <td class="no-print"><?php echo esc_html( (string) round( (int) $p['duration_seconds'] / 60 ) ); ?> min</td>
                        <td class="no-print" style="white-space:nowrap">
                            <?php $ac = (string) ( $p['access_code'] ?? '' ); ?>
                            <?php if ( $ac !== '' ) : ?>
                                <code style="font-size:14px;font-weight:700;letter-spacing:1px;background:#f0fdf4;padding:2px 8px;border-radius:4px;color:#166534"><?php echo esc_html( $ac ); ?></code>
                                <?php if ( $can_manage ) : ?>
                                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;margin-left:4px"
                                          onsubmit="return confirm('Regenerate access code? The old code will stop working immediately.');">
                                        <input type="hidden" name="action" value="educbt_regenerate_access_code">
                                        <input type="hidden" name="paper_id" value="<?php echo esc_attr( (string) $p['id'] ); ?>">
                                        <?php wp_nonce_field( 'educbt_regenerate_access_code' ); ?>
                                        <button type="submit" class="educbt-btn" style="font-size:.8rem;padding:4px 10px;border-color:var(--sage);color:var(--forest)">↻</button>
                                    </form>
                                <?php endif; ?>
                            <?php elseif ( $can_manage ) : ?>
                                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline"
                                      onsubmit="return confirm('Generate an access code for this paper?');">
                                    <input type="hidden" name="action" value="educbt_regenerate_access_code">
                                    <input type="hidden" name="paper_id" value="<?php echo esc_attr( (string) $p['id'] ); ?>">
                                    <?php wp_nonce_field( 'educbt_regenerate_access_code' ); ?>
                                    <button type="submit" class="educbt-btn" style="font-size:.78rem;padding:4px 10px;border-color:var(--sage);color:var(--forest)">Generate</button>
                                </form>
                            <?php else : ?>
                                <span class="educbt-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html( (string) ( $p['invigilators'] ?: '—' ) ); ?></td>
                        <td class="no-print" style="white-space:nowrap">
                            <?php if ( $can_manage ) : ?>
                                <button type="button" class="educbt-btn" style="padding:4px 10px;font-size:12.5px"
                                        onclick="var r=document.getElementById('resched-<?php echo esc_attr( (string) $paper_id ); ?>');r.style.display=(r.style.display==='none'?'':'none')">Reschedule</button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php if ( $can_manage ) : ?>
                    <tr id="resched-<?php echo esc_attr( (string) $paper_id ); ?>" class="no-print" style="display:none">
                        <td colspan="9" style="background:var(--edu-bg)">
                            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="educbt-form" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;padding:12px 0">
                                <input type="hidden" name="action" value="educbt_reschedule_paper">
                                <input type="hidden" name="paper_id" value="<?php echo esc_attr( (string) $paper_id ); ?>">
                                <input type="hidden" name="series_id" value="<?php echo esc_attr( (string) $series_id ); ?>">
                                <?php wp_nonce_field( 'educbt_reschedule_paper' ); ?>
                                <div>
                                    <label for="new-date-<?php echo esc_attr( (string) $paper_id ); ?>">Date &amp; time</label>
                                    <input type="datetime-local" id="new-date-<?php echo esc_attr( (string) $paper_id ); ?>" name="new_scheduled_at"
                                           value="<?php echo esc_attr( $current_dt_local ); ?>" required>
                                </div>
                                <div>
                                    <label for="dur-<?php echo esc_attr( (string) $paper_id ); ?>">Duration (minutes)</label>
                                    <input type="number" id="dur-<?php echo esc_attr( (string) $paper_id ); ?>" name="duration_minutes"
                                           min="5" max="300" step="5"
                                           value="<?php echo esc_attr( (string) max( 5, (int) round( (int) $p['duration_seconds'] / 60 ) ) ); ?>" required>
                                </div>
                                <div>
                                    <label for="closes-<?php echo esc_attr( (string) $paper_id ); ?>">Closes at (optional)</label>
                                    <input type="datetime-local" id="closes-<?php echo esc_attr( (string) $paper_id ); ?>" name="closes_at"
                                           value="<?php echo esc_attr( (string) ( $p['closes_at_local'] ?? '' ) ); ?>">
                                    <small class="educbt-muted">When the exam window closes. Blank = no hard cutoff.</small>
                                </div>
                                <div>
                                    <label for="invig-<?php echo esc_attr( (string) $paper_id ); ?>">Invigilator</label>
                                    <select id="invig-<?php echo esc_attr( (string) $paper_id ); ?>" name="invigilator_id">
                                        <option value="0">— none —</option>
                                        <?php foreach ( $invigilator_pool as $staff_member ) : ?>
                                            <option value="<?php echo esc_attr( (string) $staff_member['id'] ); ?>"
                                                <?php selected( (int) $staff_member['id'], (int) ( $p['invigilator_id'] ?? 0 ) ); ?>>
                                                <?php echo esc_html( (string) $staff_member['name'] ); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <button type="submit" class="educbt-btn educbt-btn--primary">Save</button>
                            </form>
                        </td>
                    </tr>
                    <?php endif; ?>
                <?php endforeach; ?>
            <?php endforeach; ?>
            </tbody>
        </table>

        <div class="educbt-print-doc__footer">
            <span>Signed: ______________________________</span>
            <span>Printed <?php echo esc_html( mysql2date( 'j M Y', current_time( 'mysql' ) ) ); ?></span>
        </div>
    </div>
    <?php endif; ?>
    <?php
};

require EDUCBT_PRO_PATH . 'templates/portal/shell.php';
