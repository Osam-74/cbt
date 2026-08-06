<?php

namespace EduCBTPro\Services;

use EduCBTPro\Core\Capabilities;
use EduCBTPro\Core\EventDispatcher;
use EduCBTPro\Core\Schema;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * PHASE 9 — announcements and direct messages.
 *
 * ANNOUNCEMENTS are broadcast, targeted by audience. The audience rules matter as
 * much as the message: a class teacher may address their own class, and only a
 * principal or vice principal may address the whole school. Without that, every
 * teacher can mail 500 parents.
 *
 * MESSAGES are threaded and between named people. Student-to-student messaging is
 * deliberately NOT supported — a school portal is not a chat app, and moderating
 * one is not a job any school signed up for. Guardian↔staff and staff↔staff are.
 */
class AnnouncementService {

    public const AUDIENCE_SCHOOL     = 'school';
    public const AUDIENCE_CLASS      = 'class';
    public const AUDIENCE_LEVEL      = 'level';
    public const AUDIENCE_DEPARTMENT = 'department';
    public const AUDIENCE_ROLE       = 'role';
    public const AUDIENCE_GUARDIANS  = 'guardians';

    /**
     * @return array<string,string>
     */
    public static function audiences(): array {
        return [
            self::AUDIENCE_SCHOOL     => 'Everyone in the school',
            self::AUDIENCE_CLASS      => 'A single class',
            self::AUDIENCE_LEVEL      => 'A whole year group',
            self::AUDIENCE_DEPARTMENT => 'A department',
            self::AUDIENCE_ROLE       => 'A role (all teachers, all students…)',
            self::AUDIENCE_GUARDIANS  => 'Guardians of a class',
        ];
    }

    /**
     * Which capability an audience requires. School-wide is separated deliberately.
     */
    public static function capability_for( string $audience ): string {
        return $audience === self::AUDIENCE_SCHOOL || $audience === self::AUDIENCE_ROLE
            ? Capabilities::SEND_SCHOOL_WIDE
            : Capabilities::SEND_ANNOUNCEMENT;
    }

    /**
     * @return array{success:bool,announcement_id?:int,errors?:array<int,string>}
     */
    public function create( int $school_id, int $author_user_id, array $data ): array {
        $errors = [];

        $title = trim( (string) ( $data['title'] ?? '' ) );
        $body  = trim( (string) ( $data['body'] ?? '' ) );

        if ( $title === '' ) {
            $errors[] = 'title_required';
        }

        if ( wp_strip_all_tags( $body ) === '' ) {
            $errors[] = 'body_required';
        }

        $audience = (string) ( $data['audience_type'] ?? self::AUDIENCE_SCHOOL );

        if ( ! array_key_exists( $audience, self::audiences() ) ) {
            $errors[] = 'invalid_audience';
        }

        $ref = $data['audience_ref'] ?? [];

        if ( in_array( $audience, [ self::AUDIENCE_CLASS, self::AUDIENCE_GUARDIANS ], true ) && empty( $ref['class_id'] ) ) {
            $errors[] = 'class_required_for_this_audience';
        }

        if ( ! empty( $errors ) ) {
            return [ 'success' => false, 'errors' => $errors ];
        }

        global $wpdb;

        $wpdb->insert(
            Schema::table( 'announcements' ),
            [
                'school_id'     => $school_id,
                'title'         => sanitize_text_field( $title ),
                'body'          => wp_kses_post( $body ),
                'audience_type' => $audience,
                'audience_ref'  => (string) wp_json_encode( $ref ),
                'send_email'    => ! empty( $data['send_email'] ) ? 1 : 0,
                'status'        => 'draft',
                'created_by'    => $author_user_id,
            ],
            [ '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%d' ]
        );

        return [ 'success' => true, 'announcement_id' => absint( $wpdb->insert_id ) ];
    }

