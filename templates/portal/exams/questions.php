<?php
/**
 * Question Bank — Teacher Submission Interface.
 *
 * Rebuilt per the functional specification. The unit of work is a Question Set,
 * not an individual question. Four stacked regions:
 *
 *   A — Scope Selector (sticky)     Subject · Class · Exam Type · Marks · Method
 *   B — Input Surface               Content swaps based on Method + Exam Type
 *   C — Live Preview / Question List Everything already in the draft, editable
 *   D — Progress + Submit Bar (sticky)  Count / Marks / Submit
 *
 * @var array<string,mixed> $educbt
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

global $wpdb;

$school_id   = (int) $educbt['school_id'];
$flash       = \EduCBTPro\Frontend\PortalActions::flash();
$exam_prep_open = ( new \EduCBTPro\Services\SchoolService() )->is_exam_prep_enabled( $school_id );
$actor       = $educbt['scope']->actor();
$is_reviewer = $educbt['scope']->is_school_wide();

$subjects_table = \EduCBTPro\Core\Schema::table( 'subjects_v2' );
$assign         = \EduCBTPro\Core\Schema::table( 'staff_assignments' );
$classes_table  = \EduCBTPro\Core\Schema::table( 'classes' );

// --- Data: subjects and classes for the scope selector ---

if ( $is_reviewer ) {
    $subjects = (array) $wpdb->get_results(
        $wpdb->prepare( "SELECT id, name FROM {$subjects_table} WHERE school_id = %d AND status = 'active' ORDER BY name ASC", $school_id ),
        ARRAY_A
    );
} else {
    $subjects = (array) $wpdb->get_results(
        $wpdb->prepare(
            "SELECT DISTINCT s.id, s.name FROM {$assign} a
             INNER JOIN {$subjects_table} s ON s.id = a.subject_id
             WHERE a.school_id = %d AND a.staff_id = %d AND a.status = 'active' AND s.status = 'active'
             ORDER BY s.name ASC",
            $school_id,
            (int) $actor['id']
        ),
        ARRAY_A
    );
}

// Build subject → classes mapping for the cascading dropdown.
$subject_classes = [];
if ( ! empty( $subjects ) ) {
    $subject_ids = array_map( static fn( $s ) => (int) $s['id'], $subjects );
    $holder = implode( ',', array_fill( 0, count( $subject_ids ), '%d' );

    $rows = (array) $wpdb->get_results(
        $wpdb->prepare(
            "SELECT DISTINCT a.subject_id, c.id AS class_id, c.display_name
             FROM {$assign} a
             INNER JOIN {$classes_table} c ON c.id = a.class_id
             WHERE a.school_id = %d AND a.staff_id = %d AND a.status = 'active'
               AND a.subject_id IN ($holder)
             ORDER BY c.display_name ASC",
            array_merge( [ $school_id, (int) $actor['id'] ], $subject_ids )
        ),
        ARRAY_A
    );

    foreach ( $rows as $r ) {
        $sid = (int) $r['subject_id'];
        if ( ! isset( $subject_classes[ $sid ] ) ) {
            $subject_classes[ $sid ] = [];
        }
        $subject_classes[ $sid ][] = [ 'id' => (int) $r['class_id'], 'name' => $r['display_name'] ];
    }

    // Reviewers see all classes for all subjects.
    if ( $is_reviewer ) {
        $all_classes = (array) $wpdb->get_results(
            $wpdb->prepare( "SELECT id, display_name FROM {$classes_table} WHERE school_id = %d AND status = 'active' ORDER BY display_name ASC", $school_id ),
            ARRAY_A
        );
        foreach ( $subjects as $s ) {
            $sid = (int) $s['id'];
            $subject_classes[ $sid ] = array_map( static fn( $c ) => [ 'id' => (int) $c['id'], 'name' => $c['display_name'] ], $all_classes );
        }
    }
}

// Session and term (read-only).
$ay_service = new \EduCBTPro\Services\AcademicYearService();
$session    = $ay_service->current_session( $school_id );
$session_id = absint( $session['id'] ?? 0 );
$term_id    = absint( $session['current_term_id'] ?? 0 );
$session_label = '';
if ( ! empty( $session['title'] ) ) {
    $term_label = '';
    if ( $term_id > 0 ) {
        $term_row = $wpdb->get_row(
            $wpdb->prepare( "SELECT title FROM " . \EduCBTPro\Core\Schema::table( 'terms' ) . " WHERE id = %d", $term_id ),
            ARRAY_A
        );
        if ( $term_row ) {
            $term_label = ' · ' . $term_row['title'];
        }
    }
    $session_label = esc_html( $session['title'] . $term_label );
}

// Quotas / minimums.
$approval_svc   = new \EduCBTPro\Services\QuestionApprovalService();
$quota_info     = $approval_svc->quotas( $school_id );
$min_objective  = absint( $quota_info['objective_quota'] ?? 20 );
$min_theory     = absint( $quota_info['theory_quota'] ?? 4 );

// Existing submissions status for this teacher.
$my_submissions = [];
if ( ! $is_reviewer && ! empty( $subjects ) ) {
    $my_submissions = $approval_svc->submissions( $school_id, (int) $actor['id'] );
}

// Passages for the passage selector.
$passages = (array) $wpdb->get_results(
    $wpdb->prepare(
        'SELECT id, title FROM ' . \EduCBTPro\Core\Schema::table( 'passages' ) . " WHERE school_id = %d AND status = 'active' ORDER BY id DESC LIMIT 50",
        $school_id
    ),
    ARRAY_A
);

$educbt_title = 'Question Bank';

$educbt_body = static function () use (
    $flash, $subjects, $subject_classes, $session_label, $session_id, $term_id,
    $exam_prep_open, $is_reviewer, $actor, $min_objective, $min_theory,
    $my_submissions, $passages, $school_id
): void {
    require EDUCBT_PRO_PATH . 'templates/portal/partials/flash.php';
    ?>
<script>
window.EduCBTQS = {
    root: <?php echo wp_json_encode( esc_url_raw( rest_url( 'educbt/v1/' ) ) ); ?>,
    nonce: <?php echo wp_json_encode( wp_create_nonce( 'wp_rest' ) ); ?>,
    schoolId: <?php echo (int) $school_id; ?>,
    actorId: <?php echo (int) $actor['id']; ?>,
    isReviewer: <?php echo $is_reviewer ? 'true' : 'false'; ?>,
    examPrepOpen: <?php echo $exam_prep_open ? 'true' : 'false'; ?>,
    sessionId: <?php echo (int) $session_id; ?>,
    termId: <?php echo (int) $term_id; ?>,
    minObjective: <?php echo (int) $min_objective; ?>,
    minTheory: <?php echo (int) $min_theory; ?>,
    subjects: <?php echo wp_json_encode( $subjects ); ?>,
    subjectClasses: <?php echo wp_json_encode( $subject_classes ); ?>,
    passages: <?php echo wp_json_encode( $passages ); ?>,
};
</script>

<?php if ( ! $exam_prep_open && ! $is_reviewer ): ?>
<div class="educbt-card" style="border-left:4px solid var(--edu-warn)">
    <p style="margin:0"><strong>Exam preparation is closed.</strong> Question submission is locked. Your Exam Officer controls this.</p>
</div>
<?php return; ?>
<?php endif; ?>

<?php if ( ! empty( $my_submissions ) ): ?>
<div class="educbt-card" style="margin-bottom:14px">
    <h2 style="margin:0 0 8px;font-size:1.05rem">My Submissions</h2>
    <?php foreach ( $my_submissions as $sub ): ?>
        <div style="display:flex;align-items:center;gap:8px;padding:5px 0;border-bottom:1px solid var(--edu-line)">
            <span style="font-weight:600"><?php echo esc_html( $sub['subject_name'] ?? 'Subject' ); ?></span>
            <?php if ( ! empty( $sub['objective_count'] ) ): ?>
                <span class="educbt-pill educbt-pill--draft">Objective: <?php echo (int) $sub['objective_count']; ?>/<?php echo (int) $min_objective; ?></span>
            <?php endif; ?>
            <?php if ( ! empty( $sub['theory_count'] ) ): ?>
                <span class="educbt-pill educbt-pill--draft">Theory: <?php echo (int) $sub['theory_count']; ?>/<?php echo (int) $min_theory; ?></span>
            <?php endif; ?>
            <?php
            $status_pill = 'educbt-pill--draft';
            $status_text = 'pending';
            if ( ! empty( $sub['sent_back'] ) ) { $status_pill = 'educbt-pill--warn'; $status_text = 'sent back'; }
            elseif ( ! empty( $sub['all_approved'] ) ) { $status_pill = 'educbt-pill--ok'; $status_text = 'all approved'; }
            elseif ( ! empty( $sub['pending'] ) ) { $status_text = 'awaiting approval'; }
            ?>
            <span class="educbt-pill <?php echo esc_attr( $status_pill ); ?>"><?php echo esc_html( $status_text ); ?></span>
        </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- =========== REGION A — SCOPE SELECTOR (sticky) =========== -->
<div id="qs-scope" class="educbt-card" style="position:sticky;top:0;z-index:50;background:var(--edu-surface,#fff);border-bottom:2px solid var(--edu-line)">
    <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end">
        <div style="flex:1;min-width:160px">
            <label class="educbt-muted" style="font-size:.8rem;display:block;margin-bottom:3px">Session / Term</label>
            <span style="font-weight:600;font-size:.9rem"><?php echo $session_label ?: 'No session set'; ?></span>
        </div>
        <div style="flex:1;min-width:160px">
            <label class="educbt-muted" style="font-size:.8rem;display:block;margin-bottom:3px">Subject</label>
            <select id="qs-subject" class="educbt-input" style="width:100%">
                <option value="">Choose subject…</option>
                <?php foreach ( $subjects as $s ): ?>
                    <option value="<?php echo (int) $s['id']; ?>"><?php echo esc_html( $s['name'] ); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div style="flex:1;min-width:140px">
            <label class="educbt-muted" style="font-size:.8rem;display:block;margin-bottom:3px">Class</label>
            <select id="qs-class" class="educbt-input" style="width:100%" disabled>
                <option value="">Choose subject first…</option>
            </select>
        </div>
        <div style="min-width:180px">
            <label class="educbt-muted" style="font-size:.8rem;display:block;margin-bottom:3px">Exam Type</label>
            <div id="qs-exam-type" style="display:flex;gap:0;border:1px solid var(--edu-line);border-radius:8px;overflow:hidden">
                <button type="button" data-type="objective" class="qs-type-btn" style="flex:1;padding:7px 12px;border:0;background:var(--edu-primary,#3b82f6);color:#fff;font-weight:600;cursor:pointer">Objective</button>
                <button type="button" data-type="theory" class="qs-type-btn" style="flex:1;padding:7px 12px;border:0;background:transparent;color:inherit;font-weight:500;cursor:pointer">Theory</button>
            </div>
        </div>
        <div style="min-width:80px">
            <label class="educbt-muted" style="font-size:.8rem;display:block;margin-bottom:3px">Default Marks</label>
            <input type="number" id="qs-marks" class="educbt-input" value="1" min="0.5" step="0.5" style="width:70px">
        </div>
        <div style="min-width:200px">
            <label class="educbt-muted" style="font-size:.8rem;display:block;margin-bottom:3px">Method</label>
            <select id="qs-method" class="educbt-input" style="width:100%">
                <option value="manual">Manual Entry</option>
                <option value="paste">Paste in Format</option>
                <option value="csv">CSV / Excel Import</option>
            </select>
        </div>
    </div>
</div>

<!-- =========== STATUS BANNER =========== -->
<div id="qs-status-banner" style="display:none;margin-top:12px"></div>

<!-- =========== REGION B — INPUT SURFACE =========== -->
<div id="qs-input" class="educbt-card" style="margin-top:12px;display:none">
    <!-- Content injected by JS based on method + exam type -->
</div>

<!-- =========== REGION C — LIVE PREVIEW =========== -->
<div id="qs-preview" class="educbt-card" style="margin-top:12px;min-height:200px">
    <div id="qs-empty-state">
        <p class="educbt-muted" style="text-align:center;padding:40px 20px">
            Choose a subject and class to start writing questions.<br>
            You can enter questions manually, paste them in, or import from CSV — all three methods add to the same set.
        </p>
    </div>
    <div id="qs-question-list" style="display:none"></div>
</div>

<!-- =========== REGION D — PROGRESS + SUBMIT BAR (sticky) =========== -->
<div id="qs-submit-bar" style="position:sticky;bottom:0;z-index:50;background:var(--edu-surface,#fff);border-top:2px solid var(--edu-line);padding:10px 16px;display:flex;align-items:center;gap:16px">
    <div id="qs-progress" style="flex:1;display:none">
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px">
            <span id="qs-count-label" class="educbt-muted" style="font-size:.85rem">0 questions</span>
            <span id="qs-marks-label" class="educbt-muted" style="font-size:.85rem">0 marks</span>
            <span id="qs-sibling-label" class="educbt-muted" style="font-size:.85rem"></span>
        </div>
        <div style="height:6px;background:var(--edu-line);border-radius:3px;overflow:hidden">
            <div id="qs-progress-bar" style="height:100%;width:0%;background:var(--edu-primary,#3b82f6);transition:width .3s"></div>
        </div>
    </div>
    <div id="qs-saved-indicator" class="educbt-muted" style="font-size:.8rem"></div>
    <button type="button" id="qs-submit-btn" class="educbt-btn educbt-btn--primary" disabled style="display:none">Submit for Review</button>
</div>

<script>
(function(){
    'use strict';

    const API = window.EduCBTQS;
    let currentSet = null;
    let currentExamType = 'objective';
    let currentMethod = 'manual';
    let currentQuestions = [];
    let unsavedInput = false;

    // ---- Helpers ----

    function apiCall(method, path, body) {
        const opts = {
            method: method,
            headers: {
                'X-WP-Nonce': API.nonce,
                'Content-Type': 'application/json',
            },
            credentials: 'same-origin',
        };
        if (body) opts.body = JSON.stringify(body);
        return fetch(API.root + path, opts).then(r => r.json());
    }

    function el(id) { return document.getElementById(id); }
    function esc(s) { const d = document.createElement('div'); d.textContent = s || ''; return d.innerHTML; }

    function showSaved(msg) {
        const ind = el('qs-saved-indicator');
        ind.textContent = msg || 'All changes saved';
        ind.style.color = '';
    }
    function showSaving() { el('qs-saved-indicator').textContent = 'Saving…'; }
    function showSaveError(msg) { el('qs-saved-indicator').textContent = msg || 'Save failed — retry'; el('qs-saved-indicator').style.color = 'red'; }

    // ---- Scope selector ----

    const subjectSel = el('qs-subject');
    const classSel = el('qs-class');
    const marksInput = el('qs-marks');
    const methodSel = el('qs-method');

    subjectSel.addEventListener('change', function() {
        const sid = parseInt(this.value);
        classSel.innerHTML = '<option value="">Choose class…</option>';
        classSel.disabled = !sid;

        if (sid && API.subjectClasses[sid]) {
            API.subjectClasses[sid].forEach(function(c) {
                const opt = document.createElement('option');
                opt.value = c.id;
                opt.textContent = c.name;
                classSel.appendChild(opt);
            });
        }
        classSel.addEventListener('change', loadSet);
        loadSet();
    });

    document.querySelectorAll('.qs-type-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            if (unsavedInput && !confirm('You have an unfinished question. Discard it and switch?')) return;
            unsavedInput = false;
            currentExamType = this.dataset.type;
            document.querySelectorAll('.qs-type-btn').forEach(function(b) {
                b.style.background = b.dataset.type === currentExamType ? 'var(--edu-primary,#3b82f6)' : 'transparent';
                b.style.color = b.dataset.type === currentExamType ? '#fff' : 'inherit';
                b.style.fontWeight = b.dataset.type === currentExamType ? '600' : '500';
            });
            if (subjectSel.value && classSel.value) loadSet();
            renderInput();
        });
    });

    methodSel.addEventListener('change', function() {
        currentMethod = this.value;
        renderInput();
    });

    marksInput.addEventListener('change', function() {
        if (currentSet) {
            apiCall('POST', 'question-sets', {
                subject_id: parseInt(subjectSel.value),
                class_id: parseInt(classSel.value),
                exam_type: currentExamType,
                default_marks: parseFloat(marksInput.value),
            }).then(function(r) {
                if (r.success) currentSet = r.set;
            });
        }
    });

    // ---- Load / create set ----

    function loadSet() {
        const sid = parseInt(subjectSel.value);
        const cid = parseInt(classSel.value);
        if (!sid || !cid) return;

        showSaving();
        apiCall('GET', 'question-sets?subject_id=' + sid + '&class_id=' + cid + '&exam_type=' + currentExamType)
            .then(function(r) {
                if (!r.success) { showSaveError(); return; }
                currentSet = r.set;
                currentQuestions = r.questions || [];
                renderPreview();
                renderProgress();
                renderStatusBanner();
                showSaved(r.set ? 'Set loaded' : 'Ready');
                renderInput();
            })
            .catch(function() { showSaveError(); });
    }

    function ensureSet(cb) {
        if (currentSet) { cb(currentSet); return; }
        showSaving();
        apiCall('POST', 'question-sets', {
            subject_id: parseInt(subjectSel.value),
            class_id: parseInt(classSel.value),
            exam_type: currentExamType,
            default_marks: parseFloat(marksInput.value),
        }).then(function(r) {
            if (r.success) { currentSet = r.set; cb(currentSet); }
            else showSaveError();
        });
    }

    // ---- Status banner ----

    function renderStatusBanner() {
        const banner = el('qs-status-banner');
        if (!currentSet) { banner.style.display = 'none'; return; }
        const status = currentSet.status;
        if (status === 'draft') { banner.style.display = 'none'; return; }
        const messages = {
            submitted: { text: 'Submitted — awaiting Exam Officer review.', bg: '#e0e7ff', color: '#3730a3' },
            under_review: { text: 'Under review by Exam Officer.', bg: '#fef3c7', color: '#92400e' },
            returned: { text: 'Returned for revision: ' + (currentSet.reviewer_comment || 'See comments below.'), bg: '#fee2e2', color: '#991b1b' },
            approved: { text: 'Approved — ready for paper assembly.', bg: '#d1fae5', color: '#065f46' },
            published: { text: 'Published — attached to a live exam paper.', bg: '#e0e7ff', color: '#3730a3' },
        };
        const m = messages[status] || messages.submitted;
        banner.style.display = 'block';
        banner.style.background = m.bg;
        banner.style.color = m.color;
        banner.style.padding = '10px 14px';
        banner.style.borderRadius = '8px';
        banner.style.fontWeight = '600';
        banner.innerHTML = esc(m.text);
    }

    // ---- Region B: Input surface ----

    function renderInput() {
        const container = el('qs-input');
        if (!currentSet || !isEditable()) {
            container.style.display = 'none';
            return;
        }
        if (!subjectSel.value || !classSel.value) {
            container.style.display = 'none';
            return;
        }
        container.style.display = 'block';

        if (currentMethod === 'manual') {
            renderManualEntry(container);
        } else if (currentMethod === 'paste') {
            renderPasteEntry(container);
        } else if (currentMethod === 'csv') {
            renderCSVImport(container);
        }
    }

    function isEditable() {
        return currentSet && (currentSet.status === 'draft' || currentSet.status === 'returned');
    }

    // ---- Manual Entry ----

    function renderManualEntry(container) {
        const isTheory = currentExamType === 'theory';
        const marks = parseFloat(marksInput.value) || 1;

        let html = '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">';
        html += '<h3 style="margin:0;font-size:1rem">Add ' + (isTheory ? 'Theory' : 'Objective') + ' Question</h3>';
        html += '<span class="educbt-muted" style="font-size:.8rem">Manual Entry</span>';
        html += '</div>';

        html += '<div style="display:flex;flex-direction:column;gap:12px">';
        html += '<div><label class="educbt-muted" style="font-size:.8rem">Question Text</label>';
        html += '<textarea id="qe-stem" class="educbt-input" rows="3" style="width:100%;margin-top:3px" placeholder="Type your question…"></textarea></div>';

        if (isTheory) {
            html += '<div id="qe-sub-items" style="display:none;border:1px solid var(--edu-line);border-radius:8px;padding:10px">';
            html += '<label class="educbt-muted" style="font-size:.8rem">Sub-questions (optional)</label>';
            html += '<div id="qe-sub-list"></div>';
            html += '<button type="button" id="qe-add-sub" class="educbt-btn" style="font-size:.8rem;padding:4px 10px;margin-top:6px">+ Add sub-question</button>';
            html += '</div>';
            html += '<div style="display:flex;gap:10px">';
            html += '<div><label class="educbt-muted" style="font-size:.8rem">Marks</label>';
            html += '<input type="number" id="qe-marks" class="educbt-input" value="' + marks + '" min="0.5" step="0.5" style="width:80px"></div>';
            html += '</div>';
            html += '<div><label class="educbt-muted" style="font-size:.8rem">Marking Guide / Model Answer (markers only)</label>';
            html += '<textarea id="qe-guide" class="educbt-input" rows="2" style="width:100%;margin-top:3px" placeholder="What a correct answer should contain…"></textarea></div>';
            html += '<div id="qe-sub-toggle" style="text-align:right"><button type="button" id="qe-toggle-sub" class="educbt-btn" style="font-size:.8rem;padding:3px 8px">+ Sub-questions</button></div>';
        } else {
            html += '<div id="qe-options" style="display:flex;flex-direction:column;gap:6px"></div>';
            html += '<div style="margin-top:4px"><button type="button" id="qe-add-option" class="educbt-btn" style="font-size:.8rem;padding:3px 8px">+ Add option</button></div>';
            html += '<div><label class="educbt-muted" style="font-size:.8rem">Explanation (optional, shown to students in review)</label>';
            html += '<textarea id="qe-explanation" class="educbt-input" rows="1" style="width:100%;margin-top:3px"></textarea></div>';
            html += '<div style="display:flex;gap:10px"><div><label class="educbt-muted" style="font-size:.8rem">Marks</label>';
            html += '<input type="number" id="qe-marks" class="educbt-input" value="' + marks + '" min="0.5" step="0.5" style="width:80px"></div></div>';
        }

        html += '<div style="display:flex;gap:8px;margin-top:6px">';
        html += '<button type="button" id="qe-save" class="educbt-btn educbt-btn--primary">Add Question</button>';
        html += '<button type="button" id="qe-dup" class="educbt-btn">Add & Duplicate</button>';
        html += '<button type="button" id="qe-clear" class="educbt-btn" style="margin-left:auto">Clear</button>';
        html += '</div>';
        html += '</div>';

        container.innerHTML = html;

        if (!isTheory) {
            // Initialize 4 options A-D
            let optCount = 4;
            function renderOptions() {
                const optsContainer = el('qe-options');
                optsContainer.innerHTML = '';
                for (let i = 0; i < optCount; i++) {
                    const letter = String.fromCharCode(65 + i);
                    const row = document.createElement('div');
                    row.style.cssText = 'display:flex;align-items:center;gap:6px';
                    row.innerHTML =
                        '<input type="radio" name="qe-correct" value="' + i + '" style="margin:0" title="Correct answer">' +
                        '<span style="font-weight:600;width:20px">' + letter + '.</span>' +
                        '<input type="text" class="educbt-input qe-opt" data-idx="' + i + '" placeholder="Option ' + letter + '" style="flex:1">' +
                        (optCount > 2 ? '<button type="button" class="qe-rm-opt" data-idx="' + i + '" style="border:0;background:transparent;color:red;cursor:pointer;padding:0 4px">×</button>' : '');
                    optsContainer.appendChild(row);
                }
                optsContainer.querySelectorAll('.qe-rm-opt').forEach(function(btn) {
                    btn.addEventListener('click', function() {
                        optCount--;
                        renderOptions();
                    });
                });
            }
            renderOptions();
            el('qe-add-option').addEventListener('click', function() {
                if (optCount < 8) { optCount++; renderOptions(); }
            });
        } else {
            // Theory: sub-questions toggle
            let subVisible = false;
            let subItems = [];
            el('qe-toggle-sub').addEventListener('click', function() {
                subVisible = !subVisible;
                el('qe-sub-items').style.display = subVisible ? 'block' : 'none';
                el('qe-toggle-sub').textContent = subVisible ? '− Sub-questions' : '+ Sub-questions';
                if (subVisible && subItems.length === 0) {
                    subItems.push({ text: '', marks: 1 });
                    renderSubItems();
                }
            });
            function renderSubItems() {
                const list = el('qe-sub-list');
                list.innerHTML = '';
                subItems.forEach(function(item, i) {
                    const letter = String.fromCharCode(97 + i);
                    const row = document.createElement('div');
                    row.style.cssText = 'display:flex;gap:6px;margin-bottom:4px;align-items:flex-start';
                    row.innerHTML =
                        '<span style="font-weight:600;width:20px;padding-top:4px">(' + letter + ')</span>' +
                        '<input type="text" class="educbt-input qe-sub-text" data-idx="' + i + '" placeholder="Sub-question ' + letter + '" style="flex:1">' +
                        '<input type="number" class="educbt-input qe-sub-marks" data-idx="' + i + '" value="' + item.marks + '" min="0.5" step="0.5" style="width:65px">' +
                        (subItems.length > 1 ? '<button type="button" class="qe-rm-sub" data-idx="' + i + '" style="border:0;background:transparent;color:red;cursor:pointer;padding:0 4px">×</button>' : '');
                    list.appendChild(row);
                });
                list.querySelectorAll('.qe-sub-text').forEach(function(inp) {
                    inp.addEventListener('input', function() {
                        subItems[parseInt(this.dataset.idx)].text = this.value;
                    });
                });
                list.querySelectorAll('.qe-sub-marks').forEach(function(inp) {
                    inp.addEventListener('input', function() {
                        subItems[parseInt(this.dataset.idx)].marks = parseFloat(this.value) || 0;
                        updateSubTotal();
                    });
                });
                list.querySelectorAll('.qe-rm-sub').forEach(function(btn) {
                    btn.addEventListener('click', function() {
                        subItems.splice(parseInt(this.dataset.idx), 1);
                        renderSubItems();
                        updateSubTotal();
                    });
                });
            }
            function updateSubTotal() {
                const total = subItems.reduce(function(s, i) { return s + (i.marks || 0); }, 0);
                if (total > 0) el('qe-marks').value = total;
            }
            el('qe-add-sub').addEventListener('click', function() {
                subItems.push({ text: '', marks: 1 });
                renderSubItems();
            });
            el('qe-save')._subItems = function() { return subItems; };
        }

        // Detect unsaved input
        container.querySelectorAll('input, textarea').forEach(function(inp) {
            inp.addEventListener('input', function() { unsavedInput = true; });
        });

        // Save
        el('qe-save').addEventListener('click', function() {
            saveQuestion(false);
        });
        el('qe-dup').addEventListener('click', function() {
            saveQuestion(true);
        });
        el('qe-clear').addEventListener('click', function() {
            clearForm();
            unsavedInput = false;
        });
    }

    function saveQuestion(keepOptions) {
        const stem = el('qe-stem').value.trim();
        if (!stem) { alert('Question text is required.'); return; }

        const marks = parseFloat(el('qe-marks').value) || 0;
        if (marks <= 0) { alert('Marks must be greater than zero.'); return; }

        const data = {
            stem: stem,
            marks: marks,
            source_method: 'manual',
        };

        if (currentExamType === 'objective') {
            const opts = [];
            const optInputs = document.querySelectorAll('.qe-opt');
            const correctRadio = document.querySelector('input[name="qe-correct"]:checked');
            let correctIdx = -1;
            if (correctRadio) correctIdx = parseInt(correctRadio.value);

            optInputs.forEach(function(inp, i) {
                const text = inp.value.trim();
                if (text) {
                    opts.push({ text: text, is_correct: i === correctIdx });
                }
            });

            if (opts.length < 2) { alert('Give at least two options.'); return; }
            if (correctIdx < 0 || !opts[correctIdx]) { alert('Mark which option is correct.'); return; }

            data.options = opts;
        } else {
            const guide = el('qe-guide');
            if (guide) data.marking_guide = guide.value.trim();
            // Sub-questions
            const subContainer = el('qe-sub-items');
            if (subContainer && subContainer.style.display !== 'none') {
                const subTexts = document.querySelectorAll('.qe-sub-text');
                const subMarks = document.querySelectorAll('.qe-sub-marks');
                const subs = [];
                subTexts.forEach(function(inp, i) {
                    const text = inp.value.trim();
                    if (text) {
                        subs.push({ text: text, marks: parseFloat(subMarks[i].value) || 0 });
                    }
                });
                if (subs.length > 0) data.sub_items = subs;
            }
        }

        ensureSet(function(set) {
            showSaving();
            apiCall('POST', 'question-sets/' + set.id + '/questions', data)
                .then(function(r) {
                    if (r.success) {
                        unsavedInput = false;
                        refreshQuestions();
                        if (!keepOptions) {
                            clearForm();
                            el('qe-stem').focus();
                        }
                        showSaved('Question saved');
                    } else {
                        showSaveError(r.error || 'Save failed');
                        alert('Could not save: ' + (r.error || 'unknown error'));
                    }
                })
                .catch(function() { showSaveError(); });
        });
    }

    function clearForm() {
        const container = el('qs-input');
        const stem = el('qe-stem');
        if (stem) stem.value = '';
        const explanation = el('qe-explanation');
        if (explanation) explanation.value = '';
        const guide = el('qe-guide');
        if (guide) guide.value = '';
        document.querySelectorAll('.qe-opt').forEach(function(inp) { inp.value = ''; });
        document.querySelectorAll('input[name="qe-correct"]').forEach(function(r) { r.checked = false; });
    }

    // ---- Paste in Format ----

    function renderPasteEntry(container) {
        const isTheory = currentExamType === 'theory';
        let guide = '';
        if (!isTheory) {
            guide = '<div style="background:var(--edu-muted-bg,#f5f5f5);border-radius:8px;padding:10px;margin-bottom:10px;font-family:monospace;font-size:.85rem;white-space:pre-wrap">1. What is the capital of Nigeria?\nA) Lagos\nB) Abuja\nC) Kano\nD) Port Harcourt\nANSWER: B\nMARKS: 2\n\n2. Which gas do plants absorb?\nA) Oxygen\nB) Nitrogen\nC) Carbon dioxide\nD) Hydrogen\nANSWER: C</div>';
        } else {
            guide = '<div style="background:var(--edu-muted-bg,#f5f5f5);border-radius:8px;padding:10px;margin-bottom:10px;font-family:monospace;font-size:.85rem;white-space:pre-wrap">1. Explain three causes of the Nigerian Civil War.\nMARKS: 9\n\n2. With a labelled diagram, describe the structure of a plant cell.\nMARKS: 12</div>';
        }

        container.innerHTML =
            '<h3 style="margin:0 0 8px;font-size:1rem">Paste Questions — ' + (isTheory ? 'Theory' : 'Objective') + '</h3>' +
            '<details style="margin-bottom:10px"><summary style="cursor:pointer;font-size:.85rem;color:var(--edu-muted)">Format Guide</summary>' + guide + '</details>' +
            '<textarea id="qs-paste-area" class="educbt-input" rows="12" style="width:100%;font-family:monospace" placeholder="Paste your questions here…"></textarea>' +
            '<div style="display:flex;gap:8px;margin-top:8px">' +
            '<button type="button" id="qs-parse-paste" class="educbt-btn educbt-btn--primary">Parse & Preview</button>' +
            '<button type="button" id="qs-paste-cancel" class="educbt-btn" style="margin-left:auto">Cancel</button>' +
            '</div>' +
            '<div id="qs-staging" style="margin-top:12px"></div>';

        el('qs-parse-paste').addEventListener('click', function() {
            const text = el('qs-paste-area').value.trim();
            if (!text) { alert('Paste some questions first.'); return; }
            const parsed = isTheory ? parseTheoryPaste(text) : parseObjectivePaste(text);
            renderStagingTable(parsed);
        });
        el('qs-paste-cancel').addEventListener('click', function() {
            container.style.display = 'none';
        });
    }

    function parseObjectivePaste(text) {
        // Normalize Word artifacts: smart quotes, nbsp, etc.
        text = text.replace(/\u2018|\u2019/g, "'").replace(/\u201c|\u201d/g, '"').replace(/\u00a0/g, ' ').replace(/\u2013|\u2014/g, '-');
        const blocks = text.split(/\n\s*\n/).filter(b => b.trim());
        const results = [];

        blocks.forEach(function(block) {
            const lines = block.trim().split('\n').map(l => l.trim()).filter(l => l);
            let q = { stem: '', options: [], correct: -1, marks: 0, status: 'valid', errors: [] };

            for (let line of lines) {
                // Question stem (starts with number+dot or number+paren)
                let m = line.match(/^(\d+)[.)]\s*(.*)/);
                if (m && !q.stem) { q.stem = m[2]; continue; }

                // Option line: A) B. C- D) etc
                m = line.match(/^([A-Fa-f])\s*[.)\-]\s*(.*)/);
                if (m) {
                    q.options.push({ text: m[2], label: m[1].toUpperCase() });
                    continue;
                }

                // Answer line
                m = line.match(/^ANSWER\s*[:\.]\s*([A-Fa-f])/i);
                if (m) {
                    const letter = m[1].toUpperCase();
                    q.correct = q.options.findIndex(function(o) { return o.label === letter; });
                    if (q.correct < 0) q.correct = letter.charCodeAt(0) - 65;
                    continue;
                }

                // Marks line
                m = line.match(/^MARKS\s*[:\.]\s*(\d+(?:\.\d+)?)/i);
                if (m) { q.marks = parseFloat(m[1]); continue; }

                // Continuation of stem (multi-line)
                if (q.stem && q.options.length === 0) {
                    q.stem += ' ' + line;
                }
            }

            // Validate
            if (!q.stem) { q.status = 'error'; q.errors.push('Missing question text'); }
            if (q.options.length < 2) { q.status = 'error'; q.errors.push('Need at least 2 options'); }
            if (q.correct < 0) { q.status = 'warning'; q.errors.push('No correct answer marked'); }
            if (!q.marks) q.marks = parseFloat(marksInput.value) || 1;

            results.push(q);
        });

        return results;
    }

    function parseTheoryPaste(text) {
        text = text.replace(/\u2018|\u2019/g, "'").replace(/\u201c|\u201d/g, '"').replace(/\u00a0/g, ' ').replace(/\u2013|\u2014/g, '-');
        const lines = text.split('\n');
        const results = [];
        let q = null;

        for (let line of lines) {
            line = line.trim();
            if (!line) continue;

            let m = line.match(/^(\d+)[.)]\s*(.*)/);
            if (m) {
                if (q) results.push(q);
                q = { stem: m[2], marks: 0, status: 'valid', errors: [] };
                continue;
            }
            m = line.match(/^MARKS\s*[:\.]\s*(\d+(?:\.\d+)?)/i);
            if (m && q) { q.marks = parseFloat(m[1]); continue; }
            if (q && q.stem) q.stem += ' ' + line;
        }
        if (q) results.push(q);

        results.forEach(function(r) {
            if (!r.stem) { r.status = 'error'; r.errors.push('Missing question text'); }
            if (!r.marks) r.marks = parseFloat(marksInput.value) || 1;
        });

        return results;
    }

    // ---- CSV Import ----

    function renderCSVImport(container) {
        const isTheory = currentExamType === 'theory';
        const cols = isTheory
            ? 'question, marks, marking_guide, sub_questions'
            : 'question, option_a, option_b, option_c, option_d, correct_option, marks, passage_ref, explanation';

        const example = isTheory
            ? 'question,marks,marking_guide,sub_questions\n"Explain photosynthesis",10,"Light energy → chemical energy...","a. Light reaction|b. Dark reaction"\n"Define gravity",5,"Force of attraction between masses...",""'
            : 'question,option_a,option_b,option_c,option_d,correct_option,marks,passage_ref,explanation\n"What is 2+2?","1","2","3","4","D","2","",""\n"Capital of Nigeria?","Lagos","Abuja","Kano","PH","B","1","",""';

        container.innerHTML =
            '<h3 style="margin:0 0 8px;font-size:1rem">CSV / Excel Import — ' + (isTheory ? 'Theory' : 'Objective') + '</h3>' +
            '<div style="margin-bottom:10px">' +
            '<button type="button" id="qs-download-template" class="educbt-btn" style="font-size:.8rem">Download Template</button>' +
            '</div>' +
            '<input type="file" id="qs-csv-file" accept=".csv,.xlsx,.xls" class="educbt-input" style="margin-bottom:8px">' +
            '<div style="font-size:.8rem;color:var(--edu-muted);margin-bottom:8px">Columns: <code>' + esc(cols) + '</code></div>' +
            '<div id="qs-staging" style="margin-top:12px"></div>';

        el('qs-download-template').addEventListener('click', function() {
            const blob = new Blob([example], { type: 'text/csv' });
            const a = document.createElement('a');
            a.href = URL.createObjectURL(blob);
            a.download = 'template_' + currentExamType + '.csv';
            a.click();
        });

        el('qs-csv-file').addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (!file) return;
            const reader = new FileReader();
            reader.onload = function(ev) {
                const text = ev.target.result;
                const parsed = parseCSV(text, isTheory);
                renderStagingTable(parsed);
            };
            reader.readAsText(file);
        });
    }

    function parseCSV(text, isTheory) {
        const lines = text.split('\n').filter(l => l.trim());
        if (lines.length < 2) return [];
        const headers = lines[0].split(',').map(h => h.trim().toLowerCase());
        const results = [];

        for (let i = 1; i < lines.length; i++) {
            const cols = parseCSVLine(lines[i]);
            const row = {};
            headers.forEach(function(h, j) { row[h] = (cols[j] || '').trim(); });

            if (isTheory) {
                const q = { stem: row.question || '', marks: parseFloat(row.marks) || 0, marking_guide: row.marking_guide || '', status: 'valid', errors: [] };
                if (!q.stem) { q.status = 'error'; q.errors.push('Missing question text'); }
                if (!q.marks) q.marks = parseFloat(marksInput.value) || 1;
                results.push(q);
            } else {
                const options = [];
                if (row.option_a) options.push({ text: row.option_a, label: 'A' });
                if (row.option_b) options.push({ text: row.option_b, label: 'B' });
                if (row.option_c) options.push({ text: row.option_c, label: 'C' });
                if (row.option_d) options.push({ text: row.option_d, label: 'D' });
                if (row.option_e) options.push({ text: row.option_e, label: 'E' });

                let correct = -1;
                if (row.correct_option) {
                    const letter = row.correct_option.toUpperCase().charAt(0);
                    correct = options.findIndex(function(o) { return o.label === letter; });
                    if (correct < 0) correct = letter.charCodeAt(0) - 65;
                }

                const q = {
                    stem: row.question || '',
                    options: options,
                    correct: correct,
                    marks: parseFloat(row.marks) || 0,
                    status: 'valid',
                    errors: []
                };
                if (!q.stem) { q.status = 'error'; q.errors.push('Missing question text'); }
                if (q.options.length < 2) { q.status = 'error'; q.errors.push('Need at least 2 options'); }
                if (q.correct < 0) { q.status = 'warning'; q.errors.push('No correct answer'); }
                if (!q.marks) q.marks = parseFloat(marksInput.value) || 1;
                results.push(q);
            }
        }
        return results;
    }

    function parseCSVLine(line) {
        const cols = [];
        let cur = '';
        let inQuotes = false;
        for (let i = 0; i < line.length; i++) {
            const c = line[i];
            if (c === '"') { inQuotes = !inQuotes; continue; }
            if (c === ',' && !inQuotes) { cols.push(cur); cur = ''; continue; }
            cur += c;
        }
        cols.push(cur);
        return cols;
    }

    // ---- Staging Table (shared by Paste and CSV) ----

    function renderStagingTable(rows) {
        const container = el('qs-staging');
        if (!rows || rows.length === 0) {
            container.innerHTML = '<p class="educbt-muted">No questions parsed.</p>';
            return;
        }

        const valid = rows.filter(r => r.status === 'valid').length;
        const warnings = rows.filter(r => r.status === 'warning').length;
        const errors = rows.filter(r => r.status === 'error').length;

        let html = '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">';
        html += '<span style="font-size:.85rem">' + rows.length + ' rows read · ' + valid + ' valid · ' + warnings + ' warnings · ' + errors + ' errors</span>';
        html += '<div style="display:flex;gap:6px"><button type="button" id="qs-commit-staging" class="educbt-btn educbt-btn--primary" style="font-size:.85rem">Add Valid Questions to Draft</button>';
        html += '<button type="button" id="qs-cancel-staging" class="educbt-btn" style="font-size:.85rem">Cancel</button></div>';
        html += '</div>';

        html += '<table style="width:100%;border-collapse:collapse;font-size:.85rem">';
        html += '<thead><tr style="border-bottom:2px solid var(--edu-line)"><th style="text-align:left;padding:4px">✓</th><th style="text-align:left;padding:4px">Status</th><th style="text-align:left;padding:4px">Question</th>';
        if (currentExamType === 'objective') {
            html += '<th style="text-align:left;padding:4px">Options</th><th style="text-align:left;padding:4px">Answer</th>';
        }
        html += '<th style="text-align:left;padding:4px">Marks</th></tr></thead><tbody>';

        rows.forEach(function(r, i) {
            const icon = r.status === 'valid' ? '✅' : (r.status === 'warning' ? '⚠' : '❌');
            const checked = r.status !== 'error' ? 'checked' : '';
            html += '<tr style="border-bottom:1px solid var(--edu-line)">';
            html += '<td style="padding:4px"><input type="checkbox" class="qs-staging-check" data-idx="' + i + '" ' + checked + ' ' + (r.status === 'error' ? 'disabled' : '') + '></td>';
            html += '<td style="padding:4px">' + icon + '</td>';
            html += '<td style="padding:4px;max-width:300px;overflow:hidden;text-overflow:ellipsis">' + esc(r.stem || '(empty)') + '</td>';
            if (currentExamType === 'objective') {
                html += '<td style="padding:4px">' + (r.options ? r.options.length : 0) + '</td>';
                html += '<td style="padding:4px">' + (r.correct >= 0 ? String.fromCharCode(65 + r.correct) : '?') + '</td>';
            }
            html += '<td style="padding:4px">' + r.marks + '</td>';
            html += '</tr>';
            if (r.errors && r.errors.length) {
                html += '<tr style="background:#fef2f2"><td colspan="' + (currentExamType === 'objective' ? 7 : 5) + '" style="padding:2px 4px 6px 24px;font-size:.8rem;color:red">' + esc(r.errors.join('; ')) + '</td></tr>';
            }
        });
        html += '</tbody></table>';

        container.innerHTML = html;

        el('qs-commit-staging').addEventListener('click', function() {
            const checked = document.querySelectorAll('.qs-staging-check:checked');
            const toCommit = [];
            checked.forEach(function(cb) {
                const idx = parseInt(cb.dataset.idx);
                const r = rows[idx];
                if (r && r.status !== 'error') {
                    const q = { stem: r.stem, marks: r.marks, source_method: currentMethod === 'csv' ? 'import' : 'paste' };
                    if (currentExamType === 'objective' && r.options) {
                        q.options = r.options.map(function(o, i) { return { text: o.text, is_correct: i === r.correct }; });
                    }
                    if (currentExamType === 'theory' && r.marking_guide) {
                        q.marking_guide = r.marking_guide;
                    }
                    toCommit.push(q);
                }
            });

            if (toCommit.length === 0) { alert('No valid rows selected.'); return; }

            ensureSet(function(set) {
                let done = 0;
                let failed = 0;
                showSaving();

                function commitNext() {
                    if (done >= toCommit.length) {
                        if (failed === 0) showSaved(done + ' questions added');
                        else showSaveError(failed + ' failed');
                        refreshQuestions();
                        el('qs-staging').innerHTML = '';
                        return;
                    }
                    apiCall('POST', 'question-sets/' + set.id + '/questions', toCommit[done])
                        .then(function(r) {
                            if (r.success) done++;
                            else failed++;
                            commitNext();
                        })
                        .catch(function() { failed++; commitNext(); });
                }
                commitNext();
            });
        });

        el('qs-cancel-staging').addEventListener('click', function() {
            container.innerHTML = '';
        });
    }

    // ---- Region C: Live Preview ----

    function renderPreview() {
        const emptyState = el('qs-empty-state');
        const list = el('qs-question-list');

        if (!currentSet || currentQuestions.length === 0) {
            emptyState.style.display = 'block';
            list.style.display = 'none';
            return;
        }

        emptyState.style.display = 'none';
        list.style.display = 'block';

        const editable = isEditable();
        let html = '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">';
        html += '<h3 style="margin:0;font-size:1rem">Questions in this set (' + currentQuestions.length + ')</h3>';
        html += '<input type="text" id="qs-search" class="educbt-input" placeholder="Search…" style="font-size:.85rem;width:200px"></div>';

        currentQuestions.forEach(function(q, i) {
            const isObj = q.question_type === 'single_choice' || q.question_type === 'objective';
            const src = q.source_method || 'manual';
            const srcIcon = src === 'paste' ? '📋' : (src === 'import' ? '📥' : '✏');
            const reviewerNote = q.reviewer_comment || '';

            html += '<div class="qs-card" data-qid="' + q.id + '" style="border:1px solid var(--edu-line);border-radius:8px;padding:12px;margin-bottom:8px' + (reviewerNote ? ';border-color:#f59e0b' : '') + '">';
            html += '<div style="display:flex;justify-content:space-between;align-items:flex-start">';
            html += '<div style="flex:1">';
            html += '<div style="display:flex;align-items:center;gap:6px;margin-bottom:4px">';
            html += '<span style="font-weight:700;color:var(--edu-muted)">' + (i + 1) + '.</span>';
            html += '<span class="educbt-pill educbt-pill--draft" style="font-size:.7rem">' + srcIcon + ' ' + src + '</span>';
            html += '<span class="educbt-pill" style="font-size:.7rem">' + q.marks + ' marks</span>';
            html += '</div>';
            html += '<p style="margin:0 0 6px">' + esc(q.question_text || q.stem || '') + '</p>';

            if (isObj && q.options && q.options.length) {
                html += '<div style="display:flex;flex-direction:column;gap:3px;margin-left:12px">';
                q.options.forEach(function(opt) {
                    const isCorrect = parseInt(opt.is_correct) === 1;
                    html += '<div style="font-size:.85rem;' + (isCorrect ? 'color:#16a34a;font-weight:600' : '') + '">';
                    html += (opt.option_key || '?') + '. ' + esc(opt.option_text || '');
                    if (isCorrect) html += ' ✓';
                    html += '</div>';
                });
                html += '</div>';
            }

            if (!isObj && q.sub_items && q.sub_items.length) {
                html += '<div style="margin-left:12px;margin-top:4px">';
                q.sub_items.forEach(function(sub) {
                    html += '<div style="font-size:.85rem;margin-bottom:2px">(' + (sub.label || '?') + ') ' + esc(sub.text || '') + ' <span class="educbt-muted">' + sub.marks + 'm</span></div>';
                });
                html += '</div>';
            }

            if (reviewerNote) {
                html += '<div style="margin-top:6px;padding:6px 8px;background:#fef3c7;border-radius:6px;font-size:.8rem"><strong>Reviewer:</strong> ' + esc(reviewerNote) + '</div>';
            }

            html += '</div>';

            if (editable) {
                html += '<div style="display:flex;flex-direction:column;gap:3px">';
                html += '<button type="button" class="educbt-btn qs-edit-btn" data-qid="' + q.id + '" style="font-size:.8rem;padding:2px 8px">Edit</button>';
                html += '<button type="button" class="educbt-btn qs-dup-btn" data-qid="' + q.id + '" style="font-size:.8rem;padding:2px 8px">Copy</button>';
                html += '<button type="button" class="educbt-btn qs-del-btn" data-qid="' + q.id + '" style="font-size:.8rem;padding:2px 8px;color:red">Del</button>';
                html += '</div>';
            }
            html += '</div></div>';
        });

        list.innerHTML = html;

        // Wire up actions
        if (editable) {
            list.querySelectorAll('.qs-del-btn').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    if (!confirm('Delete this question?')) return;
                    const qid = parseInt(this.dataset.qid);
                    apiCall('DELETE', 'question-sets/' + currentSet.id + '/questions/' + qid)
                        .then(function(r) { if (r.success) refreshQuestions(); });
                });
            });
            list.querySelectorAll('.qs-dup-btn').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    const qid = parseInt(this.dataset.qid);
                    apiCall('POST', 'question-sets/' + currentSet.id + '/questions/' + qid + '/duplicate')
                        .then(function(r) { if (r.success) refreshQuestions(); });
                });
            });
            list.querySelectorAll('.qs-edit-btn').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    const qid = parseInt(this.dataset.qid);
                    const q = currentQuestions.find(function(x) { return parseInt(x.id) === qid; });
                    if (q) openInlineEdit(q);
                });
            });
        }

        // Search
        const search = el('qs-search');
        if (search) {
            search.addEventListener('input', function() {
                const term = this.value.toLowerCase();
                list.querySelectorAll('.qs-card').forEach(function(card) {
                    const text = card.textContent.toLowerCase();
                    card.style.display = text.includes(term) ? '' : 'none';
                });
            });
        }
    }

    function openInlineEdit(q) {
        const card = document.querySelector('.qs-card[data-qid="' + q.id + '"]');
        if (!card) return;
        const isObj = q.question_type === 'single_choice' || q.question_type === 'objective';

        let html = '<div style="background:var(--edu-muted-bg,#f9fafb);border-radius:8px;padding:12px">';
        html += '<textarea class="educbt-input qs-edit-stem" rows="2" style="width:100%;margin-bottom:6px">' + esc(q.question_text || '') + '</textarea>';

        if (isObj) {
            q.options.forEach(function(opt, i) {
                html += '<div style="display:flex;gap:6px;margin-bottom:3px;align-items:center">';
                html += '<input type="radio" name="qe-edit-correct-' + q.id + '" value="' + i + '" ' + (parseInt(opt.is_correct) === 1 ? 'checked' : '') + ' style="margin:0">';
                html += '<span style="font-weight:600;width:20px">' + (opt.option_key || String.fromCharCode(65 + i)) + '.</span>';
                html += '<input type="text" class="educbt-input qs-edit-opt" data-idx="' + i + '" value="' + esc(opt.option_text || '') + '" style="flex:1;font-size:.85rem">';
                html += '</div>';
            });
        }

        html += '<div style="display:flex;gap:6px;align-items:center;margin-top:6px">';
        html += '<label class="educbt-muted" style="font-size:.8rem">Marks</label>';
        html += '<input type="number" class="educbt-input qs-edit-marks" value="' + q.marks + '" min="0.5" step="0.5" style="width:65px">';
        html += '<button type="button" class="educbt-btn educbt-btn--primary qs-save-edit" data-qid="' + q.id + '" style="font-size:.85rem;margin-left:auto">Save</button>';
        html += '<button type="button" class="educbt-btn qs-cancel-edit" style="font-size:.85rem">Cancel</button>';
        html += '</div></div>';

        card.innerHTML = html;

        card.querySelector('.qs-save-edit').addEventListener('click', function() {
            const data = { stem: card.querySelector('.qs-edit-stem').value.trim(), marks: parseFloat(card.querySelector('.qs-edit-marks').value) || 0 };
            if (isObj) {
                const opts = [];
                card.querySelectorAll('.qs-edit-opt').forEach(function(inp) {
                    if (inp.value.trim()) opts.push({ text: inp.value.trim(), is_correct: card.querySelector('input[name="qe-edit-correct-' + q.id + '"]:checked') && parseInt(card.querySelector('input[name="qe-edit-correct-' + q.id + '"]:checked').value) === parseInt(inp.dataset.idx) });
                });
                data.options = opts;
            }
            apiCall('PUT', 'question-sets/' + currentSet.id + '/questions/' + q.id, data)
                .then(function(r) { if (r.success) refreshQuestions(); });
        });

        card.querySelector('.qs-cancel-edit').addEventListener('click', function() {
            refreshQuestions();
        });
    }

    // ---- Region D: Progress + Submit ----

    function renderProgress() {
        const bar = el('qs-progress');
        const btn = el('qs-submit-btn');

        if (!currentSet) {
            bar.style.display = 'none';
            btn.style.display = 'none';
            return;
        }

        bar.style.display = 'block';
        const count = currentQuestions.length;
        const marks = currentQuestions.reduce(function(s, q) { return s + (parseFloat(q.marks) || 0); }, 0);
        const min = currentExamType === 'objective' ? API.minObjective : API.minTheory;

        el('qs-count-label').textContent = count + ' / ' + min + ' ' + currentExamType + ' questions';
        el('qs-marks-label').textContent = marks + ' marks total';

        const pct = min > 0 ? Math.min(100, (count / min) * 100) : 100;
        el('qs-progress-bar').style.width = pct + '%';

        // Sibling set indicator
        if (currentSet._sibling) {
            el('qs-sibling-label').textContent = capitalize(currentSet._sibling.exam_type) + ': ' + currentSet._sibling.status;
        }

        // Submit button
        if (isEditable()) {
            btn.style.display = 'inline-flex';
            if (count < min) {
                btn.disabled = true;
                btn.title = 'Add ' + (min - count) + ' more question' + (min - count > 1 ? 's' : '') + ' to submit.';
            } else {
                btn.disabled = false;
                btn.title = '';
            }
            btn.textContent = currentSet.status === 'returned' ? 'Resubmit for Review' : 'Submit for Review';
        } else {
            btn.style.display = 'none';
        }
    }

    function capitalize(s) { return s ? s.charAt(0).toUpperCase() + s.slice(1) : ''; }

    el('qs-submit-btn').addEventListener('click', function() {
        if (!currentSet) return;
        const count = currentQuestions.length;
        const marks = currentQuestions.reduce(function(s, q) { return s + (parseFloat(q.marks) || 0); }, 0);

        const msg = 'Subject: ' + (subjectSel.options[subjectSel.selectedIndex] ? subjectSel.options[subjectSel.selectedIndex].text : '?') +
            '\nClass: ' + (classSel.options[classSel.selectedIndex] ? classSel.options[classSel.selectedIndex].text : '?') +
            '\nType: ' + capitalize(currentExamType) +
            '\nQuestions: ' + count +
            '\nTotal marks: ' + marks +
            '\n\nOnce submitted, you will not be able to edit these questions unless the Exam Officer returns them to you.';

        if (!confirm(msg)) return;

        apiCall('POST', 'question-sets/' + currentSet.id + '/submit')
            .then(function(r) {
                if (r.success) {
                    alert('Submitted for review.');
                    loadSet();
                } else {
                    alert('Could not submit: ' + (r.error || 'unknown error'));
                }
            });
    });

    // ---- Refresh questions after add/edit/delete ----

    function refreshQuestions() {
        if (!currentSet) return;
        apiCall('GET', 'question-sets?subject_id=' + subjectSel.value + '&class_id=' + classSel.value + '&exam_type=' + currentExamType)
            .then(function(r) {
                if (r.success) {
                    currentSet = r.set;
                    currentQuestions = r.questions || [];
                    renderPreview();
                    renderProgress();
                    renderStatusBanner();
                    renderInput();
                }
            });
    }

    // Warn before leaving with unsaved input
    window.addEventListener('beforeunload', function(e) {
        if (unsavedInput) {
            e.preventDefault();
            e.returnValue = '';
        }
    });

})();
</script>

<?php
};

require EDUCBT_PRO_PATH . 'templates/portal/shell.php';
