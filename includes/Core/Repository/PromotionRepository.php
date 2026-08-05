<?php

namespace EduCBTPro\Core\Repository;

use EduCBTPro\Core\Models\Promotion;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PromotionRepository {
    public function get_all_promotions( int $school_id ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'educbt_promotions';
        return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE school_id = %d", $school_id ), ARRAY_A ) ?: [];
    }

    public function create_promotion( int $school_id, array $data ): int {
        global $wpdb;
        $table = $wpdb->prefix . 'educbt_promotions';

        $wpdb->insert(
            $table,
            [
                'school_id'    => $school_id,
                'student_id'   => absint( $data['student_id'] ?? 0 ),
                'from_class'   => sanitize_text_field( $data['from_class'] ?? '' ),
                'to_class'     => sanitize_text_field( $data['to_class'] ?? '' ),
                'session_year' => sanitize_text_field( $data['session_year'] ?? '' ),
                'status'       => sanitize_text_field( $data['status'] ?? 'pending' ),
            ],
            [ '%d', '%d', '%s', '%s', '%s', '%s' ]
        );

        return (int) $wpdb->insert_id;
    }
}
