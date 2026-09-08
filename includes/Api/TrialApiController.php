<?php

namespace EduCBTPro\Api;

use EduCBTPro\Services\TrialExamService;
use EduCBTPro\Data\TrialQuestionSeed;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Public REST API endpoints for the trial/practice test.
 * No authentication required — this is the marketing-site CBT demo.
 */
class TrialApiController {

    public function init(): void {
        add_action( 'rest_api_init', [ $this, 'register_routes' ] );
    }

    public function register_routes(): void {
        register_rest_route( 'educbt/v1', '/trial/subjects', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_subjects' ],
            'permission_callback' => '__return_true',
        ] );

        register_rest_route( 'educbt/v1', '/trial/start', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'start_trial' ],
            'permission_callback' => '__return_true',
        ] );

        register_rest_route( 'educbt/v1', '/trial/submit', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'submit_trial' ],
            'permission_callback' => '__return_true',
        ] );
    }

    public function get_subjects( \WP_REST_Request $request ): \WP_REST_Response {
        $svc = new TrialExamService();
        $band = sanitize_text_field( (string) $request->get_param( 'band' ) ) ?: TrialQuestionSeed::BAND_BOTH;
        return new \WP_REST_Response( [ 'success' => true, 'subjects' => $svc->subjects( $band ) ] );
    }

    public function start_trial( \WP_REST_Request $request ): \WP_REST_Response {
        $svc = new TrialExamService();

        $subject_code  = sanitize_text_field( (string) $request->get_param( 'subject_code' ) );
        $display_name  = sanitize_text_field( (string) $request->get_param( 'display_name' ) );
        $count         = absint( $request->get_param( 'count' ) );

        $result = $svc->start( $subject_code, TrialQuestionSeed::BAND_BOTH, $display_name, $count );

        if ( empty( $result['success'] ) ) {
            return new \WP_REST_Response( $result, 400 );
        }

        return new \WP_REST_Response( $result );
    }

    public function submit_trial( \WP_REST_Request $request ): \WP_REST_Response {
        $svc = new TrialExamService();

        $token   = sanitize_text_field( (string) $request->get_param( 'token' ) );
        $answers = $request->get_param( 'answers' );

        if ( ! is_array( $answers ) ) {
            $answers = [];
        }

        // Sanitize: keys are question IDs (int), values are option keys (string A-D)
        $clean = [];
        foreach ( $answers as $qid => $key ) {
            $clean[ absint( $qid ) ] = strtoupper( substr( sanitize_text_field( (string) $key ), 0, 2 ) );
        }

        $result = $svc->submit( $token, $clean );

        if ( empty( $result['success'] ) ) {
            return new \WP_REST_Response( $result, 400 );
        }

        return new \WP_REST_Response( $result );
    }
}
