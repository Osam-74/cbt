<?php

namespace EduCBTPro\Core\Repository;

use EduCBTPro\Core\Models\AuditLog;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class AuditLogRepository {
    public function get_all_logs( int $school_id ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'educbt_audit_logs';
        return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE school_id = %d", $school_id ), ARRAY_A ) ?: [];
    }

    public function add_log( int $school_id, array $data ): int {
        global $wpdb;
        $table = $wpdb->prefix . 'educbt_audit_logs';

        $wpdb->insert(
            $table,
            [
                'school_id'      => $school_id,
                'user_id'        => absint( $data['user_id'] ?? 0 ),
                'action'         => sanitize_text_field( $data['action'] ?? '' ),
                'object_type'    => sanitize_text_field( $data['object_type'] ?? '' ),
                'object_id'      => absint( $data['object_id'] ?? 0 ),
                'previous_value' => sanitize_textarea_field( wp_json_encode( $data['previous_value'] ?? '' ) ),
                'new_value'      => sanitize_textarea_field( wp_json_encode( $data['new_value'] ?? '' ) ),
                'ip_address'     => sanitize_text_field( $data['ip_address'] ?? '' ),
                'device'         => sanitize_text_field( $data['device'] ?? '' ),
            ],
            [ '%d', '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s' ]
        );

        return (int) $wpdb->insert_id;
    }
}
