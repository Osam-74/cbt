/* Type-ahead tag picker.
 *
 * Used where a field holds several named things — compulsory subjects for promotion,
 * for instance. Typing filters a suggestion list; choosing one turns it into a
 * removable chip and clears the box for the next.
 *
 * Values are posted as hidden inputs, so the form still works exactly as a plain
 * multi-value field would. Asking a principal to type "ENG, MTH, CVE" from memory
 * was the problem: a typo silently changed who repeats a year.
 */
(function () {
    'use strict';

    function init(root) {
        var fields = (root || document).querySelectorAll('[data-educbt-tags]');

        Array.prototype.forEach.call(fields, function (field) {
            if (field.dataset.tagsReady) { return; }
            field.dataset.tagsReady = '1';

            var input = field.querySelector('[data-tag-input]');
            var list = field.querySelector('[data-tag-list]');
            var chips = field.querySelector('[data-tag-chips]');
            var name = field.dataset.tagsName || 'tags[]';
            var options = JSON.parse(field.dataset.educbtTags || '[]');
            var chosen = JSON.parse(field.dataset.tagsSelected || '[]');

            function renderChips() {
                chips.innerHTML = '';

                chosen.forEach(function (value) {
                    var match = options.filter(function (o) { return o.value === value; })[0];
                    var chip = document.createElement('span');
                    chip.className = 'educbt-chip';
                    chip.textContent = match ? match.label : value;

                    var remove = document.createElement('button');
                    remove.type = 'button';
                    remove.className = 'educbt-chip__x';
                    remove.setAttribute('aria-label', 'Remove ' + (match ? match.label : value));
                    remove.textContent = '×';
                    remove.onclick = function () {
                        chosen = chosen.filter(function (v) { return v !== value; });
                        renderChips();
                    };

                    chip.appendChild(remove);
                    chips.appendChild(chip);

                    var hidden = document.createElement('input');
                    hidden.type = 'hidden';
                    hidden.name = name;
                    hidden.value = value;
                    chips.appendChild(hidden);
                });
            }

            function renderSuggestions() {
                var q = input.value.trim().toLowerCase();
                list.innerHTML = '';

                if (q === '') { list.hidden = true; return; }

                var matches = options.filter(function (o) {
                    return chosen.indexOf(o.value) === -1
                        && (o.label.toLowerCase().indexOf(q) !== -1 || o.value.toLowerCase().indexOf(q) !== -1);
                }).slice(0, 8);

                if (!matches.length) { list.hidden = true; return; }

                matches.forEach(function (o) {
                    var item = document.createElement('button');
                    item.type = 'button';
                    item.className = 'educbt-suggest__item';
                    item.textContent = o.label;
                    item.onclick = function () {
                        chosen.push(o.value);
                        input.value = '';
                        renderChips();
                        list.hidden = true;
                        input.focus();
                    };
                    list.appendChild(item);
                });

                list.hidden = false;
            }

            input.addEventListener('input', renderSuggestions);

            input.addEventListener('keydown', function (e) {
                // Enter picks the first suggestion rather than submitting the form —
                // submitting mid-typing loses the entry the person was adding.
                if (e.key === 'Enter') {
                    e.preventDefault();
                    var first = list.querySelector('.educbt-suggest__item');
                    if (first) { first.click(); }
                }

                if (e.key === 'Backspace' && input.value === '' && chosen.length) {
                    chosen.pop();
                    renderChips();
                }
            });

            document.addEventListener('click', function (e) {
                if (!field.contains(e.target)) { list.hidden = true; }
            });

            renderChips();
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { init(document); });
    } else {
        init(document);
    }

    window.EduCBTTags = { init: init };
}());
