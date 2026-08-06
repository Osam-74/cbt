<?php

namespace EduCBTPro\Services;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class RiskAlertService {
    private AcademicAnalyticsService $academic_analytics_service;
    private OperationalReadinessService $operational_readiness_service;
    private IntegrityAnalyticsService $integrity_analytics_service;

    public function __construct( ?AcademicAnalyticsService $academic_analytics_service = null, ?OperationalReadinessService $operational_readiness_service = null, ?IntegrityAnalyticsService $integrity_analytics_service = null ) {
        $this->academic_analytics_service = $academic_analytics_service ?? new AcademicAnalyticsService();
        $this->operational_readiness_service = $operational_readiness_service ?? new OperationalReadinessService();
        $this->integrity_analytics_service = $integrity_analytics_service ?? new IntegrityAnalyticsService();
    }

    public function get_risk_alerts( int $school_id, array $filters = [] ): array {
        $academic = $this->academic_analytics_service->get_academic_intelligence( $school_id, $filters );
        $operational = $this->operational_readiness_service->get_operational_readiness( $school_id, $filters );
        $integrity = $this->integrity_analytics_service->get_integrity_analytics( $school_id, $filters );

        $alerts = [];

        foreach ( (array) ( $academic['readiness']['students'] ?? [] ) as $student ) {
            $category = strtolower( trim( (string) ( $student['category'] ?? '' ) ) );
            if ( $category === 'at risk' ) {
                $alerts[] = [
                    'severity' => 'Critical',
                    'domain' => 'Academic',
                    'code' => 'ACADEMIC_AT_RISK',
                    'message' => sprintf( 'Student %d is at risk and needs immediate intervention.', absint( $student['student_id'] ?? 0 ) ),
                    'meta' => [ 'student' => $student ],
                ];
            } elseif ( $category === 'needs improvement' ) {
                $alerts[] = [
                    'severity' => 'High',
                    'domain' => 'Academic',
                    'code' => 'ACADEMIC_NEEDS_IMPROVEMENT',
                    'message' => sprintf( 'Student %d needs targeted remediation support.', absint( $student['student_id'] ?? 0 ) ),
                    'meta' => [ 'student' => $student ],
                ];
            }
        }

        $operational_band = strtolower( trim( (string) ( $operational['metrics']['readiness_band'] ?? '' ) ) );
        $suspicious_event_count = absint( $operational['summary']['suspicious_event_count'] ?? 0 );

        if ( $operational_band === 'at risk' ) {
            $alerts[] = [
                'severity' => 'Critical',
                'domain' => 'Operations',
                'code' => 'OPS_AT_RISK',
                'message' => 'Operational readiness is at risk. Review exam operations immediately.',
                'meta' => [ 'metrics' => $operational['metrics'] ?? [] ],
            ];
        } elseif ( $operational_band === 'needs improvement' ) {
            $alerts[] = [
                'severity' => 'High',
                'domain' => 'Operations',
                'code' => 'OPS_NEEDS_IMPROVEMENT',
                'message' => 'Operational readiness needs improvement. Stabilize exam workflows.',
                'meta' => [ 'metrics' => $operational['metrics'] ?? [] ],
            ];
        }

        if ( $suspicious_event_count > 0 ) {
            $alerts[] = [
                'severity' => 'High',
                'domain' => 'Operations',
                'code' => 'OPS_SUSPICIOUS_EVENTS',
                'message' => sprintf( '%d suspicious operational events detected from audit logs.', $suspicious_event_count ),
                'meta' => [ 'suspicious_event_count' => $suspicious_event_count ],
            ];
        }

        $flagged_pairs = absint( $integrity['summary']['flagged_pairs'] ?? 0 );
        $integrity_band = strtolower( trim( (string) ( $integrity['summary']['risk_band'] ?? '' ) ) );

        if ( $flagged_pairs > 0 ) {
            $severity = $integrity_band === 'high' ? 'Critical' : 'High';
            $alerts[] = [
                'severity' => $severity,
                'domain' => 'Integrity',
                'code' => 'INTEGRITY_SIMILARITY_FLAGS',
                'message' => sprintf( '%d high-similarity answer pattern pairs detected.', $flagged_pairs ),
                'meta' => [ 'flagged_pairs' => $flagged_pairs ],
            ];
        }

        if ( empty( $alerts ) ) {
            $alerts[] = [
                'severity' => 'Info',
                'domain' => 'System',
                'code' => 'NO_ACTIVE_RISKS',
                'message' => 'No high-priority risks detected. Continue routine monitoring.',
                'meta' => [],
            ];
        }

        usort( $alerts, [ $this, 'compare_alert_priority' ] );

        return [
            'summary' => [
                'total_alerts' => count( $alerts ),
                'critical' => $this->count_by_severity( $alerts, 'Critical' ),
                'high' => $this->count_by_severity( $alerts, 'High' ),
                'medium' => $this->count_by_severity( $alerts, 'Medium' ),
                'info' => $this->count_by_severity( $alerts, 'Info' ),
            ],
            'alerts' => $alerts,
        ];
    }

    private function compare_alert_priority( array $left, array $right ): int {
        $rank = [
            'Critical' => 4,
            'High' => 3,
            'Medium' => 2,
            'Info' => 1,
        ];

        $left_rank = $rank[ $left['severity'] ?? 'Info' ] ?? 1;
        $right_rank = $rank[ $right['severity'] ?? 'Info' ] ?? 1;

        return $right_rank <=> $left_rank;
    }

    private function count_by_severity( array $alerts, string $severity ): int {
        return count( array_filter( $alerts, static function ( array $alert ) use ( $severity ): bool {
            return ( $alert['severity'] ?? '' ) === $severity;
        } ) );
    }
}
