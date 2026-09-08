<?php
/**
 * Student — subject registration.
 *
 * Presented as a registration slip rather than a settings form, because that is
 * what it is: the student, their class, the subjects the school has assigned, and
 * the ones they choose. It is also the thing they print and hand in, so it carries
 * the letterhead and prints as a document.
 *
 * Registration is not paperwork. A student's registered subjects decide which CA
 * tests and which examinations appear on their portal — a student registered in
 * nothing sees nothing.
 *
 * @var array<string,mixed> $educbt
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

global $wpdb;

$school_id  = (int) $educbt['school_id'];
$actor      = $educbt['scope']->actor();
$student_id = (int) $actor['id'];
$flash      = \EduCBTPro\Frontend\PortalActions::flash();

$ay         = new \EduCBTPro\Services\AcademicYearService();
$session    = $ay->current_session( $school_id );
$session_id = (int) ( $session['id'] ?? 0 );
$term       = $ay->resolve_current_term( $school_id, $session_id );

$view = $session_id > 0
    ? ( new \EduCBTPro\Services\AcademicStructureService() )->student_registration_view( $school_id, $student_id, $session_id )
    : [ 'error' => 'no_session' ];

$branding = ( new \EduCBTPro\Services\DocumentBrandingService() )->letterhead( $school_id );

$student = (array) $wpdb->get_row(
    $wpdb->prepare(
        'SELECT first_name, last_name, admission_number, passport_photo FROM ' . $wpdb->prefix . 'educbt_students WHERE id = %d',
        $student_id
    ),
    ARRAY_A
);

$educbt_title = 'Subject Registration';

$educbt_body = static function () use ( $view, $flash, $branding, $student, $session, $term ): void {
    require EDUCBT_PRO_PATH . 'templates/portal/partials/flash.php';

    if ( isset( $view['error'] ) ) {
        echo '<div class="educbt-card"><p class="educbt-muted">You are not enrolled in a class for the current session, so there is nothing to register yet. Your class teacher sorts this out.</p></div>';
        return;
    }

    $max     = (int) $view['maximum'];
    $chosen  = (int) $view['selected_electives'];
    $may_add = (int) $view['may_add'];
    $locked  = ! empty( $view['locked'] );
    ?>
    <div class="educbt-print-doc">

        <?php // Letterhead: school, session, and who this slip belongs to. ?>
        <div class="educbt-print-doc__header">
            <?php if ( ! empty( $branding['logo'] ) ) : ?>
                <img class="educbt-print-doc__crest" src="<?php echo esc_url( (string) $branding['logo'] ); ?>" alt="">
            <?php endif; ?>
            <div style="flex:1">
                <p class="educbt-print-doc__school"><?php echo esc_html( (string) $branding['name'] ); ?></p>
                <p class="educbt-print-doc__title">SUBJECT REGISTRATION SLIP</p>
                <p class="educbt-print-doc__meta">
                    <?php echo esc_html( trim( (string) ( $session['title'] ?? '' ) . ' · ' . (string) ( $term['title'] ?? '' ), ' ·' ) ); ?>
                </p>
            </div>
        </div>

        <div class="educbt-print-section" style="display:flex;gap:14px;align-items:center;padding:10px 0 14px;border-bottom:1px solid #cbd5e1">
            <?php if ( ! empty( $student['passport_photo'] ) ) : ?>
                <img src="<?php echo esc_url( (string) $student['passport_photo'] ); ?>" alt=""
                     style="width:56px;height:56px;object-fit:cover;border-radius:8px">
            <?php endif; ?>
            <div>
                <div style="font-weight:700;font-size:1.05rem">
                    <?php echo esc_html( trim( (string) ( $student['first_name'] ?? '' ) . ' ' . (string) ( $student['last_name'] ?? '' ) ) ); ?>
                </div>
                <div class="educbt-muted" style="font-size:.85rem">
                    <?php echo esc_html( (string) ( $student['admission_number'] ?? '' ) ); ?>
                    &nbsp;·&nbsp;
                    <?php echo esc_html( (string) $view['class'] ); ?>
                </div>
            </div>
            <div style="margin-left:auto;text-align:right">
                <div style="font-weight:700;font-size:1.4rem"><?php echo esc_html( (string) (int) $view['selected_total'] ); ?></div>
                <div class="educbt-muted" style="font-size:.75rem">
                    of <?php echo esc_html( (string) (int) $view['minimum'] ); ?>&ndash;<?php echo esc_html( (string) $max ); ?> subjects
                </div>
            </div>
        </div>

        <?php if ( ! $locked && (int) $view['still_to_choose'] > 0 ) : ?>
            <p class="educbt-note educbt-note--warn no-print" style="margin-top:12px">
                Your registration is not complete — choose
                <strong><?php echo esc_html( (string) (int) $view['still_to_choose'] ); ?> more</strong>
                subject(s) below and save.
            </p>
        <?php elseif ( ! empty( $view['complete'] ) ) : ?>
            <p class="educbt-note no-print" style="margin-top:12px">Your registration is complete.</p>
        <?php endif; ?>

        <?php // ── Compulsory ────────────────────────────────────────────────── ?>
        <div class="educbt-print-section" style="margin-top:16px">
            <h3 style="margin:0 0 8px">
                Compulsory subjects
                <span class="educbt-muted" style="font-weight:400;font-size:.85rem">— assigned by the school</span>
            </h3>

            <?php if ( empty( $view['core'] ) ) : ?>
                <p class="educbt-muted">None set for your class yet.</p>
            <?php else : ?>
                <table>
                    <thead><tr><th style="width:40px">#</th><th>Subject</th><th style="width:120px">Code</th></tr></thead>
                    <tbody>
                    <?php foreach ( (array) $view['core'] as $i => $s ) : ?>
                        <tr>
                            <td><?php echo esc_html( (string) ( $i + 1 ) ); ?></td>
                            <td><?php echo esc_html( (string) $s['name'] ); ?></td>
                            <td><?php echo esc_html( (string) ( $s['code'] ?? '' ) ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <?php // ── Electives ─────────────────────────────────────────────────── ?>
        <div class="educbt-print-section" style="margin-top:18px">
            <h3 style="margin:0 0 8px">
                Elective subjects
                <span class="educbt-muted" style="font-weight:400;font-size:.85rem">
                    — <?php echo esc_html( (string) $chosen ); ?> chosen<?php echo $may_add > 0 ? esc_html( ', up to ' . $may_add . ' more' ) : ''; ?>
                </span>
            </h3>

            <?php
            $selected_electives = array_values(
                array_filter( (array) $view['electives'], static fn( array $s ): bool => ! empty( $s['selected'] ) )
            );
            ?>

            <?php if ( empty( $selected_electives ) ) : ?>
                <p class="educbt-muted">No elective subjects chosen yet.</p>
            <?php else : ?>
                <table>
                    <thead><tr><th style="width:40px">#</th><th>Subject</th><th style="width:120px">Code</th></tr></thead>
                    <tbody>
                    <?php foreach ( $selected_electives as $i => $s ) : ?>
                        <tr>
                            <td><?php echo esc_html( (string) ( $i + 1 ) ); ?></td>
                            <td><?php echo esc_html( (string) $s['name'] ); ?></td>
                            <td><?php echo esc_html( (string) ( $s['code'] ?? '' ) ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <div class="educbt-print-doc__footer">
            <span>Student signature: ____________________</span>
            <span>Class teacher: ____________________</span>
        </div>
    </div>

    <?php // ── The picker. Screen only — it is the form, not the document. ───── ?>
    <section class="educbt-card no-print" style="margin-top:14px">
        <h2>Choose your electives</h2>

        <?php if ( $locked ) : ?>
            <p class="educbt-note educbt-note--warn">
                Subject registration is closed for this session. Speak to your class teacher if something above is wrong.
            </p>

        <?php elseif ( empty( $view['electives'] ) ) : ?>
            <p class="educbt-muted">Your class has no electives — every subject is compulsory.</p>

        <?php else : ?>
            <p class="educbt-muted">
                Tick the subjects you are taking, then save. A subject you have already
                been examined or marked in cannot be removed.
            </p>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="educbt-elective-form">
                <input type="hidden" name="action" value="educbt_student_electives">
                <?php wp_nonce_field( 'educbt_student_electives' ); ?>

                <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:8px;margin-top:10px">
                    <?php foreach ( (array) $view['electives'] as $s ) : ?>
                        <label style="display:flex;align-items:center;gap:9px;padding:10px 12px;border:1px solid var(--edu-line);border-radius:9px;font-weight:400;cursor:pointer">
                            <input type="checkbox" name="elective_ids[]"
                                   class="educbt-elective-check"
                                   value="<?php echo esc_attr( (string) (int) $s['id'] ); ?>"
                                   <?php checked( ! empty( $s['selected'] ) ); ?>
                                   style="width:auto">
                            <span style="flex:1"><?php echo esc_html( (string) $s['name'] ); ?></span>
                            <?php if ( ! empty( $s['code'] ) ) : ?>
                                <span class="educbt-muted" style="font-size:.75rem"><?php echo esc_html( (string) $s['code'] ); ?></span>
                            <?php endif; ?>
                        </label>
                    <?php endforeach; ?>
                </div>

                <div style="display:flex;gap:10px;align-items:center;margin-top:14px;flex-wrap:wrap">
                    <button type="submit" class="educbt-btn educbt-btn--primary">Save my subjects</button>
                    <button type="button" class="educbt-btn" id="educbt-slip-download">Download slip</button>
<script>
                    (function(){
                        var btn = document.getElementById("educbt-slip-download");
                        if (!btn) return;
                        btn.addEventListener("click", function(){
                            
                            btn.disabled = true; var o = btn.textContent; btn.textContent = "Generating\u2026";
                            var el = document.querySelector(".educbt-card") || document.body;
                            window.print(); btn.disabled = false; btn.textContent = o;
                        });
                    })();
                    </script>
                    <span class="educbt-muted" id="educbt-elective-counter"></span>
                </div>
            </form>

            <script>
            (function() {
                'use strict';
                // Live count against the school's maximum, so the limit is visible
                // while choosing rather than reported after a failed save.
                var core = <?php echo (int) count( (array) $view['core'] ); ?>;
                var max  = <?php echo (int) $max; ?>;
                var form = document.getElementById('educbt-elective-form');
                var out  = document.getElementById('educbt-elective-counter');
                if (!form || !out) return;

                function refresh() {
                    var checks = form.querySelectorAll('.educbt-elective-check');
                    var picked = form.querySelectorAll('.educbt-elective-check:checked').length;
                    var total  = core + picked;
                    var full   = max > 0 && total >= max;

                    out.textContent = total + ' of ' + max + ' subjects selected'
                        + (full ? ' — that is the maximum' : '');

                    checks.forEach(function(c) {
                        c.disabled = full && !c.checked;
                    });
                }

                form.addEventListener('change', refresh);
                refresh();
            })();
            </script>
        <?php endif; ?>
    </section>
    <?php
};

require EDUCBT_PRO_PATH . 'templates/portal/shell.php';
