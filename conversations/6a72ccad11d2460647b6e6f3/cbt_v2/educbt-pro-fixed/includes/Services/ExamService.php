<?php

namespace EduCBTPro\Services;

use EduCBTPro\Core\EventDispatcher;
use EduCBTPro\Core\Repository\ExamRepository;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ExamService {
    private ExamRepository $repository;

    public function __construct( ?ExamRepository $repository = null ) {
        $this->repository = $repository ?? new ExamRepository();
    }

    public function list_exams( int $school_id ): array {
        return $this->repository->get_all_exams( $school_id );
    }

    public function create_exam( int $school_id, array $data ): int {
        $id = $this->repository->create_exam( $school_id, $data );
        if ( $id > 0 ) {
            EventDispatcher::action( 'exam_created', [
                'school_id' => $school_id,
                'exam_id'   => $id,
                'data'      => $data,
            ] );
        }
        return $id;
    }

    public function assign_questions( int $school_id, int $exam_id, array $question_ids ): int {
        return $this->repository->assign_questions( $school_id, $exam_id, $question_ids );
    }

    public function list_exam_questions( int $school_id, int $exam_id ): array {
        $questions = $this->repository->get_exam_questions( $school_id, $exam_id );
        $questions = EventDispatcher::filter( 'exam_questions', $questions, [
            'school_id' => $school_id,
            'exam_id'   => $exam_id,
        ] );

        if ( ! is_array( $questions ) ) {
            return [];
        }

        return $questions;
    }

    public function get_exam( int $school_id, int $exam_id ): ?array {
        if ( $school_id <= 0 || $exam_id <= 0 ) {
            return null;
        }

        $exam = $this->repository->get_exam( $exam_id );
        if ( ! is_array( $exam ) ) {
            return null;
        }

        if ( absint( $exam['school_id'] ?? 0 ) !== $school_id ) {
            return null;
        }

        return $exam;
    }
}
