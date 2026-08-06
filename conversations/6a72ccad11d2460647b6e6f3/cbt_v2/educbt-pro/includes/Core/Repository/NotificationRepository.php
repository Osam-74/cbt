<?php

namespace EduCBTPro\Core\Repository;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class NotificationRepository {
    public function get_all_notifications( int $school_id ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'educbt_notifications';
        return $wpdb->get_results(
            $wpdb->prepare( "SELECT * FROM {$table} WHERE school_id = %d ORDER BY id DESC", $school_id ),
            ARRAY_A
        ) ?: [];
    }

    public function get_for_recipient( int $school_id, int $recipient_id ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'educbt_notifications';
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE school_id = %d AND recipient_id = %d ORDER BY id DESC",
                $school_id,
                $recipient_id
            ),
            ARRAY_A
        ) ?: [];
    }

    public function get_unread_count( int $school_id, int $recipient_id ): int {
        global $wpdb;
        $table = $wpdb->prefix . 'educbt_notifications';
        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE school_id = %d AND recipient_id = %d AND is_read = 0",
                $school_id,
                $recipient_id
            )
        );
    }

    public function create_notification( int $school_id, array $data ): int {
        global $wpdb;
        $table = $wpdb->prefix . 'educbt_notifications';

        $wpdb->insert(
            $table,
            [
                'school_id'    => $school_id,
                'recipient_id' => absint( $data['recipient_id'] ?? 0 ),
                'type'         => sanitize_text_field( $data['type'] ?? 'info' ),
                'title'        => sanitize_text_field( $data['title'] ?? '' ),
                'message'      => sanitize_textarea_field( $data['message'] ?? '' ),
                'is_read'      => 0,
                'created_at'   => current_time( 'mysql' ),
            ],
            [ '%d', '%d', '%s', '%s', '%s', '%d', '%s' ]
        );

        return (int) $wpdb->insert_id;
    }

    public function mark_read( int $school_id, int $notification_id, int $recipient_id ): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'educbt_notifications';

        $result = $wpdb->update(
            $table,
            [ 'is_read' => 1 ],
            [ 'id' => $notification_id, 'school_id' => $school_id, 'recipient_id' => $recipient_id ],
            [ '%d' ],
            [ '%d', '%d', '%d' ]
        );

        return $result !== false;
    }

    public function mark_all_read( int $school_id, int $recipient_id ): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'educbt_notifications';

        $result = $wpdb->update(
            $table,
            [ 'is_read' => 1 ],
            [ 'school_id' => $school_id, 'recipient_id' => $recipient_id ],
            [ '%d' ],
            [ '%d', '%d' ]
        );

        return $result !== false;
    }
}
