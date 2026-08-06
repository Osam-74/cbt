<?php

namespace EduCBTPro\Services;

use EduCBTPro\Core\Repository\ResultRepository;
use EduCBTPro\Core\Repository\ExamAttemptRepository;
use EduCBTPro\Core\Repository\AuditLogRepository;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class OperationalReadinessService {
    private ResultRepository $result_repository;
    private ExamAttemptRepository $attempt_repository;
    private AuditLogRepository $audit_log_repository;

    public function __construct( ?ResultRepository $result_repository = null, ?ExamAttemptRepository $attempt_repository = null, ?AuditLogRepository $audit_log_repository = null ) {
        $this->result_repository = $result_repository ?? new ResultRepository();
        $this->attempt_repository = $attempt_repository ?? new ExamAttemptRepository();
        $this->audit_log_repository = $audit_log_repository ?? new AuditLogRepository();
    }

    public function get_operational_readiness( int $school_id, array $filters = [] ): array {
        $results = $this->apply_result_filters( $this->result_repository->get_all_results( $school_id ), $filters );
        $attempts = $this->apply_attempt_filters( $this->attempt_repository->get_all_attempts( $school_id ), $filters );
        $logs = $this->audit_log_repository->get_all_logs( $school_id );

        $total_attempts = count( $attempts );
        $submitted_attempts = count( array_filter( $attempts, static function ( array $attempt ): bool {
            $status = strtolower( trim( (string) ( $attempt['status'] ?? '' ) ) );
            return $status === 'submitted' || $status === 'completed';
        } ) );
        $active_attempts = count( array_filter( $attempts, static function ( array $attempt ): bool {
            return strtolower( trim( (string) ( $attempt['status'] ?? '' ) ) ) === 'in_progress';
        } ) );

        $timeout_risk_sessions = count( array_filter( $attempts, static function ( array $attempt ): bool {
            $status = strtolower( trim( (string) ( $attempt['status'] ?? '' ) ) );
            $remaining = absint( $attempt['timer_seconds_remaining'] ?? 0 );
            return $status === 'in_progress' && $remaining > 0 && $remaining <= 300;
        } ) );

        $total_results = count( $results );
        $published_results = count( array_filter( $results, static function ( array $result ): bool {
            $status = strtolower( trim( (string) ( $result['status'] ?? '' ) ) );
            return $status === 'published' || $status === 'finalized';
        } ) );

        $missing_grade_results = count( array_filter( $results, static function ( array $result ): bool {
            $score = floatval( $result['score'] ?? 0 );
            $grade = trim( (string) ( $result['grade'] ?? '' ) );
            return $score > 0 && $grade === '';
        } ) );

        $completion_rate = $total_attempts > 0 ? round( ( $submitted_attempts / $total_attempts ) * 100, 2 ) : 0.0;
        $publication_rate = $total_results > 0 ? round( ( $published_results / $total_results ) * 100, 2 ) : 0.0;
        $grading_completeness = $total_results > 0 ? round( ( ( $total_results - $missing_grade_results ) / $total_results ) * 100, 2 ) : 0.0;
        $session_stability = $active_attempts > 0 ? round( ( ( $active_attempts - $timeout_risk_sessions ) / $active_attempts ) * 100, 2 ) : 100.0;

        $suspicious_event_count = $this->count_suspicious_events( $logs );

        $readiness_score = $this->calculate_readiness_score( $completion_rate, $publication_rate, $grading_completeness, $session_stability, $suspicious_event_count );
        $readiness_band = $this->to_readiness_band( $readiness_score );

        return [
            'summary' => [
                'total_results' => $total_results,
                'total_attempts' => $total_attempts,
                'active_attempts' => $active_attempts,
                'published_results' => $published_results,
                'missing_grade_results' => $missing_grade_results,
                'timeout_risk_sessions' => $timeout_risk_sessions,
                'suspicious_event_count' => $suspicious_event_count,
            ],
            'metrics' => [
                'completion_rate' => $completion_rate,
                'publication_rate' => $publication_rate,
                'grading_completeness' => $grading_completeness,
                'session_stability' => $session_stability,
                'readiness_score' => $readiness_score,
                'readiness_band' => $readiness_band,
            ],
            'recommendations' => $this->build_recommendations( $completion_rate, $publication_rate, $grading_completeness, $session_stability, $suspicious_event_count ),
        ];
    }

    private function apply_result_filters( array $results, array $filters ): array {
        return array_values( array_filter( $results, static function ( array $result ) use ( $filters ): bool {
            if ( ! empty( $filters['exam_id'] ) && absint( $result['exam_id'] ?? 0 ) !== absint( $filters['exam_id'] ) ) {
                return false;
            }

            if ( ( $filters['subject'] ?? '' ) !== '' && strcasecmp( trim( (string) ( $result['subject'] ?? '' ) ), trim( (string) $filters['subject'] ) ) !== 0 ) {
                return false;
            }

            if ( ( $filters['term'] ?? '' ) !== '' && strcasecmp( trim( (string) ( $result['term'] ?? '' ) ), trim( (string) $filters['term'] ) ) !== 0 ) {
                return false;
            }

            if ( ( $filters['session_year'] ?? '' ) !== '' && strcasecmp( trim( (string) ( $result['session_year'] ?? '' ) ), trim( (string) $filters['session_year'] ) ) !== 0 ) {
                return false;
            }

            return true;
        } ) );
    }

    private function apply_attempt_filters( array $attempts, array $filters ): array {
        return array_values( array_filter( $attempts, static function ( array $attempt ) use ( $filters ): bool {
            if ( ! empty( $filters['exam_id'] ) && absint( $attempt['exam_id'] ?? 0 ) !== absint( $filters['exam_id'] ) ) {
                return false;
            }

            return true;
        } ) );
    }

    private function count_suspicious_events( array $logs ): int {
        $suspicious_actions = [
            'manual_grade_override',
            'result_deleted',
            'exam_force_submitted',
            'access_denied',
            'multiple_failed_logins',
        ];

        return count( array_filter( $logs, static function ( array $log ) use ( $suspicious_actions ): bool {
            $action = strtolower( trim( (string) ( $log['action'] ?? '' ) ) );
            if ( $action === '' ) {
                return false;
            }

            if ( in_array( $action, $suspicious_actions, true ) ) {
                return true;
            }

            return str_contains( $action, 'override' ) || str_contains( $action, 'force_' );
        } ) );
    }

    private function calculate_readiness_score( float $completion_rate, float $publication_rate, float $grading_completeness, float $session_stability, int $suspicious_event_count ): float {
        $base_score = ( $completion_rate * 0.35 ) + ( $publication_rate * 0.30 ) + ( $grading_completeness * 0.20 ) + ( $session_stability * 0.15 );
        $penalty = min( 25.0, $suspicious_event_count * 2.5 );

        return round( max( 0.0, min( 100.0, $base_score - $penalty ) ), 2 );
    }

    private function to_readiness_band( float $score ): string {
        if ( $score >= 85 ) {
            return 'Excellent';
        }

        if ( $score >= 60 ) {
            return 'Ready';
        }

        if ( $score >= 40 ) {
            return 'Needs Improvement';
        }

        return 'At Risk';
    }

    private function build_recommendations( float $completion_rate, float $publication_rate, float $grading_completeness, float $session_stability, int $suspicious_event_count ): array {
        $recommendations = [];

        if ( $completion_rate < 70 ) {
            $recommendations[] = 'Improve exam completion flow and monitor in-progress attempts.';
        }

        if ( $publication_rate < 80 ) {
            $recommendations[] = 'Increase result publication turnaround for completed assessments.';
        }

        if ( $grading_completeness < 95 ) {
            $recommendations[] = 'Backfill missing grades to improve reporting accuracy.';
        }

        if ( $session_stability < 80 ) {
            $recommendations[] = 'Review timer/session stability to reduce timeout risk during exams.';
        }

        if ( $suspicious_event_count > 0 ) {
            $recommendations[] = 'Investigate suspicious operational events and enforce audit controls.';
        }

        if ( empty( $recommendations ) ) {
            $recommendations[] = 'Operational readiness is healthy. Continue current monitoring cadence.';
        }

        return $recommendations;
    }
}
