<?php

namespace EduCBTPro\Services;

use EduCBTPro\Core\Schema;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * A durable copy of the question bank.
 *
 * WHY THIS EXISTS
 * ---------------
 * A term's questions are the most expensive thing in this system. A teacher spends
 * days writing them and a reviewer spends hours checking them, and unlike a result
 * they cannot be recomputed from anything else — if the rows go, the work is gone.
 *
 * They HAVE gone once: a plugin delete dropped `educbt_questions`, and because the
 * questions lived nowhere else there was nothing to restore from. Results survived
 * that same event because they are derived from scores; questions had no such
 * second copy.
 *
 * So this keeps one. Every time a set is submitted or approved — the two moments
 * at which the work is finished and worth preserving — the whole set is serialised
 * into `wp_options`. That table belongs to WordPress, not to this plugin, and is
 * never dropped by any uninstall routine here. A snapshot therefore survives
 * exactly the event that destroyed the originals.
 *
 * Restoring reads the snapshot back, preserving question ids so papers, answers and
 * approval history reconnect to the right rows.
 *
 * This is deliberately not a general backup system. It protects one thing, at the
 * two moments that matter, in a place that cannot be dropped by accident.
 */
class QuestionVaultService {

    private const PREFIX = 'educbt_qvault_';
    private const INDEX  = 'educbt_qvault_index';

