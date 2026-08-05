<?php

namespace EduCBTPro\Services;

use EduCBTPro\Core\Repository\ExamTimetableRepository;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ExamTimetableService {
    private ExamTimetableRepository $repository;
    private const MAX_SUBJECTS_PER_DAY = 3;

    public function __construct( ?ExamTimetableRepository $repository = null ) {
        $this->repository = $repository ?? new ExamTimetableRepository();
    }

    public function list_timetables( int $school_id ): array {
        return $this->repository->get_all_timetables( $school_id );
    }

    public function create_timetable( int $school_id, array $data ): int {
        if ( $school_id <= 0 || empty( $data['exam_id'] ) ) {
            return 0;
        }

        $class_name = sanitize_text_field( (string) ( $data['class_name'] ?? '' ) );
        $department = sanitize_text_field( (string) ( $data['department'] ?? '' ) );
        $subject = sanitize_text_field( (string) ( $data['subject'] ?? '' ) );

        if ( $class_name === '' || $department === '' || $subject === '' ) {
            return 0;
        }

        $constraint = $this->validate_daily_subject_constraints( $school_id, $data );
        if ( ! $constraint['success'] ) {
            return 0;
        }

        return $this->repository->create_timetable( $school_id, $data );
    }

    public function validate_daily_subject_constraints( int $school_id, array $data ): array {
        $class_name = sanitize_text_field( (string) ( $data['class_name'] ?? '' ) );
        $department = sanitize_text_field( (string) ( $data['department'] ?? '' ) );
        $subject = sanitize_text_field( (string) ( $data['subject'] ?? '' ) );
        $exam_date = sanitize_text_field( (string) ( $data['exam_date'] ?? '' ) );

        if ( $class_name === '' || $department === '' || $subject === '' || $exam_date === '' ) {
            return [ 'success' => false, 'message' => 'class_department_subject_exam_date_required' ];
        }

        $subject_already_scheduled = $this->repository->is_subject_scheduled_for_day_scope( $school_id, $class_name, $department, $exam_date, $subject );
        $distinct_subject_count = $this->repository->count_distinct_subjects_for_day_scope( $school_id, $class_name, $department, $exam_date );

        if ( ! $subject_already_scheduled && $distinct_subject_count >= self::MAX_SUBJECTS_PER_DAY ) {
            return [ 'success' => false, 'message' => 'max_three_subjects_per_day_for_class_department' ];
        }

        return [ 'success' => true, 'message' => '' ];
    }

    public function get_exam_timetable( int $school_id, int $exam_id ): ?array {
        return $this->repository->get_exam_timetable( $school_id, $exam_id );
    }

    public function filter_exams_for_student( array $exams, int $school_id, array $student ): array {
        $class_name = strtolower( (string) ( $student['class'] ?? '' ) );
        $arm = strtolower( (string) ( $student['arm'] ?? '' ) );
        $department = strtolower( (string) ( $student['department'] ?? '' ) );
        $session_year = strtolower( (string) ( $student['session_year'] ?? '' ) );
        $subject_bundle = $this->normalize_subject_bundle( $student['subject_bundle'] ?? [] );
        $now = current_time( 'timestamp' );

        $visible = [];

        foreach ( $exams as $exam ) {
            if ( ! is_array( $exam ) ) {
                continue;
            }

            $exam_id = absint( $exam['id'] ?? 0 );
            if ( $exam_id <= 0 ) {
                continue;
            }

            $timetable = $this->repository->get_exam_timetable( $school_id, $exam_id );
            if ( ! $timetable ) {
                // Backward-compatible fallback: show published exam even if timetable is not yet configured.
                $exam['is_trial_mode'] = false;
                $exam['is_active_window'] = false;
                $visible[] = $exam;
                continue;
            }

            if ( ! $this->student_matches_timetable( $timetable, $class_name, $arm, $department, $session_year, $subject_bundle ) ) {
                continue;
            }

            $exam['timetable'] = $timetable;
            $exam['is_trial_mode'] = (bool) ( $timetable['is_trial_mode'] ?? 0 );
            $exam['is_active_window'] = $this->is_active_window( $timetable, $now );

            $visible[] = $exam;
        }

        usort(
            $visible,
            static function ( array $left, array $right ): int {
                if ( (bool) ( $left['is_active_window'] ?? false ) !== (bool) ( $right['is_active_window'] ?? false ) ) {
                    return ( $left['is_active_window'] ?? false ) ? -1 : 1;
                }

                if ( (bool) ( $left['is_trial_mode'] ?? false ) !== (bool) ( $right['is_trial_mode'] ?? false ) ) {
                    return ( $left['is_trial_mode'] ?? false ) ? 1 : -1;
                }

                $left_start = strtotime( (string) ( $left['start_time'] ?? '' ) ) ?: 0;
                $right_start = strtotime( (string) ( $right['start_time'] ?? '' ) ) ?: 0;

                return $left_start <=> $right_start;
            }
        );

        return $visible;
    }

    public function count_scheduled_subjects_for_student_on_date( int $school_id, array $student, string $exam_date ): int {
        if ( $school_id <= 0 || trim( $exam_date ) === '' ) {
            return 0;
        }

        $exam_date = sanitize_text_field( $exam_date );
        $class_name = strtolower( (string) ( $student['class'] ?? '' ) );
        $arm = strtolower( (string) ( $student['arm'] ?? '' ) );
        $department = strtolower( (string) ( $student['department'] ?? '' ) );
        $session_year = strtolower( (string) ( $student['session_year'] ?? '' ) );
        $subject_bundle = $this->normalize_subject_bundle( $student['subject_bundle'] ?? [] );

        $subjects = [];
        $timetables = $this->repository->get_all_timetables( $school_id );
        foreach ( $timetables as $timetable ) {
            if ( ! is_array( $timetable ) ) {
                continue;
            }

            $tt_date = sanitize_text_field( (string) ( $timetable['exam_date'] ?? '' ) );
            if ( $tt_date !== $exam_date ) {
                continue;
            }

            if ( ! $this->student_matches_timetable( $timetable, $class_name, $arm, $department, $session_year, $subject_bundle ) ) {
                continue;
            }

            $subject = strtolower( trim( (string) ( $timetable['subject'] ?? '' ) ) );
            if ( $subject !== '' ) {
                $subjects[ $subject ] = true;
            }
        }

        return count( $subjects );
    }

    private function student_matches_timetable( array $timetable, string $class_name, string $arm, string $department, string $session_year, array $subject_bundle ): bool {
        $tt_class = strtolower( (string) ( $timetable['class_name'] ?? '' ) );
        $tt_arm = strtolower( (string) ( $timetable['arm'] ?? '' ) );
        $tt_department = strtolower( (string) ( $timetable['department'] ?? '' ) );
        $tt_subject = strtolower( trim( (string) ( $timetable['subject'] ?? '' ) ) );
        $tt_session = strtolower( (string) ( $timetable['session_year'] ?? '' ) );

        if ( $tt_class !== '' && $class_name !== '' && $tt_class !== $class_name ) {
            return false;
        }

        if ( $tt_arm !== '' && $arm !== '' && $tt_arm !== $arm ) {
            return false;
        }

        if ( $tt_department !== '' && $department !== '' && $tt_department !== $department ) {
            return false;
        }

        if ( $tt_subject === '' ) {
            return false;
        }

        if ( ! empty( $subject_bundle ) && ! in_array( $tt_subject, $subject_bundle, true ) ) {
            return false;
        }

        if ( $tt_session !== '' && $session_year !== '' && $tt_session !== $session_year ) {
            return false;
        }

        return true;
    }

    private function normalize_subject_bundle( $raw ): array {
        if ( is_string( $raw ) ) {
            $decoded = json_decode( $raw, true );
            if ( is_array( $decoded ) ) {
                $raw = $decoded;
            }
        }

        if ( ! is_array( $raw ) ) {
            return [];
        }

        return array_values(
            array_unique(
                array_filter(
                    array_map(
                        static fn( $subject ): string => strtolower( trim( sanitize_text_field( (string) $subject ) ) ),
                        $raw
                    ),
                    static fn( string $subject ): bool => $subject !== ''
                )
            )
        );
    }

    private function is_active_window( array $timetable, int $now ): bool {
        $exam_date = trim( (string) ( $timetable['exam_date'] ?? '' ) );
        $start_time = trim( (string) ( $timetable['start_time'] ?? '' ) );
        $end_time = trim( (string) ( $timetable['end_time'] ?? '' ) );

        if ( $exam_date === '' || $start_time === '' || $end_time === '' ) {
            return false;
        }

        $start_ts = strtotime( $exam_date . ' ' . $start_time );
        $end_ts = strtotime( $exam_date . ' ' . $end_time );

        if ( ! $start_ts || ! $end_ts ) {
            return false;
        }

        return $now >= $start_ts && $now <= $end_ts;
    }
}
