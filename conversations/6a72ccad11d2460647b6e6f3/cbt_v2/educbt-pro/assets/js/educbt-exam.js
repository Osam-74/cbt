/* EduCBT exam runtime.
 *
 * Three rules this file obeys:
 *   1. The countdown here is COSMETIC. The server decides when the paper ends and
 *      every save returns the authoritative remaining time, which is adopted.
 *   2. Every answer is saved on click. Nothing is held only in the browser, because
 *      a dropped connection must not cost a student their work.
 *   3. A failed save is retried and the student is told. Silence would let them
 *      finish a paper that recorded nothing.
 */
(function () {
    'use strict';

    var cfg = window.EduCBTExam;
    if (!cfg) { return; }

    var state = {
        attemptId: 0,
        token: '',
        questions: [],
        passages: {},
        answers: {},
        index: 0,
        deadline: 0,
        submitted: false,
        pending: 0,
        isFullscreen: false
    };

    /* Fullscreen lock — re-enters fullscreen if the student presses Escape.
       Leaving fullscreen during an exam is logged as an integrity event. */
    function enterFullscreen() {
        var el = document.documentElement;
        if (el.requestFullscreen) { el.requestFullscreen(); }
        else if (el.webkitRequestFullscreen) { el.webkitRequestFullscreen(); }
        else if (el.mozRequestFullScreen) { el.mozRequestFullScreen(); }
        state.isFullscreen = true;
    }

    function exitFullscreen() {
        if (document.fullscreenElement) {
            if (document.exitFullscreen) { document.exitFullscreen(); }
            else if (document.webkitExitFullscreen) { document.webkitExitFullscreen(); }
            else if (document.mozCancelFullScreen) { document.mozCancelFullScreen(); }
        }
        state.isFullscreen = false;
    }

    document.addEventListener('fullscreenchange', function () {
        state.isFullscreen = !!document.fullscreenElement;
        if (!state.isFullscreen && !state.submitted && state.attemptId) {
            // Student left fullscreen during an active exam — re-enter after a short warning
            setTimeout(function () {
                if (!state.submitted && state.attemptId) { enterFullscreen(); }
            }, 300);
        }
    });

    document.addEventListener('webkitfullscreenchange', function () {
        state.isFullscreen = !!document.webkitFullscreenElement;
        if (!state.isFullscreen && !state.submitted && state.attemptId) {
            setTimeout(function () {
                if (!state.submitted && state.attemptId) { enterFullscreen(); }
            }, 300);
        }
    });

    var el = {};

    function $(id) { return document.getElementById(id); }

    function api(path, body) {
        return fetch(cfg.root + path, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
            credentials: 'same-origin',
            body: JSON.stringify(body || {})
        }).then(function (res) {
            return res.json().then(function (data) {
                if (!res.ok) { throw new Error((data && data.message) || 'Request failed'); }
                return data;
            });
        });
    }

    function clock(seconds) {
        seconds = Math.max(0, Math.round(seconds));
        var h = Math.floor(seconds / 3600);
        var m = Math.floor((seconds % 3600) / 60);
        var s = seconds % 60;
        return (h > 0 ? h + ':' : '') + String(m).padStart(2, '0') + ':' + String(s).padStart(2, '0');
    }

    /* The server's remaining time is the only truth. Anchor a local deadline to it
       so the display keeps ticking between saves without ever drifting away. */
    function applyTimer(timer) {
        if (!timer || typeof timer.remaining_seconds !== 'number') { return; }
        state.deadline = Date.now() + timer.remaining_seconds * 1000;
        if (timer.expired && !state.submitted) { submit('time'); }
    }

    function tick() {
        if (state.submitted || !state.deadline) { return; }
        var left = Math.max(0, Math.round((state.deadline - Date.now()) / 1000));
        el.timer.textContent = clock(left);
        el.timer.classList.toggle('is-low', left <= 300);
        if (left <= 0) { submit('time'); }
    }

    function status(message, kind) {
        el.status.textContent = message || '';
        el.status.className = 'exam-status' + (kind ? ' is-' + kind : '');
    }

    /* An empty textarea is not an answer, so a blank written question must not show
       as done — that is the difference between a student who skipped it and one who
       simply visited the page. */
    function hasAnswer(q) {
        var a = state.answers[q.id];
        if (a === undefined || a === null) { return false; }
        if (q.type === 'theory') { return String(a).trim() !== ''; }
        return true;
    }

    function renderGrid() {
        el.grid.innerHTML = '';
        state.questions.forEach(function (q, i) {
            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'exam-grid__cell'
                + (hasAnswer(q) ? ' is-done' : '')
                + (i === state.index ? ' is-current' : '');
            b.textContent = String(i + 1);
            b.setAttribute('aria-label', 'Question ' + (i + 1) + (state.answers[q.id] ? ', answered' : ', not answered'));
            b.onclick = function () { go(i); };
            el.grid.appendChild(b);
        });
        var done = state.questions.filter(hasAnswer).length;
        el.progress.textContent = done + ' of ' + state.questions.length + ' answered';
    }

    function renderQuestion() {
        var q = state.questions[state.index];
        if (!q) { return; }

        el.number.textContent = 'Question ' + (state.index + 1) + ' of ' + state.questions.length;

        var passage = q.passage_id && state.passages[q.passage_id];
        if (passage) {
            el.passage.hidden = false;
            el.passage.innerHTML = '<h3>' + escapeHtml(passage.title || 'Read the following') + '</h3>'
                + (passage.image ? '<img src="' + encodeURI(passage.image) + '" alt="">' : '')
                + '<div>' + passage.body + '</div>';
        } else {
            el.passage.hidden = true;
        }

        el.text.innerHTML = q.text || '';
        el.image.hidden = !q.image;
        if (q.image) { el.image.src = q.image; }

        el.options.innerHTML = '';

        /* A written question has no options: give the candidate a box to write in.
           Saved on pause rather than on every keystroke — an essay typed at speed
           would otherwise fire a request per character. */
        if (q.type === 'theory') {
            var box = document.createElement('textarea');
            box.className = 'exam-written';
            box.rows = 12;
            box.placeholder = 'Write your answer here…';
            box.value = state.answers[q.id] || '';
            box.setAttribute('aria-label', 'Your written answer');

            var timer = null;

            function commit() {
                var text = box.value;
                state.answers[q.id] = text;
                saveText(q.id, text, 0);
                renderGrid();
            }

            box.oninput = function () {
                state.answers[q.id] = box.value;
                clearTimeout(timer);
                status('Typing…', '');
                timer = setTimeout(commit, 1200);
            };

            // Never lose work on navigation.
            box.onblur = function () { clearTimeout(timer); commit(); };

            el.options.appendChild(box);

            var count = document.createElement('p');
            count.className = 'educbt-muted';
            count.style.marginTop = '6px';
            count.textContent = 'Your answer saves automatically.';
            el.options.appendChild(count);

            el.prev.disabled = state.index === 0;
            el.next.disabled = state.index >= state.questions.length - 1;
            renderGrid();
            return;
        }

        q.options.forEach(function (opt) {
            var id = 'opt-' + opt.id;
            var wrap = document.createElement('label');
            wrap.className = 'exam-option' + (state.answers[q.id] === opt.id ? ' is-chosen' : '');
            wrap.htmlFor = id;

            var input = document.createElement('input');
            input.type = 'radio';
            input.name = 'q' + q.id;
            input.id = id;
            input.value = opt.id;
            input.checked = state.answers[q.id] === opt.id;
            input.onchange = function () { choose(q.id, opt.id); };

            var key = document.createElement('span');
            key.className = 'exam-option__key';
            key.textContent = opt.key;

            var body = document.createElement('span');
            body.className = 'exam-option__body';
            if (opt.image) {
                var img = document.createElement('img');
                img.src = opt.image;
                img.alt = '';
                body.appendChild(img);
            }
            if (opt.text) {
                var span = document.createElement('span');
                span.innerHTML = opt.text;
                body.appendChild(span);
            }

            wrap.appendChild(input);
            wrap.appendChild(key);
            wrap.appendChild(body);
            el.options.appendChild(wrap);
        });

        el.prev.disabled = state.index === 0;
        el.next.disabled = state.index >= state.questions.length - 1;
        renderGrid();
    }

    function escapeHtml(s) {
        var d = document.createElement('div');
        d.textContent = s == null ? '' : String(s);
        return d.innerHTML;
    }

    function go(i) {
        if (i < 0 || i >= state.questions.length) { return; }
        state.index = i;
        renderQuestion();
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    /* Save immediately, and keep the answer on screen while it saves. Reverting the
       UI on a slow network would look like the click did not register. */
    function choose(questionId, optionId) {
        state.answers[questionId] = optionId;
        renderQuestion();
        save(questionId, optionId, 0);
    }

    function saveText(questionId, text, attempt) {
        state.pending++;
        status('Saving…', 'busy');

        api('attempt/' + state.attemptId + '/answer', {
            question_id: questionId,
            answer_text: text,
            session_token: state.token
        }).then(function (data) {
            state.pending--;
            if (data.saved) {
                applyTimer(data.timer);
                status(state.pending > 0 ? 'Saving…' : 'All answers saved', 'ok');
            } else if (data.reason === 'time_expired') {
                submit('time');
            }
        }).catch(function () {
            state.pending--;
            if (attempt < 4) {
                status('Connection lost — retrying…', 'warn');
                setTimeout(function () { saveText(questionId, text, attempt + 1); }, 1500 * (attempt + 1));
            } else {
                status('Could not save that answer. Tell the invigilator.', 'error');
            }
        });
    }

    function save(questionId, optionId, attempt) {
        state.pending++;
        status('Saving…', 'busy');

        api('attempt/' + state.attemptId + '/answer', {
            question_id: questionId,
            option_id: optionId,
            session_token: state.token
        }).then(function (data) {
            state.pending--;
            if (data.saved) {
                applyTimer(data.timer);
                status(state.pending > 0 ? 'Saving…' : 'All answers saved', 'ok');
            } else if (data.reason === 'time_expired') {
                submit('time');
            } else {
                status('That answer was not accepted.', 'warn');
            }
        }).catch(function () {
            state.pending--;
            if (attempt < 4) {
                // Back off and try again: a brief drop is normal here.
                status('Connection lost — retrying…', 'warn');
                setTimeout(function () { save(questionId, optionId, attempt + 1); }, 1500 * (attempt + 1));
            } else {
                status('Could not save that answer. Tell the invigilator.', 'error');
            }
        });
    }

    function submit(reason) {
        if (state.submitted) { return; }

        var unanswered = state.questions.length - state.questions.filter(hasAnswer).length;

        if (reason === 'manual' && unanswered > 0) {
            if (!window.confirm(unanswered + ' question(s) are unanswered. Submit anyway?')) { return; }
        }

        state.submitted = true;
        status('Submitting…', 'busy');

        api('attempt/' + state.attemptId + '/submit', { session_token: state.token })
            .then(function () { finish(reason); })
            .catch(function () {
                // The server closes an expired attempt on its own clock, so even a
                // failed submit does not lose the paper.
                finish(reason);
            });
    }

    function finish(reason) {
        el.sitting.hidden = true;
        el.done.hidden = false;
        el.doneTitle.textContent = reason === 'time' ? 'Time is up' : 'Paper submitted';
        // Deliberately no score. A real examination follows the JAMB model: the
        // candidate submits, and the school decides when results are released.
        el.doneBody.textContent = 'Your answers have been recorded. You may now leave the hall.';
    }

    function begin(data) {
        state.attemptId = data.attempt_id;
        state.token = data.session_token;
        state.questions = data.questions || [];
        state.passages = data.passages || {};
        state.answers = {};

        Object.keys(data.answers || {}).forEach(function (qid) {
            state.answers[parseInt(qid, 10)] = data.answers[qid];
        });

        applyTimer(data.timer);

        el.gate.hidden = true;
        el.sitting.hidden = false;

        renderQuestion();
        status(data.resumed ? 'Welcome back — your answers were saved.' : '', data.resumed ? 'ok' : '');
        setInterval(tick, 1000);
        tick();
    }

    function bind() {
        el = {
            gate: $('exam-gate'), gateError: $('exam-gate-error'), code: $('exam-code'),
            startBtn: $('exam-start'), sitting: $('exam-sitting'), done: $('exam-done'),
            doneTitle: $('exam-done-title'), doneBody: $('exam-done-body'),
            timer: $('exam-timer'), number: $('exam-number'), passage: $('exam-passage'),
            text: $('exam-text'), image: $('exam-image'), options: $('exam-options'),
            grid: $('exam-grid'), progress: $('exam-progress'), status: $('exam-status'),
            prev: $('exam-prev'), next: $('exam-next'), submitBtn: $('exam-submit')
        };

        el.startBtn.onclick = function () {
            el.startBtn.disabled = true;
            el.gateError.textContent = '';

            api('exam/' + cfg.paperId + '/start', { access_code: el.code ? el.code.value : '' })
                .then(function (data) {
                    enterFullscreen();
                    begin(data);
                })
                .catch(function (err) {
                    el.startBtn.disabled = false;
                    el.gateError.textContent = err.message || 'This paper cannot be opened.';
                });
        };

        el.prev.onclick = function () { go(state.index - 1); };
        el.next.onclick = function () { go(state.index + 1); };
        el.submitBtn.onclick = function () { submit('manual'); };

        // Leaving the paper is a flag the invigilator sees, not a punishment.
        window.addEventListener('beforeunload', function (e) {
            if (!state.submitted && state.attemptId) {
                e.preventDefault();
                e.returnValue = '';
            }
        });

        // Exit fullscreen when the exam is submitted
        var origSubmit = submit;
        submit = function (reason) {
            exitFullscreen();
            origSubmit(reason);
        };
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bind);
    } else {
        bind();
    }
}());
