<?php

namespace EduCBTPro\Services;

use EduCBTPro\Core\Repository\ResultRepository;
use EduCBTPro\Core\Repository\StudentRepository;
use EduCBTPro\Core\Repository\TeacherRepository;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class AcademicAnalyticsService {
    private const CACHE_TTL = 60;
    private static array $analytics_cache = [];

    private ResultRepository $result_repository;
    private StudentRepository $student_repository;
    private TeacherRepository $teacher_repository;
    private GradeComputationService $grade_computation_service;

    public function __construct( ?ResultRepository $result_repository = null, ?StudentRepository $student_repository = null, ?TeacherRepository $teacher_repository = null, ?GradeComputationService $grade_computation_service = null ) {
        $this->result_repository = $result_repository ?? new ResultRepository();
        $this->student_repository = $student_repository ?? new StudentRepository();
        $this->teacher_repository = $teacher_repository ?? new TeacherRepository();
        $this->grade_computation_service = $grade_computation_service ?? new GradeComputationService();
    }

    public function get_academic_intelligence( int $school_id, array $filters = [] ): array {
        $cache_key = $this->build_cache_key( $school_id, $filters );
        $cached = $this->get_cached_payload( $cache_key );
        if ( $cached !== null ) {
            return $cached;
        }

        $results = $this->apply_filters( $this->result_repository->get_all_results( $school_id ), $school_id, $filters );

        $payload = [
            'summary'   => $this->build_summary( $results ),
            'subjects'  => $this->analyze_subjects( $results ),
            'classes'   => $this->analyze_classes( $school_id, $results ),
            'teachers'  => $this->analyze_teachers( $school_id, $results ),
            'sessions'  => $this->analyze_sessions( $results ),
            'readiness' => $this->generate_readiness_profile( $school_id, $results ),
        ];

        $this->set_cached_payload( $cache_key, $payload );

        return $payload;
    }

    public static function clear_cache(): void {
        if ( function_exists( 'delete_transient' ) ) {
            foreach ( array_keys( self::$analytics_cache ) as $key ) {
                delete_transient( $key );
            }
        }
        self::$analytics_cache = [];
    }

    private function build_cache_key( int $school_id, array $filters ): string {
        ksort( $filters );
        return 'educbt_analytics_' . md5( (string) $school_id . '|' . wp_json_encode( $filters ) );
    }

    private function get_cached_payload( string $cache_key ): ?array {
        if ( isset( self::$analytics_cache[ $cache_key ] ) ) {
            return self::$analytics_cache[ $cache_key ];
        }

        if ( function_exists( 'get_transient' ) ) {
            $transient = get_transient( $cache_key );
            if ( is_array( $transient ) ) {
                self::$analytics_cache[ $cache_key ] = $transient;
                return $transient;
            }
        }

        return null;
    }

    private function set_cached_payload( string $cache_key, array $payload ): void {
        self::$analytics_cache[ $cache_key ] = $payload;

        if ( function_exists( 'set_transient' ) ) {
            set_transient( $cache_key, $payload, self::CACHE_TTL );
        }
    }

    private function apply_filters( array $results, int $school_id, array $filters ): array {
        $class_filter = trim( (string) ( $filters['class'] ?? '' ) );
        $student_class_map = $class_filter !== '' ? $this->get_student_class_map( $school_id ) : [];

        return array_values( array_filter( $results, function ( array $result ) use ( $filters, $class_filter, $student_class_map ): bool {
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

            if ( $class_filter !== '' ) {
                $student_id = (string) absint( $result['student_id'] ?? 0 );
                $result_class = $student_class_map[ $student_id ] ?? 'Unknown';
                if ( strcasecmp( trim( $result_class ), $class_filter ) !== 0 ) {
                    return false;
                }
            }

            return true;
        } ) );
    }

    private function build_summary( array $results ): array {
        $scores = array_map( function ( array $result ) {
            return floatval( $result['score'] ?? 0 );
        }, $results );

        $grade_counts = $this->build_grade_counts( $results );
        $total = count( $results );
        $pass_rate = $this->grade_computation_service->calculate_pass_rate( $results, 'E' );
        $distinction_rate = $total > 0 ? round( ( ( $grade_counts['A'] ?? 0 ) / $total ) * 100, 2 ) : 0.0;
        $failure_rate = $total > 0 ? round( ( ( $grade_counts['F'] ?? 0 ) / $total ) * 100, 2 ) : 0.0;

        return [
            'total_results'      => $total,
            'average_score'      => $this->grade_computation_service->calculate_average( $scores ),
            'pass_rate'          => $pass_rate,
            'failure_rate'       => $failure_rate,
            'distinction_rate'   => $distinction_rate,
            'grade_distribution' => $this->grade_computation_service->generate_grade_distribution( $results ),
        ];
    }

    private function analyze_subjects( array $results ): array {
        $grouped = [];

        foreach ( $results as $result ) {
            $subject = trim( (string) ( $result['subject'] ?? '' ) );
            if ( $subject === '' ) {
                $subject = 'Unknown';
            }
            $grouped[ $subject ][] = $result;
        }

        return $this->build_group_analysis( $grouped );
    }

    private function analyze_classes( int $school_id, array $results ): array {
        $student_class_map = $this->get_student_class_map( $school_id );
        $grouped = [];

        foreach ( $results as $result ) {
            $student_id = (string) absint( $result['student_id'] ?? 0 );
            $class_name = $student_class_map[ $student_id ] ?? 'Unknown';
            $grouped[ $class_name ][] = $result;
        }

        return $this->build_group_analysis( $grouped );
    }

    private function analyze_teachers( int $school_id, array $results ): array {
        $teacher_rows = $this->teacher_repository->get_all_teachers( $school_id );
        $analysis = [];

        foreach ( $teacher_rows as $teacher ) {
            $subjects = $this->decode_list( $teacher['subjects'] ?? [] );
            $teacher_name = trim( (string) ( $teacher['full_name'] ?? '' ) );
            if ( $teacher_name === '' ) {
                $teacher_name = trim( (string) ( $teacher['teacher_id'] ?? 'Unknown' ) );
            }

            $matched_results = array_values( array_filter( $results, function ( array $result ) use ( $subjects ): bool {
                $subject = trim( (string) ( $result['subject'] ?? '' ) );
                return $subject !== '' && in_array( $subject, $subjects, true );
            } ) );

            $analysis[ $teacher_name ] = $this->build_metrics( $matched_results ) + [
                'teacher_id'      => $teacher['teacher_id'] ?? '',
                'subjects'        => $subjects,
                'assigned_classes'=> $this->decode_list( $teacher['assigned_classes'] ?? [] ),
            ];
        }

        return $analysis;
    }

    private function analyze_sessions( array $results ): array {
        $grouped = [];

        foreach ( $results as $result ) {
            $session_year = trim( (string) ( $result['session_year'] ?? '' ) );
            $term = trim( (string) ( $result['term'] ?? '' ) );
            $key = $session_year !== '' ? $session_year : 'Unknown';
            if ( $term !== '' ) {
                $key .= ' / ' . $term;
            }
            $grouped[ $key ][] = $result;
        }

        return $this->build_group_analysis( $grouped );
    }

    private function generate_readiness_profile( int $school_id, array $results ): array {
        $student_rows = $this->student_repository->get_all_students( $school_id );
        $student_lookup = [];
        foreach ( $student_rows as $student ) {
            $reference = (string) ( $student['id'] ?? $student['student_id'] ?? '' );
            if ( $reference !== '' ) {
                $student_lookup[ $reference ] = $student;
            }
        }

        $grouped = [];
        foreach ( $results as $result ) {
            $student_id = (string) absint( $result['student_id'] ?? 0 );
            $grouped[ $student_id ][] = $result;
        }

        $students = [];
        foreach ( $grouped as $student_id => $student_results ) {
            $scores = array_map( function ( array $result ) {
                return floatval( $result['score'] ?? 0 );
            }, $student_results );
            $average = $this->grade_computation_service->calculate_average( $scores );
            $pass_rate = $this->grade_computation_service->calculate_pass_rate( $student_results, 'E' );

            if ( $average >= 85 && $pass_rate >= 90 ) {
                $category = 'Excellent';
                $risk_level = 'Low';
                $recommendation = 'Maintain current performance and provide enrichment tasks.';
            } elseif ( $average >= 55 && $pass_rate >= 50 ) {
                $category = 'Ready';
                $risk_level = 'Moderate';
                $recommendation = 'Keep monitoring performance and reinforce weak topics.';
            } elseif ( $average >= 40 && $pass_rate >= 25 ) {
                $category = 'Needs Improvement';
                $risk_level = 'High';
                $recommendation = 'Assign remediation and targeted practice sessions.';
            } else {
                $category = 'At Risk';
                $risk_level = 'Critical';
                $recommendation = 'Schedule immediate intervention and parent support.';
            }

            $student_data = $student_lookup[ $student_id ] ?? [];
            $students[] = [
                'student_id'     => absint( $student_id ),
                'full_name'      => $student_data['full_name'] ?? '',
                'class'          => $student_data['class'] ?? '',
                'average_score'  => $average,
                'pass_rate'      => $pass_rate,
                'category'       => $category,
                'risk_level'     => $risk_level,
                'prediction'     => $this->predict_performance( $average, $pass_rate ),
                'recommendation' => $recommendation,
            ];
        }

        usort( $students, function ( array $left, array $right ): int {
            return $right['average_score'] <=> $left['average_score'];
        } );

        return [
            'overall_category' => $this->derive_school_readiness_category( $results ),
            'students'         => $students,
        ];
    }

    private function build_group_analysis( array $grouped ): array {
        $analysis = [];

        foreach ( $grouped as $label => $results ) {
            $analysis[ $label ] = $this->build_metrics( $results ) + [
                'label' => $label,
            ];
        }

        ksort( $analysis );
        return $analysis;
    }

    private function build_metrics( array $results ): array {
        $scores = array_map( function ( array $result ) {
            return floatval( $result['score'] ?? 0 );
        }, $results );

        $grade_counts = $this->build_grade_counts( $results );
        $total = count( $results );

        return [
            'total_results'      => $total,
            'average_score'      => $this->grade_computation_service->calculate_average( $scores ),
            'pass_rate'          => $this->grade_computation_service->calculate_pass_rate( $results, 'E' ),
            'failure_rate'       => $total > 0 ? round( ( ( $grade_counts['F'] ?? 0 ) / $total ) * 100, 2 ) : 0.0,
            'distinction_rate'   => $total > 0 ? round( ( ( $grade_counts['A'] ?? 0 ) / $total ) * 100, 2 ) : 0.0,
            'grade_distribution' => $this->grade_computation_service->generate_grade_distribution( $results ),
        ];
    }

    private function build_grade_counts( array $results ): array {
        $counts = [
            'A' => 0,
            'B' => 0,
            'C' => 0,
            'D' => 0,
            'E' => 0,
            'F' => 0,
        ];

        foreach ( $results as $result ) {
            $grade = strtoupper( trim( (string) ( $result['grade'] ?? 'F' ) ) );
            if ( isset( $counts[ $grade ] ) ) {
                $counts[ $grade ]++;
            }
        }

        return $counts;
    }

    private function get_student_class_map( int $school_id ): array {
        $map = [];
        $students = $this->student_repository->get_all_students( $school_id );

        foreach ( $students as $student ) {
            $reference = (string) ( $student['id'] ?? $student['student_id'] ?? '' );
            if ( $reference !== '' ) {
                $map[ $reference ] = trim( (string) ( $student['class'] ?? '' ) ) ?: 'Unknown';
            }
        }

        return $map;
    }

    private function decode_list( $value ): array {
        if ( is_array( $value ) ) {
            return array_values( $value );
        }

        if ( ! is_string( $value ) || trim( $value ) === '' ) {
            return [];
        }

        $decoded = json_decode( $value, true );
        if ( is_array( $decoded ) ) {
            return array_values( $decoded );
        }

        return [];
    }

    private function predict_performance( float $average, float $pass_rate ): string {
        if ( $average >= 85 && $pass_rate >= 90 ) {
            return 'Strong performance expected';
        }

        if ( $average >= 70 && $pass_rate >= 75 ) {
            return 'Stable performance expected';
        }

        if ( $average >= 50 && $pass_rate >= 50 ) {
            return 'Improvement required for consistent results';
        }

        return 'High risk of underperformance';
    }

    private function derive_school_readiness_category( array $results ): string {
        if ( empty( $results ) ) {
            return 'At Risk';
        }

        $scores = array_map( function ( array $result ) {
            return floatval( $result['score'] ?? 0 );
        }, $results );
        $average = $this->grade_computation_service->calculate_average( $scores );
        $pass_rate = $this->grade_computation_service->calculate_pass_rate( $results, 'E' );

        if ( $average >= 85 && $pass_rate >= 90 ) {
            return 'Excellent';
        }

        if ( $average >= 55 && $pass_rate >= 50 ) {
            return 'Ready';
        }

        if ( $average >= 40 && $pass_rate >= 25 ) {
            return 'Needs Improvement';
        }

        return 'At Risk';
    }
}
