<?php

namespace EduCBTPro\Services;

use EduCBTPro\Core\EventDispatcher;
use EduCBTPro\Core\Schema;
use EduCBTPro\Data\TrialQuestionSeed;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * PHASE 6b — public trial mode.
 *
 * A practice exam anyone can take from the marketing site with no account. Its
 * purpose is to let a prospective school, a parent, or a student try the CBT
 * experience before anyone signs up.
 *
 * Opening any write path to unauthenticated traffic needs care, so four constraints
 * are non-negotiable here:
 *
 *  1. TOTAL ISOLATION FROM SCHOOL DATA. Trial mode reads `trial_questions` and
 *     writes `trial_attempts`. It cannot reach a school's question bank, papers,
 *     students or results — not by parameter, not by any code path. Letting an
 *     anonymous endpoint touch the real bank would publish live exam papers to the
 *     internet, which is the single worst thing this system could do.
 *
 *  2. RATE LIMITED. An endpoint that writes rows for anyone who asks is a free
 *     database-filling service. Limited per client, per hour.
 *
 *  3. NO PERSONAL DATA. An optional display name for the results screen, and a
 *     HASHED client fingerprint for rate limiting only. No email, no phone, and the
 *     raw IP is never stored.
 *
 *  4. EPHEMERAL. Attempts expire and are purged. Nobody's practice score needs to
 *     live in the database forever.
 *
 * Explanations are shown ONLY here. In a real exam they would turn the first
 * finisher's screen into an answer key for everyone still sitting.
 */
class TrialExamService {

    public const DEFAULT_QUESTION_COUNT = 10;
    public const DEFAULT_DURATION       = 600;
    public const MAX_PER_HOUR           = 12;
    public const ATTEMPT_TTL_HOURS      = 48;

    // ---------------------------------------------------------------
    // Discovery
    // ---------------------------------------------------------------

    /**
     * Subjects available to try, with a live count so an empty subject is never
     * offered.
     *
     * @return array<int,array<string,mixed>>
     */
    public function subjects( string $band = TrialQuestionSeed::BAND_BOTH ): array {
        global $wpdb;

        $table = Schema::table( 'trial_questions' );

        $rows = (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT subject_code, subject_name, COUNT(*) AS available
                 FROM {$table}
                 WHERE status = 'active' AND level_band IN (%s, 'both')
                 GROUP BY subject_code, subject_name
                 HAVING available >= 5
                 ORDER BY subject_name ASC",
                $band
            ),
            ARRAY_A
        );