    /**
     * Publish: resolve the audience and fan out notifications.
     *
     * @return array{success:bool,recipients?:int,error?:string}
     */
    public function publish( int $school_id, int $announcement_id ): array {
        global $wpdb;

        $table = Schema::table( 'announcements' );

        $announcement = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d AND school_id = %d", $announcement_id, $school_id ),
            ARRAY_A
        );

        if ( ! $announcement ) {
            return [ 'success' => false, 'error' => 'announcement_not_found' ];
        }

        if ( (string) $announcement['status'] === 'published' ) {
            return [ 'success' => false, 'error' => 'already_published' ];
        }

        $recipients = $this->resolve_audience(
            $school_id,
            (string) $announcement['audience_type'],
            (array) json_decode( (string) $announcement['audience_ref'], true )
        );

        if ( empty( $recipients ) ) {
            return [ 'success' => false, 'error' => 'audience_is_empty' ];
        }

        ( new NotificationService() )->notify_many(
            $school_id,
            $recipients,
            NotificationService::ANNOUNCEMENT,
            (string) $announcement['title'],
            (string) $announcement['body']
        );

        $wpdb->update(
            $table,
            [ 'status' => 'published', 'published_at' => current_time( 'mysql', true ) ],
            [ 'id' => $announcement_id ],
            [ '%s', '%s' ],
            [ '%d' ]
        );

        EventDispatcher::action( 'educbt_announcement_published', [
            'school_id'       => $school_id,
            'announcement_id' => $announcement_id,
            'recipients'      => count( $recipients ),
        ] );

