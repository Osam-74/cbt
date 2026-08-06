<?php
/**
 * Transcripts — issue an official record of a student's whole time at the school.
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
            // Match the full name too. Searching "Chidi Nwosu" previously matched
            // neither first_name nor last_name and returned nothing, which looked
            // like the student did not exist.
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
}

$issued = (array) $wpdb->get_results(
    $wpdb->prepare(
        "SELECT t.serial, t.purpose, t.issued_at, t.status,
                CONCAT(s.first_name,' ',s.last_name) AS student_name
         FROM {$transcripts} t
         INNER JOIN {$students} s ON s.id = t.student_id
         WHERE t.school_id = %d ORDER BY t.id DESC LIMIT 25",
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
                    <thead><tr><th>Adm. no.</th><th>Student</th><th>Status</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ( $found as $s ) : ?>
                        <tr>
                            <td><code><?php echo esc_html( (string) $s['admission_number'] ); ?></code></td>
                            <td><?php echo esc_html( $s['first_name'] . ' ' . $s['last_name'] ); ?></td>
                            <td><?php echo esc_html( ucfirst( (string) $s['status'] ) ); ?></td>
                            <td>
                                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:flex;gap:8px">
                                    <input type="hidden" name="action" value="educbt_issue_transcript">
                                    <input type="hidden" name="student_id" value="<?php echo esc_attr( (string) $s['id'] ); ?>">
                                    <?php wp_nonce_field( 'educbt_issue_transcript' ); ?>
                                    <input name="purpose" type="text" placeholder="Purpose, e.g. transfer" style="padding:6px">
                                    <button type="submit" class="educbt-btn educbt-btn--primary">Issue</button>
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
                <thead><tr><th>Serial</th><th>Student</th><th>Purpose</th><th>Issued</th><th>Status</th></tr></thead>
                <tbody>
                <?php foreach ( $issued as $t ) : ?>
                    <tr>
                        <td><code><?php echo esc_html( (string) $t['serial'] ); ?></code></td>
                        <td><?php echo esc_html( (string) $t['student_name'] ); ?></td>
                        <td><?php echo esc_html( (string) ( $t['purpose'] ?: '—' ) ); ?></td>
                        <td><?php echo esc_html( mysql2date( 'j M Y', (string) $t['issued_at'] ) ); ?></td>
                        <td><span class="educbt-pill"><?php echo esc_html( ucfirst( (string) $t['status'] ) ); ?></span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <p class="educbt-muted" style="margin-top:10px">Every copy is recorded, so a transcript can always be traced back.</p>
        <?php endif; ?>
    </section>
    <?php
};

require EDUCBT_PRO_PATH . 'templates/portal/shell.php';
