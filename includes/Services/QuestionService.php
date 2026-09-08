<?php

namespace EduCBTPro\Services;

use EduCBTPro\Core\Repository\QuestionRepository;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class QuestionService {
    private QuestionRepository $repository;
    private SubjectService $subject_service;

    public function __construct() {
        $this->repository = new QuestionRepository();
        $this->subject_service = new SubjectService();
    }

    public function list_questions( int $school_id ): array {
        return $this->repository->get_all_questions( $school_id );
    }

    public function create_question( int $school_id, array $data ): int {
        $question_type = strtolower( sanitize_text_field( $data['question_type'] ?? 'objective' ) );
        $options = array_values( array_filter( array_map( 'trim', (array) ( $data['options'] ?? [] ) ) ) );
        $answers = array_values( array_filter( array_map( 'trim', (array) ( $data['answers'] ?? [] ) ) ) );

        $has_payload =
            trim( (string) ( $data['question_text'] ?? '' ) ) !== '' ||
            trim( (string) ( $data['subject'] ?? '' ) ) !== '' ||
            trim( (string) ( $data['topic'] ?? '' ) ) !== '' ||
            ! empty( $options ) ||
            ! empty( $answers );

        if ( ! $has_payload ) {
            return 0;
        }

        if ( $question_type === '' ) {
            $question_type = 'objective';
        }

        $data['subject'] = $this->subject_service->canonicalize_subject_name( $school_id, (string) ( $data['subject'] ?? '' ) );
        $data['question_type'] = $question_type;

        // Default to 'published' so questions appear in exams immediately.
        // Previously defaulted to 'draft' which caused questions to seem missing.
        $data['status'] = sanitize_text_field( $data['status'] ?? 'published' );
        $data['options'] = $options;
        $data['answers'] = $answers;

        return $this->repository->create_question( $school_id, $data );
    }
}
