<?php

namespace EduCBTPro\Services;

use EduCBTPro\Core\EventDispatcher;
use EduCBTPro\Core\Repository\LicenseRepository;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LicenseService {
    private LicenseRepository $repository;

    public function __construct( ?LicenseRepository $repository = null ) {
        $this->repository = $repository ?? new LicenseRepository();
    }

    public function list_licenses( int $school_id ): array {
        return $this->repository->get_all_licenses( $school_id );
    }

    public function create_license( int $school_id, array $data ): int {
        $id = $this->repository->create_license( $school_id, $data );

        $status = strtolower( trim( (string) ( $data['status'] ?? 'active' ) ) );
        if ( $id > 0 && $status === 'active' ) {
            EventDispatcher::action( 'license_activated', [
                'school_id'  => $school_id,
                'license_id' => $id,
                'data'       => $data,
            ] );
        }

        return $id;
    }

    public function transition_status( int $school_id, int $license_id, string $status ): array {
        $next_status = strtolower( trim( $status ) );
        if ( ! in_array( $next_status, [ 'active', 'expired', 'suspended', 'revoked' ], true ) ) {
            return [ 'success' => false, 'message' => 'invalid_status' ];
        }

        $updated = $this->repository->update_license( $school_id, $license_id, [ 'status' => $next_status ] );
        if ( ! $updated ) {
            return [ 'success' => false, 'message' => 'update_failed' ];
        }

        if ( $next_status === 'active' ) {
            EventDispatcher::action( 'license_activated', [
                'school_id'  => $school_id,
                'license_id' => $license_id,
                'data'       => [ 'status' => $next_status, 'source' => 'transition_status' ],
            ] );
        }

        return [ 'success' => true, 'status' => $next_status, 'license_id' => $license_id ];
    }

    public function renew_license( int $school_id, int $license_id, string $expires_at ): array {
        $expires = trim( $expires_at );
        if ( $expires === '' ) {
            return [ 'success' => false, 'message' => 'expires_at_required' ];
        }

        $updated = $this->repository->update_license( $school_id, $license_id, [
            'status'     => 'active',
            'expires_at' => $expires,
        ] );

        if ( ! $updated ) {
            return [ 'success' => false, 'message' => 'update_failed' ];
        }

        EventDispatcher::action( 'license_activated', [
            'school_id'  => $school_id,
            'license_id' => $license_id,
            'data'       => [ 'status' => 'active', 'expires_at' => $expires, 'source' => 'renew_license' ],
        ] );

        return [ 'success' => true, 'status' => 'active', 'expires_at' => $expires, 'license_id' => $license_id ];
    }
}
