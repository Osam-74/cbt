<?php
/**
 * Activity log.
 *
 * You asked how a principal sees what teachers and students are doing. It needs no
 * special surveillance feature: school-wide scope plus this log is a truthful
 * record of who did what, and defensible in a dispute.
 *
 * @var array<string,mixed> $educbt
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

global $wpdb;
$school_id = (int) $educbt['school_id'];

$table = $wpdb->prefix . 'educbt_audit_logs';
$page  = max( 1, (int) ( $_GET['p'] ?? 1 ) );
$per   = 50;

$rows = (array) $wpdb->get_results(
    $wpdb->prepare(
        "SELECT * FROM {$table} WHERE school_id = %d ORDER BY id DESC LIMIT %d OFFSET %d",
        $school_id,
        $per,
        ( $page - 1 ) * $per
    ),
    ARRAY_A
);

$educbt_title = 'Activity';

$educbt_body = static function () use ( $rows, $page ): void {
    ?>
    <section class="educbt-card">
        <?php if ( empty( $rows ) ) : ?>
            <p class="educbt-muted">No activity recorded yet.</p>
        <?php else : ?>
            <table class="educbt-table">
                <thead><tr><th>When</th><th>Action</th><th>By</th></tr></thead>
                <tbody>
                <?php foreach ( $rows as $row ) :
                    $user = ! empty( $row['user_id'] ) ? get_userdata( (int) $row['user_id'] ) : null; ?>
                    <tr>
                        <td style="white-space:nowrap"><?php echo esc_html( mysql2date( 'j M, g:ia', (string) ( $row['created_at'] ?? '' ) ) ); ?></td>
                        <td><?php echo esc_html( str_replace( '_', ' ', (string) ( $row['action'] ?? '' ) ) ); ?></td>
                        <td><?php echo esc_html( $user ? $user->display_name : '—' ); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <div style="margin-top:14px;display:flex;gap:10px">
                <?php if ( $page > 1 ) : ?>
                    <a class="educbt-btn" href="<?php echo esc_url( add_query_arg( 'p', $page - 1 ) ); ?>">Newer</a>
                <?php endif; ?>
                <?php if ( count( $rows ) === 50 ) : ?>
                    <a class="educbt-btn" href="<?php echo esc_url( add_query_arg( 'p', $page + 1 ) ); ?>">Older</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </section>
    <?php
};

require EDUCBT_PRO_PATH . 'templates/portal/shell.php';
