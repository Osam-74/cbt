<?php

namespace EduCBTPro\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * SRS §29 – Event & Extensibility System.
 *
 * A thin wrapper around WordPress's do_action / apply_filters that:
 *  1. Gives every plugin event a namespaced slug (educbt_*).
 *  2. Provides a typed registry of all platform events for documentation.
 *  3. Allows code outside WordPress (e.g. CLI, tests) to fire events via
 *     polyfilled stubs.
 *
 * Usage (service code):
 *   EventDispatcher::action( 'student_created', [ 'school_id' => 1, 'student_id' => 42 ] );
 *   $value = EventDispatcher::filter( 'grade_computed', $grade, [ 'score' => 88 ] );
 */
class EventDispatcher {
    /** Canonical action event names emitted by the platform. */
    public const ACTIONS = [
        'student_created'    => 'educbt_student_created',
        'student_updated'    => 'educbt_student_updated',
        'student_promoted'   => 'educbt_student_promoted',
        'teacher_created'    => 'educbt_teacher_created',
        'exam_created'       => 'educbt_exam_created',
        'exam_started'       => 'educbt_exam_started',
        'exam_submitted'     => 'educbt_exam_submitted',
        'result_created'     => 'educbt_result_created',
        'result_published'   => 'educbt_result_published',
        'result_status_changed' => 'educbt_result_status_changed',
        'ca_computed'        => 'educbt_ca_computed',
        'audit_log_created'  => 'educbt_audit_log_created',
        'license_activated'  => 'educbt_license_activated',
        'backup_completed'   => 'educbt_backup_completed',
        'notification_sent'  => 'educbt_notification_sent',
        'theory_marked'      => 'educbt_theory_marked',
        'session_abuse_detected' => 'educbt_session_abuse_detected',
    ];

    /** Canonical filter names exposed by the platform. */
    public const FILTERS = [
        'grade_computed'     => 'educbt_grade_computed',
        'result_data'        => 'educbt_result_data',
        'exam_questions'     => 'educbt_exam_questions',
        'student_data'       => 'educbt_student_data',
        'report_card_data'   => 'educbt_report_card_data',
        'broadsheet_row'     => 'educbt_broadsheet_row',
        'ca_weights'         => 'educbt_ca_weights',
    ];

    /**
     * Fire a namespaced action hook.
     *
     * @param string $event_key  Key from self::ACTIONS or a raw hook name.
     * @param mixed  ...$args    Arguments forwarded to do_action.
     */
    public static function action( string $event_key, mixed ...$args ): void {
        $hook = self::ACTIONS[ $event_key ] ?? $event_key;
        if ( function_exists( 'do_action' ) ) {
            do_action( $hook, ...$args );
        }
    }

    /**
     * Apply a namespaced filter hook.
     *
     * @param string $filter_key  Key from self::FILTERS or a raw hook name.
     * @param mixed  $value       The value to filter.
     * @param mixed  ...$args     Extra context args forwarded to apply_filters.
     * @return mixed Filtered value.
     */
    public static function filter( string $filter_key, mixed $value, mixed ...$args ): mixed {
        $hook = self::FILTERS[ $filter_key ] ?? $filter_key;
        if ( function_exists( 'apply_filters' ) ) {
            return apply_filters( $hook, $value, ...$args );
        }
        return $value;
    }

    /**
     * Convenience: register a callback on a platform action.
     * Delegates to add_action when WordPress is loaded.
     */
    public static function on( string $event_key, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
        $hook = self::ACTIONS[ $event_key ] ?? self::FILTERS[ $event_key ] ?? $event_key;
        if ( function_exists( 'add_action' ) ) {
            add_action( $hook, $callback, $priority, $accepted_args );
        }
    }

    /**
     * Convenience: register a callback on a platform filter.
     */
    public static function on_filter( string $filter_key, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
        $hook = self::FILTERS[ $filter_key ] ?? $filter_key;
        if ( function_exists( 'add_filter' ) ) {
            add_filter( $hook, $callback, $priority, $accepted_args );
        }
    }
}