    /**
     * Snapshot one question set: the set row, its questions, options and
     * sub-questions.
     */
    public function snapshot( int $school_id, int $set_id ): bool {
        global $wpdb;

        if ( $school_id <= 0 || $set_id <= 0 ) {
            return false;
        }

        $sets      = Schema::table( 'question_sets' );
        $questions = $wpdb->prefix . 'educbt_questions';
        $options   = Schema::table( 'question_options' );
        $sub_items = Schema::table( 'question_sub_items' );

        $set = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$sets} WHERE id = %d AND school_id = %d", $set_id, $school_id ),
            ARRAY_A
        );

        if ( ! $set ) {
            return false;
        }

        $rows = (array) $wpdb->get_results(
            $wpdb->prepare( "SELECT * FROM {$questions} WHERE question_set_id = %d AND school_id = %d", $set_id, $school_id ),
            ARRAY_A
        );

        if ( empty( $rows ) ) {
            return false; // nothing worth keeping yet
        }

        $payload = [
            'version'    => 1,
            'taken_at'   => current_time( 'mysql' ),
            'school_id'  => $school_id,
            'set'        => $set,
            'questions'  => [],
        ];

        foreach ( $rows as $row ) {
            $qid = absint( $row['id'] );

            $opt_rows = (array) $wpdb->get_results(
                $wpdb->prepare( "SELECT * FROM {$options} WHERE question_id = %d ORDER BY sort_order ASC", $qid ),
                ARRAY_A
            );

            $sub_rows = (array) $wpdb->get_results(
                $wpdb->prepare( "SELECT * FROM {$sub_items} WHERE question_id = %d ORDER BY sequence ASC", $qid ),
                ARRAY_A
            );

            // An objective question without its options is not a backup, it is a
            // question with no answer key — and that would only be discovered at
            // restore time, when the original is already gone. Refuse to store a
            // snapshot that has silently lost the part that matters most.
            $is_objective = (string) ( $row['question_type'] ?? '' ) !== 'theory';

            if ( $is_objective && empty( $opt_rows ) ) {
                if ( function_exists( 'error_log' ) ) {
                    error_log(
                        sprintf(
                            'EduCBT vault: refusing to snapshot set %d — question %d has no options (%s)',
                            $set_id,
                            $qid,
                            $wpdb->last_error !== '' ? $wpdb->last_error : 'none found'
                        )
                    );
                }

                return false;
            }

            $payload['questions'][] = [
                'row'       => $row,
                'options'   => $opt_rows,
                'sub_items' => $sub_rows,
            ];
        }

        $key = self::PREFIX . $school_id . '_' . $set_id;

        // Autoload off: these are large and only read during a restore.
        update_option( $key, wp_json_encode( $payload ), false );

        $index = (array) get_option( self::INDEX, [] );
        $index[ $school_id ][ $set_id ] = [
            'taken_at'  => $payload['taken_at'],
            'questions' => count( $payload['questions'] ),
        ];
        update_option( self::INDEX, $index, false );

        return true;
    }

    /**
     * Snapshot every set a school has, regardless of status. Used by the manual
     * "back up now" control, so a school is not relying solely on the automatic
     * points.
     *
     * @return array{sets:int,questions:int}
     */
    public function snapshot_school( int $school_id ): array {
        global $wpdb;

        $sets = Schema::table( 'question_sets' );

        $ids = (array) $wpdb->get_col(
            $wpdb->prepare( "SELECT id FROM {$sets} WHERE school_id = %d", $school_id )
        );

        $done  = 0;
        $count = 0;

        foreach ( $ids as $id ) {
            if ( $this->snapshot( $school_id, absint( $id ) ) ) {
                $done++;
                $count += absint(
                    $wpdb->get_var(
                        $wpdb->prepare(
                            'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'educbt_questions WHERE question_set_id = %d',
                            absint( $id )
                        )
                    )
                );
            }
        }

        return [ 'sets' => $done, 'questions' => $count ];
    }

    /**
     * What is in the vault for a school.
     *
     * @return array<int,array{set_id:int,taken_at:string,questions:int,present:bool}>
     */
    public function inventory( int $school_id ): array {
        global $wpdb;

        $index = (array) get_option( self::INDEX, [] );
        $mine  = (array) ( $index[ $school_id ] ?? [] );
        $out   = [];

        $questions = $wpdb->prefix . 'educbt_questions';
        $live      = $this->table_exists( $questions );

        foreach ( $mine as $set_id => $meta ) {
            $present = false;

            if ( $live ) {
                $present = absint(
                    $wpdb->get_var(
                        $wpdb->prepare( "SELECT COUNT(*) FROM {$questions} WHERE question_set_id = %d", absint( $set_id ) )
                    )
                ) > 0;
            }

            $out[] = [
                'set_id'    => absint( $set_id ),
                'taken_at'  => (string) ( $meta['taken_at'] ?? '' ),
                'questions' => absint( $meta['questions'] ?? 0 ),
                'present'   => $present,
            ];
        }

        return $out;
    }

    /**
     * Put a snapshot back.
     *
     * Only restores what is actually missing. A question that still exists is left
     * alone, because the live copy may have been edited since the snapshot and
     * overwriting it would undo that work.
     *
     * @return array{restored:int,skipped:int,error?:string}
     */
    public function restore( int $school_id, int $set_id ): array {
        global $wpdb;

        $raw = get_option( self::PREFIX . $school_id . '_' . $set_id, '' );

        if ( ! $raw ) {
            return [ 'restored' => 0, 'skipped' => 0, 'error' => 'no_snapshot' ];
        }

        $payload = json_decode( (string) $raw, true );

        if ( ! is_array( $payload ) || empty( $payload['questions'] ) ) {
            return [ 'restored' => 0, 'skipped' => 0, 'error' => 'unreadable_snapshot' ];
        }

        $sets      = Schema::table( 'question_sets' );
        $questions = $wpdb->prefix . 'educbt_questions';
        $options   = Schema::table( 'question_options' );
        $sub_items = Schema::table( 'question_sub_items' );

        // The set itself may also have gone.
        if ( ! empty( $payload['set'] ) && ! $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$sets} WHERE id = %d", $set_id ) ) ) {
            $wpdb->insert( $sets, $this->clean( (array) $payload['set'] ) );
        }

        $restored = 0;
        $skipped  = 0;

        foreach ( (array) $payload['questions'] as $item ) {
            $row = $this->clean( (array) ( $item['row'] ?? [] ) );
            $qid = absint( $row['id'] ?? 0 );

            if ( $qid <= 0 ) {
                continue;
            }

            if ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$questions} WHERE id = %d", $qid ) ) ) {
                $skipped++;
                continue;
            }

            $wpdb->insert( $questions, $row );

            if ( ! $wpdb->insert_id && $wpdb->rows_affected < 1 ) {
                continue;
            }

            // Options and sub-items get FRESH ids.
            //
            // Their own primary keys were being restored along with them, so a
            // restore collided with rows that already existed and the database
            // rejected the insert. Only the QUESTION keeps its id — that is what
            // results and paper composition point at. Nothing references an option
            // by id, so there is no reason to preserve one.
            foreach ( (array) ( $item['options'] ?? [] ) as $opt ) {
                $row_opt = $this->clean( (array) $opt );
                unset( $row_opt['id'] );
                $row_opt['question_id'] = $qid;

                $wpdb->insert( $options, $row_opt );
            }

            foreach ( (array) ( $item['sub_items'] ?? [] ) as $sub ) {
                $row_sub = $this->clean( (array) $sub );
                unset( $row_sub['id'] );
                $row_sub['question_id'] = $qid;

                $wpdb->insert( $sub_items, $row_sub );
            }

            $restored++;
        }

        return [ 'restored' => $restored, 'skipped' => $skipped ];
    }

    /**
     * Restore every snapshot a school has.
     *
     * @return array{restored:int,skipped:int,sets:int}
     */
    public function restore_school( int $school_id ): array {
        $total = [ 'restored' => 0, 'skipped' => 0, 'sets' => 0 ];

        foreach ( $this->inventory( $school_id ) as $entry ) {
            $result = $this->restore( $school_id, $entry['set_id'] );

            $total['restored'] += (int) ( $result['restored'] ?? 0 );
            $total['skipped']  += (int) ( $result['skipped'] ?? 0 );

            if ( ( $result['restored'] ?? 0 ) > 0 ) {
                $total['sets']++;
            }
        }

        return $total;
    }

    /**
     * Drop columns a restore must not force, so a snapshot taken against an older
     * schema still loads against a newer one.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function clean( array $row ): array {
        unset( $row['updated_at'] );

        return array_filter(
            $row,
            static fn( $value ): bool => $value !== null,
        );
    }

    private function table_exists( string $table ): bool {
        global $wpdb;

        return (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
    }
}
