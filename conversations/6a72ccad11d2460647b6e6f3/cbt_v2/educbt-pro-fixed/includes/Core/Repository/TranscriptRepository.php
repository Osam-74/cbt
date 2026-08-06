<?php

namespace EduCBTPro\Core\Repository;

use EduCBTPro\Core\Models\Transcript;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class TranscriptRepository {
    public function get_all_transcripts( int $school_id ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'educbt_transcripts';
        return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE school_id = %d", $school_id ), ARRAY_A ) ?: [];
    }

    public function create_transcript( int $school_id, array $data ): int {
        global $wpdb;
        $table = $wpdb->prefix . 'educbt_transcripts';

        $wpdb->insert(
            $table,
            [
                'school_id' => $school_id,
                'student_id'=> absint( $data['student_id'] ?? 0 ),
                'terms'     => wp_json_encode( $data['terms'] ?? [] ),
                'sessions'  => wp_json_encode( $data['sessions'] ?? [] ),
                'summary'   => sanitize_textarea_field( $data['summary'] ?? '' ),
            ],
            [ '%d', '%d', '%s', '%s', '%s' ]
        );

        return (int) $wpdb->insert_id;
    }
}
