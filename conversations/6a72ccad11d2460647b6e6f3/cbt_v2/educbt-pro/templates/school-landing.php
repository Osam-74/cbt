<?php
/**
 * A school's public front page — minimal: crest + two buttons.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

$school_id = absint( ( new \EduCBTPro\Core\TenantContext() )->resolve_from_host() ?? 0 );
$branding  = ( new \EduCBTPro\Services\DocumentBrandingService() )->letterhead( $school_id );

get_header();
?>
<main class="educbt-landing">
    <section class="educbt-landing__hero">
        <?php if ( $branding['logo'] !== '' ) : ?>
            <img class="educbt-landing__crest" src="<?php echo esc_url( $branding['logo'] ); ?>" alt="" style="max-height:72px;margin-bottom:24px">
        <?php endif; ?>

        <div class="educbt-landing__actions">
            <a class="educbt-btn educbt-btn--primary" href="<?php echo esc_url( wp_login_url( home_url( '/portal/' ) ) ); ?>">
                Go to Portal
            </a>
            <a class="educbt-btn" href="<?php echo esc_url( home_url( '/trial/' ) ); ?>">Try a Practice Test</a>
        </div>
    </section>
</main>
<?php
get_footer();
