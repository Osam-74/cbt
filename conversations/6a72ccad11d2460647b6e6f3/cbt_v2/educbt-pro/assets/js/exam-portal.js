/**
 * EduCBT Pro — Exam Portal Frontend JS
 * Conversational one-question-at-a-time exam interface with:
 * - Question navigator sidebar (green=answered, grey=unanswered)
 * - Prev/Next navigation
 * - Options A-D
 * - Autosave responses (every 5s + on answer change)
 * - Timer countdown with autosave
 * - Power/network outage recovery via session restore
 */
(function ($) {
    'use strict';

    // ===== EXAM SECURITY: Disable copy, paste, cut, right-click, and dev tools shortcuts =====
    // Prevents cheating during active exam sessions
    
    // Disable context menu (right-click)
    document.addEventListener('contextmenu', function (e) {
        e.preventDefault();
        return false;
    });

    // Disable copy, cut, paste
    ['copy', 'cut', 'paste'].forEach(function (eventName) {
        document.addEventListener(eventName, function (e) {
            e.preventDefault();
            return false;
        });
    });

    // Disable text selection on the exam portal
    document.addEventListener('selectstart', function (e) {
        if (e.target.closest('.educbt-exam-portal') || e.target.closest('#trial-portal')) {
            e.preventDefault();
            return false;
        }
    });

    // Block common keyboard shortcuts for copy/paste/dev tools
    document.addEventListener('keydown', function (e) {
        var key = e.key.toLowerCase();
        var ctrlOrCmd = e.ctrlKey || e.metaKey;

        // Block Ctrl+C, Ctrl+V, Ctrl+A, Ctrl+S, Ctrl+U, Ctrl+P, Ctrl+X
        if (ctrlOrCmd && ['c', 'v', 'a', 's', 'u', 'p', 'x'].includes(key)) {
            e.preventDefault();
            return false;
        }

        // Block F12 (dev tools), Ctrl+Shift+I/J/C (dev tools)
        if (e.key === 'F12' || 
            (ctrlOrCmd && e.shiftKey && ['i', 'j', 'c'].includes(key))) {
            e.preventDefault();
            return false;
        }
    });

    // Disable drag and drop
    document.addEventListener('dragstart', function (e) {
        if (e.target.closest('.educbt-exam-portal') || e.target.closest('#trial-portal')) {
            e.preventDefault();
            return false;
        }
    });


    if (typeof educbtExamPortal === 'undefined') {
        return;
    }

    var i18n = educbtExamPortal.i18n || {};
    var restUrl = educbtExamPortal.restUrl;
    var nonce = educbtExamPortal.nonce;
    var autoSaveMs = educbtExamPortal.autoSaveMs || 5000;

    // State
    var state = {
        attemptId: 0,
        exam: null,
        questions: [],
        currentIndex: 0,
        responses: {},          // { question_id: "selected_option_text" }
        answerTimestamps: {},
        timerSeconds: 0,
        timerInterval: null,
        autoSaveInterval: null,
        isSubmitting: false,
        isSaving: false,
        loaded: false,
        studentProfile: {},
        examMeta: {},
    };

    // DOM
    var $portal = $('#educbt-exam-portal');

    function init() {
        state.attemptId = parseInt($portal.data('attempt-id'), 10) || 0;

        if (state.attemptId <= 0) {
            // Try to get from URL
            var params = new URLSearchParams(window.location.search);
            state.attemptId = parseInt(params.get('attempt_id'), 10) || 0;
        }

        if (state.attemptId <= 0) {
            showError(i18n.noExamSelected || 'No active exam attempt found.');
            return;
        }

        loadExamSession();
    }

    function showError(msg) {
        $portal.html('<div class="educbt-exam-loading"><p style="color:#e94560;font-size:16px;">' + escapeHtml(msg) + '</p></div>');
    }

    function escapeHtml(str) {
        return String(str).replace(/[&<>"']/g, function (m) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[m];
        });
    }

    function loadExamSession() {
        $.ajax({
            url: restUrl + '/student/exams/session?attempt_id=' + state.attemptId,
            method: 'GET',
            beforeSend: function (xhr) {
                xhr.setRequestHeader('X-WP-Nonce', nonce);
            },
            success: function (res) {
                if (res && res.success && res.data) {
                    handleSessionLoaded(res.data);
                } else {
                    showError(i18n.loadFailed || 'Failed to load exam.');
                }
            },
            error: function () {
                showError(i18n.loadFailed || 'Failed to load exam.');
            }
        });
    }

    function handleSessionLoaded(data) {
        state.exam = data.exam || {};
        state.questions = data.questions || [];
        state.studentProfile = data.student_profile || {};
        state.examMeta = data.exam_meta || {};
        state.timerSeconds = (data.attempt && data.attempt.timer_seconds_remaining) || 0;
        state.attemptId = (data.attempt && data.attempt.id) || state.attemptId;

        // Restore draft responses
        if (data.draft && data.draft.responses) {
            state.responses = data.draft.responses;
            state.answerTimestamps = data.draft.answer_timestamps || {};
            if (data.draft.current_index) {
                state.currentIndex = Math.min(parseInt(data.draft.current_index, 10), state.questions.length - 1);
            }
        }

        state.loaded = true;
        renderExam();
        startTimer();
        startAutoSave();
        enterFullscreen();
    }

    // ===== FULLSCREEN LOCK: enter fullscreen when the exam starts and re-request
    // it immediately if the candidate escapes (Esc key, etc.) mid-exam. Only
    // releases once the exam has actually been submitted. =====
    function enterFullscreen() {
        var el = document.getElementById('educbt-exam-portal');
        if (!el) { return; }
        if (el.requestFullscreen) { el.requestFullscreen().catch(function(){}); }
        else if (el.webkitRequestFullscreen) { el.webkitRequestFullscreen(); }
        else if (el.msRequestFullscreen) { el.msRequestFullscreen(); }
    }

    function exitFullscreen() {
        if (document.fullscreenElement) {
            if (document.exitFullscreen) { document.exitFullscreen().catch(function(){}); }
            else if (document.webkitExitFullscreen) { document.webkitExitFullscreen(); }
            else if (document.msExitFullscreen) { document.msExitFullscreen(); }
        }
    }

    function isFullscreenActive() {
        return !!(document.fullscreenElement || document.webkitFullscreenElement || document.msFullscreenElement);
    }

    ['fullscreenchange', 'webkitfullscreenchange', 'msfullscreenchange'].forEach(function (evt) {
        document.addEventListener(evt, function () {
            if (state.loaded && !state.isSubmitting && !isFullscreenActive()) {
                setTimeout(enterFullscreen, 150);
            }
        });
    });

    // ===== RENDER =====

    function renderExam() {
        if (state.questions.length === 0) {
            showError('No questions found in this exam.');
            return;
        }

        var html = '';

        // Header
        html += '<div class="ep-header">';
        html += '  <div class="ep-header-left">';
        html += '    <div>';
        html += '      <div class="ep-exam-title">' + escapeHtml(state.exam.title || 'Exam') + '</div>';
        html += '      <div class="ep-exam-subtitle">' + (state.questions.length) + ' ' + escapeHtml(i18n.question || 'Question') + '(s)</div>';
        html += '    </div>';
        html += '  </div>';
        html += '  <div class="ep-timer" id="ep-timer">';
        html += '    <span class="ep-timer-icon">⏱</span>';
        html += '    <span class="ep-timer-display" id="ep-timer-display">00:00:00</span>';
        html += '  </div>';
        html += '  <div class="ep-save-indicator" id="ep-save-indicator"></div>';
        html += '</div>';

        // Main
        html += '<div class="ep-main">';

        // Question area
        html += '  <div class="ep-question-area" id="ep-question-area">';
        html += '    <div class="ep-question-container" id="ep-question-container"></div>';
        html += '  </div>';

        // Sidebar
        html += '  <div class="ep-sidebar">';

        // Candidate profile card at the top of the sidebar
        var sp = state.studentProfile || {};
        var em = state.examMeta || {};
        var fullName = sp.full_name || '';
        var initials = '';
        if (fullName) {
            var parts = fullName.trim().split(/\s+/);
            initials = (parts[0] ? parts[0][0] : '') + (parts.length > 1 && parts[parts.length - 1] ? parts[parts.length - 1][0] : '');
        }
        html += '    <div class="ep-candidate">';
        html += '      <div class="ep-candidate-avatar">' + escapeHtml(initials) + '</div>';
        html += '      <div class="ep-candidate-name">' + escapeHtml(fullName) + '</div>';
        html += '      <div class="ep-candidate-info">';
        if (sp.gender) html += '        <div class="ep-candidate-info-row"><span class="ep-candidate-info-label">Sex:</span><span class="ep-candidate-info-value">' + escapeHtml(sp.gender) + '</span></div>';
        if (sp.class) html += '        <div class="ep-candidate-info-row"><span class="ep-candidate-info-label">Class:</span><span class="ep-candidate-info-value">' + escapeHtml(sp.class) + '</span></div>';
        if (em.subject) html += '        <div class="ep-candidate-info-row"><span class="ep-candidate-info-label">Subject:</span><span class="ep-candidate-info-value">' + escapeHtml(em.subject) + '</span></div>';
        if (em.term) html += '        <div class="ep-candidate-info-row"><span class="ep-candidate-info-label">Term:</span><span class="ep-candidate-info-value">' + escapeHtml(em.term) + '</span></div>';
        var sessionLabel = em.session || sp.session || '';
        if (sessionLabel) html += '        <div class="ep-candidate-info-row"><span class="ep-candidate-info-label">Session:</span><span class="ep-candidate-info-value">' + escapeHtml(sessionLabel) + '</span></div>';
        html += '      </div>';
        html += '    </div>';

        html += '    <div class="ep-sidebar-header">';
        html += '      <div class="ep-sidebar-title">' + escapeHtml(i18n.question || 'Question') + ' Navigator</div>';
        html += '      <div class="ep-sidebar-stats">';
        html += '        <div class="ep-sidebar-stat"><span class="ep-sidebar-dot answered"></span> ' + escapeHtml(i18n.answered || 'Answered') + '</div>';
        html += '        <div class="ep-sidebar-stat"><span class="ep-sidebar-dot unanswered"></span> ' + escapeHtml(i18n.unanswered || 'Unanswered') + '</div>';
        html += '      </div>';
        html += '    </div>';
        html += '    <div class="ep-question-grid" id="ep-question-grid"></div>';
        html += '  </div>';

        html += '</div>';

        $portal.html(html);
        renderQuestion();
        renderNavigator();
    }

    function renderQuestion() {
        var q = state.questions[state.currentIndex];
        if (!q) return;

        var qId = q.id;
        var options = [];
        try {
            options = typeof q.options === 'string' ? JSON.parse(q.options) : (q.options || []);
        } catch (e) {
            options = [];
        }
        if (!Array.isArray(options)) options = [];

        var selectedAnswer = state.responses[String(qId)] || state.responses[qId] || '';

        var html = '';

        // Question header
        html += '<div class="ep-question-header">';
        html += '  <div class="ep-question-num">' + escapeHtml(i18n.question || 'Question') + ' ' + (state.currentIndex + 1) + ' ' + escapeHtml(i18n.of || 'of') + ' ' + state.questions.length + '</div>';
        html += '  <div class="ep-question-meta">';
        if (q.subject) html += '<span>' + escapeHtml(q.subject) + '</span>';
        if (q.difficulty) html += '<span>' + escapeHtml(q.difficulty) + '</span>';
        if (q.marks) html += '<span>' + escapeHtml(String(q.marks)) + ' marks</span>';
        html += '  </div>';
        html += '</div>';

        // Passage text (if any)
        if (q.passage_text) {
            html += '<div class="ep-question-passage">' + escapeHtml(q.passage_text) + '</div>';
        }

        // Question text
        html += '<div class="ep-question-text">' + escapeHtml(q.question_text || '') + '</div>';

        // Options
        var letters = ['A', 'B', 'C', 'D', 'E', 'F'];
        html += '<div class="ep-options" id="ep-options">';
        for (var i = 0; i < options.length; i++) {
            var optText = options[i] || '';
            var isSelected = (selectedAnswer === optText);
            html += '<div class="ep-option' + (isSelected ? ' selected' : '') + '" data-option="' + escapeHtml(optText) + '" data-qid="' + qId + '">';
            html += '  <div class="ep-option-letter">' + (letters[i] || (i + 1)) + '</div>';
            html += '  <div class="ep-option-text">' + escapeHtml(optText) + '</div>';
            html += '</div>';
        }
        html += '</div>';

        // Navigation
        html += '<div class="ep-nav">';
        html += '  <button class="ep-nav-btn ep-btn-prev" id="ep-btn-prev"' + (state.currentIndex === 0 ? ' disabled' : '') + '>';
        html += '    ← ' + escapeHtml(i18n.prev || 'Previous');
        html += '  </button>';

        if (state.currentIndex === state.questions.length - 1) {
            html += '  <button class="ep-nav-btn ep-btn-submit" id="ep-btn-submit">' + escapeHtml(i18n.submitExam || 'Submit Exam') + '</button>';
        } else {
            html += '  <button class="ep-nav-btn ep-btn-next" id="ep-btn-next">';
            html += '    ' + escapeHtml(i18n.next || 'Next') + ' →';
            html += '  </button>';
        }
        html += '</div>';

        $('#ep-question-container').html(html);
        renderNavigator(); // Update active state

        // Bind events
        $('.ep-option').on('click', function () {
            var qid = $(this).data('qid');
            var opt = $(this).data('option');
            selectAnswer(qid, opt, $(this));
        });

        $('#ep-btn-prev').on('click', prevQuestion);
        $('#ep-btn-next').on('click', nextQuestion);
        $('#ep-btn-submit').on('click', submitExam);
    }

    function selectAnswer(qId, option, $el) {
        var qKey = String(qId);
        state.responses[qKey] = option;
        state.answerTimestamps[qKey] = Math.floor(Date.now() / 1000);

        // Update UI
        $el.siblings('.ep-option').removeClass('selected');
        $el.addClass('selected');

        renderNavigator();
        autoSave(); // Immediate save on answer
    }

    function renderNavigator() {
        var html = '';
        for (var i = 0; i < state.questions.length; i++) {
            var q = state.questions[i];
            var qKey = String(q.id);
            var answered = state.responses[qKey] && state.responses[qKey] !== '';
            var isCurrent = (i === state.currentIndex);
            var classes = 'ep-q-btn';
            if (answered) classes += ' answered';
            if (isCurrent) classes += ' current';
            html += '<button class="' + classes + '" data-index="' + i + '">' + (i + 1) + '</button>';
        }
        $('#ep-question-grid').html(html);

        // Update sidebar stats
        var answeredCount = Object.keys(state.responses).filter(function (k) {
            return state.responses[k] && state.responses[k] !== '';
        }).length;
        // Update any stat displays
    }

    function prevQuestion() {
        if (state.currentIndex > 0) {
            state.currentIndex--;
            renderQuestion();
        }
    }

    function nextQuestion() {
        if (state.currentIndex < state.questions.length - 1) {
            state.currentIndex++;
            renderQuestion();
        }
    }

    // Navigate to specific question
    $(document).on('click', '.ep-q-btn', function () {
        var idx = parseInt($(this).data('index'), 10);
        if (idx >= 0 && idx < state.questions.length) {
            state.currentIndex = idx;
            renderQuestion();
        }
    });

    // ===== TIMER =====

    function startTimer() {
        updateTimerDisplay();
        state.timerInterval = setInterval(function () {
            state.timerSeconds--;
            if (state.timerSeconds <= 0) {
                state.timerSeconds = 0;
                clearInterval(state.timerInterval);
                updateTimerDisplay();
                alert(i18n.timeUp || 'Time is up!');
                submitExam(true); // Force submit
                return;
            }
            updateTimerDisplay();
        }, 1000);
    }

    function updateTimerDisplay() {
        var s = state.timerSeconds;
        var h = Math.floor(s / 3600);
        var m = Math.floor((s % 3600) / 60);
        var sec = s % 60;
        var display = pad(h) + ':' + pad(m) + ':' + pad(sec);
        $('#ep-timer-display').text(display);

        // Warning states
        var $timer = $('#ep-timer');
        $timer.removeClass('warning danger');
        if (s <= 60) {
            $timer.addClass('danger');
        } else if (s <= 300) {
            $timer.addClass('warning');
        }
    }

    function pad(n) {
        return (n < 10 ? '0' : '') + n;
    }

    // ===== AUTOSAVE =====

    function startAutoSave() {
        state.autoSaveInterval = setInterval(function () {
            autoSave();
        }, autoSaveMs);

        // Save on page hide (power outage, tab close, etc.)
        $(window).on('beforeunload pagehide', function () {
            autoSave(true); // synchronous attempt
        });

        // Save on network restore
        window.addEventListener('online', function () {
            autoSave();
        });
    }

    function autoSave(sync) {
        if (!state.loaded || state.isSubmitting) return;

        var $indicator = $('#ep-save-indicator');
        $indicator.removeClass('saved failed').addClass('saving').text(i18n.saving || 'Saving...');

        var payload = {
            attempt_id: state.attemptId,
            responses: state.responses,
            answer_timestamps: state.answerTimestamps,
            current_index: state.currentIndex,
            timer_seconds_remaining: state.timerSeconds,
        };

        if (sync && navigator.sendBeacon) {
            // Use sendBeacon for synchronous saves on page unload
            var blob = new Blob([JSON.stringify(payload)], { type: 'application/json' });
            var beaconUrl = restUrl + '/student/exams/autosave';
            // sendBeacon doesn't support custom headers, so we use a different approach
            // Fall through to regular AJAX for now
        }

        $.ajax({
            url: restUrl + '/student/exams/autosave',
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify(payload),
            beforeSend: function (xhr) {
                xhr.setRequestHeader('X-WP-Nonce', nonce);
            },
            success: function () {
                $indicator.removeClass('saving failed').addClass('saved').text(i18n.saved || 'Saved');
            },
            error: function () {
                $indicator.removeClass('saving saved').addClass('failed').text(i18n.saveFailed || 'Save failed — will retry');
            }
        });
    }

    // ===== SUBMIT =====

    function submitExam(forced) {
        if (state.isSubmitting) return;

        if (!forced) {
            var answered = Object.keys(state.responses).filter(function (k) {
                return state.responses[k] && state.responses[k] !== '';
            }).length;
            var total = state.questions.length;
            var unanswered = total - answered;

            var msg = i18n.confirmSubmit || 'Are you sure you want to submit?';
            if (unanswered > 0) {
                msg += '\n\nYou have ' + unanswered + ' unanswered question(s) out of ' + total + '.';
            }
            if (!confirm(msg)) {
                return;
            }
        }

        state.isSubmitting = true;

        // Final autosave before submit
        autoSave();

        $.ajax({
            url: restUrl + '/student/exams/submit',
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({
                attempt_id: state.attemptId,
                responses: state.responses,
            }),
            beforeSend: function (xhr) {
                xhr.setRequestHeader('X-WP-Nonce', nonce);
            },
            success: function (res) {
                if (res && res.success) {
                    clearInterval(state.timerInterval);
                    clearInterval(state.autoSaveInterval);
                    exitFullscreen();
                    renderResults(res);
                } else {
                    showError('Failed to submit exam. Please try again or contact your administrator.');
                    state.isSubmitting = false;
                }
            },
            error: function () {
                showError('Failed to submit exam. Please try again or contact your administrator.');
                state.isSubmitting = false;
            }
        });
    }

    function renderResults(data) {
        var html = '';
        html += '<div class="ep-results">';

        if (data.is_trial_mode && data.review) {
            // Trial mode — show review
            html += '<div class="ep-results-icon">✓</div>';
            html += '<div class="ep-results-title">' + escapeHtml(i18n.examSubmitted || 'Exam submitted successfully!') + '</div>';
            html += '<div class="ep-results-subtitle">Trial mode — review your answers below</div>';

            html += '<div class="ep-results-card">';
            html += '  <div class="ep-results-stat"><div class="ep-results-stat-value score">' + (data.score || 0) + '</div><div class="ep-results-stat-label">' + escapeHtml(i18n.scoreLabel || 'Score') + '</div></div>';
            html += '  <div class="ep-results-stat"><div class="ep-results-stat-value grade">' + escapeHtml(data.grade || '-') + '</div><div class="ep-results-stat-label">' + escapeHtml(i18n.gradeLabel || 'Grade') + '</div></div>';
            html += '</div>';

            html += '<div class="ep-review">';
            for (var i = 0; i < data.review.length; i++) {
                var r = data.review[i];
                html += '<div class="ep-review-item">';
                html += '  <div class="ep-review-question">' + (i + 1) + '. ' + escapeHtml(r.question_text || '') + '</div>';
                html += '  <div class="ep-review-answer ' + (r.is_correct ? 'correct' : 'incorrect') + '">';
                html += '    <span class="ep-review-label">Your answer:</span> ' + escapeHtml(r.selected_answer || 'Not answered');
                html += '  </div>';
                if (!r.is_correct) {
                    html += '  <div class="ep-review-answer correct">';
                    html += '    <span class="ep-review-label">Correct answer:</span> ' + escapeHtml((r.correct_answers || []).join(', '));
                    html += '  </div>';
                }
                if (r.explanation) {
                    html += '  <div class="ep-review-explanation">' + escapeHtml(r.explanation) + '</div>';
                }
                html += '</div>';
            }
            html += '</div>';
        } else {
            // Regular mode — show score
            html += '<div class="ep-results-icon">✓</div>';
            html += '<div class="ep-results-title">' + escapeHtml(i18n.examSubmitted || 'Exam submitted successfully!') + '</div>';
            html += '<div class="ep-results-subtitle">Your exam has been submitted for grading.</div>';

            html += '<div class="ep-results-card">';
            html += '  <div class="ep-results-stat"><div class="ep-results-stat-value score">' + (data.score || 0) + '</div><div class="ep-results-stat-label">' + escapeHtml(i18n.scoreLabel || 'Score') + '</div></div>';
            html += '  <div class="ep-results-stat"><div class="ep-results-stat-value grade">' + escapeHtml(data.grade || '-') + '</div><div class="ep-results-stat-label">' + escapeHtml(i18n.gradeLabel || 'Grade') + '</div></div>';
            html += '</div>';
        }

        html += '</div>';
        $portal.html(html);
    }

    // ===== KEYBOARD SHORTCUTS =====
    $(document).on('keydown', function (e) {
        if (!state.loaded || state.isSubmitting) return;

        // Arrow left = prev, Arrow right = next
        if (e.key === 'ArrowLeft' && state.currentIndex > 0) {
            e.preventDefault();
            prevQuestion();
        }
        if (e.key === 'ArrowRight' && state.currentIndex < state.questions.length - 1) {
            e.preventDefault();
            nextQuestion();
        }

        // A/B/C/D = select option
        if (['a', 'b', 'c', 'd'].includes(e.key.toLowerCase())) {
            var letter = e.key.toUpperCase();
            var idx = letter.charCodeAt(0) - 65; // A=0, B=1, etc.
            var $opt = $('.ep-option').eq(idx);
            if ($opt.length) {
                $opt.trigger('click');
            }
        }
    });

    // Init on ready
    $(document).ready(init);

})(jQuery);
