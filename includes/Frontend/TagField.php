<?php

namespace EduCBTPro\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * A type-ahead multi-value field.
 *
 * Replaces "type the codes, comma separated". A principal typing ENG, MTH from
 * memory is one typo away from silently changing who repeats a year, and nothing
 * would flag it because a wrong code simply never matches.
 */
class TagField {

    /**
     * @param array<int,array{value:string,label:string}> $options
     * @param array<int,string>                           $selected
     */
    public static function render( string $name, array $options, array $selected = [], string $placeholder = 'Start typing…' ): string {
        ob_start();
        ?>
        <div class="educbt-tags"
             data-educbt-tags="<?php echo esc_attr( (string) wp_json_encode( array_values( $options ) ) ); ?>"
             data-tags-selected="<?php echo esc_attr( (string) wp_json_encode( array_values( $selected ) ) ); ?>"
             data-tags-name="<?php echo esc_attr( $name ); ?>">
            <div class="educbt-tags__chips" data-tag-chips></div>
            <div class="educbt-tags__entry">
                <input type="text" data-tag-input autocomplete="off" placeholder="<?php echo esc_attr( $placeholder ); ?>">
                <div class="educbt-suggest" data-tag-list hidden></div>
            </div>
        </div>
        <?php

        return (string) ob_get_clean();
    }
}
