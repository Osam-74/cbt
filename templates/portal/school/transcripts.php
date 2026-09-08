<?php
/**
 * Transcripts — issue an official record of a student's whole time at the school.
 *
 * Search shows every matched student with their issue status. If a student has
 * been issued before, the button says "Reissue" instead of "Issue". The issued
 * table groups by student — one row per student with an issue count and the
 * latest serial, not one row per transcript copy.
 *
 * @var array<string,mixed> $educbt
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

global $wpdb;
$school_id = (int) $educbt['school_id'];
$flash     = \EduCBTPro\Frontend\PortalActions::flash();

$students    = $wpdb->prefix . 'educbt_students';
$transcripts = \EduCBTPro\Core\Schema::table( 'transcripts' );

$search = sanitize_text_field( (string) ( $_GET['q'] ?? '' ) );
$found  = [];

if ( $search !== '' ) {
    $like  = '%' . $wpdb->esc_like( $search ) . '%';
    $found = (array) $wpdb->get_results(
        $wpdb->prepare(
            "SELECT id, admission_number, first_name, last_name, status FROM {$students}
             WHERE school_id = %d AND (
                   admission_number LIKE %s
                OR first_name LIKE %s
                OR last_name LIKE %s
                OR CONCAT(first_name, ' ', last_name) LIKE %s
                OR CONCAT(last_name, ' ', first_name) LIKE %s
             )
             ORDER BY last_name ASC LIMIT 25",
            $school_id, $like, $like, $like, $like, $like
        ),
        ARRAY_A
    );

    // For each found student, check if they have been previously issued.
    if ( ! empty( $found ) ) {
        foreach ( $found as &$s ) {
            $s['issued_count'] = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$transcripts} WHERE school_id = %d AND student_id = %d AND status NOT IN (%s)",
                    $school_id, (int) $s['id'], 'revoked'
                )
            );
            $s['latest_serial'] = (string) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT serial FROM {$transcripts} WHERE school_id = %d AND student_id = %d AND status NOT IN (%s) ORDER BY id DESC LIMIT 1",
                    $school_id, (int) $s['id'], 'revoked'
                )
            );
        }
        unset( $s );
    }
}

// Issued table — grouped by student, one row per student with issue count
// and the latest transcript info. No "Reissued" status — just "Issued".
$issued = (array) $wpdb->get_results(
    $wpdb->prepare(
        "SELECT t.student_id,
                CONCAT(s.first_name, ' ', s.last_name) AS student_name,
                s.admission_number,
                COUNT(*) AS issued_count,
                MAX(t.issued_at) AS latest_issued_at,
                (SELECT t2.serial FROM {$transcripts} t2
                 WHERE t2.school_id = t.school_id AND t2.student_id = t.student_id
                   AND t2.status NOT IN ('revoked')
                 ORDER BY t2.id DESC LIMIT 1) AS latest_serial,
                (SELECT t2.purpose FROM {$transcripts} t2
                 WHERE t2.school_id = t.school_id AND t2.student_id = t.student_id
                   AND t2.status NOT IN ('revoked')
                 ORDER BY t2.id DESC LIMIT 1) AS latest_purpose
         FROM {$transcripts} t
         INNER JOIN {$students} s ON s.id = t.student_id
         WHERE t.school_id = %d AND t.status NOT IN ('revoked')
         GROUP BY t.student_id, student_name, s.admission_number
         ORDER BY latest_issued_at DESC
         LIMIT 25",
        $school_id
    ),
    ARRAY_A
);

$educbt_title = 'Transcripts';

$educbt_body = static function () use ( $flash, $search, $found, $issued ): void {
    require EDUCBT_PRO_PATH . 'templates/portal/partials/flash.php';
    ?>
    <section class="educbt-card">
        <h2>Issue a transcript</h2>
        <form method="get" class="educbt-form" style="display:flex;gap:10px;align-items:flex-end">
            <div style="flex:1 1 auto">
                <label for="q">Find a student</label>
                <input id="q" name="q" type="text" value="<?php echo esc_attr( $search ); ?>" placeholder="Name or admission number">
            </div>
            <button class="educbt-btn" type="submit">Search</button>
        </form>

        <?php if ( $search !== '' ) : ?>
            <?php if ( empty( $found ) ) : ?>
                <p class="educbt-muted" style="margin-top:14px">No student matched.</p>
            <?php else : ?>
                <table class="educbt-table" style="margin-top:14px">
                    <thead><tr><th>Adm. no.</th><th>Student</th><th>Status</th><th>Issued</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ( $found as $s ) :
                        $previously_issued = (int) $s['issued_count'] > 0;
                        ?>
                        <tr>
                            <td><code><?php echo esc_html( (string) $s['admission_number'] ); ?></code></td>
                            <td><?php echo esc_html( $s['first_name'] . ' ' . $s['last_name'] ); ?></td>
                            <td><?php echo esc_html( ucfirst( (string) $s['status'] ) ); ?></td>
                            <td>
                                <?php if ( $previously_issued ) : ?>
                                    <span class="educbt-pill"><?php echo esc_html( (string) $s['issued_count'] ); ?> time<?php echo (int) $s['issued_count'] > 1 ? 's' : ''; ?></span>
                                    <div class="educbt-muted" style="font-size:11px;margin-top:2px">Latest: <?php echo esc_html( (string) $s['latest_serial'] ); ?></div>
                                <?php else : ?>
                                    <span class="educbt-muted">Not issued</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:flex;gap:8px">
                                    <input type="hidden" name="action" value="educbt_issue_transcript">
                                    <input type="hidden" name="student_id" value="<?php echo esc_attr( (string) $s['id'] ); ?>">
                                    <?php wp_nonce_field( 'educbt_issue_transcript' ); ?>
                                    <input name="purpose" type="text" placeholder="Purpose, e.g. transfer" style="padding:6px">
                                    <button type="submit" class="educbt-btn educbt-btn--primary">
                                        <?php echo $previously_issued ? 'Reissue' : 'Issue'; ?>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        <?php endif; ?>
    </section>

    <section class="educbt-card">
        <h2>Issued</h2>
        <?php if ( empty( $issued ) ) : ?>
            <p class="educbt-muted">None yet.</p>
        <?php else : ?>
            <table class="educbt-table">
                <thead><tr><th>Adm. no.</th><th>Student</th><th>Serial</th><th>Purpose</th><th>Times Issued</th><th>Last Issued</th><th>Status</th></tr></thead>
                <tbody>
                <?php foreach ( $issued as $t ) : ?>
                    <tr>
                        <td><code><?php echo esc_html( (string) $t['admission_number'] ); ?></code></td>
                        <td><?php echo esc_html( (string) $t['student_name'] ); ?></td>
                        <td><code><?php echo esc_html( (string) $t['latest_serial'] ); ?></code></td>
                        <td><?php echo esc_html( (string) ( $t['latest_purpose'] ?: '—' ) ); ?></td>
                        <td><span class="educbt-pill"><?php echo esc_html( (string) (int) $t['issued_count'] ); ?></span></td>
                        <td><?php echo esc_html( mysql2date( 'j M Y', (string) $t['latest_issued_at'] ) ); ?></td>
                        <td><span class="educbt-pill educbt-pill--published">Issued</span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <p class="educbt-muted" style="margin-top:10px">One row per student. The serial shown is the latest issue. All copies are recorded for traceability.</p>
        <?php endif; ?>
    </section>
    <?php
};

require EDUCBT_PRO_PATH . 'templates/portal/shell.php';
