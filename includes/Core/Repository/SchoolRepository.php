<?php

namespace EduCBTPro\Core\Repository;

use EduCBTPro\Core\Models\School;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SchoolRepository {

    /**
     * Discover which columns actually exist on the schools table.
     *
     * When the table was created by an early version and dbDelta silently failed to
     * add newer columns, a SELECT * can return null on some MySQL configurations.
     * This method lets us build a query that names only the columns that exist.
     */
    private function discover_columns( string $table ): array {
        global $wpdb;

        $columns = $wpdb->get_col( "SHOW COLUMNS FROM `{$table}`" );
        return is_array( $columns ) ? $columns : [];
    }

    /**
     * Build a safe column list for SELECT, falling back to SHOW COLUMNS discovery
     * when SELECT * fails. A missing column on an upgraded table made SELECT *
     * return nothing at all — the schools list appeared empty and the settings form
     * rendered blank — so the fallback discovers what exists and queries only that.
     */
    private function safe_select_all( string $table, string $suffix = '' ): array {
        global $wpdb;

        $rows = $wpdb->get_results( "SELECT * FROM `{$table}` {$suffix}", ARRAY_A );

        if ( $rows === null && ! empty( $wpdb->last_error ) ) {
            $wpdb->last_error = '';
            $columns = $this->discover_columns( $table );

            if ( ! empty( $columns ) ) {
                $col_list = implode( ', ', array_map( static fn( string $c ): string => '`' . esc_sql( $c ) . '`', $columns ) );
                $rows = $wpdb->get_results( "SELECT {$col_list} FROM `{$table}` {$suffix}", ARRAY_A );
            }
        }

        return is_array( $rows ) ? $rows : [];
    }

    /**
     * Same fallback for a single-row query.
     */
    private function safe_select_row( string $table, string $where_sql, ...$params ): ?array {
        global $wpdb;

        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE {$where_sql}", ...$params ), ARRAY_A );

        if ( $row === null && ! empty( $wpdb->last_error ) ) {
            $wpdb->last_error = '';
            $columns = $this->discover_columns( $table );

            if ( ! empty( $columns ) ) {
                $col_list = implode( ', ', array_map( static fn( string $c ): string => '`' . esc_sql( $c ) . '`', $columns ) );
                $row = $wpdb->get_row( $wpdb->prepare( "SELECT {$col_list} FROM `{$table}` WHERE {$where_sql}", ...$params ), ARRAY_A );
            }
        }

        return $row ?: null;
    }

    public function get_all_schools(): array {
        global $wpdb;
        $table = $wpdb->prefix . 'educbt_schools';
        return $this->safe_select_all( $table, 'ORDER BY id DESC' );
    }

    public function get_school_by_id( int $id ): ?School {
        global $wpdb;
        $table = $wpdb->prefix . 'educbt_schools';
        $row = $this->safe_select_row( $table, 'id = %d', $id );

        if ( ! $row ) {
            return null;
        }

        $school = new School();
        foreach ( $row as $key => $value ) {
            if ( property_exists( $school, $key ) ) {
                // Coalesce null to empty string — model properties are typed string
                $school->{$key} = $value ?? '';
            }
        }

        return $school;
    }

    public function create_school( array $data ): int {
        global $wpdb;
        $table = $wpdb->prefix . 'educbt_schools';

        $wpdb->insert(
            $table,
            [
                'school_name'      => sanitize_text_field( $data['school_name'] ?? '' ),
                'school_code'      => sanitize_text_field( $data['school_code'] ?? '' ),
                'logo'             => sanitize_text_field( $data['logo'] ?? '' ),
                'address'          => sanitize_textarea_field( $data['address'] ?? '' ),
                'phone'            => sanitize_text_field( $data['phone'] ?? '' ),
                'email'            => sanitize_email( $data['email'] ?? '' ),
                'website'          => esc_url_raw( $data['website'] ?? '' ),
                'principal_name'   => sanitize_text_field( $data['principal_name'] ?? '' ),
                'academic_settings'=> wp_json_encode( $data['academic_settings'] ?? [] ),
                'report_settings'  => wp_json_encode( $data['report_settings'] ?? [] ),
            ],
            [ '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
        );

        return (int) $wpdb->insert_id;
    }

    public function update_academic_settings( int $school_id, array $academic_settings ): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'educbt_schools';

        $updated = $wpdb->update(
            $table,
            [
                'academic_settings' => wp_json_encode( $academic_settings ),
            ],
            [
                'id' => $school_id,
            ],
            [ '%s' ],
            [ '%d' ]
        );

        return $updated !== false;
    }
}
