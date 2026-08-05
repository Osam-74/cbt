<?php

namespace EduCBTPro\Services;

use EduCBTPro\Core\Repository\ResultRepository;
use EduCBTPro\Core\Repository\AuditLogRepository;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class IntegrityAnalyticsService {
    private ResultRepository $result_repository;
    private AuditLogRepository $audit_log_repository;

    public function __construct( ?ResultRepository $result_repository = null, ?AuditLogRepository $audit_log_repository = null ) {
        $this->result_repository = $result_repository ?? new ResultRepository();
        $this->audit_log_repository = $audit_log_repository ?? new AuditLogRepository();
    }

    public function get_integrity_analytics( int $school_id, array $filters = [] ): array {
        $results = $this->apply_filters( $this->result_repository->get_all_results( $school_id ), $filters );
        $logs = $this->audit_log_repository->get_all_logs( $school_id );

        $min_similarity = floatval( $filters['min_similarity'] ?? 90.0 );
        $min_questions = max( 1, absint( $filters['min_questions'] ?? 5 ) );

        $similarity_flags = $this->detect_similarity_flags( $results, $min_similarity, $min_questions );
        $suspicious_actions = $this->collect_suspicious_actions( $logs );

        $affected_students = [];
        foreach ( $similarity_flags as $flag ) {
            $affected_students[ (string) $flag['student_a_id'] ] = true;
            $affected_students[ (string) $flag['student_b_id'] ] = true;
        }

        $risk_score = $this->calculate_risk_score( count( $similarity_flags ), count( $suspicious_actions ), count( $affected_students ) );

        return [
            'summary' => [
                'total_results'      => count( $results ),
                'flagged_pairs'      => count( $similarity_flags ),
                'affected_students'  => count( $affected_students ),
                'suspicious_events'  => count( $suspicious_actions ),
                'risk_score'         => $risk_score,
                'risk_band'          => $this->risk_band( $risk_score ),
            ],
            'similarity_flags'  => $similarity_flags,
            'suspicious_actions'=> $suspicious_actions,
            'recommendations'   => $this->recommendations( count( $similarity_flags ), count( $suspicious_actions ), $risk_score ),
        ];
    }

    private function apply_filters( array $results, array $filters ): array {
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

    private function detect_similarity_flags( array $results, float $min_similarity, int $min_questions ): array {
        $by_exam = [];
        foreach ( $results as $result ) {
            $exam_id = absint( $result['exam_id'] ?? 0 );
            if ( $exam_id <= 0 ) {
                continue;
            }
            $by_exam[ (string) $exam_id ][] = $result;
        }

        $flags = [];

        foreach ( $by_exam as $exam_id => $exam_results ) {
            $count = count( $exam_results );
            for ( $i = 0; $i < $count; $i++ ) {
                for ( $j = $i + 1; $j < $count; $j++ ) {
                    $a = $exam_results[ $i ];
                    $b = $exam_results[ $j ];

                    $responses_a = $this->normalize_responses( $a['student_responses'] ?? [] );
                    $responses_b = $this->normalize_responses( $b['student_responses'] ?? [] );

                    $comparison = $this->compare_responses( $responses_a, $responses_b );
                    if ( $comparison['common_questions'] < $min_questions ) {
                        continue;
                    }

                    if ( $comparison['similarity_percentage'] >= $min_similarity ) {
                        $flags[] = [
                            'exam_id'               => (int) $exam_id,
                            'student_a_id'          => absint( $a['student_id'] ?? 0 ),
                            'student_b_id'          => absint( $b['student_id'] ?? 0 ),
                            'common_questions'      => $comparison['common_questions'],
                            'matching_answers'      => $comparison['matching_answers'],
                            'similarity_percentage' => $comparison['similarity_percentage'],
                        ];
                    }
                }
            }
        }

        usort( $flags, static function ( array $a, array $b ): int {
            return $b['similarity_percentage'] <=> $a['similarity_percentage'];
        } );

        return $flags;
    }

    private function normalize_responses( $value ): array {
        if ( is_string( $value ) ) {
            $decoded = json_decode( $value, true );
            if ( is_array( $decoded ) ) {
                $value = $decoded;
            }
        }

        if ( ! is_array( $value ) ) {
            return [];
        }

        $normalized = [];
        foreach ( $value as $question_id => $answer ) {
            $normalized[ (string) $question_id ] = strtolower( trim( (string) $answer ) );
        }

        return $normalized;
    }

    private function compare_responses( array $responses_a, array $responses_b ): array {
        $common_question_ids = array_intersect( array_keys( $responses_a ), array_keys( $responses_b ) );
        $common_questions = count( $common_question_ids );

        if ( $common_questions === 0 ) {
            return [
                'common_questions' => 0,
                'matching_answers' => 0,
                'similarity_percentage' => 0.0,
            ];
        }

        $matching_answers = 0;
        foreach ( $common_question_ids as $question_id ) {
            if ( $responses_a[ $question_id ] === $responses_b[ $question_id ] ) {
                $matching_answers++;
            }
        }

        return [
            'common_questions' => $common_questions,
            'matching_answers' => $matching_answers,
            'similarity_percentage' => round( ( $matching_answers / $common_questions ) * 100, 2 ),
        ];
    }

    private function collect_suspicious_actions( array $logs ): array {
        $patterns = [
            'manual_grade_override',
            'result_deleted',
            'exam_force_submitted',
            'multiple_failed_logins',
            'access_denied',
            'impersonation',
        ];

        return array_values( array_filter( $logs, static function ( array $log ) use ( $patterns ): bool {
            $action = strtolower( trim( (string) ( $log['action'] ?? '' ) ) );
            if ( $action === '' ) {
                return false;
            }

            if ( in_array( $action, $patterns, true ) ) {
                return true;
            }

            return str_contains( $action, 'override' ) || str_contains( $action, 'force_' );
        } ) );
    }

    private function calculate_risk_score( int $flagged_pairs, int $suspicious_events, int $affected_students ): float {
        $score = ( $flagged_pairs * 20 ) + ( $suspicious_events * 5 ) + ( $affected_students * 2 );
        return round( min( 100.0, max( 0.0, (float) $score ) ), 2 );
    }

    private function risk_band( float $score ): string {
        if ( $score >= 70 ) {
            return 'High';
        }

        if ( $score >= 40 ) {
            return 'Medium';
        }

        if ( $score >= 20 ) {
            return 'Low';
        }

        return 'Minimal';
    }

    private function recommendations( int $flagged_pairs, int $suspicious_events, float $risk_score ): array {
        $items = [];

        if ( $flagged_pairs > 0 ) {
            $items[] = 'Review flagged student pairs for collusion and seating-pattern anomalies.';
        }

        if ( $suspicious_events > 0 ) {
            $items[] = 'Investigate suspicious audit events and enforce stricter role permissions.';
        }

        if ( $risk_score >= 40 ) {
            $items[] = 'Run a supervised retest for high-risk assessments and compare score drift.';
        }

        if ( empty( $items ) ) {
            $items[] = 'No major integrity concerns detected; continue periodic monitoring.';
        }

        return $items;
    }
}
