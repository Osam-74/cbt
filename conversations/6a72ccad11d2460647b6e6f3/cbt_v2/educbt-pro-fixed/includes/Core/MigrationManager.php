<?php

namespace EduCBTPro\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * SRS §28 – Database Migration Framework.
 *
 * Responsibilities:
 *  - Track the current schema version in a wp_options key.
 *  - Maintain an ordered map of version → callable migration.
 *  - Apply only migrations newer than the stored version.
 *  - Expose rollback stubs (forward-only in MySQL via dbDelta; stubs provide hooks).
 */
class MigrationManager {
    private const OPTION_KEY = 'educbt_pro_db_version';

    /** Ordered list: version string → callable that receives $wpdb */
    private array $migrations = [];

    /**
     * Register a migration. Migrations run in the order they are registered.
     * The version string should follow semantic versioning: '1.0.0', '1.1.0', etc.
     */
    public function register( string $version, callable $migration ): void {
        $this->migrations[ $version ] = $migration;
    }

    /**
     * Run all migrations whose version is greater than the stored version.
     * Returns an array of versions that were applied.
     */
    public function run(): array {
        $current  = $this->get_current_version();
        $applied  = [];
        $versions = array_keys( $this->migrations );
        usort( $versions, 'version_compare' );

        foreach ( $versions as $version ) {
            if ( version_compare( $version, $current, '>' ) ) {
                global $wpdb;
                $migration = $this->migrations[ $version ];
                $migration( $wpdb );
                $applied[] = $version;
            }
        }

        if ( ! empty( $applied ) ) {
            $this->set_version( end( $applied ) );
        }

        return $applied;
    }

    /**
     * Returns the currently stored schema version.
     */
    public function get_current_version(): string {
        if ( function_exists( 'get_option' ) ) {
            return (string) ( get_option( self::OPTION_KEY, '0.0.0' ) ?: '0.0.0' );
        }
        return '0.0.0';
    }

    /**
     * Returns the highest registered migration version.
     */
    public function get_latest_version(): string {
        if ( empty( $this->migrations ) ) {
            return '0.0.0';
        }
        $versions = array_keys( $this->migrations );
        usort( $versions, 'version_compare' );
        return end( $versions );
    }

    /**
     * Returns all registered migration version strings.
     */
    public function get_registered_versions(): array {
        return array_keys( $this->migrations );
    }

    /**
     * Check whether there are pending (unapplied) migrations.
     */
    public function has_pending(): bool {
        $current = $this->get_current_version();
        foreach ( array_keys( $this->migrations ) as $version ) {
            if ( version_compare( $version, $current, '>' ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Returns the list of migration versions that have not yet been applied.
     */
    public function get_pending_versions(): array {
        $current = $this->get_current_version();
        $pending = array_values( array_filter( array_keys( $this->migrations ), static function ( string $v ) use ( $current ): bool {
            return version_compare( $v, $current, '>' );
        } ) );

        usort( $pending, 'version_compare' );
        return $pending;
    }

    /**
     * Force-set the stored version (useful for tests and manual rollback triggers).
     */
    public function set_version( string $version ): void {
        if ( function_exists( 'update_option' ) ) {
            update_option( self::OPTION_KEY, $version );
        }
    }

    /**
     * Rollback stub: fires the educbt_migration_rollback action so external code
     * can perform cleanup. Hard schema rollbacks are not supported via dbDelta,
     * so this is a hook-based extensibility point only.
     */
    public function rollback( string $to_version ): void {
        $from_version = $this->get_current_version();

        if ( version_compare( $to_version, $from_version, '>' ) ) {
            EventDispatcher::action( 'educbt_migration_rollback', [
                'from_version' => $from_version,
                'to_version'   => $to_version,
                'status'       => 'rejected',
            ] );
            return;
        }

        $this->set_version( $to_version );

        EventDispatcher::action( 'educbt_migration_rollback', [
            'from_version' => $from_version,
            'to_version'   => $to_version,
            'status'       => 'applied',
        ] );
    }
}
