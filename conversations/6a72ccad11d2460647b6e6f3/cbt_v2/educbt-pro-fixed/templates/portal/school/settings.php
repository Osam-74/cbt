<?php
/**
 * School settings — the current session and term, and the school's own details.
 *
 * The current session and term are the two values everything time-bound reads from.
 * Getting them wrong quietly misfiles a whole term's marks, so they are set here and
 * shown on the overview.
 *
 * @var array<string,mixed> $educbt
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

global $wpdb;

$school_id = (int) $educbt['school_id'];
$flash     = \EduCBTPro\Frontend\PortalActions::flash();

$year     = new \EduCBTPro\Services\AcademicYearService();
$sessions = $year->list_sessions( $school_id );
$current  = $year->current_session( $school_id );
$term     = $year->current_term( $school_id );

$terms = $current ? $year->list_terms( $school_id, (int) $current['id'] ) : [];

$schools_table = $wpdb->prefix . 'educbt_schools';

$school = $wpdb->get_row(
    $wpdb->prepare(
        'SELECT * FROM `' . $schools_table . '` WHERE id = %d',
        $school_id
    ),
    ARRAY_A
);

// SHOW COLUMNS fallback. When the table was created by an early version and
// dbDelta silently failed to add newer columns (academic_settings,
// report_settings), SELECT * could return null — the settings form rendered
// blank with no way to tell the record was there. Discover which columns
// actually exist and query only those.
if ( $school === null && ! empty( $wpdb->last_error ) ) {
    $wpdb->last_error = '';
    $cols = $wpdb->get_col( 'SHOW COLUMNS FROM `' . $schools_table . '`' );

    if ( is_array( $cols ) && ! empty( $cols ) ) {
        $col_list = implode( ', ', array_map( static fn( string $c ): string => '`' . esc_sql( $c ) . '`', $cols ) );
        $school = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT ' . $col_list . ' FROM `' . $schools_table . '` WHERE id = %d',
                $school_id
            ),
            ARRAY_A
        );
    }
}

$school = is_array( $school ) ? $school : [];

$components = ( new \EduCBTPro\Services\AssessmentService() )->components( $school_id );

$educbt_title = 'Settings';

$educbt_body = static function () use ( $flash, $sessions, $terms, $current, $term, $school, $components ): void {
    require EDUCBT_PRO_PATH . 'templates/portal/partials/flash.php';
    ?>
    <section class="educbt-card">
        <h2>Current session and term</h2>
        <p class="educbt-muted" style="margin-top:-6px">
            Everything — exams, scores, results, promotion — is filed against these two.
            Change them at the start of each term.
        </p>

        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="educbt-form">
            <input type="hidden" name="action" value="educbt_set_period">
            <?php wp_nonce_field( 'educbt_set_period' ); ?>

            <div class="educbt-grid">
                <div>
                    <label for="session_id">Academic session</label>
                    <select id="session_id" name="session_id">
                        <?php foreach ( $sessions as $s ) : ?>
                            <option value="<?php echo esc_attr( (string) $s['id'] ); ?>" <?php selected( ! empty( $s['is_current'] ) ); ?>>
                                <?php echo esc_html( (string) $s['title'] ); ?><?php echo ! empty( $s['is_current'] ) ? ' (current)' : ''; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="term_id">Term</label>
                    <select id="term_id" name="term_id">
                        <?php foreach ( $terms as $t ) : ?>
                            <option value="<?php echo esc_attr( (string) $t['id'] ); ?>" <?php selected( ! empty( $t['is_current'] ) ); ?>>
                                <?php echo esc_html( (string) $t['title'] ); ?><?php echo ! empty( $t['is_current'] ) ? ' (current)' : ''; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="educbt-muted" style="margin-top:4px;font-size:12.5px">
                        Changing the session resets the term to the first of that session.
                    </p>
                </div>
            </div>

            <button type="submit" class="educbt-btn educbt-btn--primary" style="margin-top:16px">Save period</button>
        </form>
    </section>

    <section class="educbt-card">
        <h2>Add a session</h2>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="educbt-form">
            <input type="hidden" name="action" value="educbt_add_session">
            <?php wp_nonce_field( 'educbt_add_session' ); ?>
            <div class="educbt-grid">
                <div>
                    <label for="title">Session</label>
                    <input id="title" name="title" type="text" placeholder="2026/2027" required>
                    <p class="educbt-muted" style="margin-top:4px;font-size:12.5px">Three terms are created automatically.</p>
                </div>
            </div>
            <label style="display:flex;align-items:center;gap:8px;margin-top:12px;font-weight:400">
                <input type="checkbox" name="make_current" value="1" style="width:auto"> Make this the current session
            </label>
            <button type="submit" class="educbt-btn" style="margin-top:14px">Add session</button>
        </form>
    </section>

    <section class="educbt-card">
        <h2>How a term is marked</h2>
        <p class="educbt-muted" style="margin-top:-6px">
            The weights must total 100. Exactly one of them is the examination — that is
            the component the CBT writes into; the rest are entered by teachers.
        </p>

        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="educbt-form">
            <input type="hidden" name="action" value="educbt_save_components">
            <?php wp_nonce_field( 'educbt_save_components' ); ?>

            <table class="educbt-table">
                <thead><tr><th>Assessment</th><th style="width:120px">Weight</th><th style="width:120px">Is the exam</th></tr></thead>
                <tbody>
                <?php foreach ( $components as $c ) : ?>
                    <tr>
                        <td>
                            <input name="component[<?php echo esc_attr( (string) $c['id'] ); ?>][name]" type="text"
                                   value="<?php echo esc_attr( (string) $c['name'] ); ?>">
                        </td>
                        <td>
                            <input name="component[<?php echo esc_attr( (string) $c['id'] ); ?>][max_score]" type="number"
                                   step="1" min="0" max="100" value="<?php echo esc_attr( (string) (float) $c['max_score'] ); ?>">
                        </td>
                        <td style="text-align:center">
                            <input type="radio" name="exam_component" value="<?php echo esc_attr( (string) $c['id'] ); ?>"
                                   style="width:auto" <?php checked( ! empty( $c['is_exam'] ) ); ?>>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td><strong>Total</strong></td>
                        <td><strong id="weight-total"><?php echo esc_html( (string) array_sum( array_map( static fn( array $c ): float => (float) $c['max_score'], $components ) ) ); ?></strong></td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>

            <details style="margin-top:12px">
                <summary style="cursor:pointer;font-weight:600;font-size:14px">Add another assessment</summary>
                <div class="educbt-grid" style="margin-top:10px">
                    <div><label>Name</label><input name="new_component[name]" type="text" placeholder="Third CA Test"></div>
                    <div><label>Weight</label><input name="new_component[max_score]" type="number" min="0" max="100" step="1"></div>
                </div>
            </details>

            <button type="submit" class="educbt-btn educbt-btn--primary" style="margin-top:14px">Save marking scheme</button>
        </form>

        <script>
        /* Show the running total as it is typed. Discovering the weights do not add up
           only after saving means retyping the lot. */
        (function () {
            var form = document.currentScript.closest('section').querySelector('form');
            var total = document.getElementById('weight-total');

            function recalc() {
                var sum = 0;
                form.querySelectorAll('input[type=number]').forEach(function (i) {
                    sum += parseFloat(i.value || 0) || 0;
                });
                total.textContent = sum;
                total.style.color = sum === 100 ? 'var(--edu-accent)' : '#b91c1c';
            }

            form.addEventListener('input', recalc);
            recalc();
        }());
        </script>
    </section>

    <section class="educbt-card">
        <h2>School details</h2>

        <?php if ( empty( $school ) ) : ?>
            <p class="educbt-note educbt-note--warn">
                The school record could not be read, so these fields are empty.
                <strong>Do not save from here</strong> — it would write blanks over whatever
                is stored. Deactivate and reactivate the plugin to apply pending schema
                updates, then reload this page.
            </p>
        <?php endif; ?>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="educbt-form">
            <input type="hidden" name="action" value="educbt_update_school">
            <?php wp_nonce_field( 'educbt_update_school' ); ?>

            <div class="educbt-grid">
                <div><label for="school_name">School name</label><input id="school_name" name="school_name" type="text" value="<?php echo esc_attr( (string) ( $school['school_name'] ?? '' ) ); ?>"></div>
                <div><label for="principal_name">Principal</label><input id="principal_name" name="principal_name" type="text" value="<?php echo esc_attr( (string) ( $school['principal_name'] ?? '' ) ); ?>"></div>
                <div><label for="phone">Phone</label><input id="phone" name="phone" type="text" value="<?php echo esc_attr( (string) ( $school['phone'] ?? '' ) ); ?>"></div>
                <div><label for="email">Email</label><input id="email" name="email" type="email" value="<?php echo esc_attr( (string) ( $school['email'] ?? '' ) ); ?>"></div>
                <div><label for="logo">Logo URL</label><input id="logo" name="logo" type="url" value="<?php echo esc_attr( (string) ( $school['logo'] ?? '' ) ); ?>"></div>
                <div><label for="address">Address</label><textarea id="address" name="address" rows="2"><?php echo esc_textarea( (string) ( $school['address'] ?? '' ) ); ?></textarea></div>
            </div>

            <?php if ( ! empty( $school['logo'] ) ) : ?>
                <p style="margin-top:12px"><img src="<?php echo esc_url( (string) $school['logo'] ); ?>" alt="" style="max-width:110px;border:1px solid var(--edu-line);border-radius:8px;padding:6px;background:#fff"></p>
            <?php endif; ?>

            <p class="educbt-muted">
                The logo appears on the portal, on report sheets, and as the transcript watermark.
                School code <code><?php echo esc_html( (string) ( $school['school_code'] ?? '' ) ); ?></code> cannot be changed — results and transcripts already carry it.
            </p>

            <button type="submit" class="educbt-btn educbt-btn--primary" style="margin-top:8px">Save school details</button>
        </form>
    </section>
    <?php
};

require EDUCBT_PRO_PATH . 'templates/portal/shell.php';
