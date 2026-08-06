<?php
/**
 * Promotion — a rule-driven batch with human review.
 *
 * A principal should see five numbers and the handful of cases that are not
 * clear-cut, not five hundred rows.
 *
 * @var array<string,mixed> $educbt
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

global $wpdb;
$school_id = (int) $educbt['school_id'];
$flash     = \EduCBTPro\Frontend\PortalActions::flash();

$year     = new \EduCBTPro\Services\AcademicYearService();
$sessions = $year->list_sessions( $school_id );
$current  = $year->current_session( $school_id );

$levels = (array) $wpdb->get_results(
    $wpdb->prepare(
        'SELECT id, name FROM ' . \EduCBTPro\Core\Schema::table( 'class_levels' ) . ' WHERE school_id = %d ORDER BY level_order ASC',
        $school_id
    ),
    ARRAY_A
);

$promotion = new \EduCBTPro\Services\PromotionService();
$batch_id  = (int) ( $_GET['batch'] ?? 0 );
$review    = $batch_id > 0 ? $promotion->review( $school_id, $batch_id ) : null;

// Subjects offered as name/code pairs, so a principal chooses "Mathematics" and the
// rule stores MTH. Typing codes from memory is one typo away from silently changing
// who repeats a year, and a wrong code simply never matches.
$subject_options = array_map(
    static fn( array $row ): array => [
        'value' => (string) $row['code'],
        'label' => (string) $row['name'] . ' (' . (string) $row['code'] . ')',
    ],
    (array) $wpdb->get_results(
        $wpdb->prepare(
            'SELECT name, code FROM ' . \EduCBTPro\Core\Schema::table( 'subjects_v2' ) .
            " WHERE school_id = %d AND status = 'active' ORDER BY name ASC",
            $school_id
        ),
        ARRAY_A
    )
);

$first_level = (int) ( $levels[0]['id'] ?? 0 );
$rules       = $promotion->rules_for( $school_id, $first_level );

$batches = (array) $wpdb->get_results(
    $wpdb->prepare(
        'SELECT * FROM ' . \EduCBTPro\Core\Schema::table( 'promotion_batches' ) . ' WHERE school_id = %d ORDER BY id DESC LIMIT 10',
        $school_id
    ),
    ARRAY_A
);

$educbt_title = 'Promotion';

$educbt_body = static function () use ( $flash, $sessions, $levels, $current, $review, $batches, $rules, $subject_options ): void {
    require EDUCBT_PRO_PATH . 'templates/portal/partials/flash.php';
    ?>
    <section class="educbt-card">
        <h2>Run a promotion</h2>
        <p class="educbt-muted" style="margin-top:-6px">
            Every student in the level is scored against the rules and a proposal is
            produced. <strong>Nothing moves until you commit it.</strong>
            Default rules: promote at 45% average with English and Mathematics passed;
            trial at 40%; repeat below that.
        </p>

        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="educbt-form">
            <input type="hidden" name="action" value="educbt_propose_promotion">
            <?php wp_nonce_field( 'educbt_propose_promotion' ); ?>
            <div class="educbt-grid">
                <div>
                    <label for="level_id">Level *</label>
                    <select id="level_id" name="level_id" required>
                        <option value="">Choose</option>
                        <?php foreach ( $levels as $l ) : ?>
                            <option value="<?php echo esc_attr( (string) $l['id'] ); ?>"><?php echo esc_html( (string) $l['name'] ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="from_session">From session *</label>
                    <select id="from_session" name="from_session_id" required>
                        <?php foreach ( $sessions as $s ) : ?>
                            <option value="<?php echo esc_attr( (string) $s['id'] ); ?>" <?php selected( ! empty( $s['is_current'] ) ); ?>>
                                <?php echo esc_html( (string) $s['title'] ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="to_session">Into session *</label>
                    <select id="to_session" name="to_session_id" required>
                        <?php foreach ( $sessions as $s ) : ?>
                            <option value="<?php echo esc_attr( (string) $s['id'] ); ?>"><?php echo esc_html( (string) $s['title'] ); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="educbt-muted" style="margin-top:4px;font-size:12.5px">Add next year&rsquo;s session under Settings first.</p>
                </div>
            </div>
            <button type="submit" class="educbt-btn educbt-btn--primary" style="margin-top:16px">Produce a proposal</button>
        </form>
    </section>

    <section class="educbt-card">
        <h2>Promotion rules</h2>
        <p class="educbt-muted" style="margin-top:-6px">Set per level, so JSS3 can differ from SS2.</p>

        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="educbt-form">
            <input type="hidden" name="action" value="educbt_save_promotion_rules">
            <?php wp_nonce_field( 'educbt_save_promotion_rules' ); ?>

            <div class="educbt-grid">
                <div>
                    <label for="rules_level">Level *</label>
                    <select id="rules_level" name="level_id" required>
                        <?php foreach ( $levels as $l ) : ?>
                            <option value="<?php echo esc_attr( (string) $l['id'] ); ?>"><?php echo esc_html( (string) $l['name'] ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div><label for="promote_average">Promote at average (%)</label>
                    <input id="promote_average" name="promote_average" type="number" step="0.5" min="0" max="100" value="<?php echo esc_attr( (string) $rules['promote_average'] ); ?>"></div>
                <div><label for="trial_average">On trial at average (%)</label>
                    <input id="trial_average" name="trial_average" type="number" step="0.5" min="0" max="100" value="<?php echo esc_attr( (string) $rules['trial_average'] ); ?>"></div>
                <div><label for="pass_mark">A subject is passed at (%)</label>
                    <input id="pass_mark" name="pass_mark" type="number" step="0.5" min="0" max="100" value="<?php echo esc_attr( (string) $rules['pass_mark'] ); ?>"></div>
                <div><label for="min_subjects_passed">Subjects that must be passed</label>
                    <input id="min_subjects_passed" name="min_subjects_passed" type="number" min="1" max="20" value="<?php echo esc_attr( (string) $rules['min_subjects_passed'] ); ?>">
                    <p class="educbt-muted" style="margin-top:4px;font-size:12.5px">Capped at the number a student actually offers.</p></div>
                <div style="grid-column:1 / -1">
                    <label>Subjects that must be passed</label>
                    <?php
                    echo \EduCBTPro\Frontend\TagField::render( // phpcs:ignore WordPress.Security.EscapeOutput
                        'must_pass_codes[]',
                        $subject_options,
                        (array) $rules['must_pass_codes'],
                        'Start typing a subject name…'
                    );
                    ?>
                    <p class="educbt-muted" style="margin-top:6px;font-size:12.5px">
                        Failing any of these means repeating the year, whatever the average.
                        Choose by name — the code is filled in for you.
                    </p>
                </div>
            </div>

            <label style="display:flex;align-items:center;gap:8px;margin-top:12px;font-weight:400">
                <input type="checkbox" name="require_core" value="1" style="width:auto" <?php checked( ! empty( $rules['require_core'] ) ); ?>>
                Enforce the compulsory subjects
            </label>

            <button type="submit" class="educbt-btn educbt-btn--primary" style="margin-top:14px">Save rules</button>
        </form>
    </section>

    <?php if ( $review && ! empty( $review['found'] ) ) : ?>
        <section class="educbt-stats">
            <div class="educbt-stat"><b><?php echo esc_html( (string) $review['summary']['promoted'] ); ?></b><span>Promoted</span></div>
            <div class="educbt-stat"><b><?php echo esc_html( (string) $review['summary']['trial'] ); ?></b><span>On trial</span></div>
            <div class="educbt-stat"><b><?php echo esc_html( (string) $review['summary']['repeated'] ); ?></b><span>Repeating</span></div>
            <div class="educbt-stat"><b><?php echo esc_html( (string) $review['summary']['unresolved'] ); ?></b><span>Unresolved</span></div>
        </section>

        <section class="educbt-card">
            <h2>Needs a decision <span class="educbt-muted">(<?php echo esc_html( (string) count( $review['exceptions'] ) ); ?> of <?php echo esc_html( (string) $review['summary']['evaluated'] ); ?>)</span></h2>

            <?php if ( empty( $review['exceptions'] ) ) : ?>
                <p class="educbt-muted">Every student is clear-cut.</p>
            <?php else : ?>
                <table class="educbt-table">
                    <thead><tr><th>Student</th><th>Average</th><th>Passed</th><th>Proposed</th><th>Override</th></tr></thead>
                    <tbody>
                    <?php foreach ( $review['exceptions'] as $d ) : ?>
                        <tr>
                            <td><?php echo esc_html( (string) $d['name'] ); ?><br><span class="educbt-muted"><?php echo esc_html( (string) $d['admission_number'] ); ?></span></td>
                            <td><?php echo esc_html( (string) (float) $d['average_score'] ); ?>%</td>
                            <td><?php echo esc_html( (string) $d['subjects_passed'] ); ?></td>
                            <td><span class="educbt-pill"><?php echo esc_html( (string) $d['final_outcome'] ); ?></span></td>
                            <td>
                                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:flex;gap:6px;flex-wrap:wrap">
                                    <input type="hidden" name="action" value="educbt_override_promotion">
                                    <input type="hidden" name="batch_id" value="<?php echo esc_attr( (string) $review['batch']['id'] ); ?>">
                                    <input type="hidden" name="student_id" value="<?php echo esc_attr( (string) $d['student_id'] ); ?>">
                                    <?php wp_nonce_field( 'educbt_override_promotion' ); ?>
                                    <select name="outcome" style="padding:5px">
                                        <option value="promote">Promote</option>
                                        <option value="trial">On trial</option>
                                        <option value="repeat">Repeat</option>
                                    </select>
                                    <input name="reason" type="text" placeholder="Reason" required style="padding:5px;width:130px">
                                    <button type="submit" class="educbt-btn">Set</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <?php if ( (string) $review['batch']['status'] === 'proposed' ) : ?>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:16px"
                      onsubmit="return confirm('Commit this promotion? Every student moves into next session.');">
                    <input type="hidden" name="action" value="educbt_commit_promotion">
                    <input type="hidden" name="batch_id" value="<?php echo esc_attr( (string) $review['batch']['id'] ); ?>">
                    <?php wp_nonce_field( 'educbt_commit_promotion' ); ?>
                    <button type="submit" class="educbt-btn educbt-btn--primary">Commit promotion</button>
                </form>
            <?php else : ?>
                <p class="educbt-note">This batch has been <?php echo esc_html( (string) $review['batch']['status'] ); ?>.</p>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <?php if ( ! empty( $batches ) ) : ?>
    <section class="educbt-card">
        <h2>Recent batches</h2>
        <ul class="educbt-list">
        <?php foreach ( $batches as $b ) : ?>
            <li>
                <span>Batch #<?php echo esc_html( (string) $b['id'] ); ?> — <?php echo esc_html( (string) $b['total_evaluated'] ); ?> students</span>
                <span class="educbt-pill"><?php echo esc_html( (string) $b['status'] ); ?></span>
                <a class="educbt-btn" href="<?php echo esc_url( add_query_arg( 'batch', (int) $b['id'] ) ); ?>">Review</a>
            </li>
        <?php endforeach; ?>
        </ul>
    </section>
    <?php endif; ?>
    <?php
};

require EDUCBT_PRO_PATH . 'templates/portal/shell.php';
