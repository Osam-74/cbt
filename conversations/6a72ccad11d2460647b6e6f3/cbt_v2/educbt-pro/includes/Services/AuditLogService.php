<?php

namespace EduCBTPro\Services;

use EduCBTPro\Core\EventDispatcher;
use EduCBTPro\Core\Repository\AuditLogRepository;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class AuditLogService {
    private AuditLogRepository $repository;

    public function __construct( ?AuditLogRepository $repository = null ) {
        $this->repository = $repository ?? new AuditLogRepository();
    }

    public function log_action( int $school_id, int $user_id, string $action, string $object_type, int $object_id, $previous_value = null, $new_value = null, string $ip_address = '', string $device = '' ): int {
        $id = $this->repository->add_log(
            $school_id,
            [
                'user_id'        => $user_id,
                'action'         => $action,
                'object_type'    => $object_type,
                'object_id'      => $object_id,
                'previous_value' => $previous_value,
                'new_value'      => $new_value,
                'ip_address'     => $ip_address,
                'device'         => $device,
            ]
        );

        if ( $id > 0 ) {
            EventDispatcher::action( 'audit_log_created', [
                'school_id' => $school_id,
                'log_id'    => $id,
                'action'    => $action,
                'object_type' => $object_type,
                'object_id' => $object_id,
            ] );
        }

        return $id;
    }

    public function create_log( int $school_id, array $data ): int {
        $id = $this->repository->add_log( $school_id, $data );
        if ( $id > 0 ) {
            EventDispatcher::action( 'audit_log_created', [
                'school_id' => $school_id,
                'log_id'    => $id,
                'action'    => (string) ( $data['action'] ?? '' ),
                'object_type' => (string) ( $data['object_type'] ?? '' ),
                'object_id' => absint( $data['object_id'] ?? 0 ),
            ] );
        }
        return $id;
    }

    public function list_logs( int $school_id, array $filters = [] ): array {
        $logs = $this->repository->get_all_logs( $school_id );

        $filtered = array_values( array_filter( $logs, static function ( array $log ) use ( $filters ): bool {
            if ( ! empty( $filters['action'] ) && strcasecmp( (string) ( $log['action'] ?? '' ), (string) $filters['action'] ) !== 0 ) {
                return false;
            }

            if ( ! empty( $filters['object_type'] ) && strcasecmp( (string) ( $log['object_type'] ?? '' ), (string) $filters['object_type'] ) !== 0 ) {
                return false;
            }

            if ( ! empty( $filters['user_id'] ) && absint( $log['user_id'] ?? 0 ) !== absint( $filters['user_id'] ) ) {
                return false;
            }

            return true;
        } ) );

        usort( $filtered, static function ( array $a, array $b ): int {
            $a_time = strtotime( (string) ( $a['created_at'] ?? '' ) ) ?: 0;
            $b_time = strtotime( (string) ( $b['created_at'] ?? '' ) ) ?: 0;
            return $b_time <=> $a_time;
        } );

        $limit = absint( $filters['limit'] ?? 0 );
        if ( $limit > 0 ) {
            $filtered = array_slice( $filtered, 0, $limit );
        }

        return $filtered;
    }

    public function get_audit_intelligence( int $school_id, array $filters = [] ): array {
        $logs = $this->list_logs( $school_id, $filters );

        $actions = [];
        $objects = [];
        $users = [];

        foreach ( $logs as $log ) {
            $action = trim( (string) ( $log['action'] ?? '' ) );
            $object = trim( (string) ( $log['object_type'] ?? '' ) );
            $user_id = absint( $log['user_id'] ?? 0 );

            if ( $action !== '' ) {
                $actions[ $action ] = ( $actions[ $action ] ?? 0 ) + 1;
            }

            if ( $object !== '' ) {
                $objects[ $object ] = ( $objects[ $object ] ?? 0 ) + 1;
            }

            if ( $user_id > 0 ) {
                $users[ $user_id ] = ( $users[ $user_id ] ?? 0 ) + 1;
            }
        }

        arsort( $actions );
        arsort( $objects );
        arsort( $users );

        return [
            'summary' => [
                'total_logs'       => count( $logs ),
                'actions'          => $actions,
                'objects'          => $objects,
                'active_users'     => count( $users ),
                'most_active_user' => ! empty( $users ) ? (int) array_key_first( $users ) : 0,
                'top_action'       => ! empty( $actions ) ? (string) array_key_first( $actions ) : '',
                'top_object_type'  => ! empty( $objects ) ? (string) array_key_first( $objects ) : '',
            ],
            'logs' => $logs,
        ];
    }
}
