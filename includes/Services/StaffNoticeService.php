<?php

namespace EduCBTPro\Services;

use EduCBTPro\Core\EventDispatcher;
use EduCBTPro\Core\Schema;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Notices from the exam office to teaching staff.
 *
 * Distinct from announcements, which go to the whole school. These are the handful
 * of messages an exam officer sends every term, always in the same words, always to
 * some subset of teachers. Templating them means the message is consistent, the
 * deadline is in it, and nobody has to compose the same reminder eleven times.
 *
 * Each template knows who it is FOR, which is the useful part: "submission
 * incomplete" addressed to teachers whose submission is complete is noise, and noise
 * is how staff learn to ignore notifications.
 */
class StaffNoticeService {

    public const BEGIN_SUBMISSION    = 'begin_submission';
    public const REMIND_SUBMISSION   = 'remind_submission';
    public const INCOMPLETE          = 'incomplete_submission';
    public const APPROVED            = 'submission_approved';
    public const MARKING_OUTSTANDING = 'marking_outstanding';
    public const CUSTOM              = 'custom';

    /**
     * @return array<string,array{label:string,subject:string,body:string,audience:string}>
     */
    public static function templates(): array {
        return [
            self::BEGIN_SUBMISSION => [
                'label'    => 'Begin question submission',
                'subject'  => 'Question submission is now open',
                'body'     => "Please begin submitting examination questions for your subjects.\n\nThe minimum required is shown on your Question Bank page. Submit early so there is time for review before the paper is set.",
                'audience' => 'all',
            ],
            self::REMIND_SUBMISSION => [
                'label'    => 'Reminder to begin submitting',
                'subject'  => 'Reminder: examination questions',
                'body'     => "This is a reminder to submit your examination questions.\n\nNothing has been received from you yet. If you have started but not saved, please check your Question Bank page.",
                'audience' => 'nothing_submitted',
            ],
            self::INCOMPLETE => [
                'label'    => 'Submission is incomplete',
                'subject'  => 'Your question submission is incomplete',
                'body'     => "Your submission has been received but is still below the minimum required.\n\nPlease add the outstanding questions so the paper can be composed.",
                'audience' => 'incomplete',
            ],
            self::APPROVED => [
                'label'    => 'Questions approved',
                'subject'  => 'Your questions have been approved',
                'body'     => "Your examination questions have been reviewed and approved. No further action is needed.\n\nThank you.",
                'audience' => 'complete',
            ],
            self::MARKING_OUTSTANDING => [
                'label'    => 'Written answers still to mark',
                'subject'  => 'Written answers awaiting your marking',
                'body'     => "Some written answers in your subject have not been marked yet.\n\nResults cannot be compiled until every answer has a mark, so please complete this as soon as you can.",
                'audience' => 'marking_outstanding',
            ],
            self::CUSTOM => [
                'label'    => 'Write my own',
                'subject'  => '',
                'body'     => '',
                'audience' => 'all',
            ],
        ];
    }

    /**
     * Teaching staff, each with enough context for the sender to choose sensibly.
     *
     * @return array<int,array<string,mixed>>
     */
    public function teachers( int $school_id ): array {
        global $wpdb;

        $staff     = Schema::table( 'staff' );
        $assign    = Schema::table( 'staff_assignments' );
        $questions = $wpdb->prefix . 'educbt_questions';

        $rows = (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT s.id, s.wp_user_id, CONCAT(s.first_name, ' ', s.last_name) AS name, s.role_slug,
                        COUNT(DISTINCT a.subject_id) AS subjects,
                        (SELECT COUNT(*) FROM {$questions} q
                          WHERE q.school_id = s.school_id AND q.created_by_staff = s.id AND q.status = 'active') AS questions
                 FROM {$staff} s
                 LEFT JOIN {$assign} a ON a.staff_id = s.id AND a.status = 'active' AND a.assignment_type = 'subject_teacher'
                 WHERE s.school_id = %d AND s.status = 'active' AND s.wp_user_id IS NOT NULL
                 GROUP BY s.id
                 ORDER BY s.last_name ASC",
                $school_id
            ),
            ARRAY_A
        );

