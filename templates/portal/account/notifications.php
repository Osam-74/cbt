<?php
/**
 * Notifications — the bell in the top bar.
 *
 * @var array<string,mixed> $educbt
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

$school_id = (int) $educbt['school_id'];
$user_id   = get_current_user_id();
$service   = new \EduCBTPro\Services\NotificationService();

if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && check_admin_referer( 'educbt_read_all' ) ) {
    $service->mark_all_read( $school_id, $user_id );
}

// Paged, so an inbox that has been running a whole term is still navigable.
$per_page = 20;
$page     = max( 1, (int) ( $_GET['p'] ?? 1 ) );
$all      = $service->inbox( $school_id, $user_id, false, 200 );
$items    = array_slice( $all, ( $page - 1 ) * $per_page, $per_page );
$has_more = count( $all ) > $page * $per_page;

$educbt_title = 'Notifications';

$educbt_body = static function () use ( $items, $page, $has_more ): void {
    ?>
    <?php if ( empty( $items ) ) : ?>
        <div class="educbt-card"><p class="educbt-muted">Nothing yet.</p></div>
        <?php return; ?>
    <?php endif; ?>

    <section class="educbt-card">
        <form method="post" style="margin-bottom:14px">
            <?php wp_nonce_field( 'educbt_read_all' ); ?>
            <button type="submit" class="educbt-btn">Mark all as read</button>
        </form>

        <ul class="educbt-list">
        <?php foreach ( $items as $item ) : ?>
            <li style="align-items:flex-start;<?php echo empty( $item['is_read'] ) ? 'background:var(--edu-accent-soft);border-radius:8px;padding:12px 10px' : ''; ?>">
                <div style="flex:1 1 auto;min-width:0">
                    <strong><?php echo esc_html( (string) $item['title'] ); ?></strong>
                    <?php if ( ! empty( $item['body'] ) ) : ?>
                        <?php
                        // The message is shown in full here. Sending someone elsewhere
                        // to read two sentences was the wrong shape.
                        ?>
                        <div class="educbt-muted" style="margin-top:5px;white-space:pre-wrap"><?php echo esc_html( wp_strip_all_tags( (string) $item['body'] ) ); ?></div>
                    <?php endif; ?>
                    <div class="educbt-muted" style="margin-top:6px;font-size:12.5px">
                        <?php echo esc_html( mysql2date( 'j M Y, g:ia', (string) $item['created_at'] ) ); ?>
                    </div>
                </div>
                <div style="display:flex;gap:6px;margin-top:8px;flex-wrap:wrap">
                    <button type="button" class="educbt-btn" style="padding:4px 10px;font-size:12.5px" onclick="this.nextElementSibling.toggleAttribute('hidden')">Preview</button>
                    <div hidden style="flex-basis:100%;padding:8px;background:var(--edu-bg);border-radius:8px;margin-top:4px">
                        <?php echo esc_html( wp_strip_all_tags( (string) $item['body'] ) ); ?>
                    </div>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
                        <input type="hidden" name="action" value="educbt_flag_notification">
                        <input type="hidden" name="notification_id" value="<?php echo esc_attr( (string) $item['id'] ); ?>">
                        <?php wp_nonce_field( 'educbt_flag_notification' ); ?>
                        <button type="submit" class="educbt-btn" style="padding:4px 10px;font-size:12.5px">Flag</button>
                    </form>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
                        <input type="hidden" name="action" value="educbt_report_issue">
                        <input type="hidden" name="notification_id" value="<?php echo esc_attr( (string) $item['id'] ); ?>">
                        <?php wp_nonce_field( 'educbt_report_issue' ); ?>
                        <button type="submit" class="educbt-btn" style="padding:4px 10px;font-size:12.5px">Report issue</button>
                    </form>
                </div>
                <div class="notice-actions">
                    <?php if ( ! empty( $item['link'] ) ) : ?>
                        <a class="educbt-btn" href="<?php echo esc_url( (string) $item['link'] ); ?>">Go there</a>
                    <?php endif; ?>

                    <?php
                    $payload   = (array) json_decode( (string) ( $item['payload'] ?? '' ), true );
                    $responses = (array) ( $payload['responses'] ?? [] );
                    $last      = end( $responses );
                    $flagged   = is_array( $last ) && ( $last['action'] ?? '' ) === 'flag';
                    ?>

                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="notice-actions__form">
                        <input type="hidden" name="action" value="educbt_notice_respond">
                        <input type="hidden" name="notification_id" value="<?php echo esc_attr( (string) $item['id'] ); ?>">
                        <?php wp_nonce_field( 'educbt_notice_respond' ); ?>

                        <button type="submit" name="response" value="<?php echo $flagged ? 'resolved' : 'flag'; ?>"
                                class="educbt-btn notice-actions__btn" title="<?php echo $flagged ? 'Mark this flag resolved' : 'Flag this for attention'; ?>">
                            <?php echo $flagged ? '&#9873; Flag resolved' : '&#9872; Flag'; ?>
                        </button>

                        <button type="button" class="educbt-btn notice-actions__btn"
                                onclick="var b=this.closest('form').querySelector('.notice-report');b.hidden=!b.hidden;b.querySelector('textarea').focus();"
                                title="Report a problem with this">&#9888; Report</button>

                        <div class="notice-report" hidden>
                            <textarea name="note" rows="2" placeholder="What is the problem?"></textarea>
                            <button type="submit" name="response" value="report" class="educbt-btn educbt-btn--primary">Send report</button>
                        </div>
                    </form>

                    <?php if ( ! empty( $responses ) ) : ?>
                        <p class="educbt-muted notice-actions__log">
                            <?php
                            $labels = [ 'flag' => 'Flagged', 'resolved' => 'Flag resolved', 'report' => 'Reported' ];
                            echo esc_html( $labels[ (string) ( $last['action'] ?? '' ) ] ?? '' );
                            ?>
                            <?php if ( ! empty( $last['note'] ) ) : ?>
                                — <?php echo esc_html( (string) $last['note'] ); ?>
                            <?php endif; ?>
                        </p>
                    <?php endif; ?>
                </div>
            </li>
        <?php endforeach; ?>
        </ul>

        <div class="notice-pager">
            <?php if ( $page > 1 ) : ?>
                <a class="educbt-btn" href="<?php echo esc_url( add_query_arg( 'p', $page - 1 ) ); ?>">&larr; Previous</a>
            <?php endif; ?>
            <?php if ( ! empty( $has_more ) ) : ?>
                <a class="educbt-btn" href="<?php echo esc_url( add_query_arg( 'p', $page + 1 ) ); ?>">Older &rarr;</a>
            <?php endif; ?>
        </div>
    </section>
    <?php
};

require EDUCBT_PRO_PATH . 'templates/portal/shell.php';