        return [ 'success' => true, 'recipients' => count( $recipients ) ];
    }

    /**
     * Turn an audience descriptor into WordPress user ids.
     *
     * @return array<int,int>
     */
    public function resolve_audience( int $school_id, string $audience, array $ref ): array {
        global $wpdb;

        $students    = $wpdb->prefix . 'educbt_students';
        $staff       = Schema::table( 'staff' );
        $guardians   = Schema::table( 'guardians' );
        $link        = Schema::table( 'guardian_student' );
        $enrollments = Schema::table( 'enrollments' );
        $classes     = Schema::table( 'classes' );

        $ids = [];

        switch ( $audience ) {
            case self::AUDIENCE_SCHOOL:
                $ids = array_merge(
                    (array) $wpdb->get_col( $wpdb->prepare( "SELECT wp_user_id FROM {$staff} WHERE school_id = %d AND status = 'active' AND wp_user_id IS NOT NULL", $school_id ) ),
                    (array) $wpdb->get_col( $wpdb->prepare( "SELECT wp_user_id FROM {$students} WHERE school_id = %d AND status = 'active' AND wp_user_id IS NOT NULL", $school_id ) ),
                    (array) $wpdb->get_col( $wpdb->prepare( "SELECT wp_user_id FROM {$guardians} WHERE school_id = %d AND status = 'active' AND wp_user_id IS NOT NULL", $school_id ) )
                );
                break;

            case self::AUDIENCE_CLASS:
                $ids = (array) $wpdb->get_col(
                    $wpdb->prepare(
                        "SELECT st.wp_user_id FROM {$enrollments} e
                         INNER JOIN {$students} st ON st.id = e.student_id
                         WHERE e.school_id = %d AND e.class_id = %d AND e.status = 'active' AND st.wp_user_id IS NOT NULL",
                        $school_id,
                        absint( $ref['class_id'] ?? 0 )
                    )
                );
                break;

            case self::AUDIENCE_GUARDIANS:
                $ids = (array) $wpdb->get_col(
                    $wpdb->prepare(
                        "SELECT DISTINCT g.wp_user_id FROM {$enrollments} e
                         INNER JOIN {$link} gs ON gs.student_id = e.student_id
                         INNER JOIN {$guardians} g ON g.id = gs.guardian_id
                         WHERE e.school_id = %d AND e.class_id = %d AND e.status = 'active' AND g.wp_user_id IS NOT NULL",
                        $school_id,
                        absint( $ref['class_id'] ?? 0 )
                    )
                );
                break;

            case self::AUDIENCE_LEVEL:
                $ids = (array) $wpdb->get_col(
                    $wpdb->prepare(
                        "SELECT st.wp_user_id FROM {$enrollments} e
                         INNER JOIN {$classes} c ON c.id = e.class_id
                         INNER JOIN {$students} st ON st.id = e.student_id
                         WHERE e.school_id = %d AND c.level_id = %d AND e.status = 'active' AND st.wp_user_id IS NOT NULL",
                        $school_id,
                        absint( $ref['level_id'] ?? 0 )
                    )
                );
                break;

            case self::AUDIENCE_ROLE:
                $role = (string) ( $ref['role'] ?? '' );

                if ( $role === Capabilities::ROLE_STUDENT ) {
                    $ids = (array) $wpdb->get_col( $wpdb->prepare( "SELECT wp_user_id FROM {$students} WHERE school_id = %d AND status = 'active' AND wp_user_id IS NOT NULL", $school_id ) );
                } elseif ( $role === Capabilities::ROLE_GUARDIAN ) {
                    $ids = (array) $wpdb->get_col( $wpdb->prepare( "SELECT wp_user_id FROM {$guardians} WHERE school_id = %d AND status = 'active' AND wp_user_id IS NOT NULL", $school_id ) );
                } else {
                    $ids = (array) $wpdb->get_col( $wpdb->prepare( "SELECT wp_user_id FROM {$staff} WHERE school_id = %d AND role_slug = %s AND status = 'active' AND wp_user_id IS NOT NULL", $school_id, $role ) );
                }
                break;

            case self::AUDIENCE_DEPARTMENT:
                $ids = (array) $wpdb->get_col(
                    $wpdb->prepare(
                        "SELECT st.wp_user_id FROM {$enrollments} e
                         INNER JOIN {$students} st ON st.id = e.student_id
                         WHERE e.school_id = %d AND e.department_id = %d AND e.status = 'active' AND st.wp_user_id IS NOT NULL",
                        $school_id,
                        absint( $ref['department_id'] ?? 0 )
                    )
                );
                break;
        }

        return array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
    }

    /**
     * Estimate the audience before sending. A principal about to notify 1,200 people
     * should see that number first.
     */
    public function preview_audience( int $school_id, string $audience, array $ref ): int {
        return count( $this->resolve_audience( $school_id, $audience, $ref ) );
    }

    // ---------------------------------------------------------------
    // Direct messages
    // ---------------------------------------------------------------

    /**
     * Start a thread.
     *
     * Student-to-student is refused. A school portal is not a chat app, and
     * moderating one is not a job any school signed up for.
     *
     * @param array<int,int> $participant_user_ids
     * @return array{success:bool,thread_id?:int,error?:string}
     */
    public function start_thread( int $school_id, int $sender_user_id, array $participant_user_ids, string $subject, string $body ): array {
        $participants = array_values( array_unique( array_filter( array_map( 'absint', $participant_user_ids ) ) ) );

        if ( empty( $participants ) ) {
            return [ 'success' => false, 'error' => 'no_recipients' ];
        }

        if ( trim( wp_strip_all_tags( $body ) ) === '' ) {
            return [ 'success' => false, 'error' => 'body_required' ];
        }

        if ( $this->is_student( $sender_user_id ) ) {
            foreach ( $participants as $participant ) {
                if ( $this->is_student( $participant ) ) {
                    return [ 'success' => false, 'error' => 'student_to_student_messaging_is_not_supported' ];
                }
            }
        }

        global $wpdb;

        $all = array_values( array_unique( array_merge( [ $sender_user_id ], $participants ) ) );

        $wpdb->insert(
            Schema::table( 'message_threads' ),
            [
                'school_id'       => $school_id,
                'subject'         => sanitize_text_field( $subject ),
                'participants'    => (string) wp_json_encode( $all ),
                'last_message_at' => current_time( 'mysql', true ),
                'created_by'      => $sender_user_id,
            ],
            [ '%d', '%s', '%s', '%s', '%d' ]
        );

        $thread_id = absint( $wpdb->insert_id );

        if ( $thread_id <= 0 ) {
            return [ 'success' => false, 'error' => 'could_not_create_thread' ];
        }

        $this->reply( $school_id, $thread_id, $sender_user_id, $body );

        return [ 'success' => true, 'thread_id' => $thread_id ];
    }

    /**
     * @return array{success:bool,message_id?:int,error?:string}
     */
    public function reply( int $school_id, int $thread_id, int $sender_user_id, string $body ): array {
        global $wpdb;

        $threads = Schema::table( 'message_threads' );

        $thread = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$threads} WHERE id = %d AND school_id = %d", $thread_id, $school_id ),
            ARRAY_A
        );

        if ( ! $thread ) {
            return [ 'success' => false, 'error' => 'thread_not_found' ];
        }

        $participants = array_map( 'absint', (array) json_decode( (string) $thread['participants'], true ) );

        // Only a participant may post. Without this, any logged-in user could reply
        // into any thread by guessing an id.
        if ( ! in_array( $sender_user_id, $participants, true ) ) {
            return [ 'success' => false, 'error' => 'not_a_participant' ];
        }

        $wpdb->insert(
            Schema::table( 'messages' ),
            [
                'school_id'      => $school_id,
                'thread_id'      => $thread_id,
                'sender_user_id' => $sender_user_id,
                'body'           => wp_kses_post( $body ),
                'created_at'     => current_time( 'mysql', true ),
            ],
            [ '%d', '%d', '%d', '%s', '%s' ]
        );

        $message_id = absint( $wpdb->insert_id );

        $wpdb->update(
            $threads,
            [ 'last_message_at' => current_time( 'mysql', true ) ],
            [ 'id' => $thread_id ],
            [ '%s' ],
            [ '%d' ]
        );

        $others = array_values( array_diff( $participants, [ $sender_user_id ] ) );

        if ( ! empty( $others ) ) {
            ( new NotificationService() )->notify_many(
                $school_id,
                $others,
                NotificationService::MESSAGE_RECEIVED,
                'New message: ' . (string) $thread['subject'],
                wp_trim_words( wp_strip_all_tags( $body ), 25 )
            );
        }

        return [ 'success' => true, 'message_id' => $message_id ];
    }

    private function is_student( int $user_id ): bool {
        return user_can( $user_id, Capabilities::SIT_EXAM ) && ! user_can( $user_id, Capabilities::VIEW_STUDENTS );
    }

    /**
     * Threads a user participates in.
     *
     * @return array<int,array<string,mixed>>
     */
    public function threads_for( int $school_id, int $user_id, int $limit = 30 ): array {
        global $wpdb;

        $threads = Schema::table( 'message_threads' );

        // JSON_CONTAINS is available on MySQL 5.7+ and MariaDB 10.2+, both of which
        // this build already requires for other reasons.
        return (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$threads}
                 WHERE school_id = %d AND JSON_CONTAINS(participants, %s)
                 ORDER BY last_message_at DESC LIMIT %d",
                $school_id,
                (string) wp_json_encode( $user_id ),
                max( 1, min( 100, $limit ) )
            ),
            ARRAY_A
        );
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function thread_messages( int $school_id, int $thread_id, int $user_id ): array {
        global $wpdb;

        $thread = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT participants FROM ' . Schema::table( 'message_threads' ) . ' WHERE id = %d AND school_id = %d',
                $thread_id,
                $school_id
            ),
            ARRAY_A
        );

        if ( ! $thread ) {
            return [];
        }

        $participants = array_map( 'absint', (array) json_decode( (string) $thread['participants'], true ) );

        if ( ! in_array( $user_id, $participants, true ) ) {
            return [];
        }

        return (array) $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM ' . Schema::table( 'messages' ) . ' WHERE thread_id = %d AND school_id = %d ORDER BY created_at ASC',
                $thread_id,
                $school_id
            ),
            ARRAY_A
        );
    }
}
