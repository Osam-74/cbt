/* Media picker for portal forms.
 *
 * Any element with data-educbt-media becomes a picker backed by the WordPress media
 * library. Previously every image field was a raw URL box, which meant a school
 * secretary had to upload a passport elsewhere, copy the address, and paste it in.
 *
 * Progressive: if wp.media is unavailable the hidden input is still editable, so the
 * form never becomes unusable.
 */
(function () {
    'use strict';

    function init(root) {
        var fields = (root || document).querySelectorAll('[data-educbt-media]');

        Array.prototype.forEach.call(fields, function (field) {
            if (field.dataset.educbtMediaReady) { return; }
            field.dataset.educbtMediaReady = '1';

            var input = field.querySelector('input[type=hidden]');
            var preview = field.querySelector('[data-preview]');
            var pick = field.querySelector('[data-pick]');
            var clear = field.querySelector('[data-clear]');
            var frame;

            function render(url) {
                if (!preview) { return; }
                preview.innerHTML = url
                    ? '<img src="' + encodeURI(url) + '" alt="">'
                    : '<span class="educbt-media__empty">No image chosen</span>';
                if (clear) { clear.hidden = !url; }
            }

            render(input && input.value ? input.value : '');

            if (!window.wp || !window.wp.media) {
                // No media library on this screen: fall back to a plain URL box so the
                // field still works rather than becoming a dead button.
                if (pick) {
                    pick.textContent = 'Paste an image address';
                    pick.onclick = function () {
                        var url = window.prompt('Image address', input.value || '');
                        if (url !== null) { input.value = url.trim(); render(input.value); }
                    };
                }
                return;
            }

            if (pick) {
                pick.onclick = function (e) {
                    e.preventDefault();

                    if (!frame) {
                        frame = window.wp.media({
                            title: field.dataset.educbtMedia || 'Choose an image',
                            button: { text: 'Use this image' },
                            library: { type: 'image' },
                            multiple: false
                        });

                        frame.on('select', function () {
                            var img = frame.state().get('selection').first().toJSON();
                            // Prefer a medium size where one exists: a 4MB phone photo
                            // as a passport makes every page that shows it slow.
                            var url = (img.sizes && img.sizes.medium && img.sizes.medium.url) || img.url;
                            input.value = url;
                            render(url);
                        });
                    }

                    frame.open();
                };
            }

            if (clear) {
                clear.onclick = function (e) {
                    e.preventDefault();
                    input.value = '';
                    render('');
                };
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { init(document); });
    } else {
        init(document);
    }

    window.EduCBTMedia = { init: init };
}());
