<?php
/**
 * Shown when a section has no template yet. Deliberately honest rather than blank:
 * a school piloting an early build should be told what is not built, not left
 * staring at an empty page wondering whether it failed to load.
 *
 * @var array<string,mixed> $educbt
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$educbt_title = ucfirst( $educbt['section'] !== '' ? $educbt['section'] : $educbt['area'] );

$educbt_body = static function () use ( $educbt ): void {
    ?>
    <div class="educbt-card">
        <p class="educbt-note">
            This section is routed and access-controlled, but its screen has not been built yet.
        </p>
        <p class="educbt-muted">
            <code><?php echo esc_html( '/portal/' . $educbt['area'] . '/' . $educbt['section'] ); ?></code>
        </p>
    </div>
    <?php
};

require EDUCBT_PRO_PATH . 'templates/portal/shell.php';
