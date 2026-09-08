<?php
/**
 * Principal's landing dashboard — redesigned with dark forest/lime design system.
 *
 * Visual styling matches the dashboard mockup (Sora/Inter typography, forest/lime
 * color scheme, card containers, stat tiles, and pill badges).
 * Retains all real data: student, staff & class counts, results pipeline, and audit log.
 *
 * @var array<string,mixed> $educbt
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

global $wpdb;

$school_id = (int) $educbt['school_id'];
$year      = new \EduCBTPro\Services\AcademicYearService();
$session   = $year->current_session( $school_id );
$term      = $year->current_term( $school_id );

$counts = [
    'students' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}educbt_students WHERE school_id = %d AND status = 'active'", $school_id ) ),
    'staff'    => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . \EduCBTPro\Core\Schema::table( 'staff' ) . " WHERE school_id = %d AND status = 'active'", $school_id ) ),
    'classes'  => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . \EduCBTPro\Core\Schema::table( 'classes' ) . " WHERE school_id = %d AND status = 'active'", $school_id ) ),
];

$pipeline = $term
    ? ( new \EduCBTPro\Services\ResultWorkflowService() )->pipeline_overview( $school_id, (int) $term['id'] )
    : [];

// Sessions and terms for the "Start new session/term" dropdowns.
$all_sessions = (array) $wpdb->get_results(
    $wpdb->prepare(
        'SELECT id, title FROM ' . \EduCBTPro\Core\Schema::table( 'academic_sessions' ) . ' WHERE school_id = %d ORDER BY title DESC',
        $school_id
    ),
    ARRAY_A
);
$all_terms = (array) $wpdb->get_results(
    $wpdb->prepare(
        'SELECT id, title, term_order FROM ' . \EduCBTPro\Core\Schema::table( 'terms' ) . ' WHERE session_id = %d ORDER BY term_order ASC',
        absint( $session['id'] ?? 0 )
    ),
    ARRAY_A
);

$activity_page = max( 1, absint( $_GET['activity_page'] ?? 1 ) );
$activity_per_page = 5;
$activity_offset = ( $activity_page - 1 ) * $activity_per_page;

$activity_total = (int) $wpdb->get_var(
    $wpdb->prepare(
        'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'educbt_audit_logs WHERE school_id = %d',
        $school_id
    )
);
$activity_pages = max( 1, (int) ceil( $activity_total / $activity_per_page ) );

$activity = (array) $wpdb->get_results(
    $wpdb->prepare(
        'SELECT * FROM ' . $wpdb->prefix . 'educbt_audit_logs WHERE school_id = %d ORDER BY id DESC LIMIT %d OFFSET %d',
        $school_id,
        $activity_per_page,
        $activity_offset
    ),
    ARRAY_A
);

$flash = \EduCBTPro\Frontend\PortalActions::flash();

// A vice principal sees the same school-wide picture as the principal — that is
// the point of the role — but calling their screen the Principal's Dashboard
// tells them they are somebody else. Only the heading differs; the deputy is
// deputising, not looking at a lesser version of the school.
$educbt_dash_role = (string) ( ( new \EduCBTPro\Core\Scope() )->actor()['role'] ?? '' );

$educbt_dash_title = 'Principal\'s Dashboard';

if ( $educbt_dash_role === \EduCBTPro\Core\Capabilities::ROLE_VICE_PRINCIPAL ) {
    $educbt_dash_title = 'Vice Principal\'s Dashboard';
} elseif ( $educbt_dash_role === \EduCBTPro\Core\Capabilities::ROLE_EXAM_OFFICER ) {
    $educbt_dash_title = 'School Overview';
}

$educbt_title = $educbt_dash_title;

// Pre-compute a map of session_id → [{id,title}, …] for ALL sessions.
// This runs OUTSIDE the closure so $wpdb is in scope. The result is passed
// into the closure via use() and output as JSON in the <script> block.
$_edu_all_terms_map = [];
$_edu_session_ids   = array_merge( [ 0 ], array_map( static function( $s ) { return (int) $s['id']; }, $all_sessions ) );
foreach ( $_edu_session_ids as $_sid ) {
    $_t_rows = (array) $wpdb->get_results(
        $wpdb->prepare(
            'SELECT id, title FROM ' . \EduCBTPro\Core\Schema::table( 'terms' ) . ' WHERE session_id = %d ORDER BY term_order ASC',
            $_sid
        ),
        ARRAY_A
    );
    $_edu_all_terms_map[ (string) $_sid ] = array_map( static function( $t ) {
        return [ 'id' => (int) $t['id'], 'title' => (string) $t['title'] ];
    }, $_t_rows );
}

$educbt_body = static function () use ( $counts, $session, $term, $pipeline, $activity, $flash, $activity_page, $activity_pages, $all_sessions, $all_terms, $educbt_dash_title, $_edu_all_terms_map ): void {
    require EDUCBT_PRO_PATH . 'templates/portal/partials/flash.php';
    ?>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Sora:wght@500;600;700;800&family=Inter:wght@400;500;600;700&display=swap');

        :root {
            --forest-950: #0B2118;
            --forest-900: #102C20;
            --lime-400: #A6E86B;
            --lime-300: #C9F0A6;
            --lime-100: #E9FBD8;
            --canvas: #F5F6F1;
            --surface: #FFFFFF;
            --ink-900: #152019;
            --ink-700: #37453D;
            --ink-500: #69766C;
            --ink-400: #8B968D;
            --line: #E6E9E1;
            --line-soft: #EFF2EA;
            --success-bg: #E3F6DA;
            --success-text: #2C6B36;
            --live-bg: #FFF4D9;
            --live-text: #8A5B00;
            --danger-bg: #FBE8E3;
            --danger-text: #B14328;
            --r-lg: 18px;
            --shadow-card: 0 1px 2px rgba(16,32,23,.04), 0 8px 24px rgba(16,32,23,.06);
            --shadow-hover: 0 14px 30px rgba(16,32,23,.12);
            --font-display: 'Sora', 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            --font-body: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
        }

        .edu-dash-wrap {
            font-family: var(--font-body);
            color: var(--ink-900);
            display: flex;
            flex-direction: column;
            gap: 22px;
        }

        .edu-dash-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 4px;
        }

        .edu-dash-header__title {
            font-family: var(--font-display);
            font-size: 22px;
            font-weight: 700;
            color: var(--ink-900);
            margin: 0;
        }

        .edu-dash-header__sub {
            font-size: 13px;
            color: var(--ink-500);
            font-weight: 500;
            margin-top: 2px;
        }

        /* Stat Grid */
        .edu-stat-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
        }

        .edu-stat-card {
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: var(--r-lg);
            padding: 18px 20px;
            display: flex;
            flex-direction: column;
            gap: 12px;
            box-shadow: var(--shadow-card);
            transition: transform .15s ease, box-shadow .15s ease;
        }

        .edu-stat-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-hover);
        }

        .edu-stat-icon {
            width: 38px;
            height: 38px;
            border-radius: 10px;
            background: var(--lime-100);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #2C6B36;
            flex-shrink: 0;
        }

        .edu-stat-icon svg {
            width: 19px;
            height: 19px;
        }

        .edu-stat-value {
            font-family: var(--font-display);
            font-size: 24px;
            font-weight: 700;
            color: var(--ink-900);
            line-height: 1.1;
        }

        .edu-stat-label {
            font-size: 11.5px;
            font-weight: 600;
            letter-spacing: .03em;
            color: var(--ink-500);
            text-transform: uppercase;
        }

        /* Layout Grid */
        .edu-grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }

        @media (max-width: 920px) {
            .edu-grid-2 {
                grid-template-columns: 1fr;
            }
        }

        /* Card Container */
        .edu-card {
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: var(--r-lg);
            box-shadow: var(--shadow-card);
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }

        .edu-card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 18px 22px;
            border-bottom: 1px solid var(--line);
            background: var(--surface);
        }

        .edu-card-title {
            font-family: var(--font-display);
            font-size: 16px;
            font-weight: 700;
            color: var(--ink-900);
            margin: 0;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .edu-card-body {
            padding: 20px 22px;
            flex: 1;
        }

        .edu-card-body--no-pad {
            padding: 0;
        }

        /* Table */
        .edu-tbl {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
        }

        .edu-tbl thead th {
            font-size: 11px;
            letter-spacing: .05em;
            text-transform: uppercase;
            color: var(--ink-400);
            font-weight: 700;
            padding: 12px 18px;
            border-bottom: 1px solid var(--line);
            background: #FAFBF8;
        }

        .edu-tbl tbody td {
            padding: 13px 18px;
            font-size: 13.5px;
            color: var(--ink-900);
            border-bottom: 1px solid var(--line-soft);
        }

        .edu-tbl tbody tr:last-child td {
            border-bottom: none;
        }

        .edu-tbl tbody tr {
            transition: background .12s;
        }

        .edu-tbl tbody tr:hover {
            background: #FAFBF8;
        }

        /* Pills */
        .edu-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 11.5px;
            font-weight: 600;
        }

        .edu-pill--published, .edu-pill--approved, .edu-pill--completed {
            background: var(--success-bg);
            color: var(--success-text);
        }

        .edu-pill--pending, .edu-pill--draft, .edu-pill--in_progress {
            background: var(--live-bg);
            color: var(--live-text);
        }

        .edu-pill--rejected {
            background: var(--danger-bg);
            color: var(--danger-text);
        }

        .edu-pill--class {
            background: var(--lime-100);
            color: #0B2118;
            border: 1px solid var(--lime-300);
        }

        /* Timeline / Activity */
        .edu-timeline {
            display: flex;
            flex-direction: column;
            padding: 8px 22px;
        }

        .edu-t-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 13px 0;
            border-bottom: 1px solid var(--line-soft);
        }

        .edu-t-item:last-child {
            border-bottom: none;
        }

        .edu-t-left {
            display: flex;
            align-items: center;
            gap: 11px;
            min-width: 0;
        }

        .edu-t-icon {
            width: 32px;
            height: 32px;
            border-radius: 9px;
            background: var(--lime-100);
            color: #2C6B36;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .edu-t-icon svg {
            width: 16px;
            height: 16px;
        }

        .edu-t-text {
            font-size: 13.5px;
            font-weight: 500;
            color: var(--ink-900);
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .edu-t-time {
            font-size: 12px;
            color: var(--ink-400);
            white-space: nowrap;
            flex-shrink: 0;
        }

        /* Buttons */
        .edu-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 13px;
            border-radius: 9px;
            font-family: var(--font-body);
            font-size: 12.5px;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            transition: all .15s ease;
            border: none;
        }

        .edu-btn--lime {
            background: var(--lime-400);
            color: #0B2118;
        }

        .edu-btn--lime:hover {
            background: #96DC5B;
            color: #0B2118;
        }

        .edu-btn--ghost {
            background: transparent;
            color: var(--ink-700);
            border: 1px solid var(--line);
        }

        .edu-btn--ghost:hover {
            background: var(--line-soft);
            color: var(--ink-900);
        }

        .edu-empty {
            padding: 36px 20px;
            text-align: center;
            color: var(--ink-500);
            font-size: 13.5px;
        }
    </style>

    <div class="edu-dash-wrap">

        <!-- Top Greeting Header -->
        <div class="edu-dash-header">
            <div>
                <h1 class="edu-dash-header__title"><?php echo esc_html( $educbt_dash_title ); ?></h1>
                <div class="edu-dash-header__sub">
                    <?php echo esc_html( ( $session['title'] ?? 'Current Session' ) . ' · ' . ( $term['title'] ?? 'No active term' ) ); ?>
                </div>
            </div>
            <div style="display:flex;gap:8px">
                <button type="button" class="edu-btn edu-btn--ghost" id="edu-new-term-btn"
                        style="background:var(--lime-100);border-color:var(--lime-400);color:var(--forest-900)">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/><path d="M12 14v4M10 16h4"/></svg>
                    Start New Session/Term
                </button>
                <a href="<?php echo esc_url( home_url( '/portal/school/settings/' ) ); ?>" class="edu-btn edu-btn--ghost">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.9-.3 1.7 1.7 0 0 0-1 1.6V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1-1.6 1.7 1.7 0 0 0-1.9.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.9 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.6-1 1.7 1.7 0 0 0-.3-1.9l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.9.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.9-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.9V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1Z"/></svg>
                    School Settings
                </a>
            </div>

            <!-- Start New Session/Term Modal -->
            <div id="edu-new-term-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:9999;align-items:center;justify-content:center">
                <div style="background:#fff;border-radius:14px;padding:28px;max-width:440px;width:90%;box-shadow:0 20px 60px rgba(0,0,0,.25)">
                    <h3 style="margin:0 0 6px;font-family:Sora,sans-serif;font-size:18px;color:var(--forest-950)">Switch Session / Term</h3>
                    <p style="font-size:13px;color:var(--ink-500);margin:0 0 18px">This changes the dashboard for all staff. Past question banks remain accessible. Results and exams from the previous term are preserved.</p>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:flex;flex-direction:column;gap:14px">
                        <input type="hidden" name="action" value="educbt_start_new_term">
                        <?php wp_nonce_field( 'educbt_start_new_term' ); ?>

                        <div>
                            <label style="font-size:13px;font-weight:600;color:var(--ink-700);display:block;margin-bottom:6px">Switch Session (optional)</label>
                            <select name="session_id" id="new-session-select" onchange="eduLoadTerms(this.value)" style="width:100%;padding:8px 10px;border:1px solid var(--line);border-radius:8px;font-size:14px">
                                <option value="0">Keep current session</option>
                                <?php foreach ( $all_sessions as $sess ) : ?>
                                    <option value="<?php echo esc_attr( (string) $sess['id'] ); ?>"><?php echo esc_html( (string) $sess['title'] ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div id="new-term-select-wrap">
                            <label style="font-size:13px;font-weight:600;color:var(--ink-700);display:block;margin-bottom:6px">Switch Term</label>
                            <select name="term_id" id="new-term-select" style="width:100%;padding:8px 10px;border:1px solid var(--line);border-radius:8px;font-size:14px">
                                <option value="0">Select term</option>
                            </select>
                        </div>

                        <div style="display:flex;gap:10px;margin-top:6px">
                            <button type="submit" class="edu-btn" style="background:var(--forest-900);color:var(--lime-300);border:none;padding:10px 20px;border-radius:8px;font-weight:600;cursor:pointer">Confirm</button>
                            <button type="button" onclick="document.getElementById('edu-new-term-modal').style.display='none'" class="edu-btn" style="border:1px solid var(--line);background:#fff;padding:10px 20px;border-radius:8px;cursor:pointer">Cancel</button>
                        </div>
                    </form>
                </div>
            </div>
            <script>
            (function(){
                var btn = document.getElementById('edu-new-term-btn');
                var modal = document.getElementById('edu-new-term-modal');
                if (!btn || !modal) return;
                btn.addEventListener('click', function(){ modal.style.display = 'flex'; });
                modal.addEventListener('click', function(e){ if (e.target === modal) modal.style.display = 'none'; });
            })();
            // Pre-load terms for ALL sessions so switching doesn't need AJAX.
            var eduAllTerms = <?php echo wp_json_encode( $_edu_all_terms_map ); ?>;
            var eduCurrentSessionId = '<?php echo esc_js( (string) ( $session["id"] ?? 0 ) ); ?>';
            // Initialise with the current session's terms.
            (function(){ eduLoadTerms('0'); })();
            function eduLoadTerms(sessionId) {
                var sel = document.getElementById('new-term-select');
                if (!sel) return;
                var key = (!sessionId || sessionId === '0') ? eduCurrentSessionId : sessionId;
                var terms = eduAllTerms[key] || [];
                sel.innerHTML = '<option value="0">Select term</option>';
                terms.forEach(function(t){ sel.innerHTML += '<option value="'+t.id+'">'+t.title+'</option>'; });
            }
            </script>
        </div>

        <!-- Stat Tiles Grid -->
        <div class="edu-stat-grid">
            <div class="edu-stat-card">
                <div class="edu-stat-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 4 2 9l10 5 10-5-10-5Z"/><path d="M6 11.4V17c0 1.4 2.7 3 6 3s6-1.6 6-3v-5.6"/></svg>
                </div>
                <div>
                    <div class="edu-stat-value"><?php echo esc_html( (string) $counts['students'] ); ?></div>
                    <div class="edu-stat-label">Active Students</div>
                </div>
            </div>

            <div class="edu-stat-card">
                <div class="edu-stat-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="8" r="3.2"/><path d="M3 20c0-3.6 2.7-6.4 6-6.4s6 2.8 6 6.4"/></svg>
                </div>
                <div>
                    <div class="edu-stat-value"><?php echo esc_html( (string) $counts['staff'] ); ?></div>
                    <div class="edu-stat-label">Active Staff</div>
                </div>
            </div>

            <div class="edu-stat-card">
                <div class="edu-stat-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3 3 8l9 5 9-5-9-5Z"/><path d="M3 12l9 5 9-5"/></svg>
                </div>
                <div>
                    <div class="edu-stat-value"><?php echo esc_html( (string) $counts['classes'] ); ?></div>
                    <div class="edu-stat-label">Active Classes</div>
                </div>
            </div>

            <div class="edu-stat-card">
                <div class="edu-stat-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                </div>
                <div>
                    <div class="edu-stat-value" style="font-size:18px"><?php echo esc_html( $session['title'] ?? '—' ); ?></div>
                    <div class="edu-stat-label"><?php echo esc_html( $term['title'] ?? 'No active term' ); ?></div>
                </div>
            </div>
        </div>

        <!-- 2-Column Main Section -->
        <div class="edu-grid-2">

            <!-- Card 1: Results Pipeline -->
            <div class="edu-card">
                <div class="edu-card-header">
                    <h2 class="edu-card-title">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 20V10M12 20V4M20 20v-7"/><path d="M2 20h20"/></svg>
                        Results Pipeline
                    </h2>
                    <span class="edu-pill edu-pill--class"><?php echo esc_html( $term['title'] ?? 'Term' ); ?></span>
                </div>
                <div class="edu-card-body edu-card-body--no-pad">
                    <?php if ( empty( $pipeline ) ) : ?>
                        <div class="edu-empty">Nothing compiled for this term yet.</div>
                    <?php else : ?>
                        <table class="edu-tbl">
                            <thead>
                                <tr>
                                    <th>Class</th>
                                    <th>Stage</th>
                                    <th style="text-align:right">Students</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ( $pipeline as $row ) : ?>
                                <tr>
                                    <td>
                                        <strong style="font-weight:600; color:var(--ink-900)"><?php echo esc_html( (string) $row['class_name'] ); ?></strong>
                                    </td>
                                    <td>
                                        <span class="edu-pill edu-pill--<?php echo esc_attr( (string) $row['status'] ); ?>">
                                            <?php echo esc_html( ucfirst( (string) $row['status'] ) ); ?>
                                        </span>
                                    </td>
                                    <td style="text-align:right; font-weight:600">
                                        <?php echo esc_html( (string) $row['students'] ); ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Card 2: Recent Activity Log -->
            <div class="edu-card">
                <div class="edu-card-header">
                    <h2 class="edu-card-title">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12h4l2-7 4 14 2-7h6"/></svg>
                        Recent Activity
                    </h2>
                </div>
                <div id="educbt-dash-activity" data-page="<?php echo esc_attr( (string) $activity_page ); ?>" data-total-pages="<?php echo esc_attr( (string) $activity_pages ); ?>" data-base-url="<?php echo esc_url( add_query_arg( [ 'activity_page' => '' ] ) ); ?>">
                    <div class="edu-card-body edu-card-body--no-pad" data-activity-body>
                        <?php if ( empty( $activity ) ) : ?>
                            <div class="edu-empty">No activity recorded yet.</div>
                        <?php else : ?>
                            <div class="edu-timeline">
                            <?php foreach ( $activity as $entry ) : ?>
                                <div class="edu-t-item">
                                    <div class="edu-t-left">
                                        <div class="edu-t-icon">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                                        </div>
                                        <span class="edu-t-text"><?php echo esc_html( (string) ( $entry['action'] ?? '' ) ); ?></span>
                                    </div>
                                    <span class="edu-t-time"><?php echo esc_html( (string) ( $entry['created_at'] ?? '' ) ); ?></span>
                                </div>
                            <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div style="display:flex;justify-content:space-between;align-items:center;padding:8px 16px;border-top:1px solid #e2e8f0;" data-activity-pager <?php echo $activity_pages <= 1 ? 'hidden' : ''; ?>>
                        <span style="font-size:12px;color:#64748b;" data-activity-page-info>Page <?php echo esc_html( (string) $activity_page ); ?> of <?php echo esc_html( (string) $activity_pages ); ?></span>
                        <div style="display:flex;gap:4px;">
                            <button type="button" class="edu-pg-btn" data-activity-prev <?php echo $activity_page <= 1 ? 'disabled' : ''; ?>>← Prev</button>
                            <button type="button" class="edu-pg-btn" data-activity-next <?php echo $activity_page >= $activity_pages ? 'disabled' : ''; ?>>Next →</button>
                        </div>
                    </div>
                </div>
            </div>

        </div>
        <script>
        (function(){
            var wrap = document.getElementById('educbt-dash-activity');
            if (!wrap) return;
            var baseUrl = wrap.dataset.baseUrl;
            var totalPages = parseInt(wrap.dataset.totalPages, 10);
            var currentPage = parseInt(wrap.dataset.page, 10);
            var body = wrap.querySelector('[data-activity-body]');
            var pager = wrap.querySelector('[data-activity-pager]');
            var pageInfo = wrap.querySelector('[data-activity-page-info]');
            var prevBtn = wrap.querySelector('[data-activity-prev]');
            var nextBtn = wrap.querySelector('[data-activity-next]');
            var loading = false;

            function loadPage(pg) {
                if (loading || pg < 1 || pg > totalPages) return;
                loading = true;
                prevBtn.disabled = nextBtn.disabled = true;

                // Build the URL with the new page number, preserving all existing params.
                var url = baseUrl + pg;
                // Add a cache-buster so the browser never serves a stale cached page.
                if (url.indexOf('?') === -1) { url += '?_t=' + Date.now(); }
                else { url += '&_t=' + Date.now(); }
                    .then(function(r){ return r.text(); })
                    .then(function(html){
                        var doc = new DOMParser().parseFromString(html, 'text/html');
                        var newWrap = doc.getElementById('educbt-dash-activity');
                        if (!newWrap) return;
                        var newBody = newWrap.querySelector('[data-activity-body]');
                        var newPagerEl = newWrap.querySelector('[data-activity-pager]');
                        if (newBody) body.innerHTML = newBody.innerHTML;
                        if (newWrap) {
                            totalPages = parseInt(newWrap.dataset.totalPages, 10) || totalPages;
                            currentPage = parseInt(newWrap.dataset.page, 10) || pg;
                        }
                        if (newPagerEl) {
                            pager.hidden = newPagerEl.hasAttribute('hidden');
                        }
                        pageInfo.textContent = 'Page ' + pg + ' of ' + totalPages;
                        prevBtn.disabled = pg <= 1;
                        nextBtn.disabled = pg >= totalPages;
                        window.history.replaceState(null, '', url);
                    })
                    .catch(function(){})
                    .finally(function(){ loading = false; });
            }

            prevBtn.addEventListener('click', function(){ loadPage(currentPage - 1); });
            nextBtn.addEventListener('click', function(){ loadPage(currentPage + 1); });
        })();
        </script>

    </div>
    <?php
};

require EDUCBT_PRO_PATH . 'templates/portal/shell.php';
