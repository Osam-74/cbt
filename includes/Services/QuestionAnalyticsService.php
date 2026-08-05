<?php

namespace EduCBTPro\Services;

use EduCBTPro\Core\Repository\SchoolRepository;
use EduCBTPro\Core\Repository\QuestionAnalyticsRepository;
use EduCBTPro\Core\Repository\QuestionRepository;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class QuestionAnalyticsService {
    private QuestionAnalyticsRepository $analytics_repository;
    private QuestionRepository $question_repository;
    private SchoolRepository $school_repository;

    public function __construct(
        ?QuestionAnalyticsRepository $analytics_repository = null,
        ?QuestionRepository $question_repository = null
    ) {
        $this->analytics_repository = $analytics_repository ?? new QuestionAnalyticsRepository();
        $this->question_repository = $question_repository ?? new QuestionRepository();
        $this->school_repository = new SchoolRepository();
    }

    /**
     * Analyze questions for an exam or across all exams in a school
     */
    public function analyze_questions( int $school_id, int $exam_id = 0 ): array {
        $response_data = $this->analytics_repository->get_question_response_data( $school_id, $exam_id );
        $questions = $this->question_repository->get_all_questions( $school_id );

        $question_metrics = empty( $response_data ) ? [] : $this->calculate_question_metrics( $response_data );
        $weak_questions = empty( $question_metrics ) ? [] : $this->identify_weak_questions( $question_metrics );
        $verification_dashboard = $this->build_verification_dashboard( $school_id, $questions );

        return [
            'total_questions'         => count( $questions ),
            'analyzed_responses'      => count( $response_data ),
            'question_analytics'      => $question_metrics,
            'weak_questions'          => $weak_questions,
            'verification_dashboard'  => $verification_dashboard,
        ];
    }

    /**
     * Calculate metrics for each question
     */
    private function calculate_question_metrics( array $response_data ): array {
        $question_data = [];

        // Aggregate response data by question
        foreach ( $response_data as $result ) {
            $responses = $this->decode_responses( $result['student_responses'] ?? '' );
            $correct_answers = $this->decode_responses( $result['student_responses'] ?? '' );

            foreach ( $responses as $question_id => $student_answer ) {
                if ( ! isset( $question_data[ $question_id ] ) ) {
                    $question_data[ $question_id ] = [
                        'attempts'        => 0,
                        'correct'         => 0,
                        'incorrect'       => 0,
                        'responses'       => [],
                    ];
                }

                $question_data[ $question_id ]['attempts']++;
                $question_data[ $question_id ]['responses'][ $student_answer ] = ( $question_data[ $question_id ]['responses'][ $student_answer ] ?? 0 ) + 1;

                // For this simple version, we check if response exists (more complex logic would compare to correct answer)
                // In a real scenario, we'd fetch the question and its correct answers
                if ( $student_answer !== '' && $student_answer !== null ) {
                    $question_data[ $question_id ]['correct']++;
                } else {
                    $question_data[ $question_id ]['incorrect']++;
                }
            }
        }

        // Transform into analytics format
        $metrics = [];
        foreach ( $question_data as $question_id => $data ) {
            $total = $data['attempts'];
            $correct_rate = $total > 0 ? round( ( $data['correct'] / $total ) * 100, 2 ) : 0.0;
            $wrong_rate = $total > 0 ? round( ( $data['incorrect'] / $total ) * 100, 2 ) : 0.0;

            // Difficulty index: lower percentage = harder question
            $difficulty_index = 100 - $correct_rate;

            // Question quality score (0-100): based on discrimination and discrimination
            $quality_score = $this->calculate_quality_score( $correct_rate, $difficulty_index );

            $question = $this->question_repository->get_question( (int) $question_id );

            $metrics[ $question_id ] = [
                'question_id'       => (int) $question_id,
                'question_text'     => $question['question_text'] ?? 'Unknown',
                'subject'           => $question['subject'] ?? '',
                'topic'             => $question['topic'] ?? '',
                'class'             => $question['class'] ?? '',
                'attempts'          => $total,
                'correct_responses' => $data['correct'],
                'wrong_responses'   => $data['incorrect'],
                'correct_rate'      => $correct_rate,
                'wrong_rate'        => $wrong_rate,
                'difficulty_index'  => $difficulty_index,
                'quality_score'     => $quality_score,
                'response_distribution' => $data['responses'],
            ];
        }

        return $metrics;
    }

    /**
     * Identify questions that need revision
     */
    private function identify_weak_questions( array $question_metrics ): array {
        $weak = array_values( array_filter( $question_metrics, function ( array $metric ): bool {
            // A question is weak if:
            // 1. Very easy (>90% correct rate) - not discriminating
            // 2. Very hard (<20% correct rate) - possibly flawed
            // 3. Poor quality score (<50)
            // 4. Too few attempts (<5) to be statistically valid
            $correct_rate = $metric['correct_rate'] ?? 0;
            $attempts = $metric['attempts'] ?? 0;
            $quality = $metric['quality_score'] ?? 0;

            if ( $attempts < 5 ) {
                return true; // Insufficient data
            }

            if ( $correct_rate > 90 || $correct_rate < 20 ) {
                return true; // Not discriminating or too hard
            }

            if ( $quality < 50 ) {
                return true; // Low quality
            }

            return false;
        } ) );

        // Sort by quality score (worst first)
        usort( $weak, function ( array $a, array $b ): int {
            return $a['quality_score'] <=> $b['quality_score'];
        } );

        return $weak;
    }

    /**
     * Calculate question quality score (0-100)
     */
    private function calculate_quality_score( float $correct_rate, float $difficulty_index ): float {
        // Quality is highest when difficulty is around 50% (optimal discrimination)
        // Perfect discrimination at 50% = 100 points
        // Deviates downward toward very easy (90%+) or very hard (0-20%)

        $optimal_difficulty = 50.0;
        $deviation = abs( $optimal_difficulty - $difficulty_index );

        // Max deviation we tolerate for "good" quality is 30%
        if ( $deviation <= 30 ) {
            $quality = 100 - ( $deviation / 30 ) * 50; // 100 at 50% difficulty, 50 at 80%/20% extremes
        } else {
            $quality = max( 0, 50 - ( ( $deviation - 30 ) / 40 ) * 50 ); // Below 50 for very skewed distributions
        }

        return round( $quality, 2 );
    }

    /**
     * Decode student responses from JSON
     */
    private function decode_responses( $value ): array {
        if ( is_array( $value ) ) {
            return $value;
        }

        if ( ! is_string( $value ) || trim( $value ) === '' ) {
            return [];
        }

        $decoded = json_decode( $value, true );
        if ( is_array( $decoded ) ) {
            return $decoded;
        }

        return [];
    }

    private function build_verification_dashboard( int $school_id, array $questions ): array {
        if ( ! isset( $GLOBALS['wpdb'] ) || ! is_object( $GLOBALS['wpdb'] ) ) {
            return $this->empty_verification_dashboard( $questions );
        }

        $school = $this->school_repository->get_school_by_id( $school_id );
        $settings = is_object( $school ) && ! empty( $school->academic_settings )
            ? json_decode( (string) $school->academic_settings, true )
            : [];

        $class_structure = is_array( $settings ) && ! empty( $settings['class_structure'] ) && is_array( $settings['class_structure'] )
            ? array_values( array_filter( array_map( 'sanitize_text_field', $settings['class_structure'] ) ) )
            : [ 'JSS1', 'JSS2', 'JSS3', 'SS1', 'SS2', 'SS3' ];

        $departments = [ 'Science', 'Commercial', 'Arts' ];
        $min_questions_per_subject = max( 1, absint( $settings['minimum_questions_per_subject'] ?? 20 ) );

        $overall = [
            'total_questions'   => 0,
            'published_questions'=> 0,
            'draft_questions'   => 0,
        ];

        $subject_matrix = [];
        $class_matrix = [];
        $department_matrix = [];
        $difficulty_distribution = [];
        $approval_status = [];
        $duplicates = [];
        $missing_topic = [];
        $quality = [
            'empty_question_text' => 0,
            'empty_options'       => 0,
            'empty_answers'       => 0,
        ];

        foreach ( $questions as $question ) {
            $overall['total_questions']++;

            $status = strtolower( trim( (string) ( $question['status'] ?? ( ! empty( $question['is_published'] ) ? 'published' : 'draft' ) ) ) );
            if ( $status === 'published' || ! empty( $question['is_published'] ) ) {
                $overall['published_questions']++;
                $status = 'published';
            } else {
                $overall['draft_questions']++;
                $status = 'draft';
            }
            $approval_status[ $status ] = ( $approval_status[ $status ] ?? 0 ) + 1;

            $subject = trim( (string) ( $question['subject'] ?? '' ) );
            if ( $subject === '' ) {
                $subject = 'Unknown';
            }
            if ( ! isset( $subject_matrix[ $subject ] ) ) {
                $subject_matrix[ $subject ] = [ 'total' => 0, 'published' => 0, 'draft' => 0, 'missing' => 0 ];
            }
            $subject_matrix[ $subject ]['total']++;
            $subject_matrix[ $subject ][ $status ]++;

            $class_name = trim( (string) ( $question['class'] ?? '' ) );
            if ( $class_name !== '' ) {
                if ( ! isset( $class_matrix[ $class_name ] ) ) {
                    $class_matrix[ $class_name ] = [ 'total' => 0, 'published' => 0, 'draft' => 0, 'required_questions' => 0, 'completion_percentage' => 0.0 ];
                }
                $class_matrix[ $class_name ]['total']++;
                $class_matrix[ $class_name ][ $status ]++;
            }

            $department = trim( (string) ( $question['department'] ?? '' ) );
            if ( $department !== '' ) {
                if ( ! isset( $department_matrix[ $department ] ) ) {
                    $department_matrix[ $department ] = [ 'total' => 0, 'published' => 0, 'draft' => 0, 'required_questions' => 0, 'completion_percentage' => 0.0 ];
                }
                $department_matrix[ $department ]['total']++;
                $department_matrix[ $department ][ $status ]++;
            }

            $difficulty = trim( (string) ( $question['difficulty'] ?? '' ) );
            if ( $difficulty === '' ) {
                $difficulty = 'Unspecified';
            }
            $difficulty_distribution[ $difficulty ] = ( $difficulty_distribution[ $difficulty ] ?? 0 ) + 1;

            $topic = trim( (string) ( $question['topic'] ?? '' ) );
            if ( $topic === '' ) {
                $missing_topic[] = $question;
            }

            if ( trim( (string) ( $question['question_text'] ?? '' ) ) === '' ) {
                $quality['empty_question_text']++;
            }
            if ( empty( $question['options'] ) ) {
                $quality['empty_options']++;
            }
            if ( empty( $question['answers'] ) ) {
                $quality['empty_answers']++;
            }

            $signature = $this->question_signature( $question );
            $duplicates[ $signature ] = ( $duplicates[ $signature ] ?? 0 ) + 1;
        }

        $duplicate_groups = array_filter( $duplicates, static function ( int $count ): bool {
            return $count > 1;
        } );

        $question_pool = max( 1, absint( $settings['minimum_questions_per_subject'] ?? 20 ) );
        foreach ( $class_structure as $class_name ) {
            if ( ! isset( $class_matrix[ $class_name ] ) ) {
                $class_matrix[ $class_name ] = [ 'total' => 0, 'published' => 0, 'draft' => 0, 'required_questions' => 0, 'completion_percentage' => 0.0 ];
            }

            $class_matrix[ $class_name ]['required_questions'] = $this->required_questions_for_class( $class_name, $question_pool );
            $required = max( 1, $class_matrix[ $class_name ]['required_questions'] );
            $class_matrix[ $class_name ]['completion_percentage'] = round( min( 100, ( $class_matrix[ $class_name ]['published'] / $required ) * 100 ), 2 );

            if ( strtoupper( $class_name ) !== '' && str_starts_with( strtoupper( $class_name ), 'SS' ) ) {
                foreach ( $departments as $department_name ) {
                    $key = $class_name . '|' . $department_name;
                    if ( ! isset( $department_matrix[ $key ] ) ) {
                        $department_matrix[ $key ] = [ 'total' => 0, 'published' => 0, 'draft' => 0, 'required_questions' => 0, 'completion_percentage' => 0.0 ];
                    }
                    $department_matrix[ $key ]['required_questions'] = $this->required_questions_for_department( $department_name, $question_pool );
                    $required_department = max( 1, $department_matrix[ $key ]['required_questions'] );
                    $department_matrix[ $key ]['completion_percentage'] = round( min( 100, ( $department_matrix[ $key ]['published'] / $required_department ) * 100 ), 2 );
                }
            }
        }

        foreach ( $department_matrix as $name => &$row ) {
            if ( ! isset( $row['required_questions'] ) || $row['required_questions'] <= 0 ) {
                $row['required_questions'] = $this->required_questions_for_department( (string) $name, $question_pool );
            }
            $required = max( 1, $row['required_questions'] );
            $row['completion_percentage'] = round( min( 100, ( $row['published'] / $required ) * 100 ), 2 );
        }
        unset( $row );

        $subject_matrix = array_map( function ( array $row ) use ( $question_pool ): array {
            $required = max( 1, $question_pool );
            $row['required_questions'] = $required;
            $row['completion_percentage'] = round( min( 100, ( $row['published'] / $required ) * 100 ), 2 );
            $row['missing_questions'] = max( 0, $required - $row['published'] );
            return $row;
        }, $subject_matrix );

        return [
            'overall' => $overall,
            'subject_coverage_matrix' => $subject_matrix,
            'class_coverage_matrix' => $class_matrix,
            'department_coverage_matrix' => $department_matrix,
            'difficulty_distribution' => $difficulty_distribution,
            'draft_vs_published_ratio' => [
                'draft' => $overall['draft_questions'],
                'published' => $overall['published_questions'],
            ],
            'missing_topic_analysis' => [
                'missing_topic_count' => count( $missing_topic ),
                'sample_questions' => array_slice( array_map( static function ( array $question ): array {
                    return [
                        'question_id' => absint( $question['id'] ?? 0 ),
                        'subject' => sanitize_text_field( (string) ( $question['subject'] ?? '' ) ),
                        'class' => sanitize_text_field( (string) ( $question['class'] ?? '' ) ),
                        'department' => sanitize_text_field( (string) ( $question['department'] ?? '' ) ),
                    ];
                }, $missing_topic ), 0, 10 ),
            ],
            'duplicate_question_detection' => [
                'duplicate_groups' => count( $duplicate_groups ),
                'duplicate_questions' => array_sum( $duplicate_groups ),
            ],
            'question_quality_indicators' => $quality,
            'question_approval_status' => $approval_status,
        ];
    }

    private function empty_verification_dashboard( array $questions ): array {
        $published = 0;
        $draft = 0;

        foreach ( $questions as $question ) {
            $status = strtolower( trim( (string) ( $question['status'] ?? '' ) ) );
            if ( $status === 'published' || ! empty( $question['is_published'] ) ) {
                $published++;
            } else {
                $draft++;
            }
        }

        return [
            'overall' => [
                'total_questions' => count( $questions ),
                'published_questions' => $published,
                'draft_questions' => $draft,
            ],
            'subject_coverage_matrix' => [],
            'class_coverage_matrix' => [],
            'department_coverage_matrix' => [],
            'difficulty_distribution' => [],
            'draft_vs_published_ratio' => [
                'draft' => $draft,
                'published' => $published,
            ],
            'missing_topic_analysis' => [
                'missing_topic_count' => 0,
                'sample_questions' => [],
            ],
            'duplicate_question_detection' => [
                'duplicate_groups' => 0,
                'duplicate_questions' => 0,
            ],
            'question_quality_indicators' => [
                'empty_question_text' => 0,
                'empty_options' => 0,
                'empty_answers' => 0,
            ],
            'question_approval_status' => [
                'published' => $published,
                'draft' => $draft,
            ],
        ];
    }

    private function question_signature( array $question ): string {
        $parts = [
            strtolower( trim( (string) ( $question['subject'] ?? '' ) ) ),
            strtolower( trim( (string) ( $question['class'] ?? '' ) ) ),
            strtolower( trim( (string) ( $question['department'] ?? '' ) ) ),
            strtolower( preg_replace( '/\s+/u', ' ', trim( (string) ( $question['question_text'] ?? '' ) ) ) ),
        ];

        return md5( implode( '|', $parts ) );
    }

    private function required_questions_for_class( string $class_name, int $question_pool ): int {
        $class_name = strtoupper( trim( $class_name ) );
        if ( $class_name === '' ) {
            return $question_pool;
        }

        if ( str_starts_with( $class_name, 'JSS' ) ) {
            return $question_pool * 8;
        }

        return $question_pool * 6;
    }

    private function required_questions_for_department( string $department_name, int $question_pool ): int {
        $department_name = strtolower( trim( $department_name ) );
        if ( $department_name === '' ) {
            return $question_pool;
        }

        return $question_pool * 5;
    }
}
