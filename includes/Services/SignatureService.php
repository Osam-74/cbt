<?php

namespace EduCBTPro\Services;

use EduCBTPro\Core\Schema;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Staff signatures for report sheets.
 *
 * Two kinds:
 *  - 'digital'  drawn on a canvas, stored as a data URL or uploaded file URL
 *  - 'upload'   a scanned signature image uploaded as a file
 *
 * One signature per (school, staff, role). The principal's signature and the
 * class teacher's signature are looked up by role when rendering a report sheet.
 */
class SignatureService {

    /**
     * Get the signature for a given role in a school.
     *
     * @return array{type:string,data:string,name:string}|null
     */
    public function get( int $school_id, string $role ): ?array {
        global $wpdb;

        $table = Schema::table( 'signatures' );
        $row   = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT signature_type, signature_data, display_name FROM {$table}
                 WHERE school_id = %d AND role = %s LIMIT 1",
                $school_id,
                $role
            ),
            ARRAY_A
        );

        if ( ! $row || empty( $row['signature_data'] ) ) {
            return null;
        }

        return [
            'type' => (string) $row['signature_type'],
            'data' => (string) $row['signature_data'],
            'name' => (string) $row['display_name'],
        ];
    }

    /**
     * Get the signature for a specific staff member.
     */
    public function get_for_staff( int $school_id, int $staff_id, string $role ): ?array {
        global $wpdb;

        $table = Schema::table( 'signatures' );
        $row   = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT signature_type, signature_data, display_name FROM {$table}
                 WHERE school_id = %d AND staff_id = %d AND role = %s LIMIT 1",
                $school_id,
                $staff_id,
                $role
            ),
            ARRAY_A
        );

        if ( ! $row || empty( $row['signature_data'] ) ) {
            return null;
        }

        return [
            'type' => (string) $row['signature_type'],
            'data' => (string) $row['signature_data'],
            'name' => (string) $row['display_name'],
        ];
    }

    /**
     * Resolve the right signature for a report sheet.
     *
     * The report sheet must show the actual class teacher's signature — not just
     * any row saved under the 'class_teacher' role — because a school has one
     * class teacher per class, each with their own signature. For 'principal' the
     * school-wide row is correct as-is; there is one principal.
     *
     * @return array{type:string,data:string,name:string}|null
     */
    public function resolve_for_class( int $school_id, int $class_id, string $role ): ?array {
        if ( $role === 'class_teacher' && $class_id > 0 ) {
            global $wpdb;

            $staff_id = absint(
                $wpdb->get_var(
                    $wpdb->prepare(
                        'SELECT staff_id FROM ' . Schema::table( 'staff_assignments' ) . "
                         WHERE school_id = %d AND class_id = %d AND assignment_type = 'class_teacher' AND status = 'active'
                         LIMIT 1",
                        $school_id,
                        $class_id
                    )
                )
            );

            if ( $staff_id > 0 ) {
                $sig = $this->get_for_staff( $school_id, $staff_id, $role );

                if ( $sig !== null ) {
                    return $sig;
                }
            }
        }

        // Fallback — a school-wide row for the role (older data, or nobody has
        // set a personal signature for their specific class yet).
        return $this->get( $school_id, $role );
    }

    /**
     * Save a signature (upsert).
     */
    public function save( int $school_id, int $staff_id, string $role, string $display_name, string $data, string $type = 'digital' ): bool {
        global $wpdb;

        $table = Schema::table( 'signatures' );

        $existing = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$table} WHERE school_id = %d AND staff_id = %d AND role = %s",
                $school_id,
                $staff_id,
                $role
            )
        );

        if ( $existing > 0 ) {
            $wpdb->update(
                $table,
                [
                    'signature_type' => $type,
                    'signature_data' => $data,
                    'display_name'   => $display_name,
                ],
                [ 'id' => $existing ],
                [ '%s', '%s', '%s' ],
                [ '%d' ]
            );
        } else {
            $wpdb->insert(
                $table,
                [
                    'school_id'      => $school_id,
                    'staff_id'       => $staff_id,
                    'role'           => $role,
                    'signature_type' => $type,
                    'signature_data' => $data,
                    'display_name'   => $display_name,
                ],
                [ '%d', '%d', '%s', '%s', '%s', '%s' ]
            );
        }

        return true;
    }
}
