<?php
/**
 * Messages.
 *
 * Student-to-student is deliberately unsupported: a school portal is not a chat app,
 * and moderating one is not a job any school signed up for.
 *
 * @var array<string,mixed> $educbt
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

$school_id = (int) $educbt['school_id'];
$flash     = \EduCBTPro\Frontend\PortalActions::flash();

$service   = new \EduCBTPro\Services\AnnouncementService();
$threads   = $service->threads_for( $school_id, get_current_user_id(), 30 );
$thread_id = (int) $educbt['id'];
$messages  = $thread_id > 0 ? $service->thread_messages( $school_id, $thread_id, get_current_user_id() ) : [];

$educbt_title = 'Messages';

$educbt_body = static function () use ( $flash, $threads, $messages, $thread_id ): void {
    require EDUCBT_PRO_PATH . 'templates/portal/partials/flash.php';
    ?>
    <?php if ( $thread_id > 0 && ! empty( $messages ) ) : ?>
        <section class="educbt-card">
            <h2>Conversation</h2>
            <?php foreach ( $messages as $m ) :
                $user = get_userdata( (int) $m['sender_user_id'] ); ?>
                <div style="padding:12px 0;border-bottom:1px solid var(--edu-line)">
                    <p style="margin:0 0 4px"><strong><?php echo esc_html( $user ? $user->display_name : 'Unknown' ); ?></strong>
                        <span class="educbt-muted"><?php echo esc_html( mysql2date( 'j M, g:ia', (string) $m['created_at'] ) ); ?></span></p>
                    <div><?php echo wp_kses_post( wpautop( (string) $m['body'] ) ); ?></div>
                </div>
            <?php endforeach; ?>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="educbt-form" style="margin-top:14px">
                <input type="hidden" name="action" value="educbt_reply_thread">
                <input type="hidden" name="thread_id" value="<?php echo esc_attr( (string) $thread_id ); ?>">
                <?php wp_nonce_field( 'educbt_reply_thread' ); ?>
                <label for="body">Reply</label>
                <textarea id="body" name="body" rows="3" required></textarea>
                <button type="submit" class="educbt-btn educbt-btn--primary" style="margin-top:10px">Send</button>
            </form>
        </section>
    <?php endif; ?>

    <section class="educbt-card">
        <h2>Conversations</h2>
        <?php if ( empty( $threads ) ) : ?>
            <p class="educbt-muted">No conversations yet.</p>
        <?php else : ?>
            <ul class="educbt-list">
            <?php foreach ( $threads as $t ) : ?>
                <li>
                    <span><?php echo esc_html( (string) $t['subject'] ); ?></span>
                    <span class="educbt-muted"><?php echo esc_html( mysql2date( 'j M', (string) $t['last_message_at'] ) ); ?></span>
                    <a class="educbt-btn" href="<?php echo esc_url( home_url( '/portal/teacher/messages/' . (int) $t['id'] ) ); ?>">Open</a>
                </li>
            <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
    <?php
};

require EDUCBT_PRO_PATH . 'templates/portal/shell.php';
