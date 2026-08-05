<?php

namespace EduCBTPro\Core\Repository;

use EduCBTPro\Core\Models\License;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LicenseRepository {
    public function get_all_licenses( int $school_id ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'educbt_licenses';
        return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE school_id = %d", $school_id ), ARRAY_A ) ?: [];
    }

    public function get_license( int $school_id ): ?array {
        global $wpdb;
        $table = $wpdb->prefix . 'educbt_licenses';
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE school_id = %d", $school_id ), ARRAY_A );
    }

    public function create_license( int $school_id, array $data ): int {
        global $wpdb;
        $table = $wpdb->prefix . 'educbt_licenses';

        $wpdb->insert(
            $table,
            [
                'school_id'    => $school_id,
                'license_key'  => sanitize_text_field( $data['license_key'] ?? '' ),
                'license_type' => sanitize_text_field( $data['license_type'] ?? '' ),
                'status'       => sanitize_text_field( $data['status'] ?? 'active' ),
                'issued_at'    => sanitize_text_field( $data['issued_at'] ?? current_time( 'mysql' ) ),
                'expires_at'   => sanitize_text_field( $data['expires_at'] ?? '' ),
            ],
            [ '%d', '%s', '%s', '%s', '%s', '%s' ]
        );

        return (int) $wpdb->insert_id;
    }

    public function update_license( int $school_id, int $license_id, array $data ): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'educbt_licenses';

        $update_data = [];
        $format = [];

        if ( isset( $data['status'] ) ) {
            $update_data['status'] = sanitize_text_field( $data['status'] );
            $format[] = '%s';
        }

        if ( isset( $data['expires_at'] ) ) {
            $update_data['expires_at'] = sanitize_text_field( $data['expires_at'] );
            $format[] = '%s';
        }

        if ( isset( $data['license_type'] ) ) {
            $update_data['license_type'] = sanitize_text_field( $data['license_type'] );
            $format[] = '%s';
        }

        if ( empty( $update_data ) ) {
            return false;
        }

        $result = $wpdb->update(
            $table,
            $update_data,
            [ 'id' => $license_id, 'school_id' => $school_id ],
            $format,
            [ '%d', '%d' ]
        );

        return $result !== false;
    }
}
