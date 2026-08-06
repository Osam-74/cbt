<?php

namespace EduCBTPro\Services;

use EduCBTPro\Core\EventDispatcher;
use EduCBTPro\Core\Repository\ResultRepository;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Implements the SRS §15 result approval workflow:
 * Draft → Submitted → Reviewed → Approved → Published → Archived
 *
 * Role permissions per transition:
 *   submit  : teacher, exam_officer
 *   review  : exam_officer, vice_principal
 *   approve : vice_principal, principal
 *   publish : principal
 *   archive : principal, exam_officer
 *   reject  : exam_officer, vice_principal, principal  (sends back to Draft)
 */
class ResultApprovalService {
    // Ordered statuses for forward-progress checks
    public const STATUSES = [
        'draft',
        'submitted',
        'reviewed',
        'approved',
        'published',
        'archived',
    ];

    /**
     * Valid transitions: current_status → [ action => next_status ]
     */
    private const TRANSITIONS = [
        'draft'     => [ 'submit'  => 'submitted' ],
        'submitted' => [ 'review'  => 'reviewed',  'reject' => 'draft' ],
        'reviewed'  => [ 'approve' => 'approved',  'reject' => 'draft' ],
        'approved'  => [ 'publish' => 'published', 'reject' => 'draft' ],
        'published' => [ 'archive' => 'archived' ],
        'archived'  => [],
    ];

    /**
     * Roles allowed to execute each action.
     */
    private const ACTION_ROLES = [
        'submit'  => [ 'teacher', 'exam_officer', 'manage_options' ],
        'review'  => [ 'exam_officer', 'vice_principal', 'manage_options' ],
        'approve' => [ 'vice_principal', 'principal', 'manage_options' ],
        'publish' => [ 'principal', 'manage_options' ],
        'archive' => [ 'principal', 'exam_officer', 'manage_options' ],
        'reject'  => [ 'exam_officer', 'vice_principal', 'principal', 'manage_options' ],
    ];

    private ResultRepository $repository;

    public function __construct( ?ResultRepository $repository = null ) {
        $this->repository = $repository ?? new ResultRepository();
    }

    /**
     * Attempt a workflow transition on a result.
     *
     * @param int    $school_id
     * @param int    $result_id
     * @param string $action       One of: submit, review, approve, publish, archive, reject
     * @param array  $actor_roles  WP capability slugs / custom role slugs the acting user holds
     * @param string $comment      Optional note stored as new_value in audit context (not persisted here)
     * @return array{ success: bool, status?: string, message?: string }
     */
    public function transition( int $school_id, int $result_id, string $action, array $actor_roles = [], string $comment = '' ): array {
        $result = $this->get_result( $school_id, $result_id );
        if ( $result === null ) {
            return [ 'success' => false, 'message' => 'result_not_found' ];
        }

        $current_status = trim( strtolower( (string) ( $result['status'] ?? 'draft' ) ) );
        $action = trim( strtolower( $action ) );

        if ( ! isset( self::TRANSITIONS[ $current_status ] ) ) {
            return [ 'success' => false, 'message' => 'invalid_current_status', 'current_status' => $current_status ];
        }

        if ( ! array_key_exists( $action, self::TRANSITIONS[ $current_status ] ) ) {
            return [
                'success'        => false,
                'message'        => 'invalid_transition',
                'current_status' => $current_status,
                'allowed_actions'=> array_keys( self::TRANSITIONS[ $current_status ] ),
            ];
        }

        if ( ! $this->actor_can( $action, $actor_roles ) ) {
            return [ 'success' => false, 'message' => 'permission_denied', 'action' => $action ];
        }

        $next_status = self::TRANSITIONS[ $current_status ][ $action ];
        $updated = $this->repository->update_result( $school_id, $result_id, [ 'status' => $next_status ] );

        if ( ! $updated ) {
            return [ 'success' => false, 'message' => 'update_failed' ];
        }

        EventDispatcher::action( 'result_status_changed', [
            'school_id'       => $school_id,
            'result_id'       => $result_id,
            'action'          => $action,
            'previous_status' => $current_status,
            'status'          => $next_status,
            'comment'         => $comment,
        ] );

        if ( $next_status === 'published' ) {
            EventDispatcher::action( 'result_published', [
                'school_id' => $school_id,
                'result_id' => $result_id,
                'action'    => $action,
            ] );
        }

        return [
            'success'         => true,
            'previous_status' => $current_status,
            'status'          => $next_status,
            'action'          => $action,
            'result_id'       => $result_id,
        ];
    }

    /**
     * Returns all allowed actions from a given status for a set of roles.
     */
    public function available_actions( string $status, array $actor_roles = [] ): array {
        $status = trim( strtolower( $status ) );
        $all_transitions = self::TRANSITIONS[ $status ] ?? [];

        return array_keys( array_filter( $all_transitions, function ( string $action ) use ( $actor_roles ): bool {
            return $this->actor_can( $action, $actor_roles );
        }, ARRAY_FILTER_USE_KEY ) );
    }

    /**
     * Returns the full ordered status list and whether each is reached for a result.
     */
    public function status_timeline( string $current_status ): array {
        $current_status = trim( strtolower( $current_status ) );
        $current_index = array_search( $current_status, self::STATUSES, true );

        return array_map( static function ( string $status, int $index ) use ( $current_index, $current_status ): array {
            return [
                'status'   => $status,
                'reached'  => $index <= $current_index,
                'current'  => $status === $current_status,
            ];
        }, self::STATUSES, array_keys( self::STATUSES ) );
    }

    private function actor_can( string $action, array $actor_roles ): bool {
        $allowed = self::ACTION_ROLES[ $action ] ?? [];
        foreach ( $actor_roles as $role ) {
            if ( in_array( $role, $allowed, true ) ) {
                return true;
            }
        }
        return false;
    }

    private function get_result( int $school_id, int $result_id ): ?array {
        $all = $this->repository->get_all_results( $school_id );
        foreach ( $all as $r ) {
            if ( absint( $r['id'] ?? 0 ) === $result_id ) {
                return $r;
            }
        }
        return null;
    }
}
