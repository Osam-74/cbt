<?php

namespace EduCBTPro\Core\Repository;

use EduCBTPro\Core\Models\Subject;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SubjectRepository {
    /**
     * Get the subjects_v2 table name.
     */
    private function v2_table(): string {
        global $wpdb;
        return \EduCBTPro\Core\Schema::table( 'subjects_v2' );
    }

    /**
     * Sync a subject to the subjects_v2 table (the portal uses this one).
     * Matches by legacy_subject_id first, then by name within the school.
     */
    private function sync_to_v2( int $school_id, int $legacy_id, string $name, string $code = '', string $subject_type = 'core' ): void {
        global $wpdb;
        $table = $this->v2_table();

        // Try to find an existing v2 entry linked by legacy_subject_id.
        $existing = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id FROM {$table} WHERE school_id = %d AND legacy_subject_id = %d",
                $school_id, $legacy_id
            ),
            ARRAY_A
        );

        // If not found by legacy id, try by name (case-insensitive).
        if ( ! $existing ) {
            $existing = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT id FROM {$table} WHERE school_id = %d AND LOWER(name) = LOWER(%s)",
                    $school_id, $name
                ),
                ARRAY_A
            );
        }

        if ( $existing ) {
            $wpdb->update(
                $table,
                [
                    'name'   => $name,
                    'code'   => $code,
                    'status' => 'active',
                ],
                [ 'id' => (int) $existing['id'] ],
                [ '%s', '%s', '%s' ],
                [ '%d' ]
            );
        } else {
            // Determine stage from subject_type — 'core' maps to 'both', others stay flexible.
            $stage = 'both';
            $wpdb->insert(
                $table,
                [
                    'school_id'         => $school_id,
                    'name'              => $name,
                    'code'              => $code ?: strtoupper( substr( preg_replace( '/[^A-Z]/i', '', $name ), 0, 3 ) ),
                    'stage'             => $stage,
                    'category'          => $subject_type === 'core' ? 'compulsory' : 'elective',
                    'is_compulsory'     => $subject_type === 'core' ? 1 : 0,
                    'status'            => 'active',
                    'legacy_subject_id' => $legacy_id,
                ],
                [ '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%d' ]
            );
        }
    }

    /**
     * Soft-delete a subject in subjects_v2 so the portal stops showing it.
     */
    private function soft_delete_v2( int $school_id, int $legacy_id, string $name ): void {
        global $wpdb;
        $table = $this->v2_table();

        $wpdb->update(
            $table,
            [ 'status' => 'deleted' ],
            [ 'school_id' => $school_id, 'legacy_subject_id' => $legacy_id ],
            [ '%s' ],
            [ '%d', '%d' ]
        );

        // Also try by name in case legacy_subject_id was never set.
        $wpdb->update(
            $table,
            [ 'status' => 'deleted' ],
            [ 'school_id' => $school_id, 'name' => $name ],
            [ '%s' ],
            [ '%d', '%s' ]
        );
    }

    public function get_all_subjects( int $school_id ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'educbt_subjects';
        if ( $school_id > 0 ) {
            return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE school_id = %d", $school_id ), ARRAY_A ) ?: [];
        }

        return $wpdb->get_results( "SELECT * FROM {$table}", ARRAY_A ) ?: [];
    }

    public function create_subject( int $school_id, array $data ): int {
        global $wpdb;
        $table = $wpdb->prefix . 'educbt_subjects';

        $subject_name = sanitize_text_field( $data['subject_name'] ?? '' );
        $subject_code = sanitize_text_field( $data['subject_code'] ?? '' );

        if ( $subject_name === '' ) {
            return 0;
        }

        $existing_id = $this->find_existing_subject_id( $school_id, $subject_name, $subject_code );
        if ( $existing_id > 0 ) {
            return $existing_id;
        }

        $wpdb->insert(
            $table,
            [
                'school_id'    => $school_id,
                'subject_name' => $subject_name,
                'subject_code' => $subject_code,
                'subject_type' => sanitize_text_field( $data['subject_type'] ?? 'core' ),
            ],
            [ '%d', '%s', '%s', '%s' ]
        );

        $new_id = (int) $wpdb->insert_id;

        // Keep subjects_v2 in sync so the portal shows the same subject list.
        if ( $new_id > 0 ) {
            $this->sync_to_v2( $school_id, $new_id, $subject_name, $subject_code, sanitize_text_field( $data['subject_type'] ?? 'core' ) );
        }

        return $new_id;
    }

    public function find_existing_subject_id( int $school_id, string $subject_name, string $subject_code = '' ): int {
        global $wpdb;
        $table = $wpdb->prefix . 'educbt_subjects';

        if ( $subject_name === '' && $subject_code === '' ) {
            return 0;
        }

        if ( $subject_code !== '' ) {
            $id = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT id FROM {$table} WHERE school_id = %d AND (LOWER(subject_name) = LOWER(%s) OR LOWER(subject_code) = LOWER(%s)) LIMIT 1",
                    $school_id,
                    $subject_name,
                    $subject_code
                )
            );
            return $id;
        }

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$table} WHERE school_id = %d AND LOWER(subject_name) = LOWER(%s) LIMIT 1",
                $school_id,
                $subject_name
            )
        );
    }

    /**
     * Update a subject record.
     *
     * @return bool True on success.
     */
    public function update_subject( int $school_id, int $subject_id, array $data ): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'educbt_subjects';

        $update = [];

        if ( isset( $data['subject_name'] ) ) {
            $update['subject_name'] = sanitize_text_field( $data['subject_name'] );
        }
        if ( isset( $data['subject_code'] ) ) {
            $update['subject_code'] = sanitize_text_field( $data['subject_code'] );
        }
        if ( isset( $data['subject_type'] ) ) {
            $update['subject_type'] = sanitize_text_field( $data['subject_type'] );
        }

        if ( empty( $update ) ) {
            return false;
        }

        $result = $wpdb->update(
            $table,
            $update,
            [ 'id' => $subject_id, 'school_id' => $school_id ],
            null,
            [ '%d', '%d' ]
        );

        // Sync name/code changes to subjects_v2.
        if ( $result !== false ) {
            $row = $wpdb->get_row(
                $wpdb->prepare( "SELECT subject_name, subject_code, subject_type FROM {$table} WHERE id = %d", $subject_id ),
                ARRAY_A
            );
            if ( $row ) {
                $this->sync_to_v2( $school_id, $subject_id, $row['subject_name'], $row['subject_code'] ?? '', $row['subject_type'] ?? 'core' );
            }
        }

        return $result !== false;
    }

    /**
     * Delete a subject record.
     *
     * @return bool True on success.
     */
    public function delete_subject( int $school_id, int $subject_id ): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'educbt_subjects';

        // Get the subject name before deleting so we can sync to subjects_v2.
        $row = $wpdb->get_row(
            $wpdb->prepare( "SELECT subject_name FROM {$table} WHERE id = %d AND school_id = %d", $subject_id, $school_id ),
            ARRAY_A
        );

        $result = $wpdb->delete(
            $table,
            [ 'id' => $subject_id, 'school_id' => $school_id ],
            [ '%d', '%d' ]
        );

        // Soft-delete in subjects_v2 so the portal stops showing it.
        if ( $result !== false && $row ) {
            $this->soft_delete_v2( $school_id, $subject_id, $row['subject_name'] );
        }

        return $result !== false;
    }

    /**
     * Count how many questions reference this subject by name.
     * Used to warn before deletion.
     *
     * @return int
     */
    public function count_question_references( int $school_id, string $subject_name ): int {
        global $wpdb;
        $questions = $wpdb->prefix . 'educbt_questions';

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$questions} WHERE school_id = %d AND subject = %s",
                $school_id,
                $subject_name
            )
        );
    }

    /**
     * Merge duplicate subjects: move all references from source to target,
     * then delete the source.
     *
     * @return bool
     */
    public function merge_subjects( int $school_id, int $source_id, int $target_id ): bool {
        global $wpdb;
        $subjects_table = $wpdb->prefix . 'educbt_subjects';
        $questions_table = $wpdb->prefix . 'educbt_questions';

        // Get subject names
        $source = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$subjects_table} WHERE id = %d AND school_id = %d", $source_id, $school_id ),
            ARRAY_A
        );
        $target = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$subjects_table} WHERE id = %d AND school_id = %d", $target_id, $school_id ),
            ARRAY_A
        );

        if ( ! $source || ! $target ) {
            return false;
        }

        // Update all questions that reference the source subject name
        $wpdb->update(
            $questions_table,
            [ 'subject' => $target['subject_name'] ],
            [ 'school_id' => $school_id, 'subject' => $source['subject_name'] ],
            [ '%s' ],
            [ '%d', '%s' ]
        );

        // Delete the source subject
        $wpdb->delete(
            $subjects_table,
            [ 'id' => $source_id, 'school_id' => $school_id ],
            [ '%d', '%d' ]
        );

        // Soft-delete the source in subjects_v2 and update the target.
        $this->soft_delete_v2( $school_id, $source_id, $source['subject_name'] );
        $this->sync_to_v2( $school_id, $target_id, $target['subject_name'], $target['subject_code'] ?? '', $target['subject_type'] ?? 'core' );

        return true;
    }
}
