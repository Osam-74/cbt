<?php

namespace EduCBTPro\Services;

use EduCBTPro\Core\Repository\StudentRepository;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class StudentProfileUpdateService {
    private StudentRepository $student_repository;
    private AuditLogService $audit_log_service;

    public function __construct() {
        $this->student_repository = new StudentRepository();
        $this->audit_log_service = new AuditLogService();
        $this->ensure_table();
    }

    public function submit_update_request( int $school_id, int $student_id, int $requested_by_user_id, array $changes ): int {
        if ( $school_id <= 0 || $student_id <= 0 || $requested_by_user_id <= 0 ) {
            return 0;
        }

        $allowed_fields = [
            'first_name',
            'last_name',
            'full_name',
            'gender',
            'date_of_birth',
            'parent_information',
            'parent_phone',
            'parent_email',
            'address',
        ];

        $clean_changes = [];
        foreach ( $allowed_fields as $field ) {
            if ( ! array_key_exists( $field, $changes ) ) {
                continue;
            }

            $value = $changes[ $field ];
            if ( in_array( $field, [ 'address', 'parent_information' ], true ) ) {
                $clean_changes[ $field ] = sanitize_textarea_field( (string) $value );
            } elseif ( $field === 'parent_email' ) {
                $clean_changes[ $field ] = sanitize_email( (string) $value );
            } else {
                $clean_changes[ $field ] = sanitize_text_field( (string) $value );
            }
        }

        if ( empty( $clean_changes ) ) {
            return 0;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'educbt_student_profile_updates';

        $wpdb->insert(
            $table,
            [
                'school_id' => $school_id,
                'student_id' => $student_id,
                'requested_by_user_id' => $requested_by_user_id,
                'changes_json' => wp_json_encode( $clean_changes ),
                'status' => 'pending',
            ],
            [ '%d', '%d', '%d', '%s', '%s' ]
        );

        return (int) $wpdb->insert_id;
    }

    public function list_requests( int $school_id, array $filters = [] ): array {
        if ( $school_id <= 0 ) {
            return [];
        }

        global $wpdb;
        $table = $wpdb->prefix . 'educbt_student_profile_updates';

        $where = [ 'school_id = %d' ];
        $params = [ $school_id ];

        if ( ! empty( $filters['status'] ) ) {
            $where[] = 'status = %s';
            $params[] = sanitize_text_field( (string) $filters['status'] );
        }

        if ( ! empty( $filters['student_id'] ) ) {
            $where[] = 'student_id = %d';
            $params[] = absint( $filters['student_id'] );
        }

        $limit = max( 1, min( 500, absint( $filters['limit'] ?? 100 ) ) );
        $sql = "SELECT * FROM {$table} WHERE " . implode( ' AND ', $where ) . ' ORDER BY created_at DESC LIMIT %d';
        $params[] = $limit;

        $rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$params ), ARRAY_A ) ?: [];

        foreach ( $rows as &$row ) {
            $decoded = json_decode( (string) ( $row['changes_json'] ?? '' ), true );
            $row['changes'] = is_array( $decoded ) ? $decoded : [];
        }
        unset( $row );

        return $rows;
    }

    public function decide_request( int $school_id, int $request_id, int $reviewed_by_user_id, string $decision, string $review_note = '' ): bool {
        if ( $school_id <= 0 || $request_id <= 0 || $reviewed_by_user_id <= 0 ) {
            return false;
        }

        $decision = strtolower( sanitize_text_field( $decision ) );
        if ( ! in_array( $decision, [ 'approved', 'rejected' ], true ) ) {
            return false;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'educbt_student_profile_updates';

        $request = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE id = %d AND school_id = %d LIMIT 1",
                $request_id,
                $school_id
            ),
            ARRAY_A
        );

        if ( ! is_array( $request ) ) {
            return false;
        }

        if ( sanitize_text_field( (string) ( $request['status'] ?? '' ) ) !== 'pending' ) {
            return false;
        }

        $updated = $wpdb->update(
            $table,
            [
                'status' => $decision,
                'reviewed_by_user_id' => $reviewed_by_user_id,
                'review_note' => sanitize_textarea_field( $review_note ),
            ],
            [
                'id' => $request_id,
                'school_id' => $school_id,
            ],
            [ '%s', '%d', '%s' ],
            [ '%d', '%d' ]
        );

        if ( $updated === false ) {
            return false;
        }

        $changes = json_decode( (string) ( $request['changes_json'] ?? '' ), true );
        $changes = is_array( $changes ) ? $changes : [];

        if ( $decision === 'approved' && ! empty( $changes ) ) {
            $this->student_repository->update_student( $school_id, absint( $request['student_id'] ?? 0 ), $changes );
        }

        $this->audit_log_service->create_log(
            $school_id,
            [
                'user_id' => $reviewed_by_user_id,
                'action' => 'student_profile_update_' . $decision,
                'object_type' => 'student_profile_update',
                'object_id' => $request_id,
                'previous_value' => wp_json_encode( [ 'status' => 'pending' ] ),
                'new_value' => wp_json_encode( [
                    'status' => $decision,
                    'student_id' => absint( $request['student_id'] ?? 0 ),
                    'changes' => $changes,
                    'review_note' => sanitize_textarea_field( $review_note ),
                ] ),
                'ip_address' => sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) ),
                'device' => sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? '' ) ),
            ]
        );

        return true;
    }

    private function ensure_table(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'educbt_student_profile_updates';
        $charset_collate = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            school_id bigint(20) unsigned NOT NULL,
            student_id bigint(20) unsigned NOT NULL,
            requested_by_user_id bigint(20) unsigned NOT NULL,
            changes_json longtext DEFAULT NULL,
            status varchar(50) DEFAULT 'pending',
            reviewed_by_user_id bigint(20) unsigned DEFAULT NULL,
            review_note longtext DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY school_id (school_id),
            KEY student_id (student_id),
            KEY status (status)
        ) {$charset_collate};";

        dbDelta( $sql );
    }
}
