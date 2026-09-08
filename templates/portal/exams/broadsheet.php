<?php
/**
 * The broadsheet: students down, subjects across, totals and position on the right.
 * The document a results meeting actually works from.
 *
 * @var array<string,mixed> $educbt
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

$school_id = (int) $educbt['school_id'];
$class_id  = (int) $educbt['id'];

$year       = new \EduCBTPro\Services\AcademicYearService();
$session    = $year->current_session( $school_id );
$term       = $year->current_term( $school_id );
$session_id = (int) ( $session['id'] ?? 0 );
$term_id    = (int) ( $term['id'] ?? 0 );

$structure = new \EduCBTPro\Services\AcademicStructureService();
$classes   = $structure->list_classes( $school_id );

// A class teacher sees their own class only. Leaving every class in the picker let
// one class teacher read another class's marks and positions, which is exactly the
// scope rule the rest of the system enforces.
$reachable = $educbt['scope']->reachable_class_ids();
$is_wide  = $educbt['scope']->is_school_wide();

if ( ! empty( $reachable ) ) {
    $classes = array_values(
        array_filter( $classes, static fn( array $c ): bool => in_array( (int) $c['id'], $reachable, true ) )
    );
}

if ( $class_id === 0 && ! empty( $classes ) ) {
    $class_id = (int) $classes[0]['id'];
}

// A class id typed into the URL is still a request, so check it too.
if ( $class_id > 0 && ! empty( $reachable ) && ! in_array( $class_id, $reachable, true ) ) {
    $class_id = (int) ( $classes[0]['id'] ?? 0 );
}

$sheet = ( $class_id > 0 && $term_id > 0 )
    ? ( new \EduCBTPro\Services\BroadsheetService() )->build( $school_id, $class_id, $session_id, $term_id )
    : [ 'subjects' => [], 'rows' => [], 'stats' => [] ];

$class_name = '';

foreach ( $classes as $c ) {
    if ( (int) $c['id'] === $class_id ) { $class_name = (string) $c['display_name']; }
}

$educbt_title = 'Broadsheet' . ( $class_name !== '' ? ' — ' . educbt_class_level_name( $class_name ) : '' );

$GLOBALS['educbt_school_id'] = $school_id;

$educbt_body = static function () use ( $sheet, $classes, $class_id, $session, $term, $is_wide ): void {
    ?>
    <section class="educbt-card no-print">
        <p class="educbt-muted" style="margin-top:0">
            One sheet showing every student in the class down the side and every subject
            across the top, with totals and positions. It is the sheet a results meeting
            works from and what the class teacher signs before report sheets are printed.
        </p>
        <form method="get" class="educbt-form" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap">
            <div style="flex:1 1 220px">
                <label for="cls">Class</label>
                <select id="cls" name="cls" onchange="window.location='<?php echo esc_url( home_url( '/portal/exams/broadsheet/' ) ); ?>'+this.value">
                    <?php foreach ( $classes as $c ) : ?>
                        <option value="<?php echo esc_attr( (string) $c['id'] ); ?>" <?php selected( (int) $c['id'], $class_id ); ?>>
                            <?php echo esc_html( educbt_class_level_name( (string) $c['display_name'] ) ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="button" class="educbt-btn" id="educbt-broadsheet-download">Download / Print</button>
            <span class="educbt-muted" style="font-size:.78rem;max-width:180px">Choose <strong>Save as PDF</strong>, and landscape, in the dialog.</span>
        </form>
    </section>
    <script>
    // Downloading is the browser own print-to-PDF.
    //
    // This used to run html2pdf/html2canvas, which rasterises the page into an
    // image and slices it at fixed intervals — so a broadsheet wide enough to
    // need landscape came out as a picture cut at arbitrary points, losing its
    // column rules and starting partway down the page.
    //
    // The print stylesheet already sets landscape for this document and repeats
    // the column headings across pages, which is what a broadsheet needs.
    (function(){
        var btn = document.getElementById("educbt-broadsheet-download");
        if (!btn) { return; }
        btn.addEventListener("click", function(){ window.print(); });
    })();
    </script>

    <?php if ( empty( $sheet['rows'] ) ) : ?>
        <div class="educbt-card"><p class="educbt-muted">Nothing compiled for this class yet.</p></div>
        <?php return; ?>
    <?php endif; ?>

    <?php
    // Class teachers: block access until the principal has approved the results.
    $sheet_status = '';
    foreach ( $sheet['rows'] as $check_row ) {
        $sheet_status = (string) ( $check_row['status'] ?? '' );
        break;
    }
    if ( ! $is_wide && $sheet_status !== '' && ! in_array( $sheet_status, [ 'approved', 'published' ], true ) ) :
    ?>
        <div class="educbt-card">
            <p class="educbt-note educbt-note--warn">
                Results for this class have not been approved yet. The broadsheet will be
                available once school management has reviewed and approved the results.
            </p>
        </div>
        <?php return; ?>
    <?php endif; ?>

    <?php // School management: show moderation status so they know what they can do. ?>
    <?php if ( $is_wide && $sheet_status !== '' && $sheet_status !== 'published' ) : ?>
        <div class="educbt-card" style="margin-bottom:12px">
            <p class="educbt-note" style="margin:0">
                <strong>Status: <?php echo esc_html( ucfirst( $sheet_status ) ); ?></strong> —
                <?php if ( $sheet_status === 'compiled' ) : ?>
                    Results are compiled. You can moderate remarks and recompile as needed. Moderation will be locked once results are published to students.
                <?php elseif ( $sheet_status === 'approved' ) : ?>
                    Results are approved but not yet published. Moderation is still possible. Once you publish, moderation will be locked.
                <?php else : ?>
                    Results are at the <?php echo esc_html( $sheet_status ); ?> stage.
                <?php endif; ?>
            </p>
        </div>
    <?php elseif ( $is_wide && $sheet_status === 'published' ) : ?>
        <div class="educbt-card" style="margin-bottom:12px">
            <p class="educbt-note educbt-note--warn" style="margin:0">
                <strong>Published.</strong> These results are now visible to students. Moderation is locked.
            </p>
        </div>
    <?php endif; ?>

    <?php
    // A printed broadsheet is a document: it needs the school on it, or a page found
    // on a desk months later belongs to nobody.
    $branding = ( new \EduCBTPro\Services\DocumentBrandingService() )->letterhead( (int) $GLOBALS['educbt_school_id'] );
    ?>
    <?php // Everything from here down is the document. Nothing else prints. ?>
    <div class="educbt-print-doc educbt-print-landscape">
        <div class="educbt-print-doc__header">
            <?php if ( ! empty( $branding['logo'] ) ) : ?>
                <img class="educbt-print-doc__crest" src="<?php echo esc_url( (string) $branding['logo'] ); ?>" alt="">
            <?php endif; ?>
            <div>
                <p class="educbt-print-doc__school"><?php echo esc_html( (string) $branding['name'] ); ?></p>
                <p class="educbt-print-doc__title">BROADSHEET</p>
                <p class="educbt-print-doc__meta">
                    <?php echo esc_html( (string) $branding['address'] ); ?>
                </p>
            </div>
        </div>

        <div class="educbt-print-section">
        <p class="educbt-muted">
            <?php echo esc_html( (string) ( $session['title'] ?? '' ) . ' · ' . (string) ( $term['title'] ?? '' ) ); ?> ·
            <?php echo esc_html( (string) ( $sheet['stats']['class_size'] ?? 0 ) ); ?> students ·
            class average <?php echo esc_html( (string) ( $sheet['stats']['class_average'] ?? 0 ) ); ?>%
        </p>

        <div style="overflow-x:auto">
            <table class="educbt-table" style="min-width:640px">
                <thead>
                    <tr>
                        <th>Student</th>
                        <?php foreach ( $sheet['subjects'] as $subject ) : ?>
                            <th title="<?php echo esc_attr( (string) $subject['name'] ); ?>"><?php echo esc_html( (string) ( $subject['code'] ?: $subject['name'] ) ); ?></th>
                        <?php endforeach; ?>
                        <th>Total</th><th>Avg</th><th>Pos</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $sheet['rows'] as $row ) : ?>
                    <tr>
                        <td style="white-space:nowrap"><?php echo esc_html( (string) $row['name'] ); ?></td>
                        <?php foreach ( $sheet['subjects'] as $subject ) :
                            $cell = $row['cells'][ $subject['id'] ] ?? null; ?>
                            <td>
                                <?php if ( $cell === null ) : ?>
                                    <span class="educbt-muted">—</span>
                                <?php else : ?>
                                    <?php echo esc_html( (string) (float) $cell['total'] ); ?>
                                    <span class="educbt-muted" style="font-size:11px"><?php echo esc_html( (string) $cell['grade'] ); ?></span>
                                <?php endif; ?>
                            </td>
                        <?php endforeach; ?>
                        <td><strong><?php echo esc_html( (string) (float) $row['total'] ); ?></strong></td>
                        <td><?php echo esc_html( (string) (float) $row['average'] ); ?></td>
                        <td><?php echo esc_html( \EduCBTPro\Services\ReportCardDocument::ordinal( (int) $row['position'] ) ); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <p class="educbt-muted" style="margin-top:10px">A dash means the student does not offer that subject — it is not a zero.</p>
        </div>

    <?php if ( ! empty( $sheet['stats']['per_subject'] ) ) : ?>
        <div class="educbt-print-section" style="margin-top:16px">
        <h2>By subject</h2>
        <table class="educbt-table">
            <thead><tr><th>Subject</th><th>Entered</th><th>Average</th><th>Highest</th><th>Lowest</th><th>Pass rate</th></tr></thead>
            <tbody>
            <?php foreach ( $sheet['stats']['per_subject'] as $s ) : ?>
                <tr>
                    <td><?php echo esc_html( (string) $s['name'] ); ?></td>
                    <td><?php echo esc_html( (string) $s['entered'] ); ?></td>
                    <td><?php echo esc_html( (string) $s['average'] ); ?></td>
                    <td><?php echo esc_html( (string) $s['highest'] ); ?></td>
                    <td><?php echo esc_html( (string) $s['lowest'] ); ?></td>
                    <td><?php echo esc_html( (string) $s['pass_rate'] ); ?>%</td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>

        <div class="educbt-print-doc__footer">
            <span><?php echo esc_html( (string) ( $session['title'] ?? '' ) . ' · ' . (string) ( $term['title'] ?? '' ) ); ?></span>
            <span>Printed <?php echo esc_html( mysql2date( 'j M Y', current_time( 'mysql' ) ) ); ?></span>
        </div>
    </div>
    <?php
};

require EDUCBT_PRO_PATH . 'templates/portal/shell.php';
