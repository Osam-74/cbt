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

    /**
     * Center-crop an already-uploaded image to a square, in place.
     *
     * The school crest is used both as a document watermark (any shape is fine
     * there) AND as the browser-tab favicon, which only has a square slot. A
     * school uploading a wide banner-style logo used to get it squished flat in
     * every browser tab — this crops the longer side down to match the shorter
     * one, centered, so the crest reads correctly wherever it is shown small.
     * Uses WordPress's own image editor (GD or Imagick, whichever the host has)
     * so no new dependency is introduced.
     *
     * @param string $local_path Absolute path to the file on disk (e.g. $movefile['file']).
     * @return bool True if the file was cropped (or was already square), false on failure.
     */
    public static function crop_to_square( string $local_path ): bool {
        if ( $local_path === '' || ! file_exists( $local_path ) ) {
            return false;
        }

        if ( ! function_exists( 'wp_get_image_editor' ) ) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }

        $size = @getimagesize( $local_path );
        if ( false === $size ) {
            return false;
        }

        [ $width, $height ] = $size;

        if ( $width === $height ) {
            return true; // Already square — nothing to do.
        }

        $editor = wp_get_image_editor( $local_path );
        if ( is_wp_error( $editor ) ) {
            return false;
        }

        $side = min( $width, $height );
        $src_x = (int) round( ( $width - $side ) / 2 );
        $src_y = (int) round( ( $height - $side ) / 2 );

        // crop( $src_x, $src_y, $src_w, $src_h, $dst_w = null, $dst_h = null ).
        // A cap of 512px keeps the favicon/preview light without visibly softening
        // a typical school crest.
        $target = min( $side, 512 );
        $cropped = $editor->crop( $src_x, $src_y, $side, $side, $target, $target );
        if ( is_wp_error( $cropped ) ) {
            return false;
        }

        $saved = $editor->save( $local_path );

        return ! is_wp_error( $saved );
    }
}
