<?php

namespace EduCBTPro\Core\Repository;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ExamTimetableRepository {
    public function get_all_timetables( int $school_id ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'educbt_exam_timetables';

        return $wpdb->get_results(
            $wpdb->prepare( "SELECT * FROM {$table} WHERE school_id = %d ORDER BY exam_date ASC, start_time ASC", $school_id ),
            ARRAY_A
        ) ?: [];
    }

    public function create_timetable( int $school_id, array $data ): int {
        global $wpdb;
        $table = $wpdb->prefix . 'educbt_exam_timetables';

        $wpdb->insert(
            $table,
            [
                'school_id'        => $school_id,
                'exam_id'          => absint( $data['exam_id'] ?? 0 ),
                'session_year'     => sanitize_text_field( $data['session_year'] ?? '' ),
                'term'             => sanitize_text_field( $data['term'] ?? '' ),
                'class_name'       => sanitize_text_field( $data['class_name'] ?? '' ),
                'arm'              => sanitize_text_field( $data['arm'] ?? '' ),
                'department'       => sanitize_text_field( $data['department'] ?? '' ),
                'subject'          => sanitize_text_field( $data['subject'] ?? '' ),
                'exam_type'        => sanitize_text_field( $data['exam_type'] ?? '' ),
                'exam_date'        => sanitize_text_field( $data['exam_date'] ?? '' ),
                'start_time'       => sanitize_text_field( $data['start_time'] ?? '' ),
                'end_time'         => sanitize_text_field( $data['end_time'] ?? '' ),
                'duration_minutes' => absint( $data['duration_minutes'] ?? 0 ),
                'venue'            => sanitize_text_field( $data['venue'] ?? '' ),
                'invigilator'      => sanitize_text_field( $data['invigilator'] ?? '' ),
                'is_trial_mode'    => ! empty( $data['is_trial_mode'] ) ? 1 : 0,
                'status'           => sanitize_text_field( $data['status'] ?? 'scheduled' ),
            ],
            [ '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%s' ]
        );

        return (int) $wpdb->insert_id;
    }

    public function get_exam_timetable( int $school_id, int $exam_id ): ?array {
        global $wpdb;
        $table = $wpdb->prefix . 'educbt_exam_timetables';

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE school_id = %d AND exam_id = %d ORDER BY id DESC LIMIT 1",
                $school_id,
                $exam_id
            ),
            ARRAY_A
        );

        return is_array( $row ) ? $row : null;
    }

    public function count_distinct_subjects_for_day_scope( int $school_id, string $class_name, string $department, string $exam_date ): int {
        global $wpdb;
        $table = $wpdb->prefix . 'educbt_exam_timetables';

        $count = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(DISTINCT subject) FROM {$table} WHERE school_id = %d AND class_name = %s AND department = %s AND exam_date = %s",
                $school_id,
                sanitize_text_field( $class_name ),
                sanitize_text_field( $department ),
                sanitize_text_field( $exam_date )
            )
        );

        return absint( $count );
    }

    public function is_subject_scheduled_for_day_scope( int $school_id, string $class_name, string $department, string $exam_date, string $subject ): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'educbt_exam_timetables';

        $id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$table} WHERE school_id = %d AND class_name = %s AND department = %s AND exam_date = %s AND subject = %s LIMIT 1",
                $school_id,
                sanitize_text_field( $class_name ),
                sanitize_text_field( $department ),
                sanitize_text_field( $exam_date ),
                sanitize_text_field( $subject )
            )
        );

        return absint( $id ) > 0;
    }
}
