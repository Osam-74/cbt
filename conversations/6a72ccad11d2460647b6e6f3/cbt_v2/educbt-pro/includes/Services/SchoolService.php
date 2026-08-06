<?php

namespace EduCBTPro\Services;

use EduCBTPro\Core\Repository\SchoolRepository;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SchoolService {
    private SchoolRepository $repository;
    private SubjectService $subject_service;
    private ClassService $class_service;

    public function __construct() {
        $this->repository = new SchoolRepository();
        $this->subject_service = new SubjectService();
        $this->class_service = new ClassService();
    }

    public function list_schools(): array {
        return $this->repository->get_all_schools();
    }

    public function get_school_academic_settings( int $school_id ): array {
        $school = $this->repository->get_school_by_id( $school_id );
        if ( ! $school || empty( $school->academic_settings ) ) {
            return $this->default_academic_settings();
        }

        $settings = json_decode( $school->academic_settings, true );
        if ( ! is_array( $settings ) ) {
            return $this->default_academic_settings();
        }

        return wp_parse_args( $settings, $this->default_academic_settings() );
    }

    public function update_department_subject_map( int $school_id, array $department_map ): bool {
        if ( $school_id <= 0 ) {
            return false;
        }

        $settings = $this->get_school_academic_settings( $school_id );
        $sanitized = [];

        foreach ( $department_map as $department => $subjects ) {
            $name = sanitize_text_field( (string) $department );
            if ( $name === '' ) {
                continue;
            }

            $subject_list = array_values( array_filter( array_map( 'sanitize_text_field', (array) $subjects ) ) );
            $sanitized[ $name ] = $subject_list;
            $this->subject_service->ensure_subject_catalog( $school_id, $subject_list );
        }

        if ( empty( $sanitized ) ) {
            return false;
        }

        $settings['departments'] = $sanitized;

        return $this->repository->update_academic_settings( $school_id, $settings );
    }

    public function get_subject_aliases( int $school_id ): array {
        $settings = $this->get_school_academic_settings( $school_id );
        $aliases = isset( $settings['subject_aliases'] ) && is_array( $settings['subject_aliases'] )
            ? $settings['subject_aliases']
            : [];

        $clean = [];
        foreach ( $aliases as $alias => $canonical ) {
            $alias_key = strtolower( sanitize_text_field( (string) $alias ) );
            $canonical_name = sanitize_text_field( (string) $canonical );
            if ( $alias_key === '' || $canonical_name === '' ) {
                continue;
            }

            $clean[ $alias_key ] = $canonical_name;
        }

        return $clean;
    }

    public function update_subject_aliases( int $school_id, array $subject_aliases ): bool {
        if ( $school_id <= 0 ) {
            return false;
        }

        $settings = $this->get_school_academic_settings( $school_id );
        $clean = [];

        foreach ( $subject_aliases as $alias => $canonical ) {
            $alias_key = strtolower( sanitize_text_field( (string) $alias ) );
            $canonical_name = sanitize_text_field( (string) $canonical );
            if ( $alias_key === '' || $canonical_name === '' ) {
                continue;
            }

            $clean[ $alias_key ] = $canonical_name;
        }

        $settings['subject_aliases'] = $clean;
        return $this->repository->update_academic_settings( $school_id, $settings );
    }

    public function get_integrity_monitoring_settings( int $school_id ): array {
        $settings = $this->get_school_academic_settings( $school_id );
        $current = isset( $settings['integrity_monitoring'] ) && is_array( $settings['integrity_monitoring'] )
            ? $settings['integrity_monitoring']
            : [];

        return wp_parse_args( $current, $this->default_integrity_monitoring_settings() );
    }

    public function update_integrity_monitoring_settings( int $school_id, array $integrity_settings ): bool {
        if ( $school_id <= 0 ) {
            return false;
        }

        $settings = $this->get_school_academic_settings( $school_id );
        $defaults = $this->default_integrity_monitoring_settings();

        $settings['integrity_monitoring'] = [
            'blur_threshold' => max( 1, absint( $integrity_settings['blur_threshold'] ?? $defaults['blur_threshold'] ) ),
            'hidden_threshold' => max( 1, absint( $integrity_settings['hidden_threshold'] ?? $defaults['hidden_threshold'] ) ),
            'total_suspicious_threshold' => max( 1, absint( $integrity_settings['total_suspicious_threshold'] ?? $defaults['total_suspicious_threshold'] ) ),
        ];

        return $this->repository->update_academic_settings( $school_id, $settings );
    }

    public function update_school_academic_preferences( int $school_id, array $preferences ): bool {
        if ( $school_id <= 0 ) {
            return false;
        }

        $settings = $this->get_school_academic_settings( $school_id );
        $settings['registration_number_format'] = sanitize_text_field( (string) ( $preferences['registration_number_format'] ?? $settings['registration_number_format'] ?? '{school_code}-{year}-{class}-{sequence}' ) );
        $settings['minimum_questions_per_subject'] = max( 1, absint( $preferences['minimum_questions_per_subject'] ?? $settings['minimum_questions_per_subject'] ?? 20 ) );

        return $this->repository->update_academic_settings( $school_id, $settings );
    }

    public function update_class_subject_allocation( int $school_id, array $allocation ): bool {
        if ( $school_id <= 0 ) {
            return false;
        }

        $settings = $this->get_school_academic_settings( $school_id );

        $class_structure = array_values( array_filter( array_map( static function ( $value ): string {
            $value = strtoupper( sanitize_text_field( (string) $value ) );
            return $value;
        }, (array) ( $allocation['class_structure'] ?? [] ) ) ) );

        $jss_subjects = array_values( array_filter( array_map( 'sanitize_text_field', (array) ( $allocation['jss_compulsory_subjects'] ?? [] ) ) ) );

        if ( ! empty( $class_structure ) ) {
            $settings['class_structure'] = $class_structure;
        }

        if ( ! empty( $jss_subjects ) ) {
            $settings['jss_compulsory_subjects'] = $jss_subjects;
            $this->subject_service->ensure_subject_catalog( $school_id, $jss_subjects );
        }

        return $this->repository->update_academic_settings( $school_id, $settings );
    }

    public function create_school( array $data ): int {
        $school_name = sanitize_text_field( $data['school_name'] ?? '' );
        if ( $school_name === '' ) {
            return 0;
        }

        if ( empty( $data['school_code'] ) ) {
            $data['school_code'] = $this->generate_school_code( $school_name );
        }

        if ( empty( $data['academic_settings'] ) || ! is_array( $data['academic_settings'] ) ) {
            $data['academic_settings'] = $this->default_academic_settings();
        }

        $school_id = $this->repository->create_school( $data );
        if ( $school_id > 0 ) {
            $this->subject_service->seed_default_subjects( $school_id );
            $this->class_service->seed_default_classes( $school_id );

            $department_map = $this->subject_service->list_department_subject_map();
            foreach ( $department_map as $subject_names ) {
                $this->subject_service->ensure_subject_catalog( $school_id, (array) $subject_names );
            }

            $this->subject_service->ensure_subject_catalog( $school_id, $this->subject_service->list_jss_compulsory_subjects() );
        }

        return $school_id;
    }

    private function generate_school_code( string $school_name ): string {
        $letters = preg_replace( '/[^A-Z]/', '', strtoupper( $school_name ) );
        $letters = substr( $letters, 0, 5 );
        if ( $letters === '' ) {
            $letters = 'EDUCB';
        }

        return $letters . '-' . wp_generate_password( 4, false, false );
    }

    private function default_academic_settings(): array {
        return [
            'class_structure' => [ 'JSS1', 'JSS2', 'JSS3', 'SS1', 'SS2', 'SS3' ],
            'arms' => [ 'A' ],
            'departments' => $this->subject_service->list_department_subject_map(),
            'jss_compulsory_subjects' => $this->subject_service->list_jss_compulsory_subjects(),
            'subject_aliases' => [],
            'registration_number_format' => '{school_code}-{year}-{class}-{sequence}',
            'minimum_questions_per_subject' => 20,
            'integrity_monitoring' => $this->default_integrity_monitoring_settings(),
            'exam_prep_enabled' => true,
        ];
    }

    private function default_integrity_monitoring_settings(): array {
        return [
            'blur_threshold' => 3,
            'hidden_threshold' => 3,
            'total_suspicious_threshold' => 4,
        ];
    }
    /**
     * Whether exam prep (question submission) is currently open.
     */
    public function is_exam_prep_enabled( int $school_id ): bool {
        $settings = $this->get_school_academic_settings( $school_id );
        return (bool) ( $settings['exam_prep_enabled'] ?? true );
    }

    /**
     * Toggle exam prep on or off.
     */
    public function set_exam_prep_enabled( int $school_id, bool $enabled ): bool {
        $settings = $this->get_school_academic_settings( $school_id );
        $settings['exam_prep_enabled'] = $enabled;
        return $this->repository->update_academic_settings( $school_id, $settings );
    }

}
