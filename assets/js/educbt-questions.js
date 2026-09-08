/* Question authoring.
 *
 * Writing forty questions used to mean forty page loads, each one discarding the
 * subject, class and passage already chosen. Saving now happens in place: the
 * context stays, the counter advances, and the form clears ready for the next one.
 */
(function () {
    'use strict';

    var cfg = window.EduCBTQuestions;
    if (!cfg) { return; }

    var el = {};
    var saving = false;

    function $(id) { return document.getElementById(id); }

    function status(message, kind) {
        el.status.textContent = message || '';
        el.status.className = 'q-status' + (kind ? ' is-' + kind : '');
    }

    function context() {
        return {
            subject_id: el.subject.value,
            class_level: el.level.value,
            question_type: el.type.value,
            marks: el.marks.value,
            estimated_duration: el.duration ? el.duration.value : "",
            passage_id: el.passage ? el.passage.value : '',
            question_text: el.text.value,
            question_image: el.image ? el.image.value : '',
            marking_guide: el.guide ? el.guide.value : '',
            correct: (document.querySelector('input[name=correct]:checked') || {}).value || '',
            option_A: $('option_A').value,
            option_B: $('option_B').value,
            option_C: $('option_C').value,
            option_D: $('option_D').value
        };
    }

    function clearAnswerFields() {
        el.text.value = '';
        if (el.image) { el.image.value = ''; }
        if (el.guide) { el.guide.value = ''; }

        ['A', 'B', 'C', 'D'].forEach(function (k) { $('option_' + k).value = ''; });

        var first = document.querySelector('input[name=correct][value=A]');
        if (first) { first.checked = true; }

        // Reset the media preview, if one was chosen.
        var preview = document.querySelector('#q-image-field [data-preview]');
        if (preview) { preview.innerHTML = '<span class="educbt-media__empty">No image chosen</span>'; }

        el.text.focus();
    }

    function save() {
        if (saving) { return; }

        if (!el.subject.value || !el.level.value) {
            status('Choose the subject and class first.', 'error');
            return;
        }

        saving = true;
        el.saveBtn.disabled = true;
        status('Saving…', 'busy');

        fetch(cfg.root + 'question-bank', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
            credentials: 'same-origin',
            body: JSON.stringify(context())
        }).then(function (res) {
            return res.json().then(function (data) {
                if (!res.ok) { throw new Error((data && data.message) || 'Could not save'); }
                return data;
            });
        }).then(function (data) {
            saving = false;
            el.saveBtn.disabled = false;

            // The REST API can return HTTP 200 with success=false when the server
            // rejects the save (e.g. exam prep is closed). Without this check the
            // form cleared as if the question was saved, the counter showed
            // "Question NaN", and the teacher had no idea nothing was stored —
            // which is exactly how the overview ended up showing 0 questions.
            if (data && data.success === false) {
                status(data.message || 'Could not save that question.', 'error');
                return;
            }

            status(data.message + ' That makes ' + data.total + ' so far.', 'ok');
            el.number.textContent = 'Question ' + data.next_number;

            addToTally(data.question_id, context());
            clearAnswerFields();
        }).catch(function (err) {
            saving = false;
            el.saveBtn.disabled = false;
            status(err.message || 'Could not save that question.', 'error');
        });
    }

    /* A short list of what has just been written, so a teacher can see their work
       accumulating without leaving the form. */
    function addToTally(id, q) {
        if (!el.tally) { return; }

        el.tallyWrap.hidden = false;

        var item = document.createElement('li');
        item.className = 'q-tally__item';

        var kind = q.question_type === 'theory' ? 'Written' : 'Objective';
        var text = (q.question_text || '').replace(/<[^>]*>/g, '').slice(0, 90);

        item.innerHTML = '<span class="educbt-pill">' + kind + '</span> ';
        item.appendChild(document.createTextNode(text));

        el.tally.insertBefore(item, el.tally.firstChild);
    }

    function switchKind(kind) {
        var theory = kind === 'theory';

        $('q-objective').hidden = theory;
        $('q-theory').hidden = !theory;

        // Marks matter far more on a written question, so surface it there and keep
        // it out of the way for objectives, which are almost always worth one.
        $('q-marks-field').hidden = !theory;

        if (theory) { el.marks.value = el.marks.value === '1' ? '5' : el.marks.value; }
        else { el.marks.value = '1'; }
    }

    function bind() {
        el = {
            subject: $('subject_id'), level: $('class_level'), type: $('qtype'),
            marks: $('marks'), duration: $('suggested_duration'), text: $('question_text'), image: $('question_image'),
            guide: $('marking_guide'), passage: $('passage_id'),
            number: $('question-number'), status: $('q-status'),
            saveBtn: $('q-save'), tally: $('q-tally'), tallyWrap: $('q-tally-wrap')
        };

        if (!el.saveBtn) { return; }

        el.saveBtn.addEventListener('click', function (e) { e.preventDefault(); save(); });
        el.type.addEventListener('change', function () { switchKind(this.value); });

        // Ctrl/Cmd+Enter saves, because a teacher working through a list should not
        // have to reach for the mouse forty times.
        document.addEventListener('keydown', function (e) {
            if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') { e.preventDefault(); save(); }
        });

        [el.subject, el.level].forEach(function (field) {
            field.addEventListener('change', refreshNumber);
        });

        el.subject.addEventListener('change', syncBankGroups);

        switchKind(el.type.value);
        refreshNumber();
    }

    function refreshNumber() {
        if (!el.subject.value || !el.level.value) {
            el.number.textContent = 'Question';
            return;
        }

        var count = (cfg.counts || {})[el.subject.value + '|' + el.level.value] || 0;
        el.number.textContent = 'Question ' + (count + 1);
    }

    /* When a teacher picks a subject, auto-open the matching collapsible bank
       group and collapse the others, so the preview below shows only what
       matters for the subject they are writing for. */
    function syncBankGroups() {
        var subjectId = el.subject.value;
        if (!subjectId) { return; }

        document.querySelectorAll('.q-bank-group').forEach(function (group) {
            var id = group.id || '';
            // Group IDs are qb-obj-{subjectId} and qb-thy-{subjectId}.
            if (id.indexOf('-' + subjectId) !== -1 && id.indexOf(subjectId) === id.length - subjectId.length) {
                group.open = true;
            } else {
                group.open = false;
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bind);
    } else {
        bind();
    }
}());
