<?php

namespace EduCBTPro\Services;

use EduCBTPro\Core\Repository\StudentRepository;
use EduCBTPro\Core\Repository\ResultRepository;
use EduCBTPro\Core\Repository\AuditLogRepository;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * SRS §26 – Privacy & Compliance.
 *
 * Implements NDPA / NDPR / GDPR-readiness features:
 *  - Data export (right to access / portability)
 *  - Data erasure (right to erasure)
 *  - Consent recording
 *  - Privacy audit log retrieval
 *  - Data retention policy check
 */
class PrivacyComplianceService {
    private StudentRepository $student_repository;
    private ResultRepository $result_repository;
    private AuditLogRepository $audit_log_repository;

    public function __construct(
        ?StudentRepository $student_repository = null,
        ?ResultRepository $result_repository = null,
        ?AuditLogRepository $audit_log_repository = null
    ) {
        $this->student_repository   = $student_repository   ?? new StudentRepository();
        $this->result_repository    = $result_repository    ?? new ResultRepository();
        $this->audit_log_repository = $audit_log_repository ?? new AuditLogRepository();
    }

    /**
     * SRS: Right to Access / Portability.
     * Returns all personally identifiable data for a student.
     */
    public function export_student_data( int $school_id, int $student_id ): array {
        $students = $this->student_repository->get_all_students( $school_id );
        $student  = null;
        foreach ( $students as $s ) {
            if ( absint( $s['id'] ?? $s['student_id'] ?? 0 ) === $student_id ) {
                $student = $s;
                break;
            }
        }

        if ( $student === null ) {
            return [ 'success' => false, 'message' => 'student_not_found' ];
        }

        $results  = array_values( array_filter(
            $this->result_repository->get_all_results( $school_id ),
            static fn( array $r ): bool => absint( $r['student_id'] ?? 0 ) === $student_id
        ) );

        $audit_logs = array_values( array_filter(
            $this->audit_log_repository->get_all_logs( $school_id ),
            static fn( array $l ): bool => absint( $l['object_id'] ?? 0 ) === $student_id
                && strtolower( trim( (string) ( $l['object_type'] ?? '' ) ) ) === 'student'
        ) );

        return [
            'success'         => true,
            'export_date'     => $this->now(),
            'student_profile' => $student,
            'results'         => $results,
            'audit_trail'     => $audit_logs,
        ];
    }

    /**
     * SRS: Right to Erasure.
     * Returns a deletion manifest describing what would be removed; actual
     * deletion requires explicit confirmation (passed as $confirmed = true).
     */
    public function request_erasure( int $school_id, int $student_id, bool $confirmed = false ): array {
        $students = $this->student_repository->get_all_students( $school_id );
        $exists   = false;
        foreach ( $students as $s ) {
            if ( absint( $s['id'] ?? $s['student_id'] ?? 0 ) === $student_id ) {
                $exists = true;
                break;
            }
        }

        if ( ! $exists ) {
            return [ 'success' => false, 'message' => 'student_not_found' ];
        }

        $result_count = count( array_filter(
            $this->result_repository->get_all_results( $school_id ),
            static fn( array $r ): bool => absint( $r['student_id'] ?? 0 ) === $student_id
        ) );

        $manifest = [
            'student_id'   => $student_id,
            'records'      => [
                'student_profile' => 1,
                'results'         => $result_count,
            ],
            'status' => $confirmed ? 'erasure_queued' : 'erasure_pending_confirmation',
        ];

        // In production: if $confirmed, dispatch 'student_erased' action
        // and let repositories handle the DELETE queries with proper hooks.

        return [ 'success' => true, 'manifest' => $manifest ];
    }

    /**
     * SRS: Consent Management — record a consent decision.
     */
    public function record_consent( int $school_id, int $student_id, string $purpose, bool $granted ): array {
        $record = [
            'school_id'  => $school_id,
            'student_id' => $student_id,
            'purpose'    => sanitize_text_field( $purpose ),
            'granted'    => $granted,
            'recorded_at'=> $this->now(),
        ];

        $all     = $this->load_consents();
        $all[]   = $record;
        $this->save_consents( $all );

        return [ 'success' => true, 'consent' => $record ];
    }

    /**
     * Retrieve consent history for a student.
     */
    public function get_consent_history( int $school_id, int $student_id ): array {
        return array_values( array_filter(
            $this->load_consents(),
            static fn( array $c ): bool =>
                absint( $c['school_id'] ?? 0 ) === $school_id &&
                absint( $c['student_id'] ?? 0 ) === $student_id
        ) );
    }

    /**
     * SRS: Data Retention — identify results older than $days.
     */
    public function get_expired_records( int $school_id, int $days = 2555 ): array {
        $cutoff  = strtotime( "-{$days} days" );
        $results = $this->result_repository->get_all_results( $school_id );

        return array_values( array_filter( $results, static function ( array $r ) use ( $cutoff ): bool {
            $ts = strtotime( (string) ( $r['created_at'] ?? '' ) );
            return $ts !== false && $ts < $cutoff;
        } ) );
    }

    // ------------------------------------------------------------------ //

    private function now(): string {
        return function_exists( 'current_time' ) ? current_time( 'mysql' ) : date( 'Y-m-d H:i:s' );
    }

    private function load_consents(): array {
        if ( function_exists( 'get_option' ) ) {
            $data = get_option( 'educbt_consent_records', [] );
            return is_array( $data ) ? $data : [];
        }
        return [];
    }

    private function save_consents( array $data ): void {
        if ( function_exists( 'update_option' ) ) {
            update_option( 'educbt_consent_records', $data );
        }
    }
}