        $approvals = new QuestionApprovalService();
        $quotas    = $approvals->quotas( $school_id );
        $theory    = new TheoryService();

        $marking = [];

        foreach ( $theory->papers_awaiting_marking( $school_id ) as $paper ) {
            $marking[ (int) ( $paper['id'] ?? 0 ) ] = true;
        }

        $out = [];

        foreach ( $rows as $row ) {
            $staff_id    = absint( $row['id'] );
            $submissions = $approvals->submissions( $school_id, $staff_id );

            $short = false;

            foreach ( $submissions as $sub ) {
                if ( ! $sub['complete'] ) {
                    $short = true;
                    break;
                }
            }

            $submitted = absint( $row['questions'] );

            $out[] = [
                'staff_id'   => $staff_id,
                'user_id'    => absint( $row['wp_user_id'] ),
                'name'       => trim( (string) $row['name'] ),
                'role'       => (string) $row['role_slug'],
                'subjects'   => absint( $row['subjects'] ),
                'questions'  => $submitted,
                'incomplete' => $submitted > 0 && $short,
                'nothing'    => $submitted === 0,
                'complete'   => $submitted > 0 && ! $short,
                'marking'    => count( $theory->papers_awaiting_marking( $school_id, $staff_id ) ) > 0,
            ];
        }

        return $out;
    }

    /**
     * Who a template is aimed at.
     *
     * @param array<int,array<string,mixed>> $teachers
     * @return array<int,int> staff ids
     */
    public function audience_for( string $template, array $teachers ): array {
        $rules = self::templates()[ $template ]['audience'] ?? 'all';

        $matches = static function ( array $t ) use ( $rules ): bool {
            switch ( $rules ) {
                case 'nothing_submitted':
                    return ! empty( $t['nothing'] );
                case 'incomplete':
                    return ! empty( $t['incomplete'] );
                case 'complete':
                    return ! empty( $t['complete'] );
                case 'marking_outstanding':
                    return ! empty( $t['marking'] );
                default:
                    return true;
            }
        };

        $out = [];

        foreach ( $teachers as $teacher ) {
            if ( $matches( $teacher ) ) {
                $out[] = (int) $teacher['staff_id'];
            }
        }

        return $out;
    }

    /**
     * Send to named staff.
     *
     * @param array<int,int> $staff_ids
     * @return array{sent:int,skipped:int}
     */
    public function send( int $school_id, array $staff_ids, string $subject, string $body, string $link = '' ): array {
        global $wpdb;

        $staff_ids = array_values( array_filter( array_map( 'absint', $staff_ids ) ) );

        if ( empty( $staff_ids ) || trim( $subject ) === '' ) {
            return [ 'sent' => 0, 'skipped' => 0 ];
        }

        $placeholders = implode( ',', array_fill( 0, count( $staff_ids ), '%d' ) );

        $user_ids = array_map(
            'absint',
            (array) $wpdb->get_col(
                $wpdb->prepare(
                    'SELECT wp_user_id FROM ' . Schema::table( 'staff' ) . "
                     WHERE school_id = %d AND wp_user_id IS NOT NULL AND id IN ({$placeholders})",
                    array_merge( [ $school_id ], $staff_ids )
                )
            )
        );

        $user_ids = array_values( array_filter( $user_ids ) );

        $sent = ( new NotificationService() )->notify_many(
            $school_id,
            $user_ids,
            NotificationService::ANNOUNCEMENT,
            sanitize_text_field( $subject ),
            wp_kses_post( $body ),
            $link !== '' ? $link : home_url( '/portal/exams/questions/' )
        );

        EventDispatcher::action( 'educbt_staff_notice_sent', [
            'school_id' => $school_id,
            'sent'      => $sent,
            'subject'   => $subject,
        ] );

        return [ 'sent' => $sent, 'skipped' => count( $staff_ids ) - count( $user_ids ) ];
    }
}
