<?php

namespace EduCBTPro\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * A reusable image field backed by the WordPress media library.
 *
 * Every image on the site — passports, school crests, question images, option
 * images — goes through this, so a school never has to upload a file elsewhere and
 * paste an address in.
 */
class MediaField {

    /**
     * @param string $name  form field name
     * @param string $value current URL
     * @param string $label picker title
     * @param string $shape 'passport' for a portrait crop, 'wide' otherwise
     */
    public static function render( string $name, string $value = '', string $label = 'Choose an image', string $shape = 'wide' ): string {
        ob_start();
        ?>
        <div class="educbt-media educbt-media--<?php echo esc_attr( $shape ); ?>"
             data-educbt-media="<?php echo esc_attr( $label ); ?>">
            <div class="educbt-media__preview" data-preview>
                <?php if ( $value !== '' ) : ?>
                    <img src="<?php echo esc_url( $value ); ?>" alt="">
                <?php else : ?>
                    <span class="educbt-media__empty">No image chosen</span>
                <?php endif; ?>
            </div>
            <div class="educbt-media__actions">
                <input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>">
                <button type="button" class="educbt-btn" data-pick><?php echo esc_html( $label ); ?></button>
                <button type="button" class="educbt-btn educbt-btn--ghost" data-clear <?php echo $value === '' ? 'hidden' : ''; ?>>Remove</button>
            </div>
        </div>
        <?php

        return (string) ob_get_clean();
    }
}
