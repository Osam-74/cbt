<?php

namespace EduCBTPro\Services;

use EduCBTPro\Core\EventDispatcher;
use EduCBTPro\Core\Repository\SchoolRepository;
use EduCBTPro\Core\Repository\StudentRepository;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class StudentService {
    private StudentRepository $repository;
    private SubjectService $subject_service;
    private SchoolRepository $school_repository;

    public function __construct( ?StudentRepository $repository = null ) {
        $this->repository = $repository ?? new StudentRepository();
        $this->subject_service = new SubjectService();
        $this->school_repository = new SchoolRepository();
    }

    public function list_students( int $school_id ): array {
        return $this->repository->get_all_students( $school_id );
    }

    public function create_student( int $school_id, array $data ): int {
        $data = EventDispatcher::filter( 'student_data', $data, [ 'school_id' => $school_id ] );
        if ( ! is_array( $data ) ) {
            $data = [];
        }

        $data = $this->normalize_student_data( $school_id, $data );
        if ( $data['full_name'] === '' ) {
            return 0;
        }

        $account = $this->provision_student_account( $data );
        $data['wp_user_id'] = $account['wp_user_id'];
        $data['login_username'] = $account['login_username'];
        $data['subject_bundle'] = $this->resolve_subject_bundle( $school_id, $data );

        $id = $this->repository->create_student( $school_id, $data );

        if ( $id > 0 ) {
            if ( $data['wp_user_id'] > 0 ) {
                update_user_meta( $data['wp_user_id'], 'educbt_school_id', $school_id );
                update_user_meta( $data['wp_user_id'], 'educbt_student_record_id', $id );
            }

            EventDispatcher::action( 'student_created', [
                'school_id'  => $school_id,
                'student_id' => $id,
                'data'       => $data,
            ] );
        }

        return $id;
    }

    public function update_student( int $school_id, int $student_id, array $data ): bool {
        $updated = $this->repository->update_student( $school_id, $student_id, $data );

        if ( $updated ) {
            EventDispatcher::action( 'student_updated', [
                'school_id'  => $school_id,
                'student_id' => $student_id,
                'data'       => $data,
            ] );
        }

        return $updated;
    }

    private function normalize_student_data( int $school_id, array $data ): array {
        $first_name = sanitize_text_field( $data['first_name'] ?? '' );
        $last_name = sanitize_text_field( $data['last_name'] ?? '' );
        $full_name = sanitize_text_field( $data['full_name'] ?? '' );
        $class_name = sanitize_text_field( $data['class'] ?? '' );
        $session_year = sanitize_text_field( $data['session_year'] ?? '' );

        if ( $full_name === '' ) {
            $full_name = trim( $first_name . ' ' . $last_name );
        }

        $registration_number = $this->generate_registration_number( $school_id, $class_name, $session_year );

        $student_id = sanitize_text_field( $data['student_id'] ?? '' );
        if ( $student_id === '' ) {
            $student_id = strtoupper( 'STU-' . wp_generate_password( 6, false, false ) );
        }

        return [
            'admission_number'   => $registration_number,
            'registration_number'=> $registration_number,
            'student_id'         => $student_id,
            'wp_user_id'         => absint( $data['wp_user_id'] ?? 0 ),
            'login_username'     => sanitize_user( $data['login_username'] ?? '' ),
            'temporary_password' => (string) ( $data['temporary_password'] ?? '' ),
            'passport_photo'     => sanitize_text_field( $data['passport_photo'] ?? '' ),
            'first_name'         => $first_name,
            'last_name'          => $last_name,
            'full_name'          => $full_name,
            'gender'             => sanitize_text_field( $data['gender'] ?? '' ),
            'date_of_birth'      => sanitize_text_field( $data['date_of_birth'] ?? '' ),
            'parent_information' => sanitize_textarea_field( $data['parent_information'] ?? '' ),
            'parent_phone'       => sanitize_text_field( $data['parent_phone'] ?? '' ),
            'parent_email'       => sanitize_email( $data['parent_email'] ?? '' ),
            'address'            => sanitize_textarea_field( $data['address'] ?? '' ),
            'class'              => sanitize_text_field( $data['class'] ?? '' ),
            'arm'                => sanitize_text_field( $data['arm'] ?? '' ),
            'department'         => sanitize_text_field( $data['department'] ?? '' ),
            'session_year'       => sanitize_text_field( $data['session_year'] ?? '' ),
            'status'             => sanitize_text_field( $data['status'] ?? 'active' ),
            'subject_bundle'     => isset( $data['subject_bundle'] ) ? (array) $data['subject_bundle'] : [],
            'manual_subjects'    => isset( $data['manual_subjects'] ) ? (array) $data['manual_subjects'] : [],
        ];
    }

    private function generate_registration_number( int $school_id, string $class_name = '', string $session_year = '' ): string {
        $school = $this->school_repository->get_school_by_id( $school_id );
        $settings = is_object( $school ) && ! empty( $school->academic_settings )
            ? json_decode( (string) $school->academic_settings, true )
            : [];

        $format = is_array( $settings ) ? (string) ( $settings['registration_number_format'] ?? '' ) : '';
        if ( $format === '' ) {
            $format = '{school_code}-{year}-{class}-{sequence}';
        }

        $school_code = is_object( $school ) && ! empty( $school->school_code ) ? strtoupper( sanitize_text_field( (string) $school->school_code ) ) : 'SCH';

        $year = '';
        if ( $session_year !== '' && preg_match( '/\d{4}/', $session_year, $matches ) ) {
            $year = $matches[0];
        }
        if ( $year === '' ) {
            $year = gmdate( 'Y' );
        }

        $class_token = strtoupper( preg_replace( '/[^A-Z0-9]/', '', $class_name ) );
        if ( $class_token === '' ) {
            $class_token = 'STUDENT';
        }

        $sequence = 1;
        do {
            $candidate = strtr(
                $format,
                [
                    '{school_code}' => $school_code,
                    '{year}'        => $year,
                    '{class}'       => $class_token,
                    '{sequence}'    => str_pad( (string) $sequence, 6, '0', STR_PAD_LEFT ),
                ]
            );
            $candidate = strtoupper( preg_replace( '/\s+/', '', (string) $candidate ) );
            $sequence++;
        } while ( $candidate === '' || $this->repository->registration_number_exists( $candidate ) );

        return $candidate;
    }

    /**
     * @return array{wp_user_id:int,login_username:string}
     */
    private function provision_student_account( array $data ): array {
        $existing_user_id = absint( $data['wp_user_id'] ?? 0 );
        $username = sanitize_user( (string) ( $data['login_username'] ?? '' ) );
        $username_source = $username !== '' ? $username : ( $data['registration_number'] ?? $data['admission_number'] ?? '' );

        if ( $username_source === '' ) {
            $username_source = $data['student_id'] ?? $data['full_name'] ?? 'student';
        }

        $username = sanitize_user( (string) $username_source, true );
        if ( $username === '' ) {
            $username = 'student' . wp_rand( 1000, 9999 );
        }

        if ( $existing_user_id > 0 ) {
            $user = get_userdata( $existing_user_id );
            if ( $user instanceof \WP_User ) {
                $user->set_role( 'educbt_student' );

                return [
                    'wp_user_id' => $existing_user_id,
                    'login_username' => $user->user_login,
                ];
            }
        }

        $user_with_username = get_user_by( 'login', $username );
        if ( $user_with_username instanceof \WP_User ) {
            $user_with_username->set_role( 'educbt_student' );

            return [
                'wp_user_id' => (int) $user_with_username->ID,
                'login_username' => $user_with_username->user_login,
            ];
        }

        $base_username = $username;
        $suffix = 1;
        while ( username_exists( $username ) ) {
            $username = $base_username . $suffix;
            $suffix++;
        }

        $email = sanitize_email( $data['parent_email'] ?? '' );
        if ( $email === '' || email_exists( $email ) ) {
            $email = $username . '@local.educbt';
        }

        $password = (string) ( $data['temporary_password'] ?? '' );
        if ( $password === '' ) {
            $password = wp_generate_password( 12, true, true );
        }

        $user_id = wp_create_user( $username, $password, $email );
        if ( is_wp_error( $user_id ) || $user_id <= 0 ) {
            return [
                'wp_user_id' => 0,
                'login_username' => '',
            ];
        }

        $wp_user = get_user_by( 'id', (int) $user_id );
        if ( $wp_user instanceof \WP_User ) {
            $wp_user->set_role( 'educbt_student' );
            wp_update_user(
                [
                    'ID' => (int) $user_id,
                    'display_name' => sanitize_text_field( (string) ( $data['full_name'] ?? $username ) ),
                    'first_name' => sanitize_text_field( (string) ( $data['first_name'] ?? '' ) ),
                    'last_name' => sanitize_text_field( (string) ( $data['last_name'] ?? '' ) ),
                ]
            );
        }

        return [
            'wp_user_id' => (int) $user_id,
            'login_username' => $username,
        ];
    }

    private function resolve_subject_bundle( int $school_id, array $data ): array {
        $manual_subjects = array_values( array_filter( array_map( 'sanitize_text_field', (array) ( $data['manual_subjects'] ?? [] ) ) ) );
        if ( ! empty( $manual_subjects ) ) {
            $this->subject_service->ensure_subject_catalog( $school_id, $manual_subjects );
            return $manual_subjects;
        }

        $class_name = strtoupper( (string) ( $data['class'] ?? '' ) );
        $department = ucfirst( strtolower( (string) ( $data['department'] ?? '' ) ) );

        if ( str_starts_with( $class_name, 'JSS' ) ) {
            $subjects = $this->subject_service->list_jss_compulsory_subjects();
            $this->subject_service->ensure_subject_catalog( $school_id, $subjects );
            return $subjects;
        }

        $department_map = $this->subject_service->list_department_subject_map();
        if ( isset( $department_map[ $department ] ) ) {
            $subjects = array_values( array_filter( array_map( 'sanitize_text_field', (array) $department_map[ $department ] ) ) );
            $this->subject_service->ensure_subject_catalog( $school_id, $subjects );
            return $subjects;
        }

        $school = $this->school_repository->get_school_by_id( $school_id );
        if ( $school && ! empty( $school->academic_settings ) ) {
            $settings = json_decode( $school->academic_settings, true );
            if ( is_array( $settings ) && isset( $settings['departments'][ $department ] ) && is_array( $settings['departments'][ $department ] ) ) {
                $subjects = array_values( array_filter( array_map( 'sanitize_text_field', $settings['departments'][ $department ] ) ) );
                $this->subject_service->ensure_subject_catalog( $school_id, $subjects );
                return $subjects;
            }
        }

        return [];
    }
}
