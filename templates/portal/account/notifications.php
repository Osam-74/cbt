<?php
/**
 * Notifications — the bell in the top bar.
 *
 * The list shows the SUBJECT only. Opening one reveals the message and its two
 * actions. The previous version printed every message in full, carried two
 * competing sets of Flag/Report buttons, and never marked anything read, so the
 * unread badge stayed lit after the notification had been read.
 *
 * @var array<string,mixed> $educbt
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

$school_id = (int) $educbt['school_id'];
$user_id   = get_current_user_id();
$service   = new \EduCBTPro\Services\NotificationService();

// Each button posts its own name, and only that one's nonce is checked.
//
// check_admin_referer() does not return false on a mismatch — it calls wp_die()
// with "The link you followed has expired". Running two of them on the same
// request meant that whichever button you pressed, the OTHER check ran, failed
// and killed the page. Verify the nonce that belongs to the action being asked
// for, and nothing else.
$deleted = 0;

if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
    $intent = sanitize_key( $_POST['notice_action'] ?? '' );
    $nonce  = (string) ( $_POST['_wpnonce'] ?? '' );

    if ( $intent === 'read_all' && wp_verify_nonce( $nonce, 'educbt_read_all' ) ) {
        $service->mark_all_read( $school_id, $user_id );
    }

    if ( $intent === 'delete_read' && wp_verify_nonce( $nonce, 'educbt_delete_read' ) ) {
        $deleted = $service->delete_all_read( $school_id, $user_id );
    }
}

// Opening a notification marks it read. Doing it here rather than in JS means the
// badge is already correct by the time the page paints.
$open_id = absint( $_GET['open'] ?? 0 );
if ( $open_id > 0 ) {
    $service->mark_read( $school_id, $user_id, [ $open_id ] );
}

// Delete a single notification.
$delete_id = absint( $_GET['delete'] ?? 0 );
if ( $delete_id > 0 && wp_verify_nonce( (string) ( $_GET['_wpnonce'] ?? '' ), 'educbt_delete_notification' ) ) {
    $service->delete( $school_id, $user_id, $delete_id );
}

$per_page = 20;
$page     = max( 1, (int) ( $_GET['p'] ?? 1 ) );
$all      = $service->inbox( $school_id, $user_id, false, 200 );
$items    = array_slice( $all, ( $page - 1 ) * $per_page, $per_page );
$has_more = count( $all ) > $page * $per_page;

$educbt_title = 'Notifications';

$educbt_body = static function () use ( $items, $page, $has_more, $open_id ): void {
    ?>
    <?php if ( empty( $items ) ) : ?>
        <div class="educbt-card"><p class="educbt-muted">Nothing yet.</p></div>
        <?php return; ?>
    <?php endif; ?>

    <section class="educbt-card">
        <div style="display:flex;gap:8px;margin-bottom:14px;flex-wrap:wrap">
            <form method="post" style="display:inline">
                <input type="hidden" name="notice_action" value="read_all">
                <?php wp_nonce_field( 'educbt_read_all' ); ?>
                <button type="submit" class="educbt-btn">Mark all as read</button>
            </form>
            <form method="post" style="display:inline">
                <input type="hidden" name="notice_action" value="delete_read">
                <?php wp_nonce_field( 'educbt_delete_read' ); ?>
                <button type="submit" class="educbt-btn" onclick="return confirm('Delete all read notifications? This cannot be undone.')">Clear read</button>
            </form>
        </div>

        <ul class="educbt-list" style="display:flex;flex-direction:column;gap:8px">
        <?php foreach ( $items as $item ) :
            $id       = absint( $item['id'] );
            $is_open  = ( $id === $open_id );
            $unread   = empty( $item['is_read'] ) && ! $is_open;
            $body     = trim( wp_strip_all_tags( (string) ( $item['body'] ?? '' ) ) );
            $link     = (string) ( $item['link'] ?? '' );
            ?>
            <li id="n<?php echo esc_attr( (string) $id ); ?>"
                style="display:block;border:1px solid var(--edu-line);border-radius:10px;padding:12px<?php echo $unread ? ';background:var(--edu-accent-soft)' : ''; ?>">

                <div style="display:flex;align-items:flex-start;gap:10px;flex-wrap:wrap">
                    <div style="flex:1 1 auto;min-width:0">
                        <strong><?php echo esc_html( (string) $item['title'] ); ?></strong>
                        <?php if ( $unread ) : ?>
                            <span class="educbt-pill educbt-pill--draft" style="font-size:.7rem;margin-left:6px">New</span>
                        <?php endif; ?>
                        <div class="educbt-muted" style="margin-top:5px;font-size:12.5px">
                            <?php echo esc_html( mysql2date( 'j M Y, g:ia', (string) $item['created_at'] ) ); ?>
                        </div>
                    </div>

                    <?php if ( ! $is_open ) : ?>
                        <div style="display:flex;gap:4px">
                            <a class="educbt-btn" style="padding:4px 12px;font-size:12.5px"
                               href="<?php echo esc_url( add_query_arg( 'open', $id ) . '#n' . $id ); ?>">Open</a>
                            <a class="educbt-btn" style="padding:4px 10px;font-size:12.5px;color:#c44;border-color:#e0c4c4"
                               href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'delete', $id ), 'educbt_delete_notification' ) ); ?>"
                               onclick="return confirm('Delete this notification?')">Delete</a>
                        </div>
                    <?php else : ?>
                        <div style="display:flex;gap:4px">
                            <a class="educbt-btn" style="padding:4px 12px;font-size:12.5px"
                               href="<?php echo esc_url( remove_query_arg( 'open' ) ); ?>">Close</a>
                            <a class="educbt-btn" style="padding:4px 10px;font-size:12.5px;color:#c44;border-color:#e0c4c4"
                               href="<?php echo esc_url( wp_nonce_url( remove_query_arg( 'open', add_query_arg( 'delete', $id ) ), 'educbt_delete_notification' ) ); ?>"
                               onclick="return confirm('Delete this notification?')">Delete</a>
                        </div>
                    <?php endif; ?>
                </div>

                <?php if ( $is_open ) : ?>
                    <?php if ( $body !== '' ) : ?>
                        <div style="margin-top:10px;padding:10px;background:var(--edu-bg);border-radius:8px;white-space:pre-wrap"><?php echo esc_html( $body ); ?></div>
                    <?php endif; ?>

                    <div style="display:flex;gap:8px;margin-top:10px;flex-wrap:wrap">
                        <?php if ( $link !== '' ) : ?>
                            <a class="educbt-btn educbt-btn--primary" style="padding:5px 14px;font-size:12.5px"
                               href="<?php echo esc_url( $link ); ?>">Review</a>
                        <?php endif; ?>

                        <button type="button" class="educbt-btn" style="padding:5px 14px;font-size:12.5px"
                                onclick="var f=document.getElementById('report-<?php echo esc_attr( (string) $id ); ?>');var open=f.style.display!=='none';f.style.display=open?'none':'flex';if(!open)f.querySelector('textarea').focus();">Report issue</button>
                    </div>

                    <?php // `hidden` loses to any CSS rule that sets display on forms,
                          // which is why this box was already open before the button
                          // was clicked. Drive it from inline display instead. ?>
                    <form id="report-<?php echo esc_attr( (string) $id ); ?>" method="post"
                          action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
                          style="margin-top:10px;display:none;flex-direction:column;gap:6px">
                        <input type="hidden" name="action" value="educbt_notice_respond">
                        <input type="hidden" name="notification_id" value="<?php echo esc_attr( (string) $id ); ?>">
                        <?php wp_nonce_field( 'educbt_notice_respond' ); ?>
                        <textarea name="note" rows="2" class="educbt-input" placeholder="What is the problem?"></textarea>
                        <div>
                            <button type="submit" name="response" value="report" class="educbt-btn educbt-btn--primary"
                                    style="padding:5px 14px;font-size:12.5px">Send report</button>
                        </div>
                    </form>

                    <?php
                    $payload   = (array) json_decode( (string) ( $item['payload'] ?? '' ), true );
                    $responses = (array) ( $payload['responses'] ?? [] );
                    $last      = end( $responses );
                    ?>
                    <?php if ( ! empty( $responses ) && is_array( $last ) ) : ?>
                        <p class="educbt-muted" style="margin-top:8px;font-size:12.5px">
                            <?php
                            $labels = [ 'flag' => 'Flagged', 'resolved' => 'Flag resolved', 'report' => 'Reported' ];
                            echo esc_html( $labels[ (string) ( $last['action'] ?? '' ) ] ?? '' );
                            ?>
                            <?php if ( ! empty( $last['note'] ) ) : ?>
                                — <?php echo esc_html( (string) $last['note'] ); ?>
                            <?php endif; ?>
                        </p>
                    <?php endif; ?>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
        </ul>

        <div class="notice-pager" style="margin-top:14px;display:flex;gap:8px">
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