        return $rows;
    }

    // ---------------------------------------------------------------
    // Sitting a trial
    // ---------------------------------------------------------------

    /**
     * @return array{success:bool,token?:string,questions?:array,duration_seconds?:int,error?:string,retry_after?:int}
     */
    public function start( string $subject_code, string $band = TrialQuestionSeed::BAND_BOTH, string $display_name = '', int $count = 0 ): array {
        global $wpdb;

        $client = $this->client_hash();
        $limit  = $this->check_rate_limit( $client );

        if ( ! $limit['allowed'] ) {
            return [ 'success' => false, 'error' => 'rate_limited', 'retry_after' => $limit['retry_after'] ];
        }

        $subject_code = strtoupper( preg_replace( '/[^A-Za-z]/', '', $subject_code ) ?? '' );

        if ( $subject_code === '' ) {
            return [ 'success' => false, 'error' => 'subject_required' ];
        }

        $count = $count > 0 ? min( 40, $count ) : self::DEFAULT_QUESTION_COUNT;

        $questions = Schema::table( 'trial_questions' );

        $rows = (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, question_text, options, topic
                 FROM {$questions}
                 WHERE subject_code = %s AND status = 'active' AND level_band IN (%s, 'both')
                 ORDER BY RAND()
                 LIMIT %d",
                $subject_code,
                $band,
                $count
            ),
            ARRAY_A
        );

        if ( count( $rows ) < 5 ) {
            return [ 'success' => false, 'error' => 'not_enough_questions_for_this_subject' ];
        }

        $ids      = array_map( static fn( array $r ): int => absint( $r['id'] ), $rows );
        $token    = wp_generate_password( 48, false, false );
        $duration = max( 120, count( $rows ) * 24 );

        $wpdb->insert(
            Schema::table( 'trial_attempts' ),
            [
                'token'            => $token,
                'client_hash'      => $client,
                // Trimmed hard and stripped of tags: this string is echoed back on
                // the results screen, and it arrived from an anonymous stranger.
                'display_name'     => substr( sanitize_text_field( wp_strip_all_tags( $display_name ) ), 0, 40 ),
                'subject_code'     => $subject_code,
                'level_band'       => $band,
                'question_ids'     => (string) wp_json_encode( $ids ),
                'question_count'   => count( $ids ),
                'duration_seconds' => $duration,
                'started_at'       => current_time( 'mysql', true ),
                'expires_at'       => gmdate( 'Y-m-d H:i:s', time() + ( self::ATTEMPT_TTL_HOURS * HOUR_IN_SECONDS ) ),
                'status'           => 'in_progress',
            ],
            [ '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s' ]
        );

        EventDispatcher::action( 'educbt_trial_started', [ 'subject' => $subject_code, 'questions' => count( $ids ) ] );

        return [
            'success'          => true,
            'token'            => $token,
            'duration_seconds' => $duration,
            'questions'        => $this->present( $rows ),
        ];
    }

    /**
     * Strip the seed rows down to what a taker may see.
     *
     * `answer_key` and `explanation` are columns on the same table and are simply
     * never selected above — the query lists its columns explicitly for exactly this
     * reason.
     *
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    private function present( array $rows ): array {
        $out      = [];
        $position = 1;

        foreach ( $rows as $row ) {
            $options = json_decode( (string) $row['options'], true );
            $options = is_array( $options ) ? $options : [];

            $shaped = [];

            foreach ( $options as $key => $text ) {
                $shaped[] = [ 'key' => (string) $key, 'text' => (string) $text ];
            }

            $out[] = [
                'number'  => $position++,
                'id'      => absint( $row['id'] ),
                'topic'   => (string) $row['topic'],
                'text'    => (string) $row['question_text'],
                'options' => $shaped,
            ];
        }

        return $out;
    }

    /**
     * Submit and mark immediately.
     *
     * Unlike a real paper this grades inline, and that is a deliberate difference
     * rather than an inconsistency: trial takers arrive one at a time from a website,
     * not three hundred at once from a hall, and they expect their score on the next
     * screen.
     *
     * @param array<int,string> $answers question_id => option key
     * @return array{success:bool,score?:int,total?:int,percentage?:float,review?:array,error?:string}
     */
    public function submit( string $token, array $answers ): array {
        global $wpdb;

        $attempts = Schema::table( 'trial_attempts' );

        $attempt = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$attempts} WHERE token = %s", $token ),
            ARRAY_A
        );

        if ( ! $attempt ) {
            return [ 'success' => false, 'error' => 'trial_not_found' ];
        }

        if ( (string) $attempt['status'] !== 'in_progress' ) {
            // Already submitted: return the existing result rather than erroring, so
            // a refreshed results page still works.
            return $this->results( $token );
        }

        $ids = json_decode( (string) $attempt['question_ids'], true );
        $ids = is_array( $ids ) ? array_map( 'absint', $ids ) : [];

        if ( empty( $ids ) ) {
            return [ 'success' => false, 'error' => 'trial_has_no_questions' ];
        }

        // Only answers to questions actually on this trial are kept.
        $clean = [];

        foreach ( $answers as $question_id => $key ) {
            $question_id = absint( $question_id );

            if ( in_array( $question_id, $ids, true ) ) {
                $clean[ $question_id ] = strtoupper( substr( (string) $key, 0, 2 ) );
            }
        }

        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

        $keys = (array) $wpdb->get_results(
            $wpdb->prepare(
                'SELECT id, answer_key FROM ' . Schema::table( 'trial_questions' ) . " WHERE id IN ({$placeholders})",
                $ids
            ),
            ARRAY_A
        );

        $score = 0;

        foreach ( $keys as $row ) {
            if ( ( $clean[ absint( $row['id'] ) ] ?? '' ) === strtoupper( (string) $row['answer_key'] ) ) {
                $score++;
            }
        }

        $wpdb->update(
            $attempts,
            [
                'answers'      => (string) wp_json_encode( $clean ),
                'score'        => $score,
                'submitted_at' => current_time( 'mysql', true ),
                'status'       => 'submitted',
            ],
            [ 'id' => absint( $attempt['id'] ) ],
            [ '%s', '%d', '%s', '%s' ],
            [ '%d' ]
        );

        EventDispatcher::action( 'educbt_trial_submitted', [
            'subject' => (string) $attempt['subject_code'],
            'score'   => $score,
            'total'   => count( $ids ),
        ] );

        return $this->results( $token );
    }

    /**
     * The results screen, WITH explanations.
     *
     * This is the whole point of trial mode and the one place explanations are ever
     * exposed. A real paper must not do this: the first student to finish would hold
     * the answer key while the rest of the hall is still sitting.
     *
     * @return array{success:bool,score?:int,total?:int,percentage?:float,review?:array,error?:string}
     */
    public function results( string $token ): array {
        global $wpdb;

        $attempt = $wpdb->get_row(
            $wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'trial_attempts' ) . ' WHERE token = %s', $token ),
            ARRAY_A
        );

        if ( ! $attempt ) {
            return [ 'success' => false, 'error' => 'trial_not_found' ];
        }

        if ( (string) $attempt['status'] === 'in_progress' ) {
            return [ 'success' => false, 'error' => 'trial_not_yet_submitted' ];
        }

        $ids     = (array) json_decode( (string) $attempt['question_ids'], true );
        $answers = (array) json_decode( (string) $attempt['answers'], true );
        $ids     = array_map( 'absint', $ids );

        $placeholders = implode( ',', array_fill( 0, max( 1, count( $ids ) ), '%d' ) );

        $rows = (array) $wpdb->get_results(
            $wpdb->prepare(
                'SELECT id, question_text, options, answer_key, explanation, topic
                 FROM ' . Schema::table( 'trial_questions' ) . " WHERE id IN ({$placeholders})",
                $ids ?: [ 0 ]
            ),
            ARRAY_A
        );

        $by_id = [];

        foreach ( $rows as $row ) {
            $by_id[ absint( $row['id'] ) ] = $row;
        }

        $review   = [];
        $position = 1;

        foreach ( $ids as $id ) {
            if ( ! isset( $by_id[ $id ] ) ) {
                continue;
            }

            $row     = $by_id[ $id ];
            $options = (array) json_decode( (string) $row['options'], true );
            $chosen  = (string) ( $answers[ $id ] ?? '' );
            $correct = strtoupper( (string) $row['answer_key'] );

            $review[] = [
                'number'        => $position++,
                'topic'         => (string) $row['topic'],
                'question'      => (string) $row['question_text'],
                'options'       => $options,
                'your_answer'   => $chosen,
                'correct_answer' => $correct,
                'is_correct'    => $chosen === $correct,
                'was_skipped'   => $chosen === '',
                'explanation'   => (string) $row['explanation'],
            ];
        }

        $total = count( $ids );
        $score = absint( $attempt['score'] );

        return [
            'success'      => true,
            'display_name' => (string) $attempt['display_name'],
            'subject'      => (string) $attempt['subject_code'],
            'score'        => $score,
            'total'        => $total,
            'percentage'   => $total > 0 ? round( ( $score / $total ) * 100, 1 ) : 0.0,
            'remark'       => $this->remark( $total > 0 ? ( $score / $total ) * 100 : 0 ),
            'review'       => $review,
        ];
    }

    private function remark( float $percentage ): string {
        if ( $percentage >= 75 ) {
            return 'Excellent';
        }

        if ( $percentage >= 60 ) {
            return 'Very good';
        }

        if ( $percentage >= 50 ) {
            return 'Good — keep practising';
        }

        if ( $percentage >= 40 ) {
            return 'Fair — review the explanations below';
        }

        return 'Needs more practice — work through the explanations below';
    }

    // ---------------------------------------------------------------
    // Abuse control
    // ---------------------------------------------------------------

    /**
     * A salted hash of IP and user agent. Enough to rate-limit a client, and not
     * reversible into an identity. The raw IP is never written anywhere.
     */
    private function client_hash(): string {
        $ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) wp_unslash( $_SERVER['REMOTE_ADDR'] ) : '';
        $ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( (string) wp_unslash( $_SERVER['HTTP_USER_AGENT'] ), 0, 120 ) : '';

        $salt = wp_salt( 'nonce' );

        return hash( 'sha256', $salt . '|' . $ip . '|' . $ua );
    }

    /**
     * @return array{allowed:bool,retry_after:int}
     */
    private function check_rate_limit( string $client ): array {
        global $wpdb;

        $recent = absint(
            $wpdb->get_var(
                $wpdb->prepare(
                    'SELECT COUNT(*) FROM ' . Schema::table( 'trial_attempts' ) .
                    ' WHERE client_hash = %s AND started_at > DATE_SUB(%s, INTERVAL 1 HOUR)',
                    $client,
                    current_time( 'mysql', true )
                )
            )
        );

        if ( $recent < self::MAX_PER_HOUR ) {
            return [ 'allowed' => true, 'retry_after' => 0 ];
        }

        return [ 'allowed' => false, 'retry_after' => HOUR_IN_SECONDS ];
    }

    /**
     * Nobody's practice score needs to live forever. Run on a schedule.
     */
    public function purge_expired(): int {
        global $wpdb;

        return absint(
            $wpdb->query(
                $wpdb->prepare(
                    'DELETE FROM ' . Schema::table( 'trial_attempts' ) . ' WHERE expires_at IS NOT NULL AND expires_at < %s',
                    current_time( 'mysql', true )
                )
            )
        );
    }

    /**
     * Aggregate interest, for the platform's own dashboard. Counts only — there is
     * nothing here that identifies a person.
     *
     * @return array<string,mixed>
     */
    public function usage_summary( int $days = 30 ): array {
        global $wpdb;

        $table = Schema::table( 'trial_attempts' );

        return [
            'attempts'   => absint(
                $wpdb->get_var(
                    $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE started_at > DATE_SUB(%s, INTERVAL %d DAY)", current_time( 'mysql', true ), $days )
                )
            ),
            'by_subject' => (array) $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT subject_code, COUNT(*) AS attempts, ROUND(AVG(score / NULLIF(question_count,0) * 100), 1) AS average_percent
                     FROM {$table}
                     WHERE status = 'submitted' AND started_at > DATE_SUB(%s, INTERVAL %d DAY)
                     GROUP BY subject_code",
                    current_time( 'mysql', true ),
                    $days
                ),
                ARRAY_A
            ),
        ];
    }
}
