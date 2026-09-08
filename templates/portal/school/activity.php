<?php
/**
 * Activity log — who did what, when.
 *
 * @var array<string,mixed> $educbt
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

global $wpdb;
$school_id = (int) $educbt['school_id'];

$table = $wpdb->prefix . 'educbt_audit_logs';
$page  = max( 1, (int) ( $_GET['pg'] ?? 1 ) );
$per   = 50;

// Filters
$filter_action = sanitize_text_field( (string) ( $_GET['action_type'] ?? '' ) );
$filter_user   = absint( $_GET['user_id'] ?? 0 );

// Build WHERE
$where  = 'school_id = %d';
$params = [ $school_id ];

if ( $filter_action !== '' ) {
    $where   .= ' AND action LIKE %s';
    $params[] = '%' . $wpdb->esc_like( $filter_action ) . '%';
}

if ( $filter_user > 0 ) {
    $where   .= ' AND user_id = %d';
    $params[] = $filter_user;
}

// Distinct action types for the filter dropdown
$action_types = (array) $wpdb->get_col(
    $wpdb->prepare(
        "SELECT DISTINCT action FROM {$table} WHERE school_id = %d ORDER BY action ASC",
        $school_id
    )
);

// Users who have logged actions
$log_users = (array) $wpdb->get_results(
    $wpdb->prepare(
        "SELECT DISTINCT u.ID, u.display_name FROM {$table} l
         INNER JOIN {$wpdb->users} u ON u.ID = l.user_id
         WHERE l.school_id = %d ORDER BY u.display_name ASC",
        $school_id
    ),
    ARRAY_A
);

// Count total for pagination
$total = (int) $wpdb->get_var(
    $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where}", $params )
);

$rows = (array) $wpdb->get_results(
    $wpdb->prepare(
        "SELECT * FROM {$table} WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d",
        array_merge( $params, [ $per, ( $page - 1 ) * $per ] )
    ),
    ARRAY_A
);

$total_pages = max( 1, (int) ceil( $total / $per ) );

$educbt_title = 'Activity';

$educbt_body = static function () use ( $rows, $page, $total_pages, $action_types, $log_users, $filter_action, $filter_user, $total ): void {
    ?>
    <section class="educbt-card no-print">
        <h2>Filter</h2>
        <form method="get" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
            <input type="hidden" name="p" value="educbt_portal">
            <input type="hidden" name="section" value="activity">
            <div>
                <label style="font-size:.8rem;font-weight:600;display:block;margin-bottom:4px">Action type</label>
                <select name="action_type" onchange="this.form.submit()">
                    <option value="">All actions</option>
                    <?php foreach ( $action_types as $a ) : ?>
                        <option value="<?php echo esc_attr( (string) $a ); ?>" <?php selected( $filter_action, (string) $a ); ?>>
                            <?php echo esc_html( str_replace( '_', ' ', (string) $a ) ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label style="font-size:.8rem;font-weight:600;display:block;margin-bottom:4px">User</label>
                <select name="user_id" onchange="this.form.submit()">
                    <option value="0">All users</option>
                    <?php foreach ( $log_users as $u ) : ?>
                        <option value="<?php echo esc_attr( (string) $u['ID'] ); ?>" <?php selected( $filter_user, (int) $u['ID'] ); ?>>
                            <?php echo esc_html( (string) $u['display_name'] ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <noscript><button class="educbt-btn" type="submit">Apply</button></noscript>
        </form>
        <p class="educbt-muted" style="margin-top:8px">
            <?php echo esc_html( sprintf( '%d log entries', $total ) ); ?>
        </p>
    </section>

    <section class="educbt-card">
        <?php if ( empty( $rows ) ) : ?>
            <p class="educbt-muted">No activity recorded<?php echo $filter_action || $filter_user ? ' for this filter' : ' yet'; ?>.</p>
        <?php else : ?>
            <table class="educbt-table">
                <thead><tr><th>When</th><th>Action</th><th>By</th><th>Details</th></tr></thead>
                <tbody>
                <?php foreach ( $rows as $row ) :
                    $user = ! empty( $row['user_id'] ) ? get_userdata( (int) $row['user_id'] ) : null;
                    $details = '';
                    if ( ! empty( $row['entity_type'] ) ) {
                        $details = ucfirst( (string) $row['entity_type'] );
                        if ( ! empty( $row['entity_id'] ) ) {
                            $details .= ' #' . (int) $row['entity_id'];
                        }
                    }
                    if ( ! empty( $row['summary'] ) ) {
                        $details .= ( $details ? ' — ' : '' ) . (string) $row['summary'];
                    }
                    ?>
                    <tr>
                        <td style="white-space:nowrap"><?php echo esc_html( mysql2date( 'j M, g:ia', (string) ( $row['created_at'] ?? '' ) ) ); ?></td>
                        <td><?php echo esc_html( str_replace( '_', ' ', (string) ( $row['action'] ?? '' ) ) ); ?></td>
                        <td><?php echo esc_html( $user ? $user->display_name : '—' ); ?></td>
                        <td class="educbt-muted"><?php echo esc_html( (string) $details ); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <div id="educbt-activity-pager" style="margin-top:14px;display:flex;gap:10px;align-items:center"
                 data-total-pages="<?php echo esc_attr( (string) $total_pages ); ?>"
                 data-base-url="<?php echo esc_url( add_query_arg( [ 'pg' => '' ] ) ); ?>">
                <button type="button" class="educbt-btn" id="educbt-activity-prev" <?php echo $page <= 1 ? 'disabled' : ''; ?>>Newer</button>
                <span class="educbt-muted" id="educbt-activity-page-info">Page <?php echo esc_html( (string) $page ); ?> of <?php echo esc_html( (string) $total_pages ); ?></span>
                <button type="button" class="educbt-btn" id="educbt-activity-next" <?php echo $page >= $total_pages ? 'disabled' : ''; ?>>Older</button>
            </div>
            <script>
            (function(){
                var pager = document.getElementById('educbt-activity-pager');
                if (!pager) return;
                var totalPages = parseInt(pager.dataset.totalPages, 10);
                var baseUrl = pager.dataset.baseUrl;
                var currentPage = <?php echo (int) $page; ?>;
                var tbody = document.querySelector('.educbt-table tbody');
                var pageInfo = document.getElementById('educbt-activity-page-info');
                var prevBtn = document.getElementById('educbt-activity-prev');
                var nextBtn = document.getElementById('educbt-activity-next');
                var loading = false;

                function loadPage(pg) {
                    if (loading || pg < 1 || pg > totalPages) return;
                    loading = true;
                    prevBtn.disabled = nextBtn.disabled = true;

                    var url = baseUrl + pg;
                    fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                        .then(function(r){ return r.text(); })
                        .then(function(html){
                            var doc = new DOMParser().parseFromString(html, 'text/html');
                            var newTbody = doc.querySelector('.educbt-table tbody');
                            var newPager = doc.getElementById('educbt-activity-pager');
                            if (newTbody && tbody) {
                                tbody.innerHTML = newTbody.innerHTML;
                            }
                            if (newPager) {
                                totalPages = parseInt(newPager.dataset.totalPages, 10);
                            }
                            currentPage = pg;
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
        <?php endif; ?>
    </section>
    <?php
};

require EDUCBT_PRO_PATH . 'templates/portal/shell.php';
