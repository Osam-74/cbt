<?php

namespace EduCBTPro\Core\Repository;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ClassRepository {
    public function get_all_classes( int $school_id ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'educbt_classes';
        if ( $school_id > 0 ) {
            return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE school_id = %d", $school_id ), ARRAY_A ) ?: [];
        }

        return $wpdb->get_results( "SELECT * FROM {$table}", ARRAY_A ) ?: [];
    }

    public function create_class( int $school_id, array $data ): int {
        global $wpdb;
        $table = $wpdb->prefix . 'educbt_classes';

        $class_name = sanitize_text_field( $data['class_name'] ?? '' );
        $arm = sanitize_text_field( $data['arm'] ?? '' );
        $class_level = sanitize_text_field( $data['class_level'] ?? '' );

        if ( $class_name === '' ) {
            return 0;
        }

        $class_name = strtoupper( $class_name );
        $arm = strtoupper( $arm );

        $existing_id = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$table} WHERE school_id = %d AND class_name = %s AND arm = %s LIMIT 1",
                $school_id,
                $class_name,
                $arm
            )
        );

        if ( $existing_id > 0 ) {
            return 0;
        }

        $wpdb->insert(
            $table,
            [
                'school_id'   => $school_id,
                'class_name'  => $class_name,
                'arm'         => $arm,
                'class_level' => $class_level,
                'status'      => sanitize_text_field( $data['status'] ?? 'active' ),
            ],
            [ '%d', '%s', '%s', '%s', '%s' ]
        );

        return (int) $wpdb->insert_id;
    }

    /**
     * Update a class record.
     *
     * @return bool
     */
    public function update_class( int $school_id, int $class_id, array $data ): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'educbt_classes';

        $update = [];

        if ( isset( $data['class_name'] ) ) {
            $update['class_name'] = strtoupper( sanitize_text_field( $data['class_name'] ) );
        }
        if ( isset( $data['arm'] ) ) {
            $update['arm'] = strtoupper( sanitize_text_field( $data['arm'] ) );
        }
        if ( isset( $data['class_level'] ) ) {
            $update['class_level'] = sanitize_text_field( $data['class_level'] );
        }
        if ( isset( $data['status'] ) ) {
            $update['status'] = sanitize_text_field( $data['status'] );
        }

        if ( empty( $update ) ) {
            return false;
        }

        $result = $wpdb->update(
            $table,
            $update,
            [ 'id' => $class_id, 'school_id' => $school_id ],
            null,
            [ '%d', '%d' ]
        );

        return $result !== false;
    }

    /**
     * Delete a class record.
     *
     * @return bool
     */
    public function delete_class( int $school_id, int $class_id ): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'educbt_classes';

        $result = $wpdb->delete(
            $table,
            [ 'id' => $class_id, 'school_id' => $school_id ],
            [ '%d', '%d' ]
        );

        return $result !== false;
    }
}
