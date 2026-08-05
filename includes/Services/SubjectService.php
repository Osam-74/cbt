<?php

namespace EduCBTPro\Services;

use EduCBTPro\Core\Repository\SubjectRepository;
use EduCBTPro\Core\Repository\SchoolRepository;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SubjectService {
    private SubjectRepository $repository;
    private SchoolRepository $school_repository;

    private const DEFAULT_SUBJECTS = [
        'English Language',
        'Mathematics',
        'Biology',
        'Chemistry',
        'Physics',
        'Agricultural Science',
        'Further Mathematics',
        'Economics',
        'Commerce',
        'Financial Accounting',
        'Government',
        'Literature in English',
        'Geography',
        'Civic Education',
        'Christian Religious Studies',
        'Islamic Religious Studies',
        'History',
        'Technical Drawing',
        'Computer Studies',
        'Data Processing',
    ];

    private const JSS_COMPULSORY_SUBJECTS = [
        'English Language',
        'Mathematics',
        'Basic Science',
        'Basic Technology',
        'Social Studies',
        'Business Studies',
        'Civic Education',
        'Christian Religious Studies',
        'Islamic Religious Studies',
        'Agricultural Science',
        'Computer Studies',
    ];

    private const DEPARTMENT_SUBJECTS = [
        'Science' => [
            'Mathematics',
            'English Language',
            'Physics',
            'Chemistry',
            'Biology',
            'Further Mathematics',
            'Agricultural Science',
            'Geography',
            'Civic Education',
        ],
        'Commercial' => [
            'Mathematics',
            'English Language',
            'Economics',
            'Commerce',
            'Financial Accounting',
            'Government',
            'Civic Education',
        ],
        'Arts' => [
            'Mathematics',
            'English Language',
            'Literature in English',
            'Government',
            'History',
            'Christian Religious Studies',
            'Islamic Religious Studies',
            'Geography',
            'Civic Education',
        ],
    ];

    private const SUBJECT_ALIASES = [
        'eng' => 'English Language',
        'english' => 'English Language',
        'eng lang' => 'English Language',
        'english studies' => 'English Language',
        'math' => 'Mathematics',
        'maths' => 'Mathematics',
        'mathematics core' => 'Mathematics',
        'agric' => 'Agricultural Science',
        'agricultural sci' => 'Agricultural Science',
        'agricultural science' => 'Agricultural Science',
        'further maths' => 'Further Mathematics',
        'further math' => 'Further Mathematics',
        'lit in english' => 'Literature in English',
        'literature' => 'Literature in English',
        'crs' => 'Christian Religious Studies',
        'christian religious education' => 'Christian Religious Studies',
        'irs' => 'Islamic Religious Studies',
        'islamic studies' => 'Islamic Religious Studies',
        'civic' => 'Civic Education',
        'commerce studies' => 'Commerce',
        'accounting' => 'Financial Accounting',
        'computer' => 'Computer Studies',
        'computer science' => 'Computer Studies',
        'data processing' => 'Data Processing',
    ];

    public function __construct() {
        $this->repository = new SubjectRepository();
        $this->school_repository = new SchoolRepository();
    }

    public function list_subjects( int $school_id ): array {
        return $this->repository->get_all_subjects( $school_id );
    }

    public function create_subject( int $school_id, array $data ): int {
        $subject_name = $this->canonicalize_subject_name( $school_id, (string) ( $data['subject_name'] ?? '' ) );
        if ( $subject_name === '' ) {
            return 0;
        }

        $data['subject_name'] = $subject_name;

        if ( empty( $data['subject_code'] ) ) {
            $data['subject_code'] = $this->generate_subject_code( $school_id, $subject_name );
        }

        return $this->repository->create_subject( $school_id, $data );
    }

    public function seed_default_subjects( int $school_id ): int {
        if ( $school_id <= 0 ) {
            return 0;
        }

        $inserted = 0;
        foreach ( self::DEFAULT_SUBJECTS as $subject_name ) {
            $id = $this->create_subject(
                $school_id,
                [
                    'subject_name' => $subject_name,
                    'subject_code' => $this->generate_subject_code( $school_id, $subject_name ),
                    'subject_type' => 'core',
                ]
            );
            if ( $id > 0 ) {
                $inserted++;
            }
        }

        return $inserted;
    }

    public function list_default_subjects(): array {
        return self::DEFAULT_SUBJECTS;
    }

    public function list_jss_compulsory_subjects(): array {
        return self::JSS_COMPULSORY_SUBJECTS;
    }

    public function list_department_subject_map(): array {
        return self::DEPARTMENT_SUBJECTS;
    }

    public function ensure_subject_catalog( int $school_id, array $subject_names ): int {
        if ( $school_id <= 0 || empty( $subject_names ) ) {
            return 0;
        }

        $ensured = 0;
        foreach ( $subject_names as $subject_name ) {
            $subject_name = sanitize_text_field( (string) $subject_name );
            if ( $subject_name === '' ) {
                continue;
            }

            $id = $this->create_subject(
                $school_id,
                [
                    'subject_name' => $subject_name,
                    'subject_code' => $this->generate_subject_code( $school_id, $subject_name ),
                    'subject_type' => 'core',
                ]
            );

            if ( $id > 0 ) {
                $ensured++;
            }
        }

        return $ensured;
    }

    public function canonicalize_subject_name( int $school_id, string $subject_name ): string {
        $normalized = $this->normalize_subject_name( $subject_name );
        if ( $normalized === '' ) {
            return '';
        }

        $alias_map = $this->get_subject_alias_map( $school_id );
        $alias_key = $this->normalize_subject_key( $normalized );
        if ( isset( $alias_map[ $alias_key ] ) ) {
            return sanitize_text_field( (string) $alias_map[ $alias_key ] );
        }

        $repository_match = $this->repository->find_existing_subject_id( $school_id, $normalized );
        if ( $repository_match > 0 ) {
            $subjects = $this->repository->get_all_subjects( $school_id );
            foreach ( $subjects as $subject ) {
                if ( absint( $subject['id'] ?? 0 ) === $repository_match ) {
                    return sanitize_text_field( (string) ( $subject['subject_name'] ?? $normalized ) );
                }
            }
        }

        return $normalized;
    }

    public function normalize_subject_name( string $subject_name ): string {
        $subject_name = sanitize_text_field( $subject_name );
        if ( $subject_name === '' ) {
            return '';
        }

        $subject_name = preg_replace( '/[[:punct:]]+/u', ' ', $subject_name );
        $subject_name = preg_replace( '/\s+/u', ' ', trim( $subject_name ) );
        if ( ! is_string( $subject_name ) || $subject_name === '' ) {
            return '';
        }

        $subject_name = ucwords( strtolower( $subject_name ) );

        $acronym_map = [
            'Crs' => 'CRS',
            'Irs' => 'IRS',
            'Neco' => 'NECO',
            'Waec' => 'WAEC',
        ];

        return str_replace( array_keys( $acronym_map ), array_values( $acronym_map ), $subject_name );
    }

    public function normalize_subject_key( string $subject_name ): string {
        $subject_name = strtolower( $this->normalize_subject_name( $subject_name ) );
        $subject_name = preg_replace( '/[^a-z0-9]+/u', '', $subject_name );

        return is_string( $subject_name ) ? $subject_name : '';
    }

    public function get_subject_alias_map( int $school_id ): array {
        if ( $school_id <= 0 ) {
            return self::SUBJECT_ALIASES;
        }

        $school = $this->school_repository->get_school_by_id( $school_id );
        $academic_settings = is_object( $school ) ? json_decode( (string) ( $school->academic_settings ?? '' ), true ) : [];
        $settings_map = is_array( $academic_settings ) && isset( $academic_settings['subject_aliases'] ) && is_array( $academic_settings['subject_aliases'] )
            ? $academic_settings['subject_aliases']
            : [];
        $merged = self::SUBJECT_ALIASES;

        foreach ( $settings_map as $alias => $canonical ) {
            $alias_key = $this->normalize_subject_key( (string) $alias );
            $canonical_name = $this->normalize_subject_name( (string) $canonical );
            if ( $alias_key === '' || $canonical_name === '' ) {
                continue;
            }

            $merged[ $alias_key ] = $canonical_name;
        }

        return $merged;
    }

    public function save_subject_alias_map( int $school_id, array $alias_map ): bool {
        if ( $school_id <= 0 ) {
            return false;
        }

        $sanitized = [];
        foreach ( $alias_map as $alias => $canonical ) {
            $alias_key = $this->normalize_subject_key( (string) $alias );
            $canonical_name = $this->normalize_subject_name( (string) $canonical );
            if ( $alias_key === '' || $canonical_name === '' ) {
                continue;
            }

            $sanitized[ $alias_key ] = $canonical_name;
        }

        $school = $this->school_repository->get_school_by_id( $school_id );
        if ( ! $school ) {
            return false;
        }

        $academic_settings = json_decode( (string) ( $school->academic_settings ?? '' ), true );
        if ( ! is_array( $academic_settings ) ) {
            $academic_settings = [];
        }

        $academic_settings['subject_aliases'] = $sanitized;

        return $this->school_repository->update_academic_settings( $school_id, $academic_settings );
    }

    public function suggest_subject_aliases( int $school_id, array $submitted_subjects ): array {
        $submitted_subjects = array_values( array_unique( array_filter( array_map( 'strval', $submitted_subjects ) ) ) );
        if ( empty( $submitted_subjects ) ) {
            return [];
        }

        $known_subjects = [];
        foreach ( $this->repository->get_all_subjects( $school_id ) as $subject ) {
            $name = $this->normalize_subject_name( (string) ( $subject['subject_name'] ?? '' ) );
            if ( $name !== '' ) {
                $known_subjects[] = $name;
            }
        }

        $suggestions = [];
        foreach ( $submitted_subjects as $submitted ) {
            $normalized = $this->normalize_subject_name( (string) $submitted );
            if ( $normalized === '' ) {
                continue;
            }

            if ( in_array( $normalized, $known_subjects, true ) ) {
                continue;
            }

            $best_subject = '';
            $best_score = 0;
            foreach ( $known_subjects as $known ) {
                similar_text( strtolower( $normalized ), strtolower( $known ), $score );
                if ( $score > $best_score ) {
                    $best_score = $score;
                    $best_subject = $known;
                }
            }

            if ( $best_subject !== '' && $best_score >= 65 ) {
                $suggestions[ $normalized ] = $best_subject;
            }
        }

        return $suggestions;
    }

    private function generate_subject_code( int $school_id, string $subject_name ): string {
        $letters = preg_replace( '/[^A-Z]/', '', strtoupper( $subject_name ) );
        $letters = substr( $letters, 0, 4 );
        if ( $letters === '' ) {
            $letters = 'SUBJ';
        }

        return sprintf( 'S%03d-%s', $school_id, $letters );
    }
}
