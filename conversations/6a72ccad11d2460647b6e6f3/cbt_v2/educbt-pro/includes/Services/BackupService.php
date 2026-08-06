<?php

namespace EduCBTPro\Services;

use EduCBTPro\Core\EventDispatcher;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * SRS §30 – Backup & Disaster Recovery.
 *
 * Responsibilities:
 *  - Enumerate the platform's tables so they can be exported.
 *  - Produce a structured backup manifest (metadata + table list + row counts).
 *  - Record backup history entries in wp_options.
 *  - Provide a restore interface (validates manifest; execution requires a DB import tool).
 *
 * Note: actual SQL file I/O is left to the admin layer / WP-CLI command
 *       because file-system writes need the WP Filesystem API. This service
 *       owns the business logic and history tracking only.
 */
class BackupService {
    private const OPTION_HISTORY = 'educbt_backup_history';
    private const MAX_HISTORY    = 20;

    /**
     * Enumerate all plugin tables for the given WP prefix.
     */
    public function get_plugin_tables( string $prefix = 'wp_' ): array {
        return [
            $prefix . 'educbt_schools',
            $prefix . 'educbt_students',
            $prefix . 'educbt_teachers',
            $prefix . 'educbt_classes',
            $prefix . 'educbt_subjects',
            $prefix . 'educbt_questions',
            $prefix . 'educbt_exams',
            $prefix . 'educbt_exam_questions',
            $prefix . 'educbt_exam_attempts',
            $prefix . 'educbt_results',
            $prefix . 'educbt_promotions',
            $prefix . 'educbt_transcripts',
            $prefix . 'educbt_audit_logs',
            $prefix . 'educbt_licenses',
        ];
    }

    /**
     * Build a backup manifest: metadata about what would be backed up.
     * Row counts are gathered from the live DB when $wpdb is available.
     */
    public function create_manifest( int $school_id, string $label = '' ): array {
        global $wpdb;
        $prefix = $wpdb ? $wpdb->prefix : 'wp_';
        $tables = $this->get_plugin_tables( $prefix );

        $table_info = [];
        foreach ( $tables as $table ) {
            $row_count = 0;
            if ( $wpdb ) {
                $count = $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );
                $row_count = $count !== null ? absint( $count ) : 0;
            }
            $table_info[] = [
                'table'     => $table,
                'row_count' => $row_count,
            ];
        }

        return [
            'version'    => '1.0',
            'school_id'  => $school_id,
            'label'      => $label !== '' ? $label : 'Manual backup',
            'created_at' => $this->now(),
            'tables'     => $table_info,
            'total_tables' => count( $tables ),
        ];
    }

    /**
     * Record a completed backup in the history.
     */
    public function record_backup( int $school_id, string $label, string $file_path = '' ): array {
        $entry = [
            'id'         => uniqid( 'bkp_', true ),
            'school_id'  => $school_id,
            'label'      => $label,
            'file_path'  => $file_path,
            'created_at' => $this->now(),
            'status'     => 'completed',
        ];

        $history = $this->get_history( $school_id );
        array_unshift( $history, $entry );

        // Keep only last N entries
        $history = array_slice( $history, 0, self::MAX_HISTORY );
        $this->save_history( $school_id, $history );

        EventDispatcher::action( 'backup_completed', [
            'school_id' => $school_id,
            'entry'     => $entry,
        ] );

        return $entry;
    }

    /**
     * Return the backup history for a school (newest first).
     */
    public function get_history( int $school_id ): array {
        $all = $this->load_all_history();
        return array_values( array_filter( $all, static fn( array $e ): bool => absint( $e['school_id'] ?? 0 ) === $school_id ) );
    }

    /**
     * Validate a manifest array; returns ['valid'=>true] or ['valid'=>false,'errors'=>[...]].
     */
    public function validate_manifest( array $manifest ): array {
        $errors = [];

        if ( ( $manifest['version'] ?? '' ) === '' ) {
            $errors[] = 'Missing version field.';
        }

        if ( empty( $manifest['tables'] ) || ! is_array( $manifest['tables'] ) ) {
            $errors[] = 'Missing or empty tables list.';
        }

        if ( ( $manifest['created_at'] ?? '' ) === '' ) {
            $errors[] = 'Missing created_at timestamp.';
        }

        return empty( $errors )
            ? [ 'valid' => true ]
            : [ 'valid' => false, 'errors' => $errors ];
    }

    /**
     * Queue a restore request after validating the manifest and confirmation.
     *
     * This service does not execute SQL restore operations directly; it only
     * validates input and returns a deterministic workflow response that an
     * admin controller / CLI command can act upon.
     */
    public function restore_from_manifest( array $manifest, bool $confirmed = false ): array {
        $validation = $this->validate_manifest( $manifest );
        if ( ! ( $validation['valid'] ?? false ) ) {
            return [
                'success' => false,
                'message' => 'invalid_manifest',
                'errors'  => $validation['errors'] ?? [ 'invalid_manifest' ],
            ];
        }

        if ( ! $confirmed ) {
            return [
                'success' => false,
                'message' => 'restore_confirmation_required',
            ];
        }

        return [
            'success' => true,
            'status'  => 'restore_queued',
            'tables'  => absint( $manifest['total_tables'] ?? count( $manifest['tables'] ?? [] ) ),
        ];
    }

    // ------------------------------------------------------------------ //

    private function now(): string {
        return function_exists( 'current_time' ) ? current_time( 'mysql' ) : date( 'Y-m-d H:i:s' );
    }

    private function load_all_history(): array {
        if ( function_exists( 'get_option' ) ) {
            $data = get_option( self::OPTION_HISTORY, [] );
            return is_array( $data ) ? $data : [];
        }
        return [];
    }

    private function save_history( int $school_id, array $history ): void {
        // Merge with other schools' entries
        $all     = $this->load_all_history();
        $others  = array_values( array_filter( $all, static fn( array $e ): bool => absint( $e['school_id'] ?? 0 ) !== $school_id ) );
        $merged  = array_merge( $history, $others );

        if ( function_exists( 'update_option' ) ) {
            update_option( self::OPTION_HISTORY, $merged );
        }
    }
}
