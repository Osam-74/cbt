<?php

namespace EduCBTPro\Services;

use EduCBTPro\Core\EventDispatcher;
use EduCBTPro\Core\Repository\ResultRepository;
use EduCBTPro\Core\Repository\ExamAttemptRepository;
use EduCBTPro\Core\Repository\ExamRepository;
use EduCBTPro\Core\Repository\QuestionRepository;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ResultService {
    private ResultRepository $repository;
    private ExamAttemptRepository $attempt_repository;
    private ExamRepository $exam_repository;
    private QuestionRepository $question_repository;

    public function __construct( ?ResultRepository $repository = null, ?ExamAttemptRepository $attempt_repository = null, ?ExamRepository $exam_repository = null, ?QuestionRepository $question_repository = null ) {
        $this->repository = $repository ?? new ResultRepository();
        $this->attempt_repository = $attempt_repository ?? new ExamAttemptRepository();
        $this->exam_repository = $exam_repository ?? new ExamRepository();
        $this->question_repository = $question_repository ?? new QuestionRepository();
    }

    public function list_results( int $school_id ): array {
        return $this->repository->get_all_results( $school_id );
    }

    public function get_exam_results( int $school_id, int $exam_id ): array {
        return $this->repository->get_exam_results( $school_id, $exam_id );
    }

    public function create_result( int $school_id, array $data ): int {
        $id = $this->repository->create_result( $school_id, $data );
        if ( $id > 0 ) {
            EventDispatcher::action( 'result_created', [
                'school_id' => $school_id,
                'result_id' => $id,
                'data'      => $data,
            ] );
        }
        return $id;
    }

    /**
     * Submit exam responses and auto-grade
     */
    public function submit_exam( int $school_id, int $attempt_id, array $responses ): array {
        if ( $attempt_id <= 0 ) {
            return [ 'success' => false, 'message' => 'invalid_params' ];
        }

        $attempt = $this->attempt_repository->get_attempt_by_id( $school_id, $attempt_id );
        if ( ! is_array( $attempt ) ) {
            return [ 'success' => false, 'message' => 'attempt_not_found' ];
        }

        $exam_id = absint( $attempt['exam_id'] ?? 0 );
        $student_id = absint( $attempt['student_id'] ?? 0 );
        $responses = is_array( $responses ) ? $responses : [];

        // Auto-grade responses with whatever data we have
        $grade_result = $this->auto_grade( $school_id, $exam_id, $responses );

        $result_data = [
            'exam_id'           => $exam_id,
            'exam_attempt_id'   => $attempt_id,
            'student_id'        => $student_id,
            'score'             => $grade_result['score'],
            'grade'             => $grade_result['grade'],
            'remark'            => $grade_result['remark'],
            'student_responses' => $responses,
            'grading_scheme'    => 'percentage',
            'status'            => 'submitted',
        ];

        $result_data = EventDispatcher::filter( 'result_data', $result_data, [
            'school_id'    => $school_id,
            'attempt_id'   => $attempt_id,
            'grade_result' => $grade_result,
            'responses'    => $responses,
        ] );

        if ( ! is_array( $result_data ) ) {
            $result_data = [
                'exam_id'           => $exam_id,
                'exam_attempt_id'   => $attempt_id,
                'student_id'        => $student_id,
                'score'             => $grade_result['score'],
                'grade'             => $grade_result['grade'],
                'remark'            => $grade_result['remark'],
                'student_responses' => $responses,
                'grading_scheme'    => 'percentage',
                'status'            => 'submitted',
            ];
        }

        $result_id = $this->repository->create_result( $school_id, $result_data );

        // Update attempt status
        $time = function_exists( 'current_time' ) ? current_time( 'mysql' ) : date( 'Y-m-d H:i:s' );
        $this->attempt_repository->update_attempt(
            $school_id,
            $attempt_id,
            [ 'status' => 'graded', 'time_submitted' => $time ]
        );

        EventDispatcher::action( 'exam_submitted', [
            'school_id'  => $school_id,
            'attempt_id' => $attempt_id,
            'result_id'  => $result_id,
            'score'      => $grade_result['score'],
            'grade'      => $grade_result['grade'],
        ] );

        return [
            'success'   => (bool) $result_id,
            'result_id' => $result_id,
            'score'     => $grade_result['score'],
            'grade'     => $grade_result['grade'],
        ];
    }

    /**
     * Auto-grade student responses against correct answers
     */
    private function auto_grade( int $school_id, int $exam_id, array $responses ): array {
        $total_questions = 0;
        $correct_answers = 0;

        foreach ( $responses as $question_id => $student_answer ) {
            $question = $this->question_repository->get_question( absint( $question_id ) );
            if ( ! $question ) {
                continue;
            }

            $total_questions++;
            $correct_answers_list = $question['answers'] ? json_decode( $question['answers'], true ) : [];

            // Handle multiple answer formats
            if ( is_array( $correct_answers_list ) ) {
                $correct = in_array( sanitize_text_field( $student_answer ), $correct_answers_list, true );
            } else {
                $correct = sanitize_text_field( $student_answer ) === $correct_answers_list;
            }

            if ( $correct ) {
                $correct_answers++;
            }
        }

        $percentage = $total_questions > 0 ? ( $correct_answers / $total_questions ) * 100 : 0;
        $grade = $this->compute_grade( $percentage );
        $grade = EventDispatcher::filter( 'grade_computed', $grade, [
            'score'           => round( $percentage, 2 ),
            'school_id'       => $school_id,
            'exam_id'         => $exam_id,
            'total_questions' => $total_questions,
            'correct_answers' => $correct_answers,
            'responses'       => $responses,
        ] );
        $grade = is_string( $grade ) ? strtoupper( trim( $grade ) ) : '';
        if ( $grade === '' ) {
            $grade = $this->compute_grade( $percentage );
        }
        $remark = $this->get_grade_remark( $grade );

        return [
            'score'  => round( $percentage, 2 ),
            'grade'  => $grade,
            'remark' => $remark,
        ];
    }

    /**
     * Compute letter grade from percentage
     */
    private function compute_grade( float $percentage ): string {
        if ( $percentage >= 90 ) {
            return 'A';
        } elseif ( $percentage >= 80 ) {
            return 'B';
        } elseif ( $percentage >= 70 ) {
            return 'C';
        } elseif ( $percentage >= 60 ) {
            return 'D';
        } elseif ( $percentage >= 50 ) {
            return 'E';
        } else {
            return 'F';
        }
    }

    /**
     * Get grade remark
     */
    private function get_grade_remark( string $grade ): string {
        $remarks = [
            'A' => 'Excellent',
            'B' => 'Very Good',
            'C' => 'Good',
            'D' => 'Fair',
            'E' => 'Pass',
            'F' => 'Fail',
        ];
        return $remarks[ $grade ] ?? '';
    }

    /**
     * Update result with manual grade
     */
    public function update_grade( int $school_id, int $result_id, string $grade, string $remark = '' ): bool {
        return $this->repository->update_result( $school_id, $result_id, [ 'grade' => $grade, 'remark' => $remark ] );
    }

    /**
     * Apply theory marking to an existing result and recompute final grade.
     */
    public function mark_theory_result( int $school_id, int $result_id, float $objective_score, float $theory_score, float $max_score = 100.0, string $remark = '' ): array {
        if ( $result_id <= 0 ) {
            return [ 'success' => false, 'message' => 'result_id_required' ];
        }

        if ( $max_score <= 0 ) {
            return [ 'success' => false, 'message' => 'max_score_required' ];
        }

        if ( $objective_score < 0 || $theory_score < 0 ) {
            return [ 'success' => false, 'message' => 'scores_must_be_non_negative' ];
        }

        $total_score = round( min( $objective_score + $theory_score, $max_score ), 2 );
        $percentage = round( ( $total_score / $max_score ) * 100, 2 );
        $grade = $this->compute_grade( $percentage );
        $final_remark = $remark !== '' ? $remark : $this->get_grade_remark( $grade );

        $updated = $this->repository->update_result( $school_id, $result_id, [
            'score'  => $total_score,
            'grade'  => $grade,
            'remark' => $final_remark,
            'status' => 'reviewed',
        ] );

        if ( ! $updated ) {
            return [ 'success' => false, 'message' => 'update_failed' ];
        }

        EventDispatcher::action( 'theory_marked', [
            'school_id'       => $school_id,
            'result_id'       => $result_id,
            'objective_score' => $objective_score,
            'theory_score'    => $theory_score,
            'score'           => $total_score,
            'grade'           => $grade,
        ] );

        return [
            'success'   => true,
            'result_id' => $result_id,
            'score'     => $total_score,
            'grade'     => $grade,
            'remark'    => $final_remark,
        ];
    }

    /**
     * Get student's exam result
     */
    public function get_student_exam_result( int $school_id, int $exam_id, int $student_id ): ?array {
        return $this->repository->get_student_exam_result( $school_id, $exam_id, $student_id );
    }
}
