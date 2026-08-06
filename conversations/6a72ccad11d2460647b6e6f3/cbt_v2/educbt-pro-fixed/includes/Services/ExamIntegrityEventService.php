<?php

namespace EduCBTPro\Services;

use EduCBTPro\Core\Repository\ExamIntegrityEventRepository;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ExamIntegrityEventService {
    private ExamIntegrityEventRepository $repository;

    public function __construct( ?ExamIntegrityEventRepository $repository = null ) {
        $this->repository = $repository ?? new ExamIntegrityEventRepository();
    }

    public function log_event( int $school_id, array $data ): int {
        if ( $school_id <= 0 ) {
            return 0;
        }

        if ( empty( $data['event_type'] ) ) {
            return 0;
        }

        return $this->repository->create_event( $school_id, $data );
    }

    public function list_events( int $school_id, array $filters = [] ): array {
        return $this->repository->list_events( $school_id, $filters );
    }
}
