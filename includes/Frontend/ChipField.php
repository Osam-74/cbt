<?php

namespace EduCBTPro\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The same "chip-select" multi-select used on Staff → Assign a class or
 * subject, reused elsewhere so every multi-select in the portal looks and
 * behaves identically. Unlike the staff page's version (which always starts
 * empty for a new assignment row), this variant supports pre-selecting
 * values that were already saved — e.g. the promotion page's "subjects
 * that must be passed" list.
 *
 * Self-contained: emits its own <style> and <script> alongside the markup,
 * so a page can drop this in without any separate asset to enqueue. Safe to
 * call more than once on the same page — the CSS/JS block is only printed
 * once per request.
 */
class ChipField {

    private static bool $assets_printed = false;

    /**
     * @param array<int,array{value:string,label:string}> $options
     * @param array<int,string>                           $selected
     */
    public static function render( string $name, array $options, array $selected = [], string $placeholder = 'Click to select…' ): string {
        $selected_set = array_flip( array_map( 'strval', $selected ) );

        ob_start();
        ?>
        <div class="chip-select educbt-chipfield" data-name="<?php echo esc_attr( $name ); ?>">
            <div class="chip-select__input">
                <div class="chip-select__chips"></div>
                <div class="chip-select__placeholder">
                    <?php echo esc_html( $placeholder ); ?>
                </div>
                <svg class="chip-select__arrow" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
            </div>
            <div class="chip-select__dropdown" hidden>
                <?php foreach ( $options as $opt ) : ?>
                    <div class="chip-select__option<?php echo isset( $selected_set[ (string) $opt['value'] ] ) ? ' is-selected' : ''; ?>"
                         data-value="<?php echo esc_attr( (string) $opt['value'] ); ?>">
                        <?php echo esc_html( (string) $opt['label'] ); ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
        if ( ! self::$assets_printed ) {
            self::$assets_printed = true;
            self::print_assets();
        }

        return (string) ob_get_clean();
    }

    private static function print_assets(): void {
        ?>
        <style>
        .chip-select { position: relative; }
        .chip-select__input {
            min-height: 42px; border: 1.5px solid var(--line, #ccc); border-radius: 10px;
            padding: 6px 36px 6px 8px; background: #fff; cursor: pointer; position: relative;
            display: flex; flex-wrap: wrap; gap: 4px; align-items: center;
        }
        .chip-select__input:hover { border-color: var(--moss, #4a7c59); }
        .chip-select__chips { display: contents; }
        .chip-select__placeholder { color: var(--muted, #6b7280); font-size: 13px; }
        .chip-select__arrow { position: absolute; right: 10px; top: 50%; transform: translateY(-50%); color: var(--muted, #6b7280); pointer-events: none; }
        .chip-select__dropdown {
            position: absolute; top: 100%; left: 0; right: 0; z-index: 100;
            max-height: 200px; overflow-y: auto; border: 1px solid var(--line, #ccc);
            border-radius: 10px; background: #fff; box-shadow: 0 8px 24px rgba(0,0,0,.12);
            margin-top: 4px;
        }
        .chip-select__dropdown[hidden] { display: none !important; }
        .chip-select__option { padding: 8px 12px; font-size: 13px; cursor: pointer; }
        .chip-select__option:hover { background: var(--lemon-soft, #F3F7DC); }
        .chip-select__option.is-selected { background: #d1fae5; color: #065f46; font-weight: 600; }
        .chip-select__chip {
            display: inline-flex; align-items: center; gap: 4px;
            background: #ecfdf5; border: 1px solid #a7f3d0; border-radius: 999px;
            padding: 3px 8px 3px 10px; font-size: 12px; font-weight: 600; color: #065f46;
        }
        .chip-select__remove {
            background: none; border: none; cursor: pointer; font-size: 14px;
            line-height: 1; color: #6b7280; padding: 0 2px; font-family: inherit;
        }
        .chip-select__remove:hover { color: #b91c1c; }
        </style>
        <script>
        (function () {
            function escHtml(s) {
                var d = document.createElement('div');
                d.textContent = s;
                return d.innerHTML;
            }

            function toggle(chipSelect, option) {
                var val = option.getAttribute('data-value');
                var text = option.textContent.trim();
                var chips = chipSelect.querySelector('.chip-select__chips');
                var placeholder = chipSelect.querySelector('.chip-select__placeholder');

                if (option.classList.contains('is-selected')) {
                    option.classList.remove('is-selected');
                    var existing = chips.querySelector('[data-chip-value="' + val + '"]');
                    if (existing) existing.remove();
                    var hidden = chipSelect.querySelector('input[type="hidden"][value="' + val + '"]');
                    if (hidden) hidden.remove();
                } else {
                    option.classList.add('is-selected');
                    var chip = document.createElement('span');
                    chip.className = 'chip-select__chip';
                    chip.setAttribute('data-chip-value', val);
                    chip.innerHTML = escHtml(text) + '<button type="button" class="chip-select__remove" aria-label="Remove">\u00d7</button>';
                    chip.querySelector('.chip-select__remove').addEventListener('click', function (e) {
                        e.preventDefault();
                        e.stopPropagation();
                        toggle(chipSelect, option);
                    });
                    chips.appendChild(chip);
                    var hiddenInput = document.createElement('input');
                    hiddenInput.type = 'hidden';
                    hiddenInput.name = chipSelect.dataset.name;
                    hiddenInput.value = val;
                    chipSelect.appendChild(hiddenInput);
                }

                var hasChips = chips.children.length > 0;
                if (placeholder) placeholder.style.display = hasChips ? 'none' : '';
            }

            function init(chipSelect) {
                if (!chipSelect || chipSelect.dataset.chipfieldInit === 'true') return;
                chipSelect.dataset.chipfieldInit = 'true';

                var input = chipSelect.querySelector('.chip-select__input');
                var dropdown = chipSelect.querySelector('.chip-select__dropdown');
                if (!input || !dropdown) return;

                // Pre-populate chips + hidden inputs for options already
                // marked is-selected server-side (previously saved values).
                chipSelect.querySelectorAll('.chip-select__option.is-selected').forEach(function (opt) {
                    opt.classList.remove('is-selected');
                    toggle(chipSelect, opt);
                });

                input.addEventListener('click', function (e) {
                    if (e.target.closest('.chip-select__remove')) return;
                    e.stopPropagation();
                    var isOpen = !dropdown.hidden;
                    document.querySelectorAll('.chip-select__dropdown:not([hidden])').forEach(function (d) {
                        d.hidden = true;
                    });
                    dropdown.hidden = isOpen;
                });

                chipSelect.querySelectorAll('.chip-select__option').forEach(function (opt) {
                    opt.addEventListener('click', function (e) {
                        e.stopPropagation();
                        toggle(chipSelect, opt);
                    });
                });

                document.addEventListener('click', function (e) {
                    if (!chipSelect.contains(e.target)) {
                        dropdown.hidden = true;
                    }
                });
            }

            function initAll() {
                document.querySelectorAll('.educbt-chipfield').forEach(init);
            }

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', initAll);
            } else {
                initAll();
            }
        })();
        </script>
        <?php
    }
}
