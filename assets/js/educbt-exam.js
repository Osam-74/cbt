/* EduCBT exam runtime.
 *
 * Three rules this file obeys:
 *   1. The countdown here is COSMETIC. The server decides when the paper ends and
 *      every save returns the authoritative remaining time, which is adopted.
 *   2. Every answer is saved on click. Nothing is held only in the browser, because
 *      a dropped connection must not cost a student their work.
 *   3. A failed save is retried and the student is told. Silence would let them
 *      finish a paper that recorded nothing.
 *
 * Theory questions are grouped: a theory question with sub-parts (1A, 1B, 1C)
 * displays all parts on one screen. The student toggles between Objective and
 * Theory views using the sidebar toggle.
 */
(function () {
    'use strict';

    var cfg = window.EduCBTExam;
    if (!cfg) { return; }

    var state = {
        attemptId: 0,
        token: '',
        questions: [],
        objectiveQuestions: [],
        theoryQuestions: [],
        passages: {},
        answers: {},
        flags: {},
        index: 0,
        mode: 'objective',       // 'objective' | 'theory'
        deadline: 0,
        submitted: false,
        pending: 0,
        isFullscreen: false,
        theoryMin: (typeof cfg.theoryMin === "number" ? cfg.theoryMin : 4) // minimum theory questions the student must answer
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

    /* ── Exam integrity: block right-click and detect tab switching ── */
    function showIntegrityWarning(message) {
        // Remove any existing banner.
        var existing = document.getElementById('exam-integrity-banner');
        if (existing) { existing.remove(); }

        var banner = document.createElement('div');
        banner.id = 'exam-integrity-banner';
        banner.className = 'exam-integrity-warning';
        banner.textContent = message;
        document.body.appendChild(banner);

        // Auto-dismiss after 5 seconds.
        setTimeout(function () {
            if (banner.parentNode) { banner.remove(); }
        }, 5000);
    }

    document.addEventListener('contextmenu', function (e) {
        if (state.attemptId && !state.submitted) {
            e.preventDefault();
            reportIntegrity('right_click');
            showIntegrityWarning('⚠ Right-click is disabled during the exam. This action has been logged.');
            return false;
        }
    });

    document.addEventListener('visibilitychange', function () {
        if (state.attemptId && !state.submitted && document.hidden) {
            // The student switched tabs or minimised the browser.
            // We can't show a banner while the tab is hidden, but we log it
            // and re-show the warning + re-enter fullscreen when they return.
            state._tabViolation = (state._tabViolation || 0) + 1;
            reportIntegrity('tab_hidden');
        } else if (state.attemptId && !state.submitted && !document.hidden && state._tabViolation) {
            // They came back — warn them.
            var count = state._tabViolation;
            showIntegrityWarning('⚠ WARNING: You left the exam tab ' + count + ' time(s)! Do NOT leave this tab again. This has been logged.');
            // Re-enter fullscreen.
            setTimeout(function () {
                if (state.attemptId && !state.submitted) { enterFullscreen(); }
            }, 200);
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

    // Report an integrity incident to the server.
    //
    // The banner already told the student "this has been logged". Until now nothing
    // was, so the message was untrue. Failures are swallowed deliberately: a
    // dropped connection must never interrupt a paper in progress, and the incident
    // is advisory rather than proof in any case.
    function reportIntegrity(type) {
        if (!state.attemptId || state.submitted) { return; }

        try {
            api('/attempt/' + state.attemptId + '/integrity', { event_type: type })
                .catch(function () { /* never interrupt the exam */ });
        } catch (e) { /* never interrupt the exam */ }
    }


    function clock(seconds) {
        seconds = Math.max(0, Math.round(seconds));
        var h = Math.floor(seconds / 3600);
        var m = Math.floor((seconds % 3600) / 60);
        var s = seconds % 60;
        return (h > 0 ? h + ':' : '') + String(m).padStart(2, '0') + ':' + String(s).padStart(2, '0');
    }

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

    function escapeHtml(s) {
        var d = document.createElement('div');
        d.textContent = s == null ? '' : String(s);
        return d.innerHTML;
    }

    /* ── Active list based on current mode ── */
    function activeQuestions() {
        return state.mode === 'theory' ? state.theoryQuestions : state.objectiveQuestions;
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

    /* For a theory question with children, it's "answered" if ALL children have answers */
    function theoryGroupAnswered(q) {
        if (!q.children || q.children.length === 0) {
            return hasAnswer(q);
        }
        return q.children.every(function (child) {
            var a = state.answers[child.id];
            return a !== undefined && a !== null && String(a).trim() !== '';
        });
    }

    /* For partial answer status on theory groups */
    function theoryGroupPartial(q) {
        if (!q.children || q.children.length === 0) {
            return hasAnswer(q) ? 1 : 0;
        }
        var answered = 0;
        q.children.forEach(function (child) {
            var a = state.answers[child.id];
            if (a !== undefined && a !== null && String(a).trim() !== '') { answered++; }
        });
        return answered;
    }

    /* Count how many theory questions are fully answered */
    function theoryAnsweredCount() {
        return state.theoryQuestions.filter(theoryGroupAnswered).length;
    }

    /* A theory question is "attempted" if the student has written anything in
       ANY of its sub-parts. Even one character counts — the student chose this
       question. This is different from "answered" which requires ALL sub-parts. */
    function theoryGroupAttempted(q) {
        if (!q.children || q.children.length === 0) {
            var a = state.answers[q.id];
            return a !== undefined && a !== null && String(a).trim() !== '';
        }
        return q.children.some(function (child) {
            var a = state.answers[child.id];
            return a !== undefined && a !== null && String(a).trim() !== '';
        });
    }

    function theoryAttemptedCount() {
        return state.theoryQuestions.filter(theoryGroupAttempted).length;
    }

    /* A theory question is locked when the student has used all their slots
       (theoryMin, default 4) and this question is NOT one of the attempted ones.
       Locked questions show grayed-out answer boxes and can't be typed in
       until the student clears an attempted question to free a slot. */
    function isTheoryLocked(q) {
        if (state.theoryMin <= 0) { return false; }
        if (theoryGroupAttempted(q)) { return false; }
        return theoryAttemptedCount() >= state.theoryMin;
    }

    // ── Flag toggling ──
    function toggleFlag() {
        var list = activeQuestions();
        var q = list[state.index];
        if (!q) return;
        var flagBtn = document.getElementById('exam-flag');
        var flagText = document.getElementById('exam-flag-text');
        if (!flagBtn) return;
        if (!state.attemptId) return;

        // Immediate visual feedback — toggle now, revert on failure.
        var wasFlagged = !!state.flags[q.id];
        if (wasFlagged) {
            delete state.flags[q.id];
            flagBtn.classList.remove('is-flagged');
            if (flagText) flagText.textContent = 'Flag';
        } else {
            state.flags[q.id] = true;
            flagBtn.classList.add('is-flagged');
            if (flagText) flagText.textContent = 'Flagged';
        }
        renderGrid();

        api('attempt/' + state.attemptId + '/flag', {
            question_id: q.id,
            flag_action: 'toggle'
        }).then(function (data) {
            // Sync with server response.
            if (data.flagged) {
                state.flags[q.id] = true;
                flagBtn.classList.add('is-flagged');
                if (flagText) flagText.textContent = 'Flagged';
            } else {
                delete state.flags[q.id];
                flagBtn.classList.remove('is-flagged');
                if (flagText) flagText.textContent = 'Flag';
            }
            renderGrid();
        }).catch(function () {
            // Revert on failure.
            if (wasFlagged) {
                state.flags[q.id] = true;
                flagBtn.classList.add('is-flagged');
                if (flagText) flagText.textContent = 'Flagged';
            } else {
                delete state.flags[q.id];
                flagBtn.classList.remove('is-flagged');
                if (flagText) flagText.textContent = 'Flag';
            }
            renderGrid();
            status('Could not update flag. Try again.', 'error');
        });
    }



    function renderGrid() {
        el.grid.innerHTML = '';
        var list = activeQuestions();
        var total = list.length;

        list.forEach(function (q, i) {
            var b = document.createElement('button');
            b.type = 'button';
            var done, partial;
            var locked = false;
            if (state.mode === 'theory') {
                locked = isTheoryLocked(q);
                if (q.children && q.children.length > 0) {
                    done = theoryGroupAnswered(q);
                    partial = theoryGroupPartial(q);
                } else {
                    done = hasAnswer(q);
                    partial = done ? 1 : 0;
                }
            } else {
                done = hasAnswer(q);
                partial = done ? 1 : 0;
            }
            b.className = 'exam-grid__cell'
                + (done ? ' is-done' : (partial > 0 ? ' is-partial' : ''))
                + (locked ? ' is-locked' : '')
                + (i === state.index ? ' is-current' : '');
            if (locked) {
                b.title = 'Clear a question to free a slot, then attempt this one.';
            }
            b.textContent = String(i + 1);
            b.setAttribute('aria-label', 'Question ' + (i + 1) + (done ? ', answered' : ', not answered'));
            b.onclick = function () { go(i); };
            el.grid.appendChild(b);
        });

        // Progress text
        if (state.mode === 'theory') {
            var attempted = theoryAttemptedCount();
            var answered = theoryAnsweredCount();
            var minNote = state.theoryMin > 0 ? ' (' + attempted + '/' + state.theoryMin + ' slots used)' : '';
            el.progress.textContent = answered + ' of ' + total + ' fully answered' + minNote;
        } else {
            var objDone = list.filter(hasAnswer).length;
            el.progress.textContent = objDone + ' of ' + total + ' answered';
        }
    }

    function renderQuestion() {
        var list = activeQuestions();
        var q = list[state.index];
        var flagBtn = document.getElementById('exam-flag');
        var flagText = document.getElementById('exam-flag-text');
        if (flagBtn) {
            if (q && state.flags[q.id]) {
                flagBtn.classList.add('is-flagged');
                if (flagText) flagText.textContent = 'Flagged';
            } else {
                flagBtn.classList.remove('is-flagged');
                if (flagText) flagText.textContent = 'Flag';
            }
        }
        if (!q) { return; }

        var sectionLabel = state.mode === 'theory' ? 'Theory' : 'Objective';
        el.number.textContent = sectionLabel + ' — Question ' + (state.index + 1) + ' of ' + list.length;

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

        /* ── Theory question with sub-parts ──
           Display the parent question text, then each sub-question (1A, 1B, 1C)
           with its own answer box, all on one screen. */
        if (q.type === 'theory') {
            renderTheoryQuestion(q);
            el.prev.disabled = state.index === 0;
            el.next.disabled = state.index >= list.length - 1;
            renderGrid();
            return;
        }

        /* ── Objective question (single choice) ──
           Short options are laid side by side, the way WAEC prints them. A row of
           four one-word choices is quicker to scan than four stacked rows of mostly
           empty space, and on a phone it saves the student scrolling to see the
           options at all. Anything longer stays stacked, because a wrapped sentence
           in a narrow column is harder to read, not easier. */
        var longest = 0;
        q.options.forEach(function (o) {
            var len = (o.text || '').length;
            if (len > longest) { longest = len; }
        });

        var hasImages = q.options.some(function (o) { return !!o.image; });
        el.options.classList.toggle('exam-options--grid', longest <= 24 && !hasImages);

        q.options.forEach(function (opt) {
            var id = 'opt-' + opt.id;
            var wrap = document.createElement('label');
            wrap.className = 'exam-option' + (state.answers[q.id] === opt.id ? ' is-chosen' : '');
            wrap.htmlFor = id;

            var input = document.createElement('input');
            input.type = 'radio';
            input.name = 'q-' + q.id;
            input.id = id;
            input.value = opt.id;
            input.checked = state.answers[q.id] === opt.id;

            input.onchange = function () { choose(q.id, opt.id); };

            var label = document.createElement('span');
            label.className = 'exam-option__text';
            if (opt.image) {
                label.innerHTML = '<img src="' + encodeURI(opt.image) + '" alt="" style="max-width:100%;border-radius:8px;margin-bottom:6px"><br>' + escapeHtml(opt.text || opt.key || '');
            } else {
                label.textContent = (opt.key ? opt.key + '. ' : '') + (opt.text || '');
            }

            wrap.appendChild(input);
            wrap.appendChild(label);
            el.options.appendChild(wrap);
        });

        el.prev.disabled = state.index === 0;
        el.next.disabled = state.index >= list.length - 1;
        renderGrid();
    }

    /* Render a theory question — either a standalone question or a parent
       with sub-parts (children). All parts display at once on one screen.
       If the student has used all theoryMin slots and this question isn't
       attempted, the answer boxes are locked (grayed out, disabled). */
    /* Is this question one of a set of essay alternatives? */
    function isEssayChoice(q) {
        return String(q.section || '').indexOf(':essay') !== -1;
    }

    /* Every essay alternative in this paper, in the order they were set. */
    function essayAlternatives() {
        return (state.questions || []).filter(isEssayChoice);
    }

    /* Show the topics, let the candidate pick one, then open the writing area.
       Choosing is recorded by which question holds an answer, so a student who
       changes their mind simply clears one and starts another — the same mechanism
       that already governs the answer slots. */
    function renderEssayChoice(q, locked) {
        var topics = essayAlternatives();
        var chosenId = 0;

        topics.forEach(function (t) {
            var a = state.answers[t.id];
            if (typeof a === 'string' && a.trim() !== '') { chosenId = t.id; }
        });

        var wrap = document.createElement('div');
        wrap.className = 'exam-essay';

        var lead = document.createElement('p');
        lead.className = 'exam-essay__lead';
        lead.textContent = chosenId
            ? 'You are answering the topic marked below. To change topic, clear your answer first.'
            : 'Choose ONE topic. The writing area opens once you have chosen.';
        wrap.appendChild(lead);

        topics.forEach(function (t, i) {
            var isChosen = chosenId === t.id;
            var dimmed = chosenId && !isChosen;

            var card = document.createElement('div');
            card.className = 'exam-essay__topic'
                + (isChosen ? ' is-chosen' : '')
                + (dimmed ? ' is-dimmed' : '');

            var head = document.createElement('div');
            head.className = 'exam-essay__head';
            head.innerHTML = '<span class="exam-essay__num">' + (i + 1) + '</span>'
                + '<span class="exam-essay__text">' + escapeHtml(t.text || '') + '</span>';
            card.appendChild(head);

            if (!dimmed && !locked) {
                var btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'exam-essay__pick';
                btn.textContent = isChosen ? 'You are answering this' : 'Answer this topic';
                btn.disabled = isChosen;

                btn.onclick = function () {
                    // Jump to that topic and open its writing area.
                    var idx = (state.questions || []).findIndex(function (x) { return x.id === t.id; });
                    if (idx >= 0) { state.index = idx; }
                    state._essayOpen = t.id;
                    render();
                };

                card.appendChild(btn);
            }

            if (isChosen || state._essayOpen === t.id) {
                createAnswerBox(t, '', card, locked);
            }

            wrap.appendChild(card);
        });

        el.options.appendChild(wrap);
    }

    function renderTheoryQuestion(q) {
        var locked = isTheoryLocked(q);

        // An essay section is a CHOICE, not a list of questions to work through.
        // WAEC sets five topics and the candidate answers one. Presenting them as
        // five separate questions with five answer boxes invites a student to start
        // three of them and run out of time, so the alternatives are shown together
        // and the writing area only opens once one is chosen.
        if (isEssayChoice(q)) {
            renderEssayChoice(q, locked);
            return;
        }

        if (locked) {
            var notice = document.createElement('div');
            notice.className = 'exam-lock-notice';
            notice.innerHTML = '<span class="exam-lock-notice__icon">🔒</span>'
                + 'You have used all ' + state.theoryMin + ' question slots. '
                + 'Clear the answers from one of your attempted questions to free a slot for this one.';
            el.options.appendChild(notice);
        }

        if (!q.children || q.children.length === 0) {
            // Standalone theory question — no sub-parts
            createAnswerBox(q, '', el.options, locked);
            return;
        }

        // Parent with children — add a parent answer box first (the main
        // question itself should have its own box, not only the sub-questions),
        // then render each child as a labeled sub-question.
        var parentWrap = document.createElement('div');
        parentWrap.className = 'exam-theory-part' + (locked ? ' is-locked' : '');
        var parentLabel = document.createElement('h4');
        parentLabel.className = 'exam-theory-part__label';
        parentLabel.textContent = 'Main question';
        parentWrap.appendChild(parentLabel);
        parentWrap.appendChild(createAnswerBox(q, 'main', parentWrap, locked));
        el.options.appendChild(parentWrap);
        q.children.forEach(function (child, ci) {
            var partWrap = document.createElement('div');
            partWrap.className = 'exam-theory-part' + (locked ? ' is-locked' : '');

            // Part label (e.g., "1A", "1B") or fallback letter
            var labelText = child.label || String.fromCharCode(97 + ci); // a, b, c…
            var labelEl = document.createElement('h4');
            labelEl.className = 'exam-theory-part__label';
            labelEl.textContent = labelText;
            partWrap.appendChild(labelEl);

            // Sub-question text
            if (child.text) {
                var textEl = document.createElement('div');
                textEl.className = 'exam-theory-part__text';
                textEl.innerHTML = child.text;
                partWrap.appendChild(textEl);
            }

            // Sub-question image
            if (child.image) {
                var imgEl = document.createElement('img');
                imgEl.src = child.image;
                imgEl.className = 'exam-image';
                imgEl.style.maxWidth = '100%';
                imgEl.style.borderRadius = '8px';
                imgEl.style.marginBottom = '8px';
                partWrap.appendChild(imgEl);
            }

            // Marks badge
            if (child.marks) {
                var marksEl = document.createElement('span');
                marksEl.className = 'educbt-muted';
                marksEl.style.cssText = 'font-size:12px;margin-bottom:8px;display:block';
                marksEl.textContent = '[' + child.marks + ' mark' + (child.marks === 1 ? '' : 's') + ']';
                partWrap.appendChild(marksEl);
            }

            partWrap.appendChild(createAnswerBox(child, labelText, partWrap, locked));
            el.options.appendChild(partWrap);
        });
    }

    /* Create a textarea for a theory question or sub-question.
       Saves on pause (debounced) and on blur — never on every keystroke.
       When locked is true the textarea is disabled (grayed out) because the
       student has used all theoryMin slots and hasn't attempted this question. */
    function createAnswerBox(q, label, container, locked) {
        var box = document.createElement('textarea');
        box.className = 'exam-written';
        box.rows = 8;
        box.placeholder = locked ? 'Locked — clear another question first' : 'Write your answer here…';
        box.value = state.answers[q.id] || '';
        box.setAttribute('aria-label', 'Your written answer' + (label ? ' for ' + label : ''));
        if (locked) {
            box.disabled = true;
        }

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

        box.onblur = function () { clearTimeout(timer); commit(); };

        var hint = document.createElement('p');
        hint.className = 'educbt-muted';
        hint.style.cssText = 'margin-top:6px;font-size:12px';
        hint.textContent = locked ? 'This question is locked.' : 'Your answer saves automatically.';
        container.appendChild(hint);

        return box;
    }

    function go(i) {
        var list = activeQuestions();
        if (i < 0 || i >= list.length) { return; }
        state.index = i;
        renderQuestion();
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    /* Switch between objective and theory modes */
    function switchMode(newMode) {
        if (newMode === state.mode) { return; }
        state.mode = newMode;
        state.index = 0;

        // Update toggle button states
        var objBtn = $('exam-toggle-objective');
        var thyBtn = $('exam-toggle-theory');
        if (objBtn && thyBtn) {
            objBtn.classList.toggle('is-active', newMode === 'objective');
            thyBtn.classList.toggle('is-active', newMode === 'theory');
        }

        // If theory has no questions, stay on objective
        if (newMode === 'theory' && state.theoryQuestions.length === 0) {
            state.mode = 'objective';
            state.index = 0;
            if (objBtn) objBtn.classList.add('is-active');
            if (thyBtn) thyBtn.classList.remove('is-active');
            status('No theory questions for this paper.', 'warn');
            return;
        }

        // Hide theory toggle if there are no theory questions
        if (state.theoryQuestions.length === 0 && thyBtn) {
            thyBtn.style.display = 'none';
        }

        renderQuestion();
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

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
                status('Connection lost — retrying…', 'warn');
                setTimeout(function () { save(questionId, optionId, attempt + 1); }, 1500 * (attempt + 1));
            } else {
                status('Could not save that answer. Tell the invigilator.', 'error');
            }
        });
    }

    var submitRetries = 0;

    function submit(reason) {
        if (state.submitted) { return; }

        var objUnanswered = state.objectiveQuestions.length - state.objectiveQuestions.filter(hasAnswer).length;
        var theoryAnswered = theoryAnsweredCount();
        var theoryTotal = state.theoryQuestions.length;
        var theoryShort = state.theoryMin > 0 && theoryAnswered < state.theoryMin && theoryTotal > 0;

        if (reason === 'manual' && submitRetries === 0) {
            var warnings = [];
            if (objUnanswered > 0) {
                warnings.push(objUnanswered + ' objective question(s) unanswered');
            }
            if (theoryShort) {
                warnings.push('only ' + theoryAnswered + ' of ' + state.theoryMin + ' required theory questions answered');
            }
            var warnText = warnings.length > 0
                ? warnings.join(', ') + '.\n\n'
                : '';
            if (!window.confirm(warnText + 'Are you sure you want to submit your paper? You cannot undo this.')) { return; }
        }

        state.submitted = true;
        status('Submitting…', 'busy');

        api('attempt/' + state.attemptId + '/submit', { session_token: state.token })
            .then(function (data) {
                if (data && data.submitted) {
                    submitRetries = 0;
                    finish(reason);
                } else {
                    // Server did not confirm — it may have rejected the submission.
                    // Do NOT show the success screen; let the student see the error.
                    state.submitted = false;
                    submitRetries++;
                    if (submitRetries <= 5) {
                        status('Submission rejected — retrying (' + submitRetries + '/5)…', 'warn');
                        setTimeout(function () { submit(reason); }, 2000 * submitRetries);
                    } else {
                        status('Could not submit. Tell the invigilator immediately.', 'error');
                    }
                }
            })
            .catch(function () {
                // Network error or server returned an error status.
                // The server may have processed the submit even though we did not
                // receive the response, so retrying is safe — the server will tell
                // us if it is already submitted.
                state.submitted = false;
                submitRetries++;
                if (submitRetries <= 5) {
                    status('Connection lost — retrying submission (' + submitRetries + '/5)…', 'warn');
                    setTimeout(function () { submit(reason); }, 2000 * submitRetries);
                } else {
                    status('Could not submit. Tell the invigilator immediately.', 'error');
                }
            });
    }

    function finish(reason) {
        el.sitting.hidden = true;
        el.done.hidden = false;
        el.doneTitle.textContent = reason === 'time' ? 'Time is up' : 'Paper submitted';
        el.doneBody.textContent = 'Your answers have been recorded. You may now leave the hall.';
    }

    // ── Exam security: right-click lock & tab-switch warning ──────────────
    var securityActive = false;
    var tabWarningCount = 0;

    function enableSecurity() {
        if (securityActive) return;
        securityActive = true;

        // Disable right-click context menu on the exam page
        document.addEventListener('contextmenu', blockContextMenu, true);

        // Detect when the student leaves the tab or window while exam is active
        document.addEventListener('visibilitychange', onVisibilityChange, true);
        window.addEventListener('blur', onWindowBlur, true);
    }

    function disableSecurity() {
        if (!securityActive) return;
        securityActive = false;
        document.removeEventListener('contextmenu', blockContextMenu, true);
        document.removeEventListener('visibilitychange', onVisibilityChange, true);
        window.removeEventListener('blur', onWindowBlur, true);
    }

    function blockContextMenu(e) {
        e.preventDefault();
        e.stopPropagation();
        reportIntegrity('right_click');
        showSecurityToast('Right-click is disabled during the exam.');
        return false;
    }

    function onVisibilityChange() {
        if (document.hidden && !state.submitted && state.attemptId) {
            tabWarningCount++;
            reportIntegrity('tab_hidden');
            showSecurityWarning(
                'You switched away from the exam tab. This is not allowed.',
                'Warning #' + tabWarningCount + ' — stay on this tab until you submit.'
            );
        }
    }

    function onWindowBlur() {
        if (!state.submitted && state.attemptId) {
            // Slight delay so clicking inside the exam (e.g. in an input)
            // doesn't falsely trigger the blur warning
            setTimeout(function () {
                if (!document.hasFocus() && !state.submitted && state.attemptId) {
                    tabWarningCount++;
                    reportIntegrity('window_blur');
                    showSecurityWarning(
                        'You left the exam window. This is not allowed.',
                        'Warning #' + tabWarningCount + ' — click here to return to your exam.'
                    );
                }
            }, 150);
        }
    }

    function showSecurityWarning(title, detail) {
        // Build a blocking overlay so the student MUST acknowledge the warning
        var overlay = document.createElement('div');
        overlay.className = 'educbt-security-warning';
        overlay.style.cssText = 'position:fixed;inset:0;background:rgba(180,30,20,.92);display:flex;align-items:center;justify-content:center;z-index:100000;animation:educbt-fade .15s ease';

        var box = document.createElement('div');
        box.style.cssText = 'background:#fff;border-radius:14px;padding:32px 36px;max-width:440px;width:90%;text-align:center;box-shadow:0 20px 60px rgba(0,0,0,.4)';

        var icon = document.createElement('div');
        icon.textContent = '\u26A0';
        icon.style.cssText = 'font-size:2.6rem;margin-bottom:8px';

        var h = document.createElement('h3');
        h.textContent = title;
        h.style.cssText = 'margin:0 0 8px;font-size:1.15rem;color:#991B1B';

        var p = document.createElement('p');
        p.textContent = detail + ' Switching tabs or windows during an exam is prohibited. Repeated violations may result in your exam being voided.';
        p.style.cssText = 'margin:0 0 18px;font-size:.88rem;color:#555;line-height:1.5';

        var btn = document.createElement('button');
        btn.textContent = 'I understand — return to exam';
        btn.type = 'button';
        btn.style.cssText = 'padding:10px 24px;border:none;border-radius:8px;background:#991B1B;color:#fff;font-size:.9rem;font-weight:600;cursor:pointer';

        btn.onclick = function () {
            document.body.removeChild(overlay);
            // Refocus the window
            window.focus();
        };

        box.appendChild(icon);
        box.appendChild(h);
        box.appendChild(p);
        box.appendChild(btn);
        overlay.appendChild(box);
        document.body.appendChild(overlay);
    }

    function showSecurityToast(msg) {
        var toast = document.createElement('div');
        toast.textContent = msg;
        toast.style.cssText = 'position:fixed;bottom:20px;left:50%;transform:translateX(-50%);background:#1a2e22;color:#fff;padding:10px 20px;border-radius:8px;font-size:.85rem;z-index:100001;box-shadow:0 4px 14px rgba(0,0,0,.3);animation:educbt-fade .15s ease';
        document.body.appendChild(toast);
        setTimeout(function () {
            if (toast.parentNode) toast.parentNode.removeChild(toast);
        }, 2500);
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

        // Split questions into objective and theory groups
        state.objectiveQuestions = state.questions.filter(function (q) {
            return q.type !== 'theory';
        });
        state.theoryQuestions = state.questions.filter(function (q) {
            return q.type === 'theory';
        });

        applyTimer(data.timer);

        el.gate.hidden = true;
        el.sitting.hidden = false;

        // Activate exam security: no right-click, no tab-switching
        enableSecurity();

        // Show/hide theory toggle based on whether theory questions exist
        var thyBtn = $('exam-toggle-theory');
        if (thyBtn) {
            thyBtn.style.display = state.theoryQuestions.length > 0 ? '' : 'none';
        }

        // Default to objective mode
        state.mode = 'objective';
        state.index = 0;

        renderQuestion();
        status(data.resumed ? 'Welcome back — your answers were saved.' : '', data.resumed ? 'ok' : '');
        setInterval(tick, 1000);
        tick();
    }

    /**
     * Show a modal dialog asking for the exam access code.
     */
    function showAccessCodeDialog() {
        return new Promise(function (resolve, reject) {
            var overlay = document.createElement('div');
            overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.55);display:flex;align-items:center;justify-content:center;z-index:9999;animation:educbt-fade .2s ease';

            var modal = document.createElement('div');
            modal.style.cssText = 'background:#fff;border-radius:14px;padding:28px 32px;max-width:380px;width:90%;box-shadow:0 20px 60px rgba(0,0,0,.3);text-align:center';

            var heading = document.createElement('h3');
            heading.textContent = 'Enter Access Code';
            heading.style.cssText = 'margin:0 0 6px;font-size:1.1rem;color:#1a2e22';

            var hint = document.createElement('p');
            hint.textContent = 'The invigilator will read this code out.';
            hint.style.cssText = 'margin:0 0 16px;font-size:.82rem;color:#888';

            var input = document.createElement('input');
            input.type = 'text';
            input.autocomplete = 'off';
            input.autocapitalize = 'characters';
            input.placeholder = 'ACCESS CODE';
            input.style.cssText = 'width:100%;box-sizing:border-box;padding:12px 14px;font-size:1.15rem;font-weight:700;letter-spacing:2px;text-align:center;border:2px solid #d4e4c4;border-radius:8px;outline:none;text-transform:uppercase';

            var errorP = document.createElement('p');
            errorP.style.cssText = 'margin:8px 0 0;font-size:.8rem;color:#b91c1c;min-height:0';

            var btnRow = document.createElement('div');
            btnRow.style.cssText = 'display:flex;gap:10px;margin-top:16px';

            var cancelBtn = document.createElement('button');
            cancelBtn.textContent = 'Cancel';
            cancelBtn.type = 'button';
            cancelBtn.style.cssText = 'flex:1;padding:10px;border:1px solid #ddd;border-radius:8px;background:#f9fafb;font-size:.9rem;cursor:pointer;color:#666';

            var startBtn = document.createElement('button');
            startBtn.textContent = 'Start Exam';
            startBtn.type = 'button';
            startBtn.style.cssText = 'flex:1;padding:10px;border:none;border-radius:8px;background:#3F6B4A;font-size:.9rem;font-weight:600;color:#fff;cursor:pointer';

            btnRow.appendChild(cancelBtn);
            btnRow.appendChild(startBtn);
            modal.appendChild(heading);
            modal.appendChild(hint);
            modal.appendChild(input);
            modal.appendChild(errorP);
            modal.appendChild(btnRow);
            overlay.appendChild(modal);
            document.body.appendChild(overlay);

            input.focus();

            function close() {
                document.body.removeChild(overlay);
            }

            function doSubmit() {
                var code = input.value.trim().toUpperCase();
                if (!code) {
                    errorP.textContent = 'Please enter the access code.';
                    input.focus();
                    return;
                }
                close();
                resolve(code);
            }

            startBtn.onclick = doSubmit;
            input.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') { e.preventDefault(); doSubmit(); }
            });
            cancelBtn.onclick = function () {
                close();
                reject(null);
            };
            overlay.addEventListener('click', function (e) {
                if (e.target === overlay) {
                    close();
                    reject(null);
                }
            });
        });
    }

    function bind() {
        el = {
            gate: $('exam-gate'), gateError: $('exam-gate-error'),
            startBtn: $('exam-start'), sitting: $('exam-sitting'), done: $('exam-done'),
            doneTitle: $('exam-done-title'), doneBody: $('exam-done-body'),
            timer: $('exam-timer'), number: $('exam-number'), passage: $('exam-passage'),
            text: $('exam-text'), image: $('exam-image'), options: $('exam-options'),
            grid: $('exam-grid'), progress: $('exam-progress'), status: $('exam-status'),
            prev: $('exam-prev'), next: $('exam-next'), submitBtn: $('exam-submit'),
            toggleObjective: $('exam-toggle-objective'), toggleTheory: $('exam-toggle-theory')
        };

        el.startBtn.onclick = function () {
            el.startBtn.disabled = true;
            el.gateError.textContent = '';

            if (cfg.requiresAccessCode) {
                showAccessCodeDialog()
                    .then(function (code) {
                        return api('exam/' + cfg.paperId + '/start', { access_code: code });
                    })
                    .then(function (data) {
                        enterFullscreen();
                        begin(data);
                    })
                    .catch(function (err) {
                        el.startBtn.disabled = false;
                        if (err && err.message) {
                            el.gateError.textContent = err.message;
                        }
                    });
                return;
            }

            api('exam/' + cfg.paperId + '/start', { access_code: '' })
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

        // Flag button — bound here with all other buttons for reliability.
        var flagBtn = $('exam-flag');
        if (flagBtn) {
            flagBtn.onclick = function (e) {
                e.preventDefault();
                toggleFlag();
            };
        }

        // Toggle buttons
        if (el.toggleObjective) {
            el.toggleObjective.onclick = function () { switchMode('objective'); };
        }
        if (el.toggleTheory) {
            el.toggleTheory.onclick = function () { switchMode('theory'); };
        }

        window.addEventListener('beforeunload', function (e) {
            if (!state.submitted && state.attemptId) {
                e.preventDefault();
                e.returnValue = '';
            }
        });

        var origSubmit = submit;
        submit = function (reason) {
            disableSecurity();
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
