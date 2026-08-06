<?php
/**
 * Renders the transcript just issued.
 *
 * Held in a short-lived transient rather than written to disk: an official document
 * sitting in the uploads folder is a document anyone with the URL can read.
 *
 * @var array<string,mixed> $educbt
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

$key  = 'educbt_transcript_' . get_current_user_id();
$html = get_transient( $key );

delete_transient( $key );

if ( is_string( $html ) && $html !== '' ) {
    echo $html; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped when built
    return;
}

$educbt_title = 'Transcript';

$educbt_body = static function (): void {
    ?>
    <section class="educbt-card educbt-card--narrow">
        <p class="educbt-note educbt-note--warn">That transcript is no longer available to print.</p>
        <p class="educbt-muted">Issue it again from the Transcripts page — every copy is recorded.</p>
        <p><a class="educbt-btn" href="<?php echo esc_url( home_url( '/portal/school/transcripts/' ) ); ?>">Back to transcripts</a></p>
    </section>
    <?php
};

require EDUCBT_PRO_PATH . 'templates/portal/shell.php';
