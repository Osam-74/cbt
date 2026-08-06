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

$educbt_title = 'Broadsheet' . ( $class_name !== '' ? ' — ' . $class_name : '' );

$GLOBALS['educbt_school_id'] = $school_id;

$educbt_body = static function () use ( $sheet, $classes, $class_id, $session, $term ): void {
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
                            <?php echo esc_html( (string) $c['display_name'] ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="button" class="educbt-btn" onclick="window.print()">Print</button>
        </form>
    </section>

    <?php if ( empty( $sheet['rows'] ) ) : ?>
        <div class="educbt-card"><p class="educbt-muted">Nothing compiled for this class yet.</p></div>
        <?php return; ?>
    <?php endif; ?>

    <?php
    // A printed broadsheet is a document: it needs the school on it, or a page found
    // on a desk months later belongs to nobody.
    $branding = ( new \EduCBTPro\Services\DocumentBrandingService() )->letterhead( (int) $GLOBALS['educbt_school_id'] );
    ?>
    <div class="print-only" style="display:none;text-align:center;margin-bottom:6mm">
        <?php if ( ! empty( $branding['logo'] ) ) : ?>
            <img src="<?php echo esc_url( (string) $branding['logo'] ); ?>" alt="" style="width:20mm;height:20mm;object-fit:contain">
        <?php endif; ?>
        <h2 style="margin:2mm 0 0"><?php echo esc_html( (string) $branding['name'] ); ?></h2>
        <p style="margin:1mm 0"><?php echo esc_html( (string) $branding['address'] ); ?></p>
        <p style="margin:2mm 0;font-weight:bold;text-transform:uppercase;letter-spacing:2px">Broadsheet</p>
    </div>

    <section class="educbt-card">
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
    </section>

    <?php if ( ! empty( $sheet['stats']['per_subject'] ) ) : ?>
    <section class="educbt-card">
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
    </section>
    <?php endif; ?>
    <?php
};

require EDUCBT_PRO_PATH . 'templates/portal/shell.php';
