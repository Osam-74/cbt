<?php

namespace EduCBTPro\Frontend;

use EduCBTPro\Core\Capabilities;
use EduCBTPro\Core\Gate;
use EduCBTPro\Core\Scope;
use EduCBTPro\Core\TenantContext;
use EduCBTPro\Services\AcademicYearService;
use EduCBTPro\Services\GuardianService;
use EduCBTPro\Services\StaffService;
use EduCBTPro\Services\StudentRegistrationService;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Front-end form handling for the portal.
 *
 * Every portal form posts to admin-post.php and lands here. Handling POSTs in one
 * place rather than inside each template means the nonce check, the capability
 * check and the redirect-after-post all happen the same way every time — a template
 * that handles its own POST is a template that eventually forgets one of them.
 *
 * Results are passed back through a short-lived transient rather than the query
 * string, so generated passwords never appear in a URL, a browser history, or a
 * server access log.
 */
class PortalActions {

    public function init(): void {
        add_action( 'admin_post_educbt_create_classes', [ $this, 'create_classes' ] );
        add_action( 'admin_post_educbt_save_subject', [ $this, 'save_subject' ] );
        add_action( 'admin_post_educbt_delete_subject', [ $this, 'delete_subject' ] );
        add_action( 'admin_post_educbt_refresh_subjects', [ $this, 'refresh_subjects' ] );
        add_action( 'admin_post_educbt_save_question', [ $this, 'save_question' ] );
        add_action( 'admin_post_educbt_save_passage', [ $this, 'save_passage' ] );
        add_action( 'admin_post_educbt_update_question', [ $this, 'update_question' ] );
        add_action( 'admin_post_educbt_delete_question', [ $this, 'delete_question' ] );
        add_action( 'admin_post_educbt_decide_questions', [ $this, 'decide_questions' ] );
        add_action( 'admin_post_educbt_remind_questions', [ $this, 'remind_questions' ] );
        add_action( 'admin_post_educbt_remind_marking', [ $this, 'remind_marking' ] );
        add_action( 'admin_post_educbt_vault_backup', [ $this, 'vault_backup' ] );
        add_action( 'admin_post_educbt_vault_restore', [ $this, 'vault_restore' ] );
        add_action( 'admin_post_educbt_student_electives', [ $this, 'student_electives' ] );
        add_action( 'admin_post_educbt_register_class_subjects', [ $this, 'register_class_subjects' ] );
        add_action( 'admin_post_educbt_register_student_subjects', [ $this, 'register_student_subjects' ] );
        add_action( 'admin_post_educbt_save_quotas', [ $this, 'save_quotas' ] );
        add_action( 'admin_post_educbt_delete_submission', [ $this, 'delete_submission' ] );
        add_action( 'admin_post_educbt_send_staff_notice', [ $this, 'send_staff_notice' ] );
        add_action( 'admin_post_educbt_notice_respond', [ $this, 'notice_respond' ] );
        add_action( 'admin_post_educbt_import_questions', [ $this, 'import_questions' ] );
        add_action( 'admin_post_educbt_question_template', [ $this, 'question_template' ] );
        add_action( 'admin_post_educbt_create_paper', [ $this, 'create_paper' ] );
        add_action( 'admin_post_educbt_create_examination', [ $this, 'create_examination' ] );
        add_action( 'admin_post_educbt_create_ca_window', [ $this, 'create_ca_window' ] );
        add_action( 'admin_post_educbt_compose_ca_window', [ $this, 'compose_ca_window' ] );
        add_action( 'admin_post_educbt_set_question_window', [ $this, 'set_question_window' ] );
        add_action( 'admin_post_educbt_delete_series', [ $this, 'delete_series' ] );
        add_action( 'admin_post_educbt_create_practice', [ $this, 'create_practice' ] );
        add_action( 'admin_post_educbt_generate_timetable', [ $this, 'generate_timetable' ] );
        add_action( 'admin_post_educbt_notify_class_teachers', [ $this, 'notify_class_teachers' ] );
        add_action( 'admin_post_educbt_create_ca_test', [ $this, 'create_ca_test' ] );
        add_action( 'admin_post_educbt_publish_paper', [ $this, 'publish_paper' ] );
        add_action( 'admin_post_educbt_unpublish_paper', [ $this, 'unpublish_paper' ] );
        add_action( 'admin_post_educbt_reset_attempt', [ $this, 'reset_attempt' ] );
        add_action( 'admin_post_educbt_recompose_paper', [ $this, 'recompose_paper' ] );
        add_action( 'admin_post_educbt_delete_paper', [ $this, 'delete_paper' ] );
        add_action( 'admin_post_educbt_delete_examination', [ $this, 'delete_examination' ] );
        add_action( 'admin_post_educbt_reschedule_paper', [ $this, 'reschedule_paper' ] );
        add_action( 'admin_post_educbt_regenerate_access_code', [ $this, 'regenerate_access_code' ] );
        add_action( 'admin_post_educbt_duplicate_question', [ $this, 'duplicate_question' ] );
        add_action( 'admin_post_educbt_flag_notification', [ $this, 'flag_notification' ] );
        add_action( 'admin_post_educbt_report_issue', [ $this, 'report_issue' ] );
        add_action( 'admin_post_educbt_save_scores', [ $this, 'save_scores' ] );
        add_action( 'admin_post_educbt_mark_theory', [ $this, 'mark_theory' ] );
        add_action( 'admin_post_educbt_compile_results', [ $this, 'compile_results' ] );
        add_action( 'admin_post_educbt_move_results', [ $this, 'move_results' ] );
        add_action( 'admin_post_educbt_moderate_results', [ $this, 'moderate_results' ] );
        add_action( 'admin_post_educbt_set_period', [ $this, 'set_period' ] );
        add_action( 'admin_post_educbt_save_components', [ $this, 'save_components' ] );
        add_action( 'admin_post_educbt_issue_transcript', [ $this, 'issue_transcript' ] );
        add_action( 'admin_post_educbt_propose_promotion', [ $this, 'propose_promotion' ] );
        add_action( 'admin_post_educbt_override_promotion', [ $this, 'override_promotion' ] );
        add_action( 'admin_post_educbt_commit_promotion', [ $this, 'commit_promotion' ] );
        add_action( 'admin_post_educbt_reply_thread', [ $this, 'reply_thread' ] );
        add_action( 'admin_post_educbt_add_session', [ $this, 'add_session' ] );
        add_action( 'admin_post_educbt_update_school', [ $this, 'update_school' ] );
        add_action( 'admin_post_educbt_update_staff', [ $this, 'update_staff' ] );
        add_action( 'admin_post_educbt_update_principal_profile', [ $this, 'update_principal_profile' ] );
        add_action( 'admin_post_educbt_remove_staff', [ $this, 'remove_staff' ] );
        add_action( 'admin_post_educbt_register_student', [ $this, 'register_student' ] );
        add_action( 'admin_post_educbt_register_staff', [ $this, 'register_staff' ] );
        add_action( 'admin_post_educbt_assign_staff', [ $this, 'assign_staff' ] );
        add_action( 'admin_post_educbt_assign_bulk', [ $this, 'assign_bulk' ] );
        add_action( 'admin_post_educbt_reset_staff_password', [ $this, 'reset_staff_password' ] );
        add_action( 'admin_post_educbt_drop_assignment', [ $this, 'drop_assignment' ] );
        add_action( 'admin_post_educbt_change_own_password', [ $this, 'change_own_password' ] );
        add_action( 'admin_post_educbt_link_guardian', [ $this, 'link_guardian' ] );
        add_action( 'admin_post_educbt_reset_student_password', [ $this, 'reset_student_password' ] );
        add_action( 'admin_post_educbt_update_student', [ $this, 'update_student' ] );
        add_action( 'admin_post_educbt_repair_data', [ $this, 'repair_data' ] );
        add_action( 'admin_post_educbt_build_invigilation', [ $this, 'build_invigilation' ] );
        add_action( 'admin_post_educbt_reassign_invigilator', [ $this, 'reassign_invigilator' ] );
        add_action( 'admin_post_educbt_withdraw_student', [ $this, 'withdraw_student' ] );
        add_action( 'admin_post_educbt_deactivate_student', [ $this, 'deactivate_student' ] );
        add_action( 'admin_post_educbt_activate_student', [ $this, 'activate_student' ] );
        add_action( 'admin_post_educbt_set_student_status', [ $this, 'set_student_status' ] );
        add_action( 'admin_post_educbt_approve_student', [ $this, 'approve_student' ] );
        add_action( 'admin_post_educbt_export_students', [ $this, 'export_students' ] );
        add_action( 'admin_post_educbt_import_students', [ $this, 'import_students' ] );
        add_action( 'admin_post_educbt_update_class', [ $this, 'update_class' ] );
        add_action( 'admin_post_educbt_remove_class', [ $this, 'remove_class' ] );
        add_action( 'admin_post_educbt_save_promotion_rules', [ $this, 'save_promotion_rules' ] );
        add_action( 'admin_post_educbt_toggle_exam_prep', [ $this, 'toggle_exam_prep' ] );
        add_action( 'admin_post_educbt_update_student_profile', [ $this, 'update_student_profile' ] );
        add_action( 'admin_post_educbt_teacher_add_student', [ $this, 'teacher_add_student' ] );
        add_action( 'admin_post_educbt_save_signature', [ $this, 'save_signature' ] );
        add_action( 'admin_post_educbt_save_remarks', [ $this, 'save_remarks' ] );
        add_action( 'admin_post_educbt_delete_remark_range', [ $this, 'delete_remark_range' ] );
        add_action( 'admin_post_educbt_save_student_remark', [ $this, 'save_student_remark' ] );
        add_action( 'admin_post_educbt_move_student', [ $this, 'move_student' ] );
        add_action( 'admin_post_educbt_start_new_term', [ $this, 'start_new_term' ] );
    }

    /**
     * Create one or more arms of a class level in a single action, which is how a
     * school actually sets up: "JSS1 has A, B and C", not three separate forms.
     */
    public function create_classes(): void {
        [ $school_id ] = $this->context( 'educbt_create_classes' );

        if ( ! Gate::allows( Capabilities::MANAGE_CLASSES ) ) {
            $this->fail( 'You do not have permission to manage classes.' );
        }

        $level_id      = absint( $_POST['level_id'] ?? 0 );
        $department_id = absint( $_POST['department_id'] ?? 0 ) ?: null;
        $raw           = (string) wp_unslash( $_POST['arms'] ?? '' );

        // Accept "A,B,C" or "A B C" — nobody should have to guess the separator.
        $arms = array_values(
            array_filter(
                array_map(
                    static fn( string $a ): string => strtoupper( trim( $a ) ),
                    preg_split( '/[\s,]+/', $raw ) ?: []
                )
            )
        );

        if ( empty( $arms ) ) {
            $arms = [ '' ];
        }

        $result = ( new \EduCBTPro\Services\AcademicStructureService() )->create_arms(
            $school_id,
            $level_id,
            $arms,
            $department_id
        );

        if ( $result['created'] === 0 ) {
            $this->fail( 'No classes were created. ' . $this->readable( $result['errors'] ) );
        }

        $this->succeed(
            [
                'type'    => 'classes',
                'created' => $result['created'],
                'skipped' => $result['errors'],
            ]
        );
    }

    public function save_subject(): void {
        [ $school_id ] = $this->context( 'educbt_save_subject' );

        if ( ! Gate::allows( Capabilities::MANAGE_SUBJECTS ) ) {
            $this->fail( 'You do not have permission to manage subjects.' );
        }

        global $wpdb;

        $name = sanitize_text_field( (string) wp_unslash( $_POST['name'] ?? '' ) );
        $code = strtoupper( sanitize_text_field( (string) wp_unslash( $_POST['code'] ?? '' ) ) );

        if ( $name === '' ) {
            $this->fail( 'A subject needs a name.' );
        }

        if ( $code === '' ) {
            $code = strtoupper( substr( (string) preg_replace( '/[^A-Za-z]/', '', $name ), 0, 4 ) );
        }

        $table = \EduCBTPro\Core\Schema::table( 'subjects_v2' );

        // The same handler creates and edits. A separate update path would drift out
        // of step with this one the first time a field was added.
        $subject_id = absint( $_POST['subject_id'] ?? 0 );

        $clash = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$table} WHERE school_id = %d AND code = %s AND id <> %d",
                $school_id,
                $code,
                $subject_id
            )
        );

        if ( $clash ) {
            $this->fail( sprintf( 'A subject with the code %s already exists.', $code ) );
        }

        $fields = [
            'name'          => $name,
            'code'          => $code,
            'stage'         => sanitize_text_field( (string) wp_unslash( $_POST['stage'] ?? 'both' ) ),
            'category'      => sanitize_text_field( (string) wp_unslash( $_POST['category'] ?? 'elective' ) ),
            'department_id' => absint( $_POST['department_id'] ?? 0 ) ?: null,
            'is_compulsory' => ! empty( $_POST['is_compulsory'] ) ? 1 : 0,
        ];

        $formats = [ '%s', '%s', '%s', '%s', '%d', '%d' ];

        if ( $subject_id > 0 ) {
            $updated = $wpdb->update( $table, $fields, [ 'id' => $subject_id, 'school_id' => $school_id ], $formats, [ '%d', '%d' ] );

            if ( $updated === false ) {
                $this->fail( 'That subject could not be updated: ' . $wpdb->last_error );
            }

            $this->succeed( [ 'type' => 'subject_updated', 'name' => $name, 'code' => $code ] );
        }

        $fields['school_id'] = $school_id;
        $fields['status']    = 'active';

        $wpdb->insert( $table, $fields );

        $this->succeed( [ 'type' => 'subject', 'name' => $name, 'code' => $code ] );
    }

    /**
     * Remove a subject.
     *
     * Retired rather than deleted where the subject has been used. A subject that
     * appears in a student's results or on a compiled report card cannot be erased
     * without making those records unreadable, so it is withdrawn from further use
     * instead and the history stays intact.
     */

    /**
     * Replace the subject list with the standard offering.
     *
     * Explicit and destructive, so it is confirmed in the interface and never runs
     * on its own. Subjects carrying history are retired rather than deleted.
     */
    public function refresh_subjects(): void {
        [ $school_id ] = $this->context( 'educbt_refresh_subjects' );

        if ( ! Gate::allows( Capabilities::MANAGE_SUBJECTS ) ) {
            $this->fail( 'You do not have permission to manage subjects.' );
        }

        $result = ( new \EduCBTPro\Services\SubjectSeederService() )->refresh( $school_id );

        $this->succeed(
            [
                'type'    => 'subjects_refreshed',
                'added'   => (int) $result['added'],
                'retired' => (int) $result['retired'],
                'removed' => (int) $result['removed'],
            ]
        );
    }

    public function delete_subject(): void {
        [ $school_id ] = $this->context( 'educbt_delete_subject' );

        if ( ! Gate::allows( Capabilities::MANAGE_SUBJECTS ) ) {
            $this->fail( 'You do not have permission to manage subjects.' );
        }

        global $wpdb;

        $subject_id = absint( $_POST['subject_id'] ?? 0 );
        $table      = \EduCBTPro\Core\Schema::table( 'subjects_v2' );

        $subject = $wpdb->get_row(
            $wpdb->prepare( "SELECT name FROM {$table} WHERE id = %d AND school_id = %d", $subject_id, $school_id ),
            ARRAY_A
        );

        if ( ! $subject ) {
            $this->fail( 'That subject could not be found.' );
        }

        // Is it referenced anywhere that matters?
        $in_use = 0;

        foreach ( [ 'subject_results', 'assessment_scores', 'student_subjects', 'questions' ] as $short ) {
            $t = $short === 'questions'
                ? $wpdb->prefix . 'educbt_questions'
                : \EduCBTPro\Core\Schema::table( $short );

            $in_use += absint(
                $wpdb->get_var(
                    $wpdb->prepare( "SELECT COUNT(*) FROM `{$t}` WHERE subject_id = %d", $subject_id )
                )
            );
        }

        if ( $in_use > 0 ) {
            $wpdb->update( $table, [ 'status' => 'retired' ], [ 'id' => $subject_id, 'school_id' => $school_id ], [ '%s' ], [ '%d', '%d' ] );

            $this->succeed(
                [
                    'type'    => 'subject_retired',
                    'name'    => (string) $subject['name'],
                    'records' => $in_use,
                ]
            );
        }

        $wpdb->delete( $table, [ 'id' => $subject_id, 'school_id' => $school_id ], [ '%d', '%d' ] );

        $this->succeed( [ 'type' => 'subject_deleted', 'name' => (string) $subject['name'] ] );
    }

    /**
     * Save one question from the builder.
     */
    /**
     * A question written by someone who cannot approve goes in as pending; one
     * written by the exam officer or principal is approved on the spot, since asking
     * them to approve their own work is theatre.
     */
    private function initial_approval(): string {
        return Gate::allows( Capabilities::APPROVE_QUESTIONS )
            ? \EduCBTPro\Services\QuestionApprovalService::APPROVED
            : \EduCBTPro\Services\QuestionApprovalService::PENDING;
    }

    public function save_question(): void {
        [ $school_id, $scope ] = $this->context( 'educbt_save_question' );

        $subject_id = absint( $_POST['subject_id'] ?? 0 );

        if ( ! Gate::allows( Capabilities::WRITE_QUESTIONS, [ 'subject_id' => $subject_id ] ) ) {
            $this->fail( 'You do not have permission to write questions for that subject.' );
        }

        // Gate: block saves when exam prep is closed (but allow school-wide reviewers).
        if ( ! ( new \EduCBTPro\Services\SchoolService() )->is_exam_prep_enabled( $school_id ) ) {
            if ( ! $scope->is_school_wide() ) {
                $this->fail( 'Exam preparation is currently closed. New questions cannot be submitted until the principal opens it.' );
            }
        }

        $type = sanitize_key( (string) wp_unslash( $_POST['question_type'] ?? '' ) );

        // A passage may be chosen or created inline. Created once, reused by id.
        $passage_id = absint( $_POST['passage_id'] ?? 0 );
        $new_title  = trim( (string) wp_unslash( $_POST['passage_title'] ?? '' ) );
        $new_body   = trim( (string) wp_unslash( $_POST['passage_body'] ?? '' ) );

        if ( $passage_id === 0 && $new_body !== '' ) {
            $created = ( new \EduCBTPro\Services\PassageService() )->create(
                $school_id,
                [
                    'title'      => $new_title !== '' ? $new_title : 'Read the following',
                    'body'       => $new_body,
                    'subject_id' => $subject_id,
                ]
            );

            $passage_id = absint( $created['passage_id'] ?? 0 );
        }

        // A written question has no options and no correct answer — it is marked by
        // a human afterwards. Sending it down the MCQ path would fail validation for
        // the entirely wrong reason.
        if ( $type === \EduCBTPro\Services\QuestionBankService::TYPE_THEORY ) {
            $text = trim( (string) wp_unslash( $_POST['question_text'] ?? '' ) );

            if ( $text === '' ) {
                $this->fail( 'A written question still needs the question itself.' );
            }

            global $wpdb;

            $wpdb->insert(
                $wpdb->prefix . 'educbt_questions',
                [
                    'school_id'       => $school_id,
                    'subject_id'      => $subject_id,
                    'question_type'   => \EduCBTPro\Services\QuestionBankService::TYPE_THEORY,
                    'class_level'     => sanitize_text_field( (string) wp_unslash( $_POST['class_level'] ?? '' ) ),
                    'question_text'   => wp_kses_post( $text ),
                    'image_reference' => esc_url_raw( (string) wp_unslash( $_POST['question_image'] ?? '' ) ),
                    'marks'           => (float) ( $_POST['marks'] ?? 1 ),
                    // The marking guide rides in `explanations`: it is the same idea,
                    // and it is never delivered to a student.
                    'explanations'    => wp_kses_post( (string) wp_unslash( $_POST['marking_guide'] ?? '' ) ),
                    'passage_id'       => $passage_id ?: null,
                    'status'           => 'active',
                    'approval_status'  => $this->initial_approval(),
                    'created_by_staff' => (int) $scope->actor()['id'],
                    'created_at'       => current_time( 'mysql', true ),
                ],
                [ '%d', '%d', '%s', '%s', '%s', '%s', '%f', '%s', '%d', '%s', '%s', '%d', '%s' ]
            );

            $this->succeed( [ 'type' => 'question', 'warnings' => [ 'written question — remember to mark it after the exam' ] ] );
        }

        $options = [];

        foreach ( [ 'A', 'B', 'C', 'D', 'E', 'F' ] as $key ) {
            $text  = (string) wp_unslash( $_POST[ 'option_' . $key ] ?? '' );
            $image = (string) wp_unslash( $_POST[ 'option_image_' . $key ] ?? '' );

            $options[] = [ 'text' => $text, 'image' => $image ];
        }

        $payload = ( new \EduCBTPro\Services\QuestionAuthoringService() )->normalise_payload(
            [
                'subject_id'     => $subject_id,
                'class_level'    => sanitize_text_field( (string) wp_unslash( $_POST['class_level'] ?? '' ) ),
                // Topic and difficulty were asked for on every question and used by
                // nothing a school could see. Difficulty still exists in the schema for
                // stratified composition, but defaulting it is honest: a teacher
                // guessing "medium" for every question was not real data.
                'topic'          => '',
                'difficulty'     => 'medium',
                'marks'          => (float) ( $_POST['marks'] ?? 1 ),
                'question_text'  => (string) wp_unslash( $_POST['question_text'] ?? '' ),
                'question_image' => (string) wp_unslash( $_POST['question_image'] ?? '' ),
                'passage_id'     => $passage_id,
                'correct'        => (string) wp_unslash( $_POST['correct'] ?? '' ),
                'options'        => $options,
            ]
        );

        $authoring = new \EduCBTPro\Services\QuestionAuthoringService();
        $check     = $authoring->validate_payload( $payload );

        if ( ! $check['valid'] ) {
            $this->fail( $this->readable( $check['errors'] ) );
        }

        $result = ( new \EduCBTPro\Services\QuestionBankService() )->create(
            $school_id,
            $authoring->payload_to_question( $payload )
        );

        if ( empty( $result['success'] ) ) {
            $this->fail( $this->readable( $result['errors'] ?? [] ) );
        }

        $this->stamp_authorship( $school_id, [ (int) $result['question_id'] ], (int) $scope->actor()['id'] );

        $this->succeed( [ 'type' => 'question', 'warnings' => $check['warnings'] ] );
    }

    public function save_passage(): void {
        [ $school_id ] = $this->context( 'educbt_save_passage' );

        if ( ! Gate::allows( Capabilities::WRITE_QUESTIONS ) ) {
            $this->fail( 'You do not have permission to add a passage.' );
        }

        $body = trim( (string) wp_unslash( $_POST['passage_body'] ?? '' ) );

        if ( $body === '' ) {
            $this->fail( 'A passage needs its text.' );
        }

        $result = ( new \EduCBTPro\Services\PassageService() )->create(
            $school_id,
            [
                'title' => sanitize_text_field( (string) wp_unslash( $_POST['passage_title'] ?? '' ) ) ?: 'Read the following',
                'body'  => wp_kses_post( $body ),
            ]
        );

        if ( empty( $result['passage_id'] ) ) {
            $this->fail( 'That passage could not be saved.' );
        }

        $this->succeed( [ 'type' => 'passage_saved' ] );
    }

    public function update_question(): void {
        [ $school_id ] = $this->context( 'educbt_update_question' );

        global $wpdb;

        $question_id = absint( $_POST['question_id'] ?? 0 );
        $questions   = $wpdb->prefix . 'educbt_questions';

        $subject_id = absint(
            $wpdb->get_var(
                $wpdb->prepare( "SELECT subject_id FROM {$questions} WHERE id = %d AND school_id = %d", $question_id, $school_id )
            )
        );

        if ( ! Gate::allows( Capabilities::WRITE_QUESTIONS, [ 'subject_id' => $subject_id ] ) ) {
            $this->fail( 'You can only edit questions for a subject you teach.' );
        }

        $text = trim( (string) wp_unslash( $_POST['question_text'] ?? '' ) );

        if ( $text === '' ) {
            $this->fail( 'A question needs its text.' );
        }

        $wpdb->update(
            $questions,
            [ 'question_text' => wp_kses_post( $text ), 'marks' => (float) ( $_POST['marks'] ?? 1 ) ],
            [ 'id' => $question_id, 'school_id' => $school_id ],
            [ '%s', '%f' ],
            [ '%d', '%d' ]
        );

        $options_table = \EduCBTPro\Core\Schema::table( 'question_options' );
        $correct_id    = absint( $_POST['correct'] ?? 0 );

        foreach ( (array) ( $_POST['option'] ?? [] ) as $option_id => $option_text ) {
            $option_id = absint( $option_id );

            $wpdb->update(
                $options_table,
                [
                    'option_text' => sanitize_text_field( (string) wp_unslash( $option_text ) ),
                    'is_correct'  => $option_id === $correct_id ? 1 : 0,
                ],
                [ 'id' => $option_id, 'question_id' => $question_id ],
                [ '%s', '%d' ],
                [ '%d', '%d' ]
            );
        }

        $this->succeed( [ 'type' => 'question_updated' ] );
    }

    public function delete_question(): void {
        [ $school_id ] = $this->context( 'educbt_delete_question' );

        global $wpdb;

        $question_id = absint( $_POST['question_id'] ?? 0 );
        $questions   = $wpdb->prefix . 'educbt_questions';

        $subject_id = absint(
            $wpdb->get_var(
                $wpdb->prepare( "SELECT subject_id FROM {$questions} WHERE id = %d AND school_id = %d", $question_id, $school_id )
            )
        );

        if ( ! Gate::allows( Capabilities::WRITE_QUESTIONS, [ 'subject_id' => $subject_id ] ) ) {
            $this->fail( 'You can only remove questions for a subject you teach.' );
        }

        // A question already used in a sat paper is never deleted: the attempts point
        // at it, and removing it would leave marked answers referring to nothing.
        $used = absint(
            $wpdb->get_var(
                $wpdb->prepare(
                    'SELECT COUNT(*) FROM ' . \EduCBTPro\Core\Schema::table( 'attempt_answers' ) . ' WHERE question_id = %d',
                    $question_id
                )
            )
        );

        if ( $used > 0 ) {
            $this->fail( 'That question has already been answered in an exam, so it cannot be removed. It can still be edited.' );
        }

        $wpdb->update(
            $questions,
            [ 'status' => 'archived' ],
            [ 'id' => $question_id, 'school_id' => $school_id ],
            [ '%s' ],
            [ '%d', '%d' ]
        );

        $this->succeed( [ 'type' => 'question_deleted' ] );
    }

    public function decide_questions(): void {
        [ $school_id ] = $this->context( 'educbt_decide_questions' );

        // Exam officer, vice principal and principal all hold this.
        if ( ! Gate::allows( Capabilities::APPROVE_QUESTIONS ) ) {
            $this->fail( 'You do not have permission to approve questions.' );
        }

        $result = ( new \EduCBTPro\Services\QuestionApprovalService() )->decide(
            $school_id,
            absint( $_POST['subject_id'] ?? 0 ),
            absint( $_POST['staff_id'] ?? 0 ),
            sanitize_key( (string) wp_unslash( $_POST['decision'] ?? '' ) ),
            (string) wp_unslash( $_POST['note'] ?? '' ),
            get_current_user_id(),
            array_map( 'absint', (array) ( $_POST['question_ids'] ?? [] ) )
        );

        if ( empty( $result['success'] ) ) {
            $this->fail(
                ( $result['error'] ?? '' ) === 'note_required'
                    ? 'Say what needs changing before sending work back — a rejection with no reason is an obstacle, not a review.'
                    : 'That decision could not be recorded.'
            );
        }

        $this->succeed(
            [
                'type'     => 'questions_reviewed',
                'decision' => sanitize_key( (string) wp_unslash( $_POST['decision'] ?? '' ) ),
                'changed'  => (int) $result['changed'],
                'sets'     => (int) ( $result['sets'] ?? 0 ),
            ]
        );
    }

    public function remind_questions(): void {
        [ $school_id ] = $this->context( 'educbt_remind_questions' );

        if ( ! Gate::allows( Capabilities::APPROVE_QUESTIONS ) ) {
            $this->fail( 'You do not have permission to send that reminder.' );
        }

        $result = ( new \EduCBTPro\Services\QuestionApprovalService() )->remind(
            $school_id,
            absint( $_POST['subject_id'] ?? 0 ),
            absint( $_POST['staff_id'] ?? 0 )
        );

        if ( empty( $result['success'] ) ) {
            $this->fail( 'That teacher has no portal account to notify.' );
        }

        $this->succeed( [ 'type' => 'reminder_sent', 'message' => (string) $result['message'] ] );
    }

    /**
     * Nudge a teacher who still has written answers to mark.
     *
     * Deliberately does nothing except send the notification — the exam office asked
     * for a reminder, not a state change.
     */

    /**
     * Create the examination itself. Subject, class, date and duration are NOT asked
     * for here — the timetable is generated later from what teachers have actually
     * submitted, rather than the exam office guessing a schedule up front.
     */

    /**
     * Open a continuous assessment window.
     *
     * The dates belong to the school office, not to teachers. A test every class
     * sits on a different afternoon is not a school assessment, it is thirty
     * separate quizzes that cannot be compared.
     */
    public function create_ca_window(): void {
        [ $school_id ] = $this->context( 'educbt_create_ca_window' );

        if ( ! Gate::allows( Capabilities::MANAGE_EXAM_SERIES ) ) {
            $this->fail( 'You do not have permission to open an assessment window.' );
        }

        $ay         = new \EduCBTPro\Services\AcademicYearService();
        $session    = $ay->current_session( $school_id );
        $session_id = absint( $session['id'] ?? 0 );
        $term       = $ay->resolve_current_term( $school_id, $session_id );

        $result = ( new \EduCBTPro\Services\CaTestWindowService() )->create_window(
            $school_id,
            [
                'session_id'            => $session_id,
                'term_id'               => absint( $term['id'] ?? 0 ),
                'component_id'          => absint( $_POST['component_id'] ?? 0 ),
                'title'                 => (string) wp_unslash( $_POST['title'] ?? '' ),
                'starts_on'             => (string) wp_unslash( $_POST['starts_on'] ?? '' ),
                'ends_on'               => (string) wp_unslash( $_POST['ends_on'] ?? '' ),
                'questions_per_student' => absint( $_POST['questions_per_student'] ?? 20 ),
                'duration_minutes'      => absint( $_POST['duration_minutes'] ?? 30 ),
            ],
            absint( ( new Scope() )->actor()['id'] ?? 0 )
        );

        if ( empty( $result['success'] ) ) {
            $messages = [
                'missing_term'      => 'No current session or term is set.',
                'missing_component' => 'Choose which assessment the marks should count towards.',
                'missing_dates'     => 'Give the window an opening and a closing date.',
                'dates_reversed'    => 'The closing date cannot fall before the opening date.',
                'window_exists'     => 'A window is already open for that assessment this term.',
            ];

            $this->fail( $messages[ $result['error'] ?? '' ] ?? 'That window could not be opened.' );
        }

        // Opening a window points the question bank at it, and closes whatever was
        // open before. A term runs first CA, second CA, then examinations, so the
        // windows run in sequence too.
        ( new \EduCBTPro\Services\QuestionWindowService() )->open(
            $school_id,
            absint( $result['series_id'] ?? 0 ),
            'ca_test'
        );

        $this->succeed( [ 'type' => 'ca_window_created' ] );
    }

    /**
     * Compose the papers for a CA window.
     */

    /**
     * Open the question bank for a series, or shut it entirely.
     *
     * Between assessments the right state is shut: nothing is being collected, so
     * nothing can be written into the wrong place.
     */

    /**
     * Delete an examination or a continuous assessment.
     *
     * Refused once anything has been SAT. An attempt is a record of a student in a
     * hall on a particular morning, and the marks derived from it sit in their
     * results — removing the examination underneath that would leave results
     * referring to something that no longer exists.
     *
     * Before that point there is nothing to protect, so the series, its papers and
     * its question sets go together. Questions themselves are left in the bank: the
     * vault holds them, and a teacher's work should not disappear because the office
     * deleted a window.
     */

    /**
     * Create the school's practice exam.
     *
     * A practice exam is not scheduled and not reviewed. Students should be able to
     * sit it whenever they like, which means:
     *
     *   - no timetable, because there is no sitting to arrange
     *   - no approval, because nothing rests on the result
     *   - always open for questions, so a teacher can add to it at any point in the
     *     term without the office reopening the bank
     *
     * One per school per term is enough. A second would only split the pool of
     * practice questions in two for no benefit.
     */
    public function create_practice(): void {
        [ $school_id ] = $this->context( 'educbt_create_practice' );

        if ( ! Gate::allows( Capabilities::MANAGE_EXAM_SERIES ) ) {
            $this->fail( 'You do not have permission to create a practice exam.' );
        }

        global $wpdb;

        $ay         = new \EduCBTPro\Services\AcademicYearService();
        $session    = $ay->current_session( $school_id );
        $session_id = absint( $session['id'] ?? 0 );
        $term       = $ay->resolve_current_term( $school_id, $session_id );
        $term_id    = absint( $term['id'] ?? 0 );

        $table = \EduCBTPro\Core\Schema::table( 'exam_series' );

        $existing = absint(
            $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT id FROM {$table}
                     WHERE school_id = %d AND session_id = %d AND COALESCE(term_id,0) = %d
                       AND series_type = 'practice' LIMIT 1",
                    $school_id,
                    $session_id,
                    $term_id
                )
            )
        );

        if ( $existing > 0 ) {
            $this->fail( 'A practice exam already exists for this term. Teachers can keep adding questions to it.' );
        }

        $wpdb->insert(
            $table,
            [
                'school_id'   => $school_id,
                'session_id'  => $session_id,
                'term_id'     => $term_id,
                'title'       => 'Practice Exam',
                'series_type' => 'practice',
                // Published from the moment it exists: there is nothing to wait for.
                'status'      => 'published',
                'created_by'  => absint( ( new Scope() )->actor()['id'] ?? 0 ) ?: null,
            ]
        );

        $series_id = absint( $wpdb->insert_id );

        if ( $series_id <= 0 ) {
            $this->fail( 'That practice exam could not be created.' );
        }

        $this->succeed( [ 'type' => 'practice_created' ] );
    }

    public function delete_series(): void {
        [ $school_id ] = $this->context( 'educbt_delete_series' );

        if ( ! Gate::allows( Capabilities::MANAGE_EXAM_SERIES ) ) {
            $this->fail( 'You do not have permission to delete this.' );
        }

        global $wpdb;

        $series_id = absint( $_POST['series_id'] ?? 0 );
        $series_t  = \EduCBTPro\Core\Schema::table( 'exam_series' );
        $papers_t  = \EduCBTPro\Core\Schema::table( 'exam_papers' );
        $attempts  = \EduCBTPro\Core\Schema::table( 'attempts' );
        $sets_t    = \EduCBTPro\Core\Schema::table( 'question_sets' );

        $series = $wpdb->get_row(
            $wpdb->prepare( "SELECT title, series_type FROM {$series_t} WHERE id = %d AND school_id = %d", $series_id, $school_id ),
            ARRAY_A
        );

        if ( ! $series ) {
            $this->fail( 'That examination could not be found.' );
        }

        $sat = absint(
            $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$attempts} a
                     INNER JOIN {$papers_t} p ON p.id = a.paper_id
                     WHERE p.series_id = %d",
                    $series_id
                )
            )
        );

        if ( $sat > 0 ) {
            $this->fail(
                sprintf(
                    '%s cannot be deleted: %d student attempt(s) have been recorded against it. Results refer to those sittings.',
                    (string) $series['title'],
                    $sat
                )
            );
        }

        // Detach question sets rather than deleting them, so the questions survive.
        $wpdb->update( $sets_t, [ 'series_id' => null ], [ 'series_id' => $series_id ], [ '%s' ], [ '%d' ] );

        $wpdb->delete( $papers_t, [ 'series_id' => $series_id, 'school_id' => $school_id ], [ '%d', '%d' ] );
        $wpdb->delete( $series_t, [ 'id' => $series_id, 'school_id' => $school_id ], [ '%d', '%d' ] );

        // If the bank was pointing at it, shut the bank rather than leave it aimed
        // at something that no longer exists.
        $window_service = new \EduCBTPro\Services\QuestionWindowService();
        $open           = $window_service->current( $school_id );

        if ( $open && absint( $open['series_id'] ) === $series_id ) {
            $window_service->close( $school_id );
        }

        $this->succeed(
            [
                'type'  => 'series_deleted',
                'title' => (string) $series['title'],
                'is_ca' => (string) $series['series_type'] === \EduCBTPro\Services\CaTestWindowService::SERIES_TYPE,
            ]
        );
    }

    public function set_question_window(): void {
        [ $school_id ] = $this->context( 'educbt_set_question_window' );

        if ( ! Gate::allows( Capabilities::MANAGE_EXAM_SERIES ) ) {
            $this->fail( 'You do not have permission to open or close the question bank.' );
        }

        $service   = new \EduCBTPro\Services\QuestionWindowService();
        $series_id = absint( $_POST['series_id'] ?? 0 );

        if ( $series_id <= 0 ) {
            $service->close( $school_id );
            $this->succeed( [ 'type' => 'question_window', 'state' => 'closed', 'title' => '' ] );
        }

        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT title, series_type FROM ' . \EduCBTPro\Core\Schema::table( 'exam_series' ) . '
                 WHERE id = %d AND school_id = %d',
                $series_id,
                $school_id
            ),
            ARRAY_A
        );

        if ( ! $row ) {
            $this->fail( 'That examination or assessment could not be found.' );
        }

        $is_ca = (string) $row['series_type'] === \EduCBTPro\Services\CaTestWindowService::SERIES_TYPE;

        $service->open( $school_id, $series_id, $is_ca ? 'ca_test' : 'exam' );

        $this->succeed(
            [
                'type'  => 'question_window',
                'state' => 'open',
                'title' => (string) $row['title'],
            ]
        );
    }

    public function compose_ca_window(): void {
        [ $school_id ] = $this->context( 'educbt_compose_ca_window' );

        if ( ! Gate::allows( Capabilities::MANAGE_PAPERS ) ) {
            $this->fail( 'You do not have permission to compose these papers.' );
        }

        $result = ( new \EduCBTPro\Services\CaTestWindowService() )->compose(
            $school_id,
            absint( $_POST['series_id'] ?? 0 )
        );

        if ( empty( $result['success'] ) ) {
            $this->fail(
                ( $result['error'] ?? '' ) === 'nothing_written'
                    ? 'No teacher has written questions for this assessment yet, so there is nothing to compose.'
                    : 'Those papers could not be composed.'
            );
        }

        $this->succeed(
            [
                'type'    => 'ca_window_composed',
                'created' => (int) $result['created'],
                'skipped' => (int) $result['skipped'],
                'short'   => (array) ( $result['short'] ?? [] ),
            ]
        );
    }

    public function create_examination(): void {
        [ $school_id ] = $this->context( 'educbt_create_examination' );

        if ( ! Gate::allows( Capabilities::MANAGE_EXAM_SERIES ) ) {
            $this->fail( 'You do not have permission to create an examination.' );
        }

        global $wpdb;

        $session_id = absint( $_POST['session_id'] ?? 0 );
        $term_id    = absint( $_POST['term_id'] ?? 0 );
        $title      = sanitize_text_field( (string) wp_unslash( $_POST['title'] ?? '' ) );

        if ( $session_id <= 0 || $term_id <= 0 ) {
            $this->fail( 'Choose the session and term this examination covers.' );
        }

        if ( $title === '' ) {
            $term_title = (string) $wpdb->get_var(
                $wpdb->prepare(
                    'SELECT title FROM ' . \EduCBTPro\Core\Schema::table( 'terms' ) . ' WHERE id = %d',
                    $term_id
                )
            );
            $title = trim( $term_title . ' Examination' );
        }

        $result = ( new \EduCBTPro\Services\ExamPaperService() )->create_series(
            $school_id,
            [
                'session_id' => $session_id,
                'term_id'    => $term_id,
                'title'      => $title,
                'starts_on'  => sanitize_text_field( (string) wp_unslash( $_POST['starts_on'] ?? '' ) ),
                'ends_on'    => sanitize_text_field( (string) wp_unslash( $_POST['ends_on'] ?? '' ) ),
            ]
        );

        if ( empty( $result['success'] ) ) {
            $this->fail( 'That examination could not be created: ' . ( $result['error'] ?? 'unknown' ) );
        }

        ( new \EduCBTPro\Services\QuestionWindowService() )->open(
            $school_id,
            absint( $result['series_id'] ?? 0 ),
            'exam'
        );

        $this->succeed( [ 'type' => 'examination_created', 'title' => $title, 'series_id' => (int) $result['series_id'] ] );
    }

    public function generate_timetable(): void {
        [ $school_id ] = $this->context( 'educbt_generate_timetable' );

        if ( ! Gate::allows( Capabilities::MANAGE_PAPERS ) ) {
            $this->fail( 'You do not have permission to build the timetable.' );
        }

        $result = ( new \EduCBTPro\Services\ExamTimetableService() )->generate_for_series(
            $school_id,
            absint( $_POST['series_id'] ?? 0 ),
            sanitize_text_field( (string) wp_unslash( $_POST['starts_on'] ?? '' ) ),
            [],
            sanitize_text_field( (string) wp_unslash( $_POST['ends_on'] ?? '' ) ),
            ! empty( $_POST['regenerate'] )
        );

        if ( empty( $result['success'] ) ) {
            $this->fail(
                ( $result['error'] ?? '' ) === 'nothing_approved'
                    ? 'No question sets have been approved for this examination yet, so there is nothing to schedule.'
                    : ( ( $result['error'] ?? '' ) === 'window_too_short'
                        ? 'The last day of sitting falls before the first, so there are no days to schedule into.'
                        : 'That timetable could not be generated.' )
            );
        }

        $this->succeed(
            [
                'type'     => 'timetable_generated',
                'created'  => (int) $result['created'],
                'skipped'  => (int) $result['skipped'],
                'unplaced' => (int) ( $result['unplaced'] ?? 0 ),
                'days'     => (int) ( $result['days'] ?? 0 ),
            ]
        );
    }

    public function notify_class_teachers(): void {
        [ $school_id ] = $this->context( 'educbt_notify_class_teachers' );

        if ( ! Gate::allows( Capabilities::MANAGE_PAPERS ) ) {
            $this->fail( 'You do not have permission to send the timetable.' );
        }

        $result = ( new \EduCBTPro\Services\ExamTimetableService() )->notify_class_teachers(
            $school_id,
            absint( $_POST['series_id'] ?? 0 )
        );

        if ( (int) $result['sent'] === 0 ) {
            $this->fail( 'Nobody was notified — no class teacher with a portal account was found for the scheduled classes.' );
        }

        $this->succeed( [ 'type' => 'timetable_sent', 'sent' => (int) $result['sent'], 'skipped' => (int) $result['skipped'] ] );
    }


    /**
     * A student saves their own elective choices.
     *
     * The service enforces the rules that matter — core subjects cannot be dropped,
     * a closed window cannot be reopened, and a subject already sat or marked stays.
     */
    public function student_electives(): void {
        [ $school_id ] = $this->context( 'educbt_student_electives' );

        $scope = new Scope();
        $actor = $scope->actor();

        if ( ( $actor['type'] ?? '' ) !== 'student' ) {
            error_log( 'EduCBT student_electives: actor type=' . ( $actor['type'] ?? 'none' ) . ' (expected student) user=' . get_current_user_id() );
            $this->fail( 'Only a student can choose their own subjects.' );
        }

        $session_id = absint( ( new \EduCBTPro\Services\AcademicYearService() )->current_session( $school_id )['id'] ?? 0 );

        if ( $session_id <= 0 ) {
            error_log( 'EduCBT student_electives: no current session school=' . $school_id );
            $this->fail( 'No academic session is currently set.' );
        }

        $elective_ids = array_map( 'absint', (array) ( $_POST['elective_ids'] ?? [] ) );

        error_log( 'EduCBT student_electives: school=' . $school_id . ' student=' . $actor['id'] . ' session=' . $session_id . ' electives=[' . implode( ',', $elective_ids ) . ']' );

        $result = ( new \EduCBTPro\Services\AcademicStructureService() )->set_student_electives(
            $school_id,
            absint( $actor['id'] ),
            $session_id,
            $elective_ids
        );

        if ( empty( $result['success'] ) ) {
            $msg = $this->readable( $result['errors'] ?? [] );
            error_log( 'EduCBT student_electives: save FAILED — ' . $msg . ' errors=[' . implode( ', ', ( $result['errors'] ?? [] ) ) . ']' );
            $this->fail( $msg );
        }

        error_log( 'EduCBT student_electives: save OK total=' . (int) ( $result['total'] ?? 0 ) );
        $this->succeed( [ 'type' => 'subjects_registered', 'total' => (int) ( $result['total'] ?? 0 ) ] );
    }

    /**
     * A class teacher registers the compulsory subjects for a whole class at once.
     *
     * This is the "generally" case: every student in the class gets the core set for
     * their level, without the teacher opening thirty profiles. Electives are left
     * exactly as each student already has them.
     */
    public function register_class_subjects(): void {
        [ $school_id ] = $this->context( 'educbt_register_class_subjects' );

        $class_id = absint( $_POST['class_id'] ?? 0 );

        if ( ! Gate::allows( Capabilities::MANAGE_STUDENTS, [ 'class_id' => $class_id ] )
             && ! Gate::allows( Capabilities::PLACE_STUDENTS, [ 'class_id' => $class_id ] ) ) {
            $this->fail( 'You do not have permission to register subjects for that class.' );
        }

        global $wpdb;

        $session_id = absint( ( new \EduCBTPro\Services\AcademicYearService() )->current_session( $school_id )['id'] ?? 0 );

        if ( $class_id <= 0 || $session_id <= 0 ) {
            $this->fail( 'Choose a class first.' );
        }

        $structure = new \EduCBTPro\Services\AcademicStructureService();

        $class = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT level_id, department_id FROM ' . \EduCBTPro\Core\Schema::table( 'classes' ) . '
                 WHERE id = %d AND school_id = %d',
                $class_id,
                $school_id
            ),
            ARRAY_A
        );

        if ( ! $class ) {
            $this->fail( 'That class could not be found.' );
        }

        $department_id = $class['department_id'] !== null ? absint( $class['department_id'] ) : null;
        $split         = $structure->offering_split( $school_id, absint( $class['level_id'] ), $department_id );
        $core_ids      = array_map( static fn( array $s ): int => absint( $s['id'] ), (array) $split['core'] );

        if ( empty( $core_ids ) ) {
            $this->fail( 'That class level has no compulsory subjects defined yet. Set them under Subjects first.' );
        }

        $students = array_map(
            'absint',
            (array) $wpdb->get_col(
                $wpdb->prepare(
                    'SELECT student_id FROM ' . \EduCBTPro\Core\Schema::table( 'enrollments' ) . "
                     WHERE school_id = %d AND class_id = %d AND session_id = %d AND status = 'active'",
                    $school_id,
                    $class_id,
                    $session_id
                )
            )
        );

        if ( empty( $students ) ) {
            $this->fail( 'There are no active students in that class.' );
        }

        $registered = \EduCBTPro\Core\Schema::table( 'student_subjects' );
        $applied    = 0;

        foreach ( $students as $student_id ) {
            $existing = array_map(
                'absint',
                (array) $wpdb->get_col(
                    $wpdb->prepare(
                        "SELECT subject_id FROM {$registered} WHERE student_id = %d AND session_id = %d",
                        $student_id,
                        $session_id
                    )
                )
            );

            $missing = array_diff( $core_ids, $existing );

            if ( empty( $missing ) ) {
                continue;
            }

            // Add only what is missing. Rewriting the row set would wipe the
            // electives a student has already chosen for themselves.
            foreach ( $missing as $subject_id ) {
                $wpdb->insert(
                    $registered,
                    [
                        'school_id'  => $school_id,
                        'student_id' => $student_id,
                        'subject_id' => absint( $subject_id ),
                        'session_id' => $session_id,
                    ],
                    [ '%d', '%d', '%d', '%d' ]
                );
            }

            $applied++;
        }

        $this->succeed(
            [
                'type'     => 'class_subjects_registered',
                'students' => $applied,
                'subjects' => count( $core_ids ),
                'total'    => count( $students ),
            ]
        );
    }

    /**
     * A class teacher adds or removes subjects for ONE student.
     *
     * Same rules as the student's own screen, but the teacher may also set electives
     * on the student's behalf — which is the common case at intake.
     */
    public function register_student_subjects(): void {
        [ $school_id ] = $this->context( 'educbt_register_student_subjects' );

        global $wpdb;

        $student_id = absint( $_POST['student_id'] ?? 0 );

        $class_id = absint(
            $wpdb->get_var(
                $wpdb->prepare(
                    'SELECT class_id FROM ' . \EduCBTPro\Core\Schema::table( 'enrollments' ) . "
                     WHERE student_id = %d AND status = 'active' LIMIT 1",
                    $student_id
                )
            )
        );

        if ( ! Gate::allows( Capabilities::MANAGE_STUDENTS, [ 'class_id' => $class_id ] )
             && ! Gate::allows( Capabilities::PLACE_STUDENTS, [ 'class_id' => $class_id ] ) ) {
            $this->fail( 'You do not have permission to change that student\'s subjects.' );
        }

        $session_id = absint( ( new \EduCBTPro\Services\AcademicYearService() )->current_session( $school_id )['id'] ?? 0 );

        if ( $student_id <= 0 || $session_id <= 0 ) {
            $this->fail( 'Choose a student first.' );
        }

        $result = ( new \EduCBTPro\Services\AcademicStructureService() )->set_student_electives(
            $school_id,
            $student_id,
            $session_id,
            array_map( 'absint', (array) ( $_POST['elective_ids'] ?? [] ) )
        );

        if ( empty( $result['success'] ) ) {
            $this->fail( $this->readable( $result['errors'] ?? [] ) );
        }

        $this->succeed( [ 'type' => 'subjects_registered', 'total' => (int) ( $result['total'] ?? 0 ) ] );
    }


    /**
     * Take a durable copy of the whole question bank now.
     */
    public function vault_backup(): void {
        [ $school_id ] = $this->context( 'educbt_vault_backup' );

        if ( ! Gate::allows( Capabilities::APPROVE_QUESTIONS ) ) {
            $this->fail( 'You do not have permission to back up the question bank.' );
        }

        $result = ( new \EduCBTPro\Services\QuestionVaultService() )->snapshot_school( $school_id );

        $this->succeed(
            [
                'type'      => 'vault_backup',
                'sets'      => (int) $result['sets'],
                'questions' => (int) $result['questions'],
            ]
        );
    }

    /**
     * Put the question bank back from the durable copy.
     */
    public function vault_restore(): void {
        [ $school_id ] = $this->context( 'educbt_vault_restore' );

        if ( ! Gate::allows( Capabilities::APPROVE_QUESTIONS ) ) {
            $this->fail( 'You do not have permission to restore the question bank.' );
        }

        // These handlers end in a redirect, so NOTHING may be printed before then.
        //
        // A database notice echoed mid-restore is what produced "cannot modify
        // header information" from pluggable.php: WordPress had already been given
        // output, so wp_safe_redirect() could not send its header. Suppressing
        // during the work keeps the redirect intact; the outcome is reported through
        // the flash notice, which is where the user is actually looking.
        global $wpdb;

        $prior_suppress = $wpdb->suppress_errors( true );
        $prior_show     = $wpdb->hide_errors();

        $result = ( new \EduCBTPro\Services\QuestionVaultService() )->restore_school( $school_id );

        $wpdb->suppress_errors( $prior_suppress );

        if ( $prior_show ) {
            $wpdb->show_errors();
        }

        if ( (int) $result['restored'] === 0 && (int) $result['skipped'] === 0 ) {
            $this->fail( 'There is nothing in the vault for this school yet.' );
        }

        $this->succeed(
            [
                'type'     => 'vault_restore',
                'restored' => (int) $result['restored'],
                'skipped'  => (int) $result['skipped'],
                'sets'     => (int) $result['sets'],
            ]
        );
    }

    public function remind_marking(): void {
        [ $school_id ] = $this->context( 'educbt_remind_marking' );

        if ( ! Gate::allows( Capabilities::VIEW_EXAMS ) || ! ( new Scope() )->is_school_wide() ) {
            $this->fail( 'You do not have permission to send that reminder.' );
        }

        global $wpdb;

        $staff_id    = absint( $_POST['staff_id'] ?? 0 );
        $outstanding = absint( $_POST['outstanding'] ?? 0 );

        $staff = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT wp_user_id, first_name FROM ' . \EduCBTPro\Core\Schema::table( 'staff' ) . '
                 WHERE id = %d AND school_id = %d',
                $staff_id,
                $school_id
            ),
            ARRAY_A
        );

        $user_id = absint( $staff['wp_user_id'] ?? 0 );

        if ( $user_id <= 0 ) {
            $this->fail( 'That teacher has no portal account to notify.' );
        }

        $sent = ( new \EduCBTPro\Services\NotificationService() )->notify(
            $school_id,
            $user_id,
            \EduCBTPro\Services\NotificationService::SCORE_SUBMITTED,
            'Marking outstanding',
            trim( (string) ( $staff['first_name'] ?? '' ) ) . ', you have ' . $outstanding
                . ' written answer(s) still to mark. Results cannot be compiled until marking is complete.',
            home_url( '/portal/exams/marking/' )
        );

        if ( $sent <= 0 ) {
            $this->fail( 'The reminder could not be sent.' );
        }

        $this->succeed( [ 'type' => 'reminder_sent', 'message' => 'Marking reminder sent.' ] );
    }

    /**
     * Reply to a notification: flag it, mark a flag resolved, or report a problem.
     *
     * The reply goes back to whoever sent the notice, so a teacher who cannot act on
     * a reminder has a way to say so without leaving the portal and without the exam
     * office having to guess why nothing happened.
     */
    public function notice_respond(): void {
        [ $school_id ] = $this->context( 'educbt_notice_respond' );

        global $wpdb;

        $table  = \EduCBTPro\Core\Schema::table( 'notifications' );
        $id     = absint( $_POST['notification_id'] ?? 0 );
        $action = sanitize_key( (string) wp_unslash( $_POST['response'] ?? '' ) );
        $note   = trim( (string) wp_unslash( $_POST['note'] ?? '' ) );

        // Scoped to the owner: an id alone must not let one user act on another's.
        $row = (array) $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, title, payload FROM {$table} WHERE id = %d AND school_id = %d AND user_id = %d",
                $id,
                $school_id,
                get_current_user_id()
            ),
            ARRAY_A
        );

        if ( empty( $row ) ) {
            $this->fail( 'That notification could not be found.' );
        }

        $labels = [
            'flag'     => 'Flagged',
            'resolved' => 'Flag resolved',
            'report'   => 'Problem reported',
        ];

        if ( ! isset( $labels[ $action ] ) ) {
            $this->fail( 'Choose what you want to do.' );
        }

        if ( $action === 'report' && $note === '' ) {
            $this->fail( 'Say what the problem is, so the exam office can act on it.' );
        }

        $payload = (array) json_decode( (string) $row['payload'], true );

        $payload['responses'][] = [
            'action' => $action,
            'note'   => sanitize_textarea_field( $note ),
            'by'     => get_current_user_id(),
            'at'     => current_time( 'mysql', true ),
        ];

        $wpdb->update(
            $table,
            [ 'payload' => (string) wp_json_encode( $payload ), 'is_read' => 1 ],
            [ 'id' => $id ],
            [ '%s', '%d' ],
            [ '%d' ]
        );

        // Tell the people who can do something about it.
        $user      = wp_get_current_user();
        $managers  = (array) $wpdb->get_col(
            $wpdb->prepare(
                'SELECT wp_user_id FROM ' . \EduCBTPro\Core\Schema::table( 'staff' ) . "
                 WHERE school_id = %d AND status = 'active' AND wp_user_id IS NOT NULL
                   AND role_slug IN (%s, %s, %s)",
                $school_id,
                \EduCBTPro\Core\Capabilities::ROLE_PRINCIPAL,
                \EduCBTPro\Core\Capabilities::ROLE_VICE_PRINCIPAL,
                \EduCBTPro\Core\Capabilities::ROLE_EXAM_OFFICER
            )
        );

        if ( ! empty( $managers ) ) {
            ( new \EduCBTPro\Services\NotificationService() )->notify_many(
                $school_id,
                array_map( 'absint', $managers ),
                \EduCBTPro\Services\NotificationService::ANNOUNCEMENT,
                $labels[ $action ] . ': ' . (string) $row['title'],
                sprintf( "%s responded to a notice.\n\n%s", $user->display_name, $note !== '' ? $note : '(no note)' ),
                home_url( '/portal/account/notifications/' )
            );
        }

        $this->succeed( [ 'type' => 'notice_response', 'label' => $labels[ $action ] ] );
    }

    public function send_staff_notice(): void {
        [ $school_id ] = $this->context( 'educbt_send_staff_notice' );

        if ( ! Gate::allows( Capabilities::SEND_ANNOUNCEMENT ) ) {
            $this->fail( 'You do not have permission to notify staff.' );
        }

        $staff_ids = array_map( 'absint', (array) ( $_POST['staff_ids'] ?? [] ) );

        if ( empty( $staff_ids ) ) {
            $this->fail( 'Choose at least one member of staff.' );
        }

        $result = ( new \EduCBTPro\Services\StaffNoticeService() )->send(
            $school_id,
            $staff_ids,
            (string) wp_unslash( $_POST['subject'] ?? '' ),
            (string) wp_unslash( $_POST['body'] ?? '' )
        );

        if ( (int) $result['sent'] === 0 ) {
            $this->fail( 'Nothing was sent — check the subject and message are filled in.' );
        }

        $this->succeed( [ 'type' => 'notice_sent', 'sent' => (int) $result['sent'], 'skipped' => (int) $result['skipped'] ] );
    }

    public function save_quotas(): void {
        [ $school_id ] = $this->context( 'educbt_save_quotas' );

        if ( ! Gate::allows( Capabilities::APPROVE_QUESTIONS ) ) {
            $this->fail( 'You do not have permission to set the requirement.' );
        }

        ( new \EduCBTPro\Services\QuestionApprovalService() )->set_quotas(
            $school_id,
            absint( $_POST['objective'] ?? 0 ),
            absint( $_POST['theory'] ?? 0 )
        );

        $this->succeed( [ 'type' => 'quotas_saved' ] );
    }

    /**
     * Delete a teacher's submission for one subject + class level.
     * Removes the question sets and their questions for that scope.
     */
    public function delete_submission(): void {
        [ $school_id ] = $this->context( 'educbt_delete_submission' );

        if ( ! Gate::allows( Capabilities::APPROVE_QUESTIONS ) ) {
            $this->fail( 'You do not have permission to delete submissions.' );
        }

        $subject_id = absint( $_POST['subject_id'] ?? 0 );
        $staff_id   = absint( $_POST['staff_id'] ?? 0 );
        $level_id   = absint( $_POST['level_id'] ?? 0 );

        if ( $subject_id <= 0 || $staff_id <= 0 ) {
            $this->fail( 'Invalid submission scope.' );
        }

        global $wpdb;

        $sets_table    = \EduCBTPro\Core\Schema::table( 'question_sets' );
        $questions_tbl = $wpdb->prefix . 'educbt_questions';

        // Find the question set IDs for this scope.
        $set_ids = (array) $wpdb->get_col(
            $wpdb->prepare(
                "SELECT id FROM {$sets_table}
                 WHERE school_id = %d AND subject_id = %d AND teacher_id = %d"
                . ( $level_id > 0 ? ' AND level_id = %d' : '' ),
                $level_id > 0 ? [ $school_id, $subject_id, $staff_id, $level_id ] : [ $school_id, $subject_id, $staff_id ]
            )
        );

        if ( empty( $set_ids ) ) {
            $this->fail( 'No submission found for that scope.' );
        }

        $set_id_list = implode( ',', array_map( 'absint', $set_ids ) );

        // Delete questions belonging to these sets.
        $wpdb->query(
            "DELETE FROM {$questions_tbl} WHERE question_set_id IN ({$set_id_list})"
        );

        // Delete the question sets themselves.
        $wpdb->query(
            "DELETE FROM {$sets_table} WHERE id IN ({$set_id_list})"
        );

        $this->succeed( [ 'type' => 'submission_deleted' ] );
    }

    /**
     * Bulk import: a pasted block, or CSV text.
     */
    public function import_questions(): void {
        [ $school_id, $scope ] = $this->context( 'educbt_import_questions' );

        $subject_id = absint( $_POST['subject_id'] ?? 0 );

        if ( ! Gate::allows( Capabilities::WRITE_QUESTIONS, [ 'subject_id' => $subject_id ] ) ) {
            $this->fail( 'You do not have permission to write questions for that subject.' );
        }

        // Gate: block imports when exam prep is closed (but allow school-wide reviewers).
        if ( ! ( new \EduCBTPro\Services\SchoolService() )->is_exam_prep_enabled( $school_id ) ) {
            if ( ! $scope->is_school_wide() ) {
                $this->fail( 'Exam preparation is currently closed. New questions cannot be submitted.' );
            }
        }

        $question_type = sanitize_key( (string) wp_unslash( $_POST['question_type'] ?? 'single_choice' ) );
        $bulk_marks    = (float) ( $_POST['bulk_marks'] ?? 1 );

        $body = (string) wp_unslash( $_POST['bulk'] ?? '' );

        // CSV arrives as a file now, not pasted text.
        if ( ! empty( $_FILES['csv_file']['tmp_name'] ) && is_uploaded_file( $_FILES['csv_file']['tmp_name'] ) ) {
            $body = (string) file_get_contents( $_FILES['csv_file']['tmp_name'] );
        }

        $level = (string) wp_unslash( $_POST['class_level'] ?? '' );
        $mode  = (string) wp_unslash( $_POST['mode'] ?? 'paste' );

        if ( trim( $body ) === '' ) {
            $this->fail( 'Paste some questions first.' );
        }

        $actor     = $scope->actor();

        // If the teacher chose "written", import each line as a theory question
        // instead of running the MCQ parser. This MUST come before the CSV check —
        // otherwise written questions imported via CSV get treated as objective
        // and silently assigned options A-D they should never have.
        if ( $question_type === 'theory' ) {
            $imported = 0;
            $failed   = 0;
            global $wpdb;
            $q_table = $wpdb->prefix . 'educbt_questions';

            $lines = preg_split( "/\n\s*\n/", trim( $body ) );
            if ( count( $lines ) === 1 ) {
                // No blank-line separation — try one-per-line.
                $lines = array_filter( array_map( 'trim', explode( "\n", $body ) ) );
            }

            foreach ( $lines as $line ) {
                $line = trim( (string) wp_unslash( $line ) );
                // Strip leading numbering like "1." or "1)"
                $line = preg_replace( '/^\d+[.)]\s*/', '', $line );

                if ( $line === '' ) {
                    continue;
                }

                $wpdb->insert(
                    $q_table,
                    [
                        'school_id'        => $school_id,
                        'subject_id'       => $subject_id,
                        'question_type'    => 'theory',
                        'class_level'      => $level,
                        'question_text'    => wp_kses_post( $line ),
                        'marks'            => $bulk_marks > 0 ? $bulk_marks : 1,
                        'status'           => 'active',
                        'approval_status'  => $this->initial_approval(),
                        'created_by_staff' => (int) $actor['id'],
                        'created_at'       => current_time( 'mysql', true ),
                    ],
                    [ '%d', '%d', '%s', '%s', '%s', '%f', '%s', '%s', '%d', '%s' ]
                );

                if ( (int) $wpdb->insert_id > 0 ) {
                    $imported++;
                } else {
                    $failed++;
                }
            }

            $this->stamp_recent_questions( $school_id, $subject_id, (int) $actor['id'] );

            $this->succeed(
                [
                    'type'     => 'import',
                    'imported' => $imported,
                    'failed'   => $failed,
                ]
            );
        }

        $authoring = new \EduCBTPro\Services\QuestionAuthoringService();

        $result = $authoring->import_pasted_block(
            $school_id,
            (int) $actor['id'],
            $body,
            $subject_id,
            $level
        );

        // Pasting produced DRAFTS and stopped there, so a teacher imported forty
        // questions, was told it worked, and found an empty question bank. Anything
        // complete is published immediately; only the ones that could not be parsed
        // stay behind as drafts needing attention.
        $published = $authoring->publish_batch( $school_id, (int) $actor['id'], (string) $result['batch_id'] );

        $this->stamp_recent_questions( $school_id, $subject_id, (int) $actor['id'] );

        $this->succeed(
            [
                'type'     => 'import',
                'imported' => (int) ( $published['published'] ?? $result['complete'] ),
                'failed'   => (int) $result['needs_attention'],
                'batch'    => (string) $result['batch_id'],
                'drafts'   => (int) $result['created'],
            ]
        );
    }

    /**
     * Record who wrote a question and whether it still needs approving.
     *
     * Done here rather than inside QuestionBankService because the bank is also used
     * by seeding and migration, where there is no author and no review to do.
     *
     * @param array<int,int> $question_ids
     */
    /**
     * Stamp questions in a subject that have no author yet — the ones just imported.
     */
    private function stamp_recent_questions( int $school_id, int $subject_id, int $staff_id ): void {
        global $wpdb;

        $wpdb->query(
            $wpdb->prepare(
                'UPDATE ' . $wpdb->prefix . "educbt_questions
                 SET created_by_staff = %d, approval_status = %s, created_at = %s
                 WHERE school_id = %d AND subject_id = %d AND created_by_staff IS NULL AND status = 'active'",
                $staff_id,
                $this->initial_approval(),
                current_time( 'mysql', true ),
                $school_id,
                $subject_id
            )
        );
    }

    private function stamp_authorship( int $school_id, array $question_ids, int $staff_id ): void {
        $ids = array_values( array_filter( array_map( 'absint', $question_ids ) ) );

        if ( empty( $ids ) ) {
            return;
        }

        global $wpdb;

        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

        $wpdb->query(
            $wpdb->prepare(
                'UPDATE ' . $wpdb->prefix . "educbt_questions
                 SET created_by_staff = %d, approval_status = %s, created_at = %s
                 WHERE school_id = %d AND id IN ({$placeholders})",
                array_merge(
                    [ $staff_id, $this->initial_approval(), current_time( 'mysql', true ), $school_id ],
                    $ids
                )
            )
        );
    }

    /**
     * The CSV template, so nobody has to guess the columns.
     */
    public function question_template(): void {
        if ( ! is_user_logged_in() || ! Gate::allows( Capabilities::WRITE_QUESTIONS ) ) {
            wp_die( esc_html__( 'Permission denied.', 'educbt-pro' ) );
        }

        check_admin_referer( 'educbt_question_template' );

        nocache_headers();
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename=question-template.csv' );

        $out = fopen( 'php://output', 'w' );

        fputcsv( $out, [ 'question', 'option_a', 'option_b', 'option_c', 'option_d', 'answer' ] );
        fputcsv( $out, [ 'What is the SI unit of force?', 'Newton', 'Joule', 'Watt', 'Pascal', 'A' ] );
        fputcsv( $out, [ 'Which gas do plants absorb?', 'Oxygen', 'Carbon dioxide', 'Nitrogen', 'Hydrogen', 'Carbon dioxide' ] );

        fclose( $out );
        exit;
    }

    public function create_paper(): void {
        [ $school_id ] = $this->context( 'educbt_create_paper' );

        if ( ! Gate::allows( Capabilities::MANAGE_PAPERS ) ) {
            $this->fail( 'You do not have permission to schedule papers.' );
        }

        global $wpdb;

        $series_id = absint( $_POST['series_id'] ?? 0 );
        $papers    = new \EduCBTPro\Services\ExamPaperService();

        // No series yet: make one, so a school is not asked to understand the
        // distinction before it has scheduled anything.
        if ( $series_id === 0 ) {
            $created = $papers->create_series(
                $school_id,
                [ 'title' => (string) wp_unslash( $_POST['series_title'] ?? 'Examination' ) ]
            );

            if ( empty( $created['success'] ) ) {
                $this->fail( $this->readable( [ $created['error'] ?? 'unknown' ] ) );
            }

            $series_id = (int) $created['series_id'];
        }

        $result = $papers->create_paper(
            $school_id,
            [
                'series_id'        => $series_id,
                'subject_id'       => absint( $_POST['subject_id'] ?? 0 ),
                'class_id'         => absint( $_POST['class_id'] ?? 0 ),
                'scheduled_at'     => (string) wp_unslash( $_POST['scheduled_at'] ?? '' ),
                'duration_minutes' => absint( $_POST['duration_minutes'] ?? 0 ),
                'question_count'   => absint( $_POST['question_count'] ?? 0 ),
                'is_practice'      => ! empty( $_POST['is_practice'] ),
                'closes_at'        => (string) wp_unslash( $_POST['closes_at'] ?? '' ),
                'delivery_mode'    => sanitize_key( (string) wp_unslash( $_POST['delivery_mode'] ?? 'cbt' ) ),
            ]
        );

        if ( empty( $result['success'] ) ) {
            $this->fail( $this->readable( $result['errors'] ?? [] ) );
        }

        // Compose immediately: a paper with no questions cannot be published, and
        // asking for a second click here only creates a state to get stuck in.
        $composed = $papers->compose( $school_id, (int) $result['paper_id'] );

        $this->succeed(
            [
                'type'        => 'paper',
                'paper_id'    => (int) $result['paper_id'],
                'access_code' => (string) $result['access_code'],
                'composed'    => (int) ( $composed['selected'] ?? 0 ),
                'compose_error' => (string) ( $composed['error'] ?? '' ),
                'warnings'    => $result['warnings'] ?? [],
            ]
        );
    }

    /**
     * A subject teacher setting a class test for their own subject.
     */
    public function create_ca_test(): void {
        [ $school_id, $scope ] = $this->context( 'educbt_create_ca_test' );

        $subject_id = absint( $_POST['subject_id'] ?? 0 );
        $class_id   = absint( $_POST['class_id'] ?? 0 );

        if ( ! Gate::allows( Capabilities::ENTER_SCORES, [ 'subject_id' => $subject_id, 'class_id' => $class_id ] ) ) {
            $this->fail( 'You can only set a test for a subject and class you teach.' );
        }

        $year    = new \EduCBTPro\Services\AcademicYearService();
        $session = $year->current_session( $school_id );
        $term    = $year->current_term( $school_id );

        $assessment = new \EduCBTPro\Services\AssessmentService();

        $result = $assessment->create_ca_test(
            $school_id,
            [
                'component_id'         => absint( $_POST['component_id'] ?? 0 ),
                'subject_id'           => $subject_id,
                'class_id'             => $class_id,
                'session_id'           => absint( $session['id'] ?? 0 ),
                'term_id'              => absint( $term['id'] ?? 0 ),
                'scheduled_at'         => (string) wp_unslash( $_POST['scheduled_at'] ?? '' ),
                'duration_minutes'     => absint( $_POST['duration_minutes'] ?? 20 ),
                'question_count'       => absint( $_POST['question_count'] ?? 20 ),
                'requires_access_code' => ! empty( $_POST['requires_access_code'] ),
                'closes_at'            => (string) wp_unslash( $_POST['closes_at'] ?? '' ),
                'is_practice'          => true,
                'title'                => 'Class test',
                'created_by_staff'     => absint( $scope->actor()['id'] ?? 0 ),
            ]
        );

        if ( empty( $result['success'] ) ) {
            $errors = (array) ( $result['errors'] ?? [] );

            if ( in_array( 'use_an_exam_series_for_the_exam_component', $errors, true ) ) {
                $this->fail( 'The examination is set by the school, not from here. Choose a continuous-assessment component.' );
            }

            $this->fail( $this->readable( $errors ) );
        }

        // Fill it immediately from approved questions; an empty test cannot be
        // published and leaving it half-made is a state to get stuck in.
        $composed = ( new \EduCBTPro\Services\ExamPaperService() )->compose( $school_id, (int) $result['paper_id'] );

        $this->succeed(
            [
                'type'          => 'ca_test',
                'paper_id'      => (int) $result['paper_id'],
                'composed'      => (int) ( $composed['selected'] ?? 0 ),
                'compose_error' => (string) ( $composed['error'] ?? '' ),
            ]
        );
    }

    public function publish_paper(): void {
        [ $school_id ] = $this->context( 'educbt_publish_paper' );

        if ( ! Gate::allows( Capabilities::MANAGE_PAPERS ) ) {
            $this->fail( 'You do not have permission to publish papers.' );
        }

        $result = ( new \EduCBTPro\Services\ExamPaperService() )->publish( $school_id, absint( $_POST['paper_id'] ?? 0 ) );

        if ( empty( $result['success'] ) ) {
            $this->fail( 'Cannot publish yet: ' . $this->readable( $result['errors'] ?? [] ) );
        }

        $this->succeed( [ 'type' => 'published' ] );
    }

    /**
     * Unpublish a paper so it leaves student dashboards without being deleted.
     * The teacher can republish it later from the same screen.
     */
    public function unpublish_paper(): void {
        [ $school_id ] = $this->context( 'educbt_unpublish_paper' );

        if ( ! Gate::allows( Capabilities::MANAGE_PAPERS ) ) {
            $this->fail( 'You do not have permission to unpublish papers.' );
        }

        $result = ( new \EduCBTPro\Services\ExamPaperService() )->unpublish( $school_id, absint( $_POST['paper_id'] ?? 0 ) );

        if ( empty( $result['success'] ) ) {
            $this->fail( 'Cannot unpublish: ' . $this->readable( $result['errors'] ?? [] ) );
        }

        $this->succeed( [ 'type' => 'unpublished' ] );
    }

    /**
     * Generate a new access code for a paper. The old code immediately stops
     * working because the invigilator and student checks compare against the
     * value in the database.
     */
    public function regenerate_access_code(): void {
        [ $school_id ] = $this->context( 'educbt_regenerate_access_code' );

        if ( ! Gate::allows( Capabilities::MANAGE_PAPERS ) ) {
            $this->fail( 'You do not have permission to regenerate access codes.' );
        }

        $result = ( new \EduCBTPro\Services\ExamPaperService() )->regenerate_access_code(
            $school_id,
            absint( $_POST['paper_id'] ?? 0 )
        );

        if ( empty( $result['success'] ) ) {
            $this->fail( 'Cannot regenerate access code: ' . $this->readable( $result['errors'] ?? [] ) );
        }

        $this->succeed( [ 'type' => 'access_code_regenerated', 'access_code' => $result['access_code'] ] );
    }

    /**
     * A test session that ended unfairly (network drop, device crash, wrongly
     * flagged) can be wiped so the student gets a clean retake. This deletes the
     * attempt, its answers and its integrity events — the student starts fresh
     * next time, with no leftover state to trip up validation.
     */
    public function reset_attempt(): void {
        [ $school_id ] = $this->context( 'educbt_reset_attempt' );

        global $wpdb;

        $attempt_id = absint( $_POST['attempt_id'] ?? 0 );

        if ( $attempt_id <= 0 ) {
            $this->fail( 'That test session could not be found.' );
        }

        $attempts_t = \EduCBTPro\Core\Schema::table( 'attempts' );
        $papers_t   = \EduCBTPro\Core\Schema::table( 'exam_papers' );

        $paper = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT p.subject_id, p.class_id FROM {$attempts_t} a
                 INNER JOIN {$papers_t} p ON p.id = a.paper_id
                 WHERE a.id = %d AND a.school_id = %d",
                $attempt_id,
                $school_id
            ),
            ARRAY_A
        );

        if ( ! $paper ) {
            $this->fail( 'That test session could not be found.' );
        }

        if ( ! Gate::allows(
            Capabilities::VIEW_EXAMS,
            [ 'subject_id' => absint( $paper['subject_id'] ?? 0 ), 'class_id' => absint( $paper['class_id'] ?? 0 ) ]
        ) ) {
            $this->fail( 'You do not have permission to reset this test session.' );
        }

        $result = ( new \EduCBTPro\Services\AttemptService() )->reset_attempt( $school_id, $attempt_id );

        if ( empty( $result['success'] ) ) {
            $this->fail( 'That test session could not be reset — it may already be gone.' );
        }

        $this->succeed( [ 'type' => 'attempt_reset' ] );
    }

    /**
     * Re-run composition for a paper — pulls the latest approved questions
     * into the paper. Useful when questions were approved after paper creation
     * or when options/correct answers were fixed after initial compose failed.
     */
    public function recompose_paper(): void {
        [ $school_id ] = $this->context( 'educbt_recompose_paper' );

        if ( ! Gate::allows( Capabilities::MANAGE_PAPERS ) ) {
            $this->fail( 'You do not have permission to recompose papers.' );
        }

        $paper_id = absint( $_POST['paper_id'] ?? 0 );

        $result = ( new \EduCBTPro\Services\ExamPaperService() )->compose( $school_id, $paper_id );

        if ( empty( $result['success'] ) ) {
            $error = $result['error'] ?? 'unknown';

            if ( strpos( $error, 'insufficient_usable_questions' ) === 0 ) {
                $this->fail( 'Not enough usable questions. Make sure questions are approved and have options with correct answers marked. (' . $error . ')' );
            }

            $this->fail( 'Could not compose: ' . $error );
        }

        $this->succeed( [
            'type'     => 'recomposed',
            'selected' => (int) ( $result['selected'] ?? 0 ),
        ] );
    }

    /**
     * A teacher enters marks for a whole class in one save.
     */
    public function save_scores(): void {
        [ $school_id, $scope ] = $this->context( 'educbt_save_scores' );

        $subject_id = absint( $_POST['subject_id'] ?? 0 );
        $class_id   = absint( $_POST['class_id'] ?? 0 );
        $term_id    = absint( $_POST['term_id'] ?? 0 );

        if ( ! Gate::allows( Capabilities::ENTER_SCORES, [ 'subject_id' => $subject_id, 'class_id' => $class_id ] ) ) {
            $this->fail( 'You can only enter scores for a subject and class you teach.' );
        }

        // Lock score entry once results have been approved or published.
        if ( $term_id > 0 ) {
            $term_results_t = \EduCBTPro\Core\Schema::table( 'term_results' );
            $class_status = (string) $GLOBALS['wpdb']->get_var(
                $GLOBALS['wpdb']->prepare(
                    "SELECT status FROM {$term_results_t} WHERE school_id = %d AND class_id = %d AND term_id = %d LIMIT 1",
                    $school_id, $class_id, $term_id
                )
            );
            if ( in_array( $class_status, [ 'approved', 'published' ], true ) ) {
                $this->fail( 'Results for this class have been ' . $class_status . '. Score entry is now locked.' );
            }
        }

        $actor  = $scope->actor();
        $raw    = (array) ( $_POST['score'] ?? [] );
        $svc    = new \EduCBTPro\Services\AssessmentService();

        // Multi-component mode: score[student_id][component_id] => value
        // Single-component mode (backward compat): score[student_id] => value
        $is_multi = ! empty( $_POST['multi_component'] );

        $total_saved   = 0;
        $total_skipped = 0;
        $all_errors    = [];

        if ( $is_multi ) {
            // Group scores by component, then call award_scores per component.
            $by_component = []; // component_id => [ student_id => value ]

            foreach ( $raw as $student_id => $components ) {
                if ( ! is_array( $components ) ) {
                    continue;
                }
                foreach ( $components as $component_id => $value ) {
                    $cid = absint( $component_id );
                    if ( ! isset( $by_component[ $cid ] ) ) {
                        $by_component[ $cid ] = [];
                    }
                    $by_component[ $cid ][ absint( $student_id ) ] = is_string( $value ) ? trim( wp_unslash( $value ) ) : $value;
                }
            }

            $all_components = $svc->components( $school_id );

            foreach ( $by_component as $component_id => $student_scores ) {
                // Skip exam components — those are CBT-sourced and read-only.
                $is_exam = false;
                foreach ( $all_components as $c ) {
                    if ( (int) $c['id'] === $component_id && ! empty( $c['is_exam'] ) ) {
                        $is_exam = true;
                        break;
                    }
                }
                if ( $is_exam ) {
                    continue;
                }

                $result = $svc->award_scores(
                    $school_id,
                    $component_id,
                    [
                        'subject_id' => $subject_id,
                        'class_id'   => $class_id,
                        'session_id' => absint( $_POST['session_id'] ?? 0 ),
                        'term_id'    => $term_id,
                    ],
                    $student_scores,
                    (int) $actor['id']
                );

                $total_saved   += (int) $result['saved'];
                $total_skipped += (int) $result['skipped'];
                $all_errors     = array_merge( $all_errors, array_slice( $result['errors'], 0, 4 ) );
            }
        } else {
            // Legacy single-component mode.
            $scores = [];
            foreach ( $raw as $student_id => $value ) {
                $scores[ absint( $student_id ) ] = is_string( $value ) ? trim( wp_unslash( $value ) ) : $value;
            }

            $result = $svc->award_scores(
                $school_id,
                absint( $_POST['component_id'] ?? 0 ),
                [
                    'subject_id' => $subject_id,
                    'class_id'   => $class_id,
                    'session_id' => absint( $_POST['session_id'] ?? 0 ),
                    'term_id'    => $term_id,
                ],
                $scores,
                (int) $actor['id']
            );

            $total_saved   = (int) $result['saved'];
            $total_skipped  = (int) $result['skipped'];
            $all_errors     = array_slice( $result['errors'], 0, 8 );
        }

        $this->succeed(
            [
                'type'    => 'scores',
                'saved'   => $total_saved,
                'skipped' => $total_skipped,
                'errors'  => array_slice( $all_errors, 0, 8 ),
            ]
        );
    }

    public function mark_theory(): void {
        [ $school_id, $scope ] = $this->context( 'educbt_mark_theory' );

        if ( ! Gate::allows( Capabilities::ENTER_SCORES ) ) {
            $this->fail( 'You do not have permission to mark written answers.' );
        }

        $raw   = (array) ( $_POST['marks'] ?? [] );
        $marks = [];

        foreach ( $raw as $answer_id => $value ) {
            $marks[ absint( $answer_id ) ] = is_string( $value ) ? trim( wp_unslash( $value ) ) : $value;
        }

        $actor  = $scope->actor();
        $result = ( new \EduCBTPro\Services\TheoryService() )->record_marks(
            $school_id,
            $marks,
            (float) ( $_POST['max_marks'] ?? 0 ),
            (int) $actor['id']
        );

        $this->succeed(
            [
                'type'    => 'theory_marked',
                'marked'  => (int) $result['marked'],
                'skipped' => (int) $result['skipped'],
                'errors'  => array_slice( $result['errors'], 0, 6 ),
            ]
        );
    }

    /**
     * Moderate (adjust) compiled scores, then recompile.
     *
     * The principal reads every component score for every student in the class
     * and may change any of them — to reward, penalise, or correct. Each changed
     * value is written back to assessment_scores, then the class is recompiled
     * so totals, grades and positions reflect the moderated figures.
     */
    public function moderate_results(): void {
        [ $school_id ] = $this->context( 'educbt_moderate_results' );

        if ( ! Gate::allows( Capabilities::APPROVE_RESULTS ) ) {
            $this->fail( 'You do not have permission to moderate results.' );
        }

        global $wpdb;

        $class_id   = absint( $_POST['class_id'] ?? 0 );
        $session_id = absint( $_POST['session_id'] ?? 0 );
        $term_id    = absint( $_POST['term_id'] ?? 0 );
        $raw_scores = (array) ( $_POST['scores'] ?? [] );

        if ( $class_id === 0 || $term_id === 0 ) {
            $this->fail( 'Missing class or term.' );
        }

        $scores_table = \EduCBTPro\Core\Schema::table( 'assessment_scores' );
        $components_table = \EduCBTPro\Core\Schema::table( 'assessment_components' );

        // Load component max scores for validation
        $component_maxes = [];
        foreach ( (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, max_score FROM {$components_table} WHERE school_id = %d AND status = 'active'",
                $school_id
            ),
            ARRAY_A
        ) as $c ) {
            $component_maxes[ (int) $c['id'] ] = (float) $c['max_score'];
        }

        $updated = 0;
        $errors  = [];

        foreach ( $raw_scores as $student_id => $subjects ) {
            $student_id = absint( $student_id );

            foreach ( $subjects as $subject_id => $components ) {
                $subject_id = absint( $subject_id );

                foreach ( $components as $component_id => $value ) {
                    $component_id = absint( $component_id );

                    // Empty means "leave as-is" — the principal did not touch this cell
                    if ( $value === '' || $value === null ) {
                        continue;
                    }

                    $score = (float) $value;
                    $comp_max = $component_maxes[ $component_id ] ?? 0;

                    if ( $score < 0 || ( $comp_max > 0 && $score > $comp_max ) ) {
                        $errors[] = sprintf(
                            'Student %d, Subject %d, Component %d: %s exceeds max %s',
                            $student_id, $subject_id, $component_id, $score, $comp_max
                        );
                        continue;
                    }

                    // Upsert into assessment_scores
                    $existing = (int) $wpdb->get_var(
                        $wpdb->prepare(
                            "SELECT id FROM {$scores_table}
                             WHERE student_id = %d AND subject_id = %d AND term_id = %d AND component_id = %d",
                            $student_id, $subject_id, $term_id, $component_id
                        )
                    );

                    if ( $existing > 0 ) {
                        $wpdb->update(
                            $scores_table,
                            [ 'score' => $score ],
                            [ 'id' => $existing ],
                            [ '%f' ],
                            [ '%d' ]
                        );
                    } else {
                        $wpdb->insert(
                            $scores_table,
                            [
                                'school_id'    => $school_id,
                                'student_id'   => $student_id,
                                'subject_id'   => $subject_id,
                                'class_id'     => $class_id,
                                'session_id'   => $session_id,
                                'term_id'      => $term_id,
                                'component_id' => $component_id,
                                'score'        => $score,
                                'max_score'    => $comp_max,
                                'source'       => 'moderated',
                            ],
                            [ '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%f', '%f', '%s' ]
                        );
                    }

                    $updated++;
                }
            }
        }

        // Recompile the class so totals, grades and positions reflect moderated scores
        $compiler = new \EduCBTPro\Services\ResultCompilationService();
        $result   = $compiler->compile_class( $school_id, $class_id, $session_id, $term_id );

        if ( empty( $result['success'] ) ) {
            $this->fail( 'Scores saved but recompile failed: ' . ( $result['error'] ?? 'unknown' ) );
        }

        \EduCBTPro\Core\EventDispatcher::action( 'educbt_results_moderated', [
            'school_id' => $school_id,
            'class_id'  => $class_id,
            'term_id'   => $term_id,
            'updated'   => $updated,
        ] );

        $this->succeed( [
            'type'     => 'moderated',
            'updated'  => $updated,
            'subjects'  => (int) ( $result['subjects'] ?? 0 ),
            'students'  => (int) ( $result['students'] ?? 0 ),
            'errors'   => array_slice( $errors, 0, 6 ),
        ] );
    }

    public function compile_results(): void {
        [ $school_id ] = $this->context( 'educbt_compile_results' );

        if ( ! Gate::allows( Capabilities::COMPILE_RESULTS ) ) {
            $this->fail( 'You do not have permission to compile results.' );
        }

        $class_id   = absint( $_POST['class_id'] ?? 0 );
        $session_id = absint( $_POST['session_id'] ?? 0 );
        $term_id    = absint( $_POST['term_id'] ?? 0 );

        $compiler = new \EduCBTPro\Services\ResultCompilationService();

        // Warn about gaps, but do not block: a school sometimes must publish while
        // one teacher is still outstanding, and it should be their decision.
        $readiness = $compiler->readiness( $school_id, $class_id, $session_id, $term_id );
        $result    = $compiler->compile_class( $school_id, $class_id, $session_id, $term_id );

        if ( empty( $result['success'] ) ) {
            $this->fail(
                ( $result['error'] ?? '' ) === 'no_scores_recorded'
                    ? 'Nothing to compile — no scores have been entered for this class this term. Compiling reads the CA and exam scores teachers have submitted; until those exist there is nothing to total.'
                    : 'Nothing could be compiled. Every subject was skipped, usually because its scores are incomplete.'
            );
        }

        $this->succeed(
            [
                'type'     => 'compiled',
                'subjects' => (int) $result['subjects'],
                'students' => (int) $result['students'],
                'gaps'     => count( $readiness['issues'] ),
                'skipped'  => count( (array) ( $result['skipped'] ?? [] ) ),
            ]
        );
    }

    /**
     * Move a class's results along the approval chain.
     */
    public function move_results(): void {
        [ $school_id, $scope ] = $this->context( 'educbt_move_results' );

        $to       = sanitize_key( (string) wp_unslash( $_POST['to'] ?? '' ) );
        $class_id = absint( $_POST['class_id'] ?? 0 );
        $term_id  = absint( $_POST['term_id'] ?? 0 );
        $reason   = (string) wp_unslash( $_POST['reason'] ?? '' );

        $workflow = new \EduCBTPro\Services\ResultWorkflowService();

        global $wpdb;

        $from = (string) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT status FROM ' . \EduCBTPro\Core\Schema::table( 'term_results' ) .
                ' WHERE school_id = %d AND class_id = %d AND term_id = %d LIMIT 1',
                $school_id,
                $class_id,
                $term_id
            )
        );

        if ( $from === '' ) {
            $this->fail( 'There are no compiled results for that class yet.' );
        }

        // The capability required depends on the move, not on the screen.
        if ( ! Gate::allows( \EduCBTPro\Services\ResultWorkflowService::capability_for( $from, $to ) ) ) {
            $this->fail( 'You do not have permission to make that change.' );
        }

        if ( $to === \EduCBTPro\Services\ResultWorkflowService::PUBLISHED ) {
            $force = (bool) ( $_POST['force'] ?? false );
            $result = $workflow->publish_class( $school_id, $class_id, absint( $_POST['session_id'] ?? 0 ), $term_id, get_current_user_id(), $force );

            if ( empty( $result['success'] ) ) {
                // If there are warnings (missing marks etc.) but the result was
                // not a hard failure, surface them so the UI can offer "Continue".
                if ( ! empty( $result['warnings'] ) ) {
                    $this->fail(
                        'Some results have issues: ' . $this->readable( $result['warnings'] ) .
                        ' — Click "Publish Anyway" to push results with zero for any missing scores.'
                    );
                }
                $this->fail( 'Cannot publish yet: ' . $this->readable( $result['errors'] ?? [] ) );
            }

            $msg = [ 'type' => 'results_moved', 'to' => $to, 'count' => (int) $result['published'] ];
            if ( ! empty( $result['warnings'] ) ) {
                $msg['warnings'] = count( $result['warnings'] );
            }
            $this->succeed( $msg );
        }

        $result = $workflow->transition_class( $school_id, $class_id, $term_id, $to, get_current_user_id(), $reason );

        if ( empty( $result['success'] ) ) {
            $this->fail( $this->readable( [ $result['error'] ?? 'unknown' ] ) );
        }

        $this->succeed( [ 'type' => 'results_moved', 'to' => $to, 'count' => (int) $result['moved'] ] );
    }

    /**
     * Switch the current session or term.
     *
     * Everything time-bound reads from these two, so getting them wrong quietly
     * misfiles a whole term's work. Both are set transactionally by the service.
     */
    public function set_period(): void {
        [ $school_id ] = $this->context( 'educbt_set_period' );

        if ( ! Gate::allows( Capabilities::MANAGE_ACADEMIC_YEAR ) ) {
            $this->fail( 'You do not have permission to change the session or term.' );
        }

        $year       = new \EduCBTPro\Services\AcademicYearService();
        $session_id = absint( $_POST['session_id'] ?? 0 );
        $term_id    = absint( $_POST['term_id'] ?? 0 );

        $changed = [];

        if ( $session_id > 0 && $year->set_current_session( $school_id, $session_id ) ) {
            $changed[] = 'session';
        }

        if ( $term_id > 0 && $year->set_current_term( $school_id, $term_id ) ) {
            $changed[] = 'term';
        }

        if ( empty( $changed ) ) {
            $this->fail( 'Nothing was changed.' );
        }

        $this->succeed( [ 'type' => 'period', 'changed' => $changed ] );
    }

    /**
     * Set how a term is marked: the weight of each assessment, and which one is the
     * examination.
     */
    public function save_components(): void {
        [ $school_id ] = $this->context( 'educbt_save_components' );

        if ( ! Gate::allows( Capabilities::MANAGE_SCHOOL ) ) {
            $this->fail( 'You do not have permission to change the marking scheme.' );
        }

        global $wpdb;

        $table   = \EduCBTPro\Core\Schema::table( 'assessment_components' );
        $rows    = (array) ( $_POST['component'] ?? [] );
        $exam_id = absint( $_POST['exam_component'] ?? 0 );
        $new     = (array) ( $_POST['new_component'] ?? [] );

        $total = 0.0;

        foreach ( $rows as $values ) {
            $total += (float) ( $values['max_score'] ?? 0 );
        }

        $new_name  = trim( (string) wp_unslash( $new['name'] ?? '' ) );
        $new_score = (float) ( $new['max_score'] ?? 0 );

        if ( $new_name !== '' ) {
            $total += $new_score;
        }

        // Weights that do not total 100 make every percentage in the system a
        // different kind of wrong, so this is refused rather than warned about.
        if ( abs( $total - 100.0 ) > 0.01 ) {
            $this->fail( sprintf( 'The weights add up to %s. They must total exactly 100.', rtrim( rtrim( number_format( $total, 2 ), '0' ), '.' ) ) );
        }

        if ( $exam_id === 0 ) {
            $this->fail( 'Mark which assessment is the examination — that is the one the CBT writes into.' );
        }

        foreach ( $rows as $component_id => $values ) {
            $component_id = absint( $component_id );

            $wpdb->update(
                $table,
                [
                    'name'      => sanitize_text_field( (string) wp_unslash( $values['name'] ?? '' ) ),
                    'max_score' => (float) ( $values['max_score'] ?? 0 ),
                    'is_exam'   => $component_id === $exam_id ? 1 : 0,
                ],
                [ 'id' => $component_id, 'school_id' => $school_id ],
                [ '%s', '%f', '%d' ],
                [ '%d', '%d' ]
            );
        }

        if ( $new_name !== '' ) {
            $wpdb->insert(
                $table,
                [
                    'school_id'  => $school_id,
                    'name'       => $new_name,
                    'code'       => strtoupper( substr( (string) preg_replace( '/[^A-Za-z]/', '', $new_name ), 0, 4 ) ) . wp_rand( 10, 99 ),
                    'max_score'  => $new_score,
                    'is_exam'    => 0,
                    'sort_order' => count( $rows ) + 1,
                    'status'     => 'active',
                ],
                [ '%d', '%s', '%s', '%f', '%d', '%d', '%s' ]
            );
        }

        $this->succeed( [ 'type' => 'components_saved', 'total' => $total ] );
    }

    public function add_session(): void {
        [ $school_id ] = $this->context( 'educbt_add_session' );

        if ( ! Gate::allows( Capabilities::MANAGE_ACADEMIC_YEAR ) ) {
            $this->fail( 'You do not have permission to add a session.' );
        }

        $result = ( new \EduCBTPro\Services\AcademicYearService() )->create_session(
            $school_id,
            (string) wp_unslash( $_POST['title'] ?? '' ),
            null,
            null,
            ! empty( $_POST['make_current'] )
        );

        if ( empty( $result['success'] ) ) {
            $this->fail( $this->readable( [ $result['error'] ?? 'unknown' ] ) );
        }

        $this->succeed( [ 'type' => 'session_added' ] );
    }

    public function propose_promotion(): void {
        [ $school_id ] = $this->context( 'educbt_propose_promotion' );

        if ( ! Gate::allows( Capabilities::RUN_PROMOTION ) ) {
            $this->fail( 'You do not have permission to run promotion.' );
        }

        $result = ( new \EduCBTPro\Services\PromotionService() )->propose(
            $school_id,
            absint( $_POST['level_id'] ?? 0 ),
            absint( $_POST['from_session_id'] ?? 0 ),
            absint( $_POST['to_session_id'] ?? 0 ),
            get_current_user_id()
        );

        if ( empty( $result['success'] ) ) {
            $map = [
                'sessions_must_differ'      => 'Choose a different session to promote into.',
                'no_students_to_evaluate'   => 'There are no students in that level for that session.',
                'level_not_found'           => 'Choose a level.',
            ];

            $this->fail( $map[ $result['error'] ?? '' ] ?? 'The proposal could not be produced.' );
        }

        set_transient( 'educbt_promotion_batch_' . get_current_user_id(), (int) $result['batch_id'], 300 );

        $this->succeed( [ 'type' => 'promotion_proposed', 'batch_id' => (int) $result['batch_id'], 'summary' => $result['summary'] ] );
    }

    public function override_promotion(): void {
        [ $school_id ] = $this->context( 'educbt_override_promotion' );

        if ( ! Gate::allows( Capabilities::RUN_PROMOTION ) ) {
            $this->fail( 'You do not have permission to change a promotion decision.' );
        }

        $result = ( new \EduCBTPro\Services\PromotionService() )->override(
            $school_id,
            absint( $_POST['batch_id'] ?? 0 ),
            absint( $_POST['student_id'] ?? 0 ),
            sanitize_key( (string) wp_unslash( $_POST['outcome'] ?? '' ) ),
            (string) wp_unslash( $_POST['reason'] ?? '' ),
            get_current_user_id()
        );

        if ( empty( $result['success'] ) ) {
            $this->fail( ( $result['error'] ?? '' ) === 'reason_required' ? 'Give a reason for the override.' : 'That change could not be saved.' );
        }

        $this->succeed( [ 'type' => 'promotion_overridden', 'batch_id' => absint( $_POST['batch_id'] ?? 0 ) ] );
    }

    public function commit_promotion(): void {
        [ $school_id ] = $this->context( 'educbt_commit_promotion' );

        if ( ! Gate::allows( Capabilities::COMMIT_PROMOTION ) ) {
            $this->fail( 'Only the principal can commit a promotion.' );
        }

        $result = ( new \EduCBTPro\Services\PromotionService() )->commit(
            $school_id,
            absint( $_POST['batch_id'] ?? 0 ),
            get_current_user_id()
        );

        if ( empty( $result['success'] ) ) {
            $errors = (array) ( $result['errors'] ?? [] );
            $first  = (string) ( $errors[0] ?? '' );

            if ( strpos( $first, 'unresolved_students' ) === 0 ) {
                $this->fail( 'Some students have no decision yet. Set an outcome for each before committing — committing around them would leave a child in no class next session.' );
            }

            if ( strpos( $first, 'students_with_no_destination_class' ) === 0 ) {
                $this->fail( 'Some students have nowhere to go. Create the classes for the next level first.' );
            }

            $this->fail( 'The promotion could not be committed.' );
        }

        $this->succeed( [ 'type' => 'promotion_committed', 'enrolled' => (int) $result['enrolled'], 'graduated' => (int) $result['graduated'] ] );
    }

    public function reply_thread(): void {
        [ $school_id ] = $this->context( 'educbt_reply_thread' );

        $result = ( new \EduCBTPro\Services\AnnouncementService() )->reply(
            $school_id,
            absint( $_POST['thread_id'] ?? 0 ),
            get_current_user_id(),
            (string) wp_unslash( $_POST['body'] ?? '' )
        );

        if ( empty( $result['success'] ) ) {
            $this->fail(
                ( $result['error'] ?? '' ) === 'not_a_participant'
                    ? 'You are not part of that conversation.'
                    : 'That message could not be sent.'
            );
        }

        $this->succeed( [ 'type' => 'message_sent' ] );
    }

    public function issue_transcript(): void {
        [ $school_id ] = $this->context( 'educbt_issue_transcript' );

        if ( ! Gate::allows( Capabilities::ISSUE_TRANSCRIPT ) ) {
            $this->fail( 'Only the principal can issue a transcript.' );
        }

        $result = ( new \EduCBTPro\Services\TranscriptService() )->issue(
            $school_id,
            absint( $_POST['student_id'] ?? 0 ),
            get_current_user_id(),
            (string) wp_unslash( $_POST['purpose'] ?? '' )
        );

        if ( empty( $result['success'] ) ) {
            $this->fail(
                ( $result['error'] ?? '' ) === 'no_published_results_to_transcribe'
                    ? 'That student has no published results yet, so there is nothing to transcribe.'
                    : 'The transcript could not be issued.'
            );
        }

        // The rendered document is handed back through the transient so it can be
        // opened and printed without being written anywhere on disk.
        set_transient( 'educbt_transcript_' . get_current_user_id(), (string) $result['html'], 300 );

        $this->succeed( [ 'type' => 'transcript', 'serial' => (string) $result['serial'] ] );
    }

    public function update_school(): void {
        [ $school_id ] = $this->context( 'educbt_update_school' );

        if ( ! Gate::allows( Capabilities::MANAGE_SCHOOL ) ) {
            $this->fail( 'You do not have permission to change the school details.' );
        }

        $updated = ( new \EduCBTPro\Services\SchoolOnboardingService() )->update_school(
            $school_id,
            [
                'school_name'    => (string) wp_unslash( $_POST['school_name'] ?? '' ),
                'address'        => (string) wp_unslash( $_POST['address'] ?? '' ),
                'phone'          => (string) wp_unslash( $_POST['phone'] ?? '' ),
                'email'          => (string) wp_unslash( $_POST['email'] ?? '' ),
                'principal_name' => (string) wp_unslash( $_POST['principal_name'] ?? '' ),
            ]
        );

        // Handle logo file upload from device (replaces the old URL-paste field).
        $logo_url = '';
        if ( ! empty( $_FILES['logo_file']['name'] ) && isset( $_FILES['logo_file']['error'] ) && $_FILES['logo_file']['error'] === UPLOAD_ERR_OK ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';

            $check_image = @getimagesize( $_FILES['logo_file']['tmp_name'] );
            if ( false !== $check_image ) {
                $movefile = wp_handle_upload( $_FILES['logo_file'], [ 'test_form' => false ] );
                if ( $movefile && ! isset( $movefile['error'] ) ) {
                    $logo_url = (string) $movefile['url'];
                }
            }
        }

        // Handle principal photo file upload (same pattern as logo upload above).
        $principal_photo_url = '';
        if ( ! empty( $_FILES['principal_photo_file']['name'] ) && isset( $_FILES['principal_photo_file']['error'] ) && $_FILES['principal_photo_file']['error'] === UPLOAD_ERR_OK ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';

            $check_pimage = @getimagesize( $_FILES['principal_photo_file']['tmp_name'] );
            if ( false !== $check_pimage ) {
                $pmovefile = wp_handle_upload( $_FILES['principal_photo_file'], [ 'test_form' => false ] );
                if ( $pmovefile && ! isset( $pmovefile['error'] ) ) {
                    $principal_photo_url = (string) $pmovefile['url'];
                }
            }
        }

        if ( $logo_url !== '' || $principal_photo_url !== '' ) {
            global $wpdb;
            $school_table = \EduCBTPro\Core\Schema::table( 'schools' );
            $update_data = [];
            $update_formats = [];
            if ( $logo_url !== '' ) { $update_data['logo'] = $logo_url; $update_formats[] = '%s'; }
            if ( $principal_photo_url !== '' ) { $update_data['principal_photo'] = $principal_photo_url; $update_formats[] = '%s'; }
            if ( ! empty( $update_data ) ) {
                $wpdb->update( $school_table, $update_data, [ 'id' => $school_id ], $update_formats, [ '%d' ] );
            }
        }

        if ( ! $updated ) {
            $this->fail( 'Nothing was changed.' );
        }

        $this->succeed( [ 'type' => 'school_updated' ] );
    }

    public function update_staff(): void {
        [ $school_id ] = $this->context( 'educbt_update_staff' );

        if ( ! Gate::allows( Capabilities::MANAGE_STAFF ) ) {
            $this->fail( 'You do not have permission to edit staff.' );
        }

        global $wpdb;

        $staff_id = absint( $_POST['staff_id'] ?? 0 );

        $fields = [
            'first_name' => sanitize_text_field( (string) wp_unslash( $_POST['first_name'] ?? '' ) ),
            'last_name'  => sanitize_text_field( (string) wp_unslash( $_POST['last_name'] ?? '' ) ),
            'title'      => sanitize_text_field( (string) wp_unslash( $_POST['title'] ?? '' ) ),
            'phone'      => sanitize_text_field( (string) wp_unslash( $_POST['phone'] ?? '' ) ),
            'email'      => sanitize_email( (string) wp_unslash( $_POST['email'] ?? '' ) ),
        ];

        if ( $fields['first_name'] === '' || $fields['last_name'] === '' ) {
            $this->fail( 'A staff member needs a first name and a surname.' );
        }

        $staff_table = \EduCBTPro\Core\Schema::table( 'staff' );

        $existing = (array) $wpdb->get_row(
            $wpdb->prepare( "SELECT role_slug, wp_user_id FROM {$staff_table} WHERE id = %d AND school_id = %d", $staff_id, $school_id ),
            ARRAY_A
        );

        if ( empty( $existing ) ) {
            $this->fail( 'That staff member could not be found.' );
        }

        // The ROLE was silently ignored before: the form offered it, the save said it
        // had worked, and the table never changed.
        $role     = sanitize_key( (string) wp_unslash( $_POST['role_slug'] ?? '' ) );
        $old_role = (string) $existing['role_slug'];

        if ( $role !== '' && $role !== $old_role && array_key_exists( $role, Capabilities::roles() ) ) {
            // A school has ONE principal. Handing the role to a second person leaves
            // two accounts that can approve results and unlock marks, and the school
            // has no way to tell which decision was whose.
            if ( $role === Capabilities::ROLE_PRINCIPAL ) {
                $incumbent = absint(
                    $wpdb->get_var(
                        $wpdb->prepare(
                            "SELECT id FROM {$staff_table}
                             WHERE school_id = %d AND role_slug = %s AND status = 'active' AND id <> %d LIMIT 1",
                            $school_id,
                            Capabilities::ROLE_PRINCIPAL,
                            $staff_id
                        )
                    )
                );

                if ( $incumbent > 0 && empty( $_POST['confirm_transfer'] ) ) {
                    $this->fail( 'This school already has a principal. Tick the transfer box to move the role — the current principal becomes a vice principal.' );
                }

                if ( $incumbent > 0 ) {
                    $this->change_role( $school_id, $incumbent, Capabilities::ROLE_VICE_PRINCIPAL );
                }
            }

            // Never leave a school with no principal. Demoting the last one logs the
            // person out into an account that cannot approve results, cannot unlock
            // marks, and cannot promote themselves back — the school is stranded.
            if ( $old_role === Capabilities::ROLE_PRINCIPAL && $role !== Capabilities::ROLE_PRINCIPAL ) {
                $others = absint(
                    $wpdb->get_var(
                        $wpdb->prepare(
                            "SELECT COUNT(*) FROM {$staff_table}
                             WHERE school_id = %d AND role_slug = %s AND status = 'active' AND id <> %d",
                            $school_id,
                            Capabilities::ROLE_PRINCIPAL,
                            $staff_id
                        )
                    )
                );

                if ( $others === 0 ) {
                    $this->fail( 'This is the school\'s only principal. Give the role to someone else first — a school with no principal cannot approve or publish results, and the change cannot be undone from inside the portal.' );
                }
            }

            $fields['role_slug'] = $role;
        }

        // Handle passport photo upload (same pattern as register_staff).
        if ( ! empty( $_FILES['photo']['name'] ) && isset( $_FILES['photo']['error'] ) && $_FILES['photo']['error'] === UPLOAD_ERR_OK ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';

            $check_image = @getimagesize( $_FILES['photo']['tmp_name'] );
            if ( false === $check_image ) {
                $this->fail( 'Uploaded passport photo is not a valid image.' );
            }

            $movefile = wp_handle_upload( $_FILES['photo'], [ 'test_form' => false ] );
            if ( $movefile && ! isset( $movefile['error'] ) ) {
                $fields['photo'] = (string) $movefile['url'];
            } else {
                $this->fail( 'Failed to save uploaded photo: ' . ( $movefile['error'] ?? 'Unknown error' ) );
            }
        }

        $formats = array_fill( 0, count( $fields ), '%s' );

        $wpdb->update(
            $staff_table,
            $fields,
            [ 'id' => $staff_id, 'school_id' => $school_id ],
            $formats,
            [ '%d', '%d' ]
        );

        if ( isset( $fields['role_slug'] ) ) {
            $this->sync_wp_role( absint( $existing['wp_user_id'] ), $fields['role_slug'] );
        }

        // Keep the WordPress account in step, or the portal greets them by their
        // old name forever.
        $user_id = absint(
            $wpdb->get_var(
                $wpdb->prepare(
                    'SELECT wp_user_id FROM ' . \EduCBTPro\Core\Schema::table( 'staff' ) . ' WHERE id = %d',
                    $staff_id
                )
            )
        );

        if ( $user_id > 0 ) {
            wp_update_user(
                [
                    'ID'           => $user_id,
                    'first_name'   => $fields['first_name'],
                    'last_name'    => $fields['last_name'],
                    'display_name' => trim( $fields['first_name'] . ' ' . $fields['last_name'] ),
                ]
            );
        }

        $this->succeed( [ 'type' => 'staff_updated', 'name' => trim( $fields['first_name'] . ' ' . $fields['last_name'] ) ] );
    }

    /**
     * Let a principal edit their own profile (name, email, phone, photo).
     */
    public function update_principal_profile(): void {
        [ $school_id ] = $this->context( 'educbt_update_principal_profile' );

        $actor = ( new \EduCBTPro\Core\Scope() )->actor();
        if ( ( $actor['role'] ?? '' ) !== Capabilities::ROLE_PRINCIPAL ) {
            $this->fail( 'Only the principal can edit this profile.' );
        }

        global $wpdb;
        $schools_table = $wpdb->prefix . 'educbt_schools';
        $staff_table   = \EduCBTPro\Core\Schema::table( 'staff' );

        $first_name = sanitize_text_field( (string) wp_unslash( $_POST['first_name'] ?? '' ) );
        $last_name  = sanitize_text_field( (string) wp_unslash( $_POST['last_name'] ?? '' ) );
        $email      = sanitize_email( (string) wp_unslash( $_POST['email'] ?? '' ) );
        $phone      = sanitize_text_field( (string) wp_unslash( $_POST['phone'] ?? '' ) );

        if ( $first_name === '' ) {
            $this->fail( 'A first name is required.' );
        }

        $principal_name = trim( $first_name . ' ' . $last_name );
        $update_fields = [ 'principal_name' => $principal_name ];
        $formats       = [ '%s' ];

        $photo_url = '';
        if ( ! empty( $_FILES['principal_photo_file']['name'] ) && isset( $_FILES['principal_photo_file']['error'] ) && $_FILES['principal_photo_file']['error'] === UPLOAD_ERR_OK ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            $check_image = @getimagesize( $_FILES['principal_photo_file']['tmp_name'] );
            if ( false !== $check_image ) {
                $movefile = wp_handle_upload( $_FILES['principal_photo_file'], [ 'test_form' => false ] );
                if ( $movefile && ! isset( $movefile['error'] ) ) {
                    $photo_url = (string) $movefile['url'];
                    $update_fields['principal_photo'] = $photo_url;
                    $formats[] = '%s';
                }
            }
        }

        // Update the schools table (principal_name + principal_photo).
        $wpdb->update( $schools_table, $update_fields, [ 'id' => $school_id ], $formats, [ '%d' ] );

        // If a staff row exists for this principal, update it too — the account
        // page reads the name and photo from the staff table when a row is present.
        $user_id   = get_current_user_id();
        $staff_row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT id FROM ' . $staff_table . ' WHERE wp_user_id = %d AND school_id = %d LIMIT 1',
                $user_id,
                $school_id
            ),
            ARRAY_A
        );

        if ( $staff_row ) {
            $staff_update = [
                'first_name' => $first_name,
                'last_name'  => $last_name,
            ];
            $staff_formats = [ '%s', '%s' ];

            if ( $email !== '' ) {
                $staff_update['email'] = $email;
                $staff_formats[] = '%s';
            }
            if ( $phone !== '' ) {
                $staff_update['phone'] = $phone;
                $staff_formats[] = '%s';
            }
            if ( $photo_url !== '' ) {
                $staff_update['photo'] = $photo_url;
                $staff_formats[] = '%s';
            }

            $wpdb->update( $staff_table, $staff_update, [ 'id' => absint( $staff_row['id'] ) ], $staff_formats, [ '%d' ] );
        }

        if ( $user_id > 0 ) {
            wp_update_user( [
                'ID'         => $user_id,
                'first_name' => $first_name,
                'last_name'  => $last_name,
                'user_email' => $email !== '' ? $email : null,
            ] );
        }

        $this->succeed( [ 'type' => 'principal_profile_updated', 'name' => $principal_name ] );
    }

    /**
     * Remove a staff member.
     *
     * Refused while they still hold a class or a subject. Removing a class teacher
     * silently leaves a class with nobody responsible for its remarks, its register
     * and its promotion decisions — and nobody notices until end of term.
     */
    public function remove_staff(): void {
        [ $school_id ] = $this->context( 'educbt_remove_staff' );

        if ( ! Gate::allows( Capabilities::MANAGE_STAFF ) ) {
            $this->fail( 'You do not have permission to remove staff.' );
        }

        global $wpdb;

        $staff_id = absint( $_POST['staff_id'] ?? 0 );
        $blockers = $this->staff_blockers( $school_id, $staff_id );

        if ( ! empty( $blockers ) && empty( $_POST['confirm_reassign'] ) ) {
            $this->fail(
                'This staff member still holds: ' . implode( '; ', $blockers )
                . '. Reassign those first, or tick the confirmation to stand them down anyway.'
            );
        }

        // Never hard-delete: results, questions and invigilation records point here,
        // and an archived row keeps that history readable.
        $wpdb->update(
            \EduCBTPro\Core\Schema::table( 'staff' ),
            [ 'status' => 'archived' ],
            [ 'id' => $staff_id, 'school_id' => $school_id ],
            [ '%s' ],
            [ '%d', '%d' ]
        );

        $wpdb->update(
            \EduCBTPro\Core\Schema::table( 'staff_assignments' ),
            [ 'status' => 'ended' ],
            [ 'staff_id' => $staff_id, 'school_id' => $school_id, 'status' => 'active' ],
            [ '%s' ],
            [ '%d', '%d', '%s' ]
        );

        $this->succeed( [ 'type' => 'staff_removed', 'released' => $blockers ] );
    }

    /**
     * Move one staff member to another role, keeping their WordPress account in step.
     */
    private function change_role( int $school_id, int $staff_id, string $role ): void {
        global $wpdb;

        $table = \EduCBTPro\Core\Schema::table( 'staff' );

        $user_id = absint(
            $wpdb->get_var( $wpdb->prepare( "SELECT wp_user_id FROM {$table} WHERE id = %d", $staff_id ) )
        );

        $wpdb->update(
            $table,
            [ 'role_slug' => $role ],
            [ 'id' => $staff_id, 'school_id' => $school_id ],
            [ '%s' ],
            [ '%d', '%d' ]
        );

        $this->sync_wp_role( $user_id, $role );
    }

    /**
     * The staff row and the WordPress role must agree. If only the row changes, the
     * person keeps their old capabilities and the change appears to have done nothing.
     */
    private function sync_wp_role( int $user_id, string $role ): void {
        if ( $user_id <= 0 ) {
            return;
        }

        $user = get_userdata( $user_id );

        if ( ! $user ) {
            return;
        }

        foreach ( array_keys( Capabilities::roles() ) as $slug ) {
            $user->remove_role( $slug );
        }

        $user->add_role( $role );
    }

    /**
     * What a staff member still holds, in words a principal can act on.
     *
     * @return array<int,string>
     */
    private function staff_blockers( int $school_id, int $staff_id ): array {
        global $wpdb;

        $assignments = \EduCBTPro\Core\Schema::table( 'staff_assignments' );
        $classes     = \EduCBTPro\Core\Schema::table( 'classes' );
        $subjects    = \EduCBTPro\Core\Schema::table( 'subjects_v2' );
        $enrolments  = \EduCBTPro\Core\Schema::table( 'enrollments' );

        $out = [];

        $held = (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT a.assignment_type, c.display_name AS class_name, s.name AS subject_name,
                        (SELECT COUNT(*) FROM {$enrolments} e WHERE e.class_id = a.class_id AND e.status = 'active') AS students
                 FROM {$assignments} a
                 LEFT JOIN {$classes} c ON c.id = a.class_id
                 LEFT JOIN {$subjects} s ON s.id = a.subject_id
                 WHERE a.school_id = %d AND a.staff_id = %d AND a.status = 'active'",
                $school_id,
                $staff_id
            ),
            ARRAY_A
        );

        foreach ( $held as $row ) {
            if ( (string) $row['assignment_type'] === 'class_teacher' ) {
                $out[] = sprintf(
                    'class teacher of %s (%d students)',
                    (string) $row['class_name'],
                    (int) $row['students']
                );
            } elseif ( (string) $row['assignment_type'] === 'subject_teacher' ) {
                $out[] = sprintf( '%s in %s', (string) $row['subject_name'], (string) $row['class_name'] );
            }
        }

        $invigilating = absint(
            $wpdb->get_var(
                $wpdb->prepare(
                    'SELECT COUNT(*) FROM ' . \EduCBTPro\Core\Schema::table( 'paper_invigilators' ) . ' i
                     INNER JOIN ' . \EduCBTPro\Core\Schema::table( 'exam_papers' ) . " p ON p.id = i.paper_id
                     WHERE i.school_id = %d AND i.staff_id = %d AND p.scheduled_at > %s",
                    $school_id,
                    $staff_id,
                    current_time( 'mysql', true )
                )
            )
        );

        if ( $invigilating > 0 ) {
            $out[] = sprintf( '%d upcoming paper(s) to invigilate', $invigilating );
        }

        return $out;
    }

    // ---------------------------------------------------------------

    public function register_student(): void {
        [ $school_id, $scope ] = $this->context( 'educbt_register_student' );

        $class_id = absint( $_POST['class_id'] ?? 0 );

        // A class teacher may only register into their OWN class; school management
        // may place into any. This is the Phase 2 scope rule doing real work.
        if ( ! Gate::allows( Capabilities::REGISTER_STUDENTS, [ 'class_id' => $class_id ] ) ) {
            $this->fail( 'You can only register students into a class you hold.' );
        }

        // Handle passport photo upload from device (file input, not WP media library)
        $photo_url = '';
        if ( ! empty( $_FILES['photo']['name'] ) && isset( $_FILES['photo']['error'] ) && $_FILES['photo']['error'] === UPLOAD_ERR_OK ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';

            $check_image = @getimagesize( $_FILES['photo']['tmp_name'] );
            if ( false === $check_image ) {
                $this->fail( 'Uploaded passport photo is not a valid image.' );
            }

            $movefile = wp_handle_upload( $_FILES['photo'], [ 'test_form' => false ] );

            if ( $movefile && ! isset( $movefile['error'] ) ) {
                $photo_url = (string) $movefile['url'];
            } else {
                $this->fail( 'Failed to save uploaded photo: ' . ( $movefile['error'] ?? 'Unknown error' ) );
            }
        }

        $service = new StudentRegistrationService();

        $result = $service->register(
            $school_id,
            [
                'first_name'     => (string) wp_unslash( $_POST['first_name'] ?? '' ),
                'last_name'      => (string) wp_unslash( $_POST['last_name'] ?? '' ),
                'gender'         => (string) wp_unslash( $_POST['gender'] ?? '' ),
                'date_of_birth'  => (string) wp_unslash( $_POST['date_of_birth'] ?? '' ),
                'passport_photo' => $photo_url,
                'class_id'       => $class_id,
                // Blank means "generate one". A school that already issues its own
                // student IDs types theirs here and keeps it.
                'admission_number' => (string) wp_unslash( $_POST['admission_number'] ?? '' ),
            ]
        );

        if ( empty( $result['success'] ) ) {
            $this->fail( $this->readable( $result['errors'] ?? [] ) );
        }

        // Optionally link a guardian in the same step — the office has the parent's
        // details in front of them at intake, and will not come back later.
        $guardian_email = trim( (string) wp_unslash( $_POST['guardian_email'] ?? '' ) );
        $guardian_phone = trim( (string) wp_unslash( $_POST['guardian_phone'] ?? '' ) );

        $invite = '';

        if ( $guardian_email !== '' || $guardian_phone !== '' ) {
            $linked = ( new GuardianService() )->link_to_student(
                $school_id,
                (int) $result['student_id'],
                [
                    'first_name' => (string) wp_unslash( $_POST['guardian_first_name'] ?? '' ),
                    'last_name'  => (string) wp_unslash( $_POST['guardian_last_name'] ?? '' ),
                    'email'      => $guardian_email,
                    'phone'      => $guardian_phone,
                ]
            );

            $invite = (string) ( $linked['invite_token'] ?? '' );
        }

        $password = (string) ( $result['credentials']['initial_password'] ?? '' );

        if ( $password === '' ) {
            // The student record was saved but the WP login could not be created.
            // Tell the school explicitly so they can reset the password later.
            $this->succeed(
                [
                    'type'             => 'student',
                    'name'             => trim( (string) wp_unslash( $_POST['first_name'] ?? '' ) . ' ' . (string) wp_unslash( $_POST['last_name'] ?? '' ) ),
                    'admission_number' => (string) $result['admission_number'],
                    'password'         => '(login setup failed — use Reset Password)',
                    'invite_token'     => $invite,
                ]
            );
        }

        $this->succeed(
            [
                'type'             => 'student',
                'name'             => trim( (string) wp_unslash( $_POST['first_name'] ?? '' ) . ' ' . (string) wp_unslash( $_POST['last_name'] ?? '' ) ),
                'admission_number' => (string) $result['admission_number'],
                'password'         => $password,
                'invite_token'     => $invite,
            ]
        );
    }

    public function register_staff(): void {
        [ $school_id ] = $this->context( 'educbt_register_staff' );

        if ( ! Gate::allows( Capabilities::MANAGE_STAFF ) ) {
            $this->fail( 'You do not have permission to add staff.' );
        }

        $photo_url = '';
        if ( ! empty( $_FILES['photo']['name'] ) && isset( $_FILES['photo']['error'] ) && $_FILES['photo']['error'] === UPLOAD_ERR_OK ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';

            $uploaded_file = $_FILES['photo'];

            $check_image = @getimagesize( $uploaded_file['tmp_name'] );
            if ( false === $check_image ) {
                $this->fail( 'Uploaded passport photo is not a valid image.' );
            }

            $upload_overrides = [ 'test_form' => false ];
            $movefile         = wp_handle_upload( $uploaded_file, $upload_overrides );

            if ( $movefile && ! isset( $movefile['error'] ) ) {
                $photo_url = (string) $movefile['url'];
            } else {
                $this->fail( 'Failed to save uploaded photo: ' . ( $movefile['error'] ?? 'Unknown error' ) );
            }
        }

        $result = ( new StaffService() )->register(
            $school_id,
            [
                'first_name' => (string) wp_unslash( $_POST['first_name'] ?? '' ),
                'last_name'  => (string) wp_unslash( $_POST['last_name'] ?? '' ),
                'title'      => (string) wp_unslash( $_POST['title'] ?? '' ),
                'gender'     => (string) wp_unslash( $_POST['gender'] ?? '' ),
                'email'      => (string) wp_unslash( $_POST['email'] ?? '' ),
                'phone'      => (string) wp_unslash( $_POST['phone'] ?? '' ),
                'photo'      => $photo_url,
                'role_slug'  => (string) wp_unslash( $_POST['role_slug'] ?? Capabilities::ROLE_TEACHER ),
            ]
        );

        if ( empty( $result['success'] ) ) {
            $this->fail( $this->readable( $result['errors'] ?? [] ) );
        }

        $this->succeed(
            [
                'type'         => 'staff',
                'name'         => trim( (string) wp_unslash( $_POST['first_name'] ?? '' ) . ' ' . (string) wp_unslash( $_POST['last_name'] ?? '' ) ),
                'staff_number' => (string) $result['staff_number'],
                'username'     => (string) ( $result['credentials']['username'] ?? '' ),
                'password'     => (string) ( $result['credentials']['temporary_password'] ?? '' ),
            ]
        );
    }

    /**
     * Assign several teachers, each to several subjects across several classes, in
     * one save.
     *
     * The old form did one pair at a time. In a Nigerian school an Agricultural
     * Science teacher usually takes the subject across the whole junior school, so
     * one teacher could mean nine separate saves — and that is before the next
     * teacher. The work is the same; the tedium was the bug.
     */
    public function assign_bulk(): void {
        [ $school_id ] = $this->context( 'educbt_assign_bulk' );

        if ( ! Gate::allows( Capabilities::ASSIGN_STAFF ) ) {
            $this->fail( 'You do not have permission to assign staff.' );
        }

        $session_id = absint( $_POST['session_id'] ?? 0 );

        if ( $session_id === 0 ) {
            $current    = ( new \EduCBTPro\Services\AcademicYearService() )->current_session( $school_id );
            $session_id = absint( $current['id'] ?? 0 );
        }

        $type    = sanitize_key( (string) wp_unslash( $_POST['assignment_type'] ?? '' ) );
        $rows    = (array) ( $_POST['row'] ?? [] );
        $service = new \EduCBTPro\Services\StaffService();

        $saved    = 0;
        $problems = [];

        foreach ( $rows as $row ) {
            $staff_id = absint( $row['staff_id'] ?? 0 );

            if ( $staff_id === 0 ) {
                continue; // an empty row left behind by the "add another" button
            }

            $classes  = array_filter( array_map( 'absint', (array) ( $row['class_ids'] ?? [] ) ) );
            $subjects = array_filter( array_map( 'absint', (array) ( $row['subject_ids'] ?? [] ) ) );

            if ( empty( $classes ) ) {
                $problems[] = 'a row had no class chosen';
                continue;
            }

            if ( $type === 'class_teacher' ) {
                foreach ( $classes as $class_id ) {
                    $result = $service->assign( $school_id, $staff_id, 'class_teacher', [ 'class_id' => $class_id ], $session_id );

                    if ( ! empty( $result['success'] ) ) {
                        $saved++;
                    } else {
                        $problems[] = (string) ( $result['error'] ?? 'unknown' );
                    }
                }

                continue;
            }

            if ( empty( $subjects ) ) {
                $problems[] = 'a row had no subject chosen';
                continue;
            }

            // Every subject in the row, across every class in the row.
            foreach ( $subjects as $subject_id ) {
                foreach ( $classes as $class_id ) {
                    $result = $service->assign(
                        $school_id,
                        $staff_id,
                        'subject_teacher',
                        [ 'class_id' => $class_id, 'subject_id' => $subject_id ],
                        $session_id
                    );

                    if ( ! empty( $result['success'] ) ) {
                        $saved++;
                    } else {
                        $problems[] = (string) ( $result['error'] ?? 'unknown' );
                    }
                }
            }
        }

        if ( $saved === 0 ) {
            $this->fail( 'Nothing was assigned. ' . $this->readable( array_slice( $problems, 0, 3 ) ) );
        }

        $this->succeed(
            [
                'type'     => 'assignments',
                'saved'    => $saved,
                'problems' => array_values( array_unique( array_slice( $problems, 0, 5 ) ) ),
            ]
        );
    }

    /**
     * A principal resetting a staff member's password.
     *
     * Previously only the platform owner could do this, which meant a teacher who
     * forgot their password had to wait for someone outside the school.
     */
    public function reset_staff_password(): void {
        [ $school_id ] = $this->context( 'educbt_reset_staff_password' );

        if ( ! Gate::allows( Capabilities::MANAGE_STAFF ) ) {
            $this->fail( 'You do not have permission to reset a staff password.' );
        }

        global $wpdb;

        $staff_id = absint( $_POST['staff_id'] ?? 0 );

        $row = (array) $wpdb->get_row(
            $wpdb->prepare(
                'SELECT wp_user_id, first_name, last_name FROM ' . \EduCBTPro\Core\Schema::table( 'staff' ) .
                ' WHERE id = %d AND school_id = %d',
                $staff_id,
                $school_id
            ),
            ARRAY_A
        );

        $user_id = absint( $row['wp_user_id'] ?? 0 );

        if ( $user_id === 0 ) {
            $this->fail( 'That staff member has no login account.' );
        }

        $password = wp_generate_password( 10, false, false );

        wp_set_password( $password, $user_id );
        update_user_meta( $user_id, '_educbt_must_change_password', 1 );

        $user = get_userdata( $user_id );

        $this->succeed(
            [
                'type'     => 'staff_reset',
                'name'     => trim( (string) $row['first_name'] . ' ' . (string) $row['last_name'] ),
                'username' => $user ? $user->user_login : '',
                'password' => $password,
            ]
        );
    }

    /**
     * Drop (deactivate) a single staff assignment — typically a subject
     * the teacher should no longer teach. Shown as a "Drop" button in the
     * staff edit form.
     */
    public function drop_assignment(): void {
        [ $school_id ] = $this->context( 'educbt_drop_assignment' );

        if ( ! Gate::allows( Capabilities::MANAGE_STAFF ) ) {
            $this->fail( 'You do not have permission to change staff assignments.' );
        }

        global $wpdb;

        $assignment_id = absint( $_POST['assignment_id'] ?? 0 );

        if ( $assignment_id <= 0 ) {
            $this->fail( 'That assignment could not be found.' );
        }

        $table = \EduCBTPro\Core\Schema::table( 'staff_assignments' );

        $wpdb->update(
            $table,
            [ 'status' => 'dropped' ],
            [ 'id' => $assignment_id, 'school_id' => $school_id ],
            [ '%s' ],
            [ '%d', '%d' ]
        );

        $this->succeed( [ 'type' => 'assignment_dropped' ] );
    }

    /**
     * Anyone changing their own password from their settings.
     */
    public function change_own_password(): void {
        if ( ! is_user_logged_in() ) {
            wp_safe_redirect( home_url( '/portal/login/' ) );
            exit;
        }

        check_admin_referer( 'educbt_change_own_password' );

        $user    = wp_get_current_user();
        $current = (string) ( $_POST['current_password'] ?? '' );
        $new     = (string) ( $_POST['new_password'] ?? '' );
        $confirm = (string) ( $_POST['confirm_password'] ?? '' );

        // The current password is required even though they are signed in: a session
        // left open on a shared exam terminal must not let the next person take over
        // the account.
        if ( ! wp_check_password( $current, $user->user_pass, $user->ID ) ) {
            $this->fail( 'Your current password is not correct.' );
        }

        if ( strlen( $new ) < 8 ) {
            $this->fail( 'Your new password must be at least 8 characters.' );
        }

        if ( $new !== $confirm ) {
            $this->fail( 'The two new passwords do not match.' );
        }

        wp_set_password( $new, $user->ID );
        delete_user_meta( $user->ID, '_educbt_must_change_password' );

        // Changing a password ends the session; sign them straight back in.
        wp_set_auth_cookie( $user->ID );

        $this->succeed( [ 'type' => 'password_changed' ] );
    }

    public function assign_staff(): void {
        [ $school_id ] = $this->context( 'educbt_assign_staff' );

        if ( ! Gate::allows( Capabilities::ASSIGN_STAFF ) ) {
            $this->fail( 'You do not have permission to assign staff.' );
        }

        $session_id = absint( $_POST['session_id'] ?? 0 );

        if ( $session_id <= 0 ) {
            $current    = ( new AcademicYearService() )->current_session( $school_id );
            $session_id = absint( $current['id'] ?? 0 );
        }

        $result = ( new StaffService() )->assign(
            $school_id,
            absint( $_POST['staff_id'] ?? 0 ),
            (string) wp_unslash( $_POST['assignment_type'] ?? '' ),
            [
                'class_id'      => absint( $_POST['class_id'] ?? 0 ),
                'subject_id'    => absint( $_POST['subject_id'] ?? 0 ),
                'department_id' => absint( $_POST['department_id'] ?? 0 ),
            ],
            $session_id
        );

        if ( empty( $result['success'] ) ) {
            $this->fail( $this->readable( [ $result['error'] ?? 'unknown' ] ) );
        }

        $this->succeed( [ 'type' => 'assignment' ] );
    }

    public function link_guardian(): void {
        [ $school_id ] = $this->context( 'educbt_link_guardian' );

        $student_id = absint( $_POST['student_id'] ?? 0 );

        if ( ! Gate::allows( Capabilities::MANAGE_GUARDIANS, [ 'student_id' => $student_id ] ) ) {
            $this->fail( 'You do not have permission to manage this student.' );
        }

        $result = ( new GuardianService() )->link_to_student(
            $school_id,
            $student_id,
            [
                'first_name'   => (string) wp_unslash( $_POST['first_name'] ?? '' ),
                'last_name'    => (string) wp_unslash( $_POST['last_name'] ?? '' ),
                'email'        => (string) wp_unslash( $_POST['email'] ?? '' ),
                'phone'        => (string) wp_unslash( $_POST['phone'] ?? '' ),
                'relationship' => (string) wp_unslash( $_POST['relationship'] ?? 'parent' ),
            ]
        );

        if ( empty( $result['success'] ) ) {
            $this->fail( $this->readable( $result['errors'] ?? [] ) );
        }

        $this->succeed( [ 'type' => 'guardian', 'invite_token' => (string) ( $result['invite_token'] ?? '' ) ] );
    }

    public function build_invigilation(): void {
        [ $school_id ] = $this->context( 'educbt_build_invigilation' );

        if ( ! Gate::allows( Capabilities::ASSIGN_INVIGILATORS ) ) {
            $this->fail( 'You do not have permission to build the invigilation schedule.' );
        }

        $series_id = absint( $_POST['series_id'] ?? 0 );
        $service   = new \EduCBTPro\Services\InvigilationScheduleService();

        // "Apply the timetable changes" clears what is there first, because the point
        // is to rebuild against the new times rather than fill gaps around stale rows.
        if ( ! empty( $_POST['rebuild'] ) ) {
            global $wpdb;

            $wpdb->query(
                $wpdb->prepare(
                    'DELETE i FROM ' . \EduCBTPro\Core\Schema::table( 'paper_invigilators' ) . ' i
                     INNER JOIN ' . \EduCBTPro\Core\Schema::table( 'exam_papers' ) . ' p ON p.id = i.paper_id
                     WHERE i.school_id = %d AND p.series_id = %d',
                    $school_id,
                    $series_id
                )
            );
        }

        $result = $service->propose( $school_id, $series_id );

        $this->succeed(
            [
                'type'     => 'invigilation_built',
                'assigned' => (int) $result['assigned'],
                'unfilled' => (array) $result['unfilled'],
            ]
        );
    }

    public function reassign_invigilator(): void {
        [ $school_id ] = $this->context( 'educbt_reassign_invigilator' );

        if ( ! Gate::allows( Capabilities::ASSIGN_INVIGILATORS ) ) {
            $this->fail( 'You do not have permission to change invigilators.' );
        }

        $force = ! empty( $_POST['force'] );
        $result = ( new \EduCBTPro\Services\InvigilationScheduleService() )->reassign(
            $school_id,
            absint( $_POST['paper_id'] ?? 0 ),
            absint( $_POST['staff_id'] ?? 0 ),
            $force
        );

        if ( empty( $result['success'] ) ) {
            $reasons = [
                'teaches_this_subject'      => 'That teacher takes this subject for this class, so they cannot invigilate it.',
                'already_invigilating_then' => 'They are already invigilating another paper at that time.',
                'paper_not_found'           => 'That paper could not be found.',
            ];

            $ctx = [];
            if ( ( $result['error'] ?? '' ) === 'already_invigilating_then' ) {
                $ctx = [
                    'paper_id' => absint( $_POST['paper_id'] ?? 0 ),
                    'staff_id' => absint( $_POST['staff_id'] ?? 0 ),
                    'error'    => 'already_invigilating_then',
                ];
            }

            $this->fail( $reasons[ $result['error'] ?? '' ] ?? 'That change could not be made.', '', $ctx );
        }

        $this->succeed( [ 'type' => 'invigilator_changed' ] );
    }

    public function repair_data(): void {
        [ $school_id ] = $this->context( 'educbt_repair_data' );

        if ( ! Gate::allows( Capabilities::MANAGE_STUDENTS ) ) {
            $this->fail( 'You do not have permission to repair records.' );
        }

        $result = ( new \EduCBTPro\Services\DataIntegrityService() )->repair(
            $school_id,
            sanitize_key( (string) wp_unslash( $_POST['key'] ?? '' ) )
        );

        $this->succeed( [ 'type' => 'data_repaired', 'message' => (string) $result['message'] ] );
    }

    public function update_student(): void {
        [ $school_id ] = $this->context( 'educbt_update_student' );

        global $wpdb;

        $student_id = absint( $_POST['student_id'] ?? 0 );
        $class_id   = absint( $_POST['class_id'] ?? 0 );

        if ( ! Gate::allows( Capabilities::MANAGE_STUDENTS, [ 'student_id' => $student_id ] ) ) {
            $this->fail( 'You do not have permission to edit this student.' );
        }

        $first_name = sanitize_text_field( (string) wp_unslash( $_POST['first_name'] ?? '' ) );
        $last_name  = sanitize_text_field( (string) wp_unslash( $_POST['last_name'] ?? '' ) );

        // Handle passport photo upload from device
        $photo_url = '';
        if ( ! empty( $_FILES['photo']['name'] ) && isset( $_FILES['photo']['error'] ) && $_FILES['photo']['error'] === UPLOAD_ERR_OK ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';

            $check_image = @getimagesize( $_FILES['photo']['tmp_name'] );
            if ( false !== $check_image ) {
                $movefile = wp_handle_upload( $_FILES['photo'], [ 'test_form' => false ] );
                if ( $movefile && ! isset( $movefile['error'] ) ) {
                    $photo_url = (string) $movefile['url'];
                }
            }
        }
        // Fall back to existing photo if no new file was uploaded
        if ( $photo_url === '' ) {
            $photo_url = esc_url_raw( (string) wp_unslash( $_POST['existing_photo'] ?? '' ) );
        }

        $fields = [
            'first_name'          => $first_name,
            'last_name'           => $last_name,
            'full_name'           => trim( $first_name . ' ' . $last_name ),
            'gender'              => sanitize_key( (string) wp_unslash( $_POST['gender'] ?? '' ) ),
            'passport_photo'      => $photo_url,
            'parent_information'  => sanitize_text_field( (string) wp_unslash( $_POST['parent_information'] ?? '' ) ),
            'parent_phone'        => sanitize_text_field( (string) wp_unslash( $_POST['parent_phone'] ?? '' ) ),
            'parent_email'        => sanitize_email( (string) wp_unslash( $_POST['parent_email'] ?? '' ) ),
            'address'             => sanitize_textarea_field( (string) wp_unslash( $_POST['address'] ?? '' ) ),
        ];

        if ( $fields['first_name'] === '' || $fields['last_name'] === '' ) {
            $this->fail( 'A student needs a first name and a surname.' );
        }

        $formats = [ '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ];

        // A school that runs its own student IDs must be able to correct one after
        // the fact. The ID is also the login username, so the WordPress account has
        // to move with it or the student is locked out.
        $students_table = $wpdb->prefix . 'educbt_students';

        $current = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT admission_number, wp_user_id FROM {$students_table} WHERE id = %d AND school_id = %d",
                $student_id,
                $school_id
            ),
            ARRAY_A
        );

        $new_id = strtoupper( trim( sanitize_text_field( (string) wp_unslash( $_POST['admission_number'] ?? '' ) ) ) );
        $old_id = (string) ( $current['admission_number'] ?? '' );

        if ( $new_id !== '' && $new_id !== $old_id ) {
            $clash = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT id FROM {$students_table} WHERE admission_number = %s AND id <> %d LIMIT 1",
                    $new_id,
                    $student_id
                )
            );

            if ( $clash ) {
                $this->fail( 'That student ID is already in use by another student.' );
            }

            $fields['admission_number']    = $new_id;
            $fields['registration_number'] = $new_id;
            $fields['student_id']          = $new_id;
            $formats[]                     = '%s';
            $formats[]                     = '%s';
            $formats[]                     = '%s';

            $wp_user_id = absint( $current['wp_user_id'] ?? 0 );

            if ( $wp_user_id > 0 ) {
                $wpdb->update(
                    $wpdb->users,
                    [ 'user_login' => $new_id ],
                    [ 'ID' => $wp_user_id ],
                    [ '%s' ],
                    [ '%d' ]
                );
                clean_user_cache( $wp_user_id );
            }
        }

        $wpdb->update(
            $students_table,
            $fields,
            [ 'id' => $student_id, 'school_id' => $school_id ],
            $formats,
            [ '%d', '%d' ]
        );

        // Moving class updates the CURRENT session's enrolment only. Past enrolments
        // are history and must not be rewritten, or last term's results detach from
        // the class they were actually earned in.
        if ( $class_id > 0 ) {
            $session = ( new \EduCBTPro\Services\AcademicYearService() )->current_session( $school_id );

            if ( ! empty( $session['id'] ) ) {
                $wpdb->update(
                    \EduCBTPro\Core\Schema::table( 'enrollments' ),
                    [ 'class_id' => $class_id ],
                    [ 'student_id' => $student_id, 'school_id' => $school_id, 'session_id' => absint( $session['id'] ) ],
                    [ '%d' ],
                    [ '%d', '%d', '%d' ]
                );
            }
        }

        $this->succeed( [ 'type' => 'student_updated', 'name' => $fields['full_name'] ] );
    }

    public function withdraw_student(): void {
        [ $school_id ] = $this->context( 'educbt_withdraw_student' );

        $student_id = absint( $_POST['student_id'] ?? 0 );

        if ( ! Gate::allows( Capabilities::MANAGE_STUDENTS, [ 'student_id' => $student_id ] ) ) {
            $this->fail( 'You do not have permission to withdraw this student.' );
        }

        global $wpdb;

        // Withdrawn, never deleted: results, attempts and transcripts point at this
        // row, and a school may need to produce them years later.
        $wpdb->update(
            $wpdb->prefix . 'educbt_students',
            [ 'status' => 'withdrawn' ],
            [ 'id' => $student_id, 'school_id' => $school_id ],
            [ '%s' ],
            [ '%d', '%d' ]
        );

        $wpdb->update(
            \EduCBTPro\Core\Schema::table( 'enrollments' ),
            [ 'status' => 'withdrawn' ],
            [ 'student_id' => $student_id, 'school_id' => $school_id, 'status' => 'active' ],
            [ '%s' ],
            [ '%d', '%d', '%s' ]
        );

        $this->succeed( [ 'type' => 'student_withdrawn' ] );
    }

    /**
     * Reactivate a withdrawn student. Re-enables their enrolment for the current
     * session so they show up in class lists and can sit papers again.
     */
    /**
     * Approve a pending student added by a class teacher: flip the student
     * and their enrolment from pending_approval to active.
     */
    public function approve_student(): void {
        [ $school_id ] = $this->context( 'educbt_approve_student' );

        $student_id = absint( $_POST['student_id'] ?? 0 );

        if ( ! Gate::allows( Capabilities::MANAGE_STUDENTS, [ 'student_id' => $student_id ] ) ) {
            $this->fail( 'You do not have permission to approve this student.' );
        }

        global $wpdb;

        $stu_table = $wpdb->prefix . 'educbt_students';

        // Flip student status to active
        $wpdb->update(
            $stu_table,
            [ 'status' => 'active' ],
            [ 'id' => $student_id, 'school_id' => $school_id ],
            [ '%s' ],
            [ '%d', '%d' ]
        );

        // Flip pending enrolment(s) to active
        $wpdb->update(
            \EduCBTPro\Core\Schema::table( 'enrollments' ),
            [ 'status' => 'active' ],
            [ 'student_id' => $student_id, 'school_id' => $school_id, 'status' => 'pending_approval' ],
            [ '%s' ],
            [ '%d', '%d', '%s' ]
        );

        $this->succeed( [ 'type' => 'student_approved' ] );
    }


    /**
     * Set a student's standing: active, suspended, withdrawn or expelled.
     *
     * One action rather than four, because these are the same decision with
     * different weight and they must be mutually exclusive — a student cannot be
     * both withdrawn and suspended, and separate toggles would allow it.
     *
     * Nothing is deleted. A student who leaves keeps their record, because their
     * results are part of the school's history and a transcript may be requested
     * years later.
     */
    public function set_student_status(): void {
        [ $school_id ] = $this->context( 'educbt_set_student_status' );

        if ( ! Gate::allows( Capabilities::MANAGE_STUDENTS ) ) {
            $this->fail( 'You do not have permission to change a student\'s status.' );
        }

        global $wpdb;

        $student_id = absint( $_POST['student_id'] ?? 0 );
        $new_status = sanitize_key( (string) wp_unslash( $_POST['new_status'] ?? '' ) );

        $allowed = [ 'active', 'inactive', 'withdrawn', 'expelled' ];

        if ( $student_id <= 0 || ! in_array( $new_status, $allowed, true ) ) {
            $this->fail( 'That status change is not valid.' );
        }

        $students = $wpdb->prefix . 'educbt_students';

        $student = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, first_name, last_name, wp_user_id, status FROM {$students}
                 WHERE id = %d AND school_id = %d",
                $student_id,
                $school_id
            ),
            ARRAY_A
        );

        if ( ! $student ) {
            $this->fail( 'That student could not be found in this school.' );
        }

        // Stamp WHEN the standing changed. An expulsion carries a 30-day window in
        // which the school can reconsider, and without a date there is no way to
        // know whether that window is open.
        // Write the standing on its own. The timestamp is a nice-to-have; the status
        // change is the point. Combining them meant that on an installation where
        // status_changed_at had not been added yet, the whole UPDATE failed and the
        // button appeared to do nothing at all.
        $changed = $wpdb->update(
            $students,
            [ 'status' => $new_status ],
            [ 'id' => $student_id, 'school_id' => $school_id ],
            [ '%s' ],
            [ '%d', '%d' ]
        );

        if ( $changed === false ) {
            $this->fail( 'That status could not be saved: ' . $wpdb->last_error );
        }

        // Best effort, and silent if the column is not there yet.
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$students} SET status_changed_at = %s WHERE id = %d AND school_id = %d",
                current_time( 'mysql' ),
                $student_id,
                $school_id
            )
        );

        // Enrolment follows standing. A student who is no longer at the school
        // must not still appear on a class register or a broadsheet.
        if ( in_array( $new_status, [ 'withdrawn', 'expelled' ], true ) ) {
            $wpdb->update(
                \EduCBTPro\Core\Schema::table( 'enrollments' ),
                [ 'status' => 'inactive' ],
                [ 'student_id' => $student_id, 'school_id' => $school_id ],
                [ '%s' ],
                [ '%d', '%d' ]
            );
        }

        \EduCBTPro\Core\EventDispatcher::action( 'educbt_student_status_changed', [
            'school_id'  => $school_id,
            'id'         => $student_id,
            'from'       => (string) $student['status'],
            'to'         => $new_status,
            'name'       => trim( (string) $student['first_name'] . ' ' . (string) $student['last_name'] ),
        ] );

        $labels = [
            'active'    => 'reinstated',
            'inactive'  => 'suspended',
            'withdrawn' => 'withdrawn',
            'expelled'  => 'expelled',
        ];

        $this->succeed(
            [
                'type'   => 'student_status',
                'name'   => trim( (string) $student['first_name'] . ' ' . (string) $student['last_name'] ),
                'action' => $labels[ $new_status ],
            ]
        );
    }

    public function activate_student(): void {
        [ $school_id ] = $this->context( 'educbt_activate_student' );

        $student_id = absint( $_POST['student_id'] ?? 0 );

        if ( ! Gate::allows( Capabilities::MANAGE_STUDENTS, [ 'student_id' => $student_id ] ) ) {
            $this->fail( 'You do not have permission to activate this student.' );
        }

        global $wpdb;

        $wpdb->update(
            $wpdb->prefix . 'educbt_students',
            [ 'status' => 'active' ],
            [ 'id' => $student_id, 'school_id' => $school_id ],
            [ '%s' ],
            [ '%d', '%d' ]
        );

        // Reactivate enrolments that were either withdrawn OR inactive
        $wpdb->query(
            $wpdb->prepare(
                'UPDATE ' . \EduCBTPro\Core\Schema::table( 'enrollments' ) . '
                 SET status = %s
                 WHERE student_id = %d AND school_id = %d AND status IN ( %s, %s )',
                'active',
                $student_id,
                $school_id,
                'withdrawn',
                'inactive'
            )
        );

        $this->succeed( [ 'type' => 'student_activated' ] );
    }

    /**
     * Deactivate a student — a temporary suspension that blocks portal access
     * (unlike withdrawal, which is for when they leave the school permanently).
     * Results and data are preserved. Used for discipline, unpaid fees, etc.
     */
    public function deactivate_student(): void {
        [ $school_id ] = $this->context( 'educbt_deactivate_student' );

        $student_id = absint( $_POST['student_id'] ?? 0 );

        if ( ! Gate::allows( Capabilities::MANAGE_STUDENTS, [ 'student_id' => $student_id ] ) ) {
            $this->fail( 'You do not have permission to deactivate this student.' );
        }

        global $wpdb;

        $wpdb->update(
            $wpdb->prefix . 'educbt_students',
            [ 'status' => 'inactive' ],
            [ 'id' => $student_id, 'school_id' => $school_id ],
            [ '%s' ],
            [ '%d', '%d' ]
        );

        $wpdb->update(
            \EduCBTPro\Core\Schema::table( 'enrollments' ),
            [ 'status' => 'inactive' ],
            [ 'student_id' => $student_id, 'school_id' => $school_id, 'status' => 'active' ],
            [ '%s' ],
            [ '%d', '%d', '%s' ]
        );

        $this->succeed( [ 'type' => 'student_deactivated' ] );
    }

    /**
     * Export doubles as the import template: the columns you get back are exactly
     * the columns to fill in, so nobody has to guess the format.
     */
    public function export_students(): void {
        [ $school_id ] = $this->context( 'educbt_export_students' );

        if ( ! Gate::allows( Capabilities::VIEW_STUDENTS ) ) {
            $this->fail( 'You do not have permission to export students.' );
        }

        global $wpdb;

        $class_id = absint( $_POST['class_id'] ?? 0 );
        $session  = ( new \EduCBTPro\Services\AcademicYearService() )->current_session( $school_id );

        $enrolments = \EduCBTPro\Core\Schema::table( 'enrollments' );
        $students   = $wpdb->prefix . 'educbt_students';

        $where  = 'e.school_id = %d AND e.session_id = %d AND e.status = %s';
        $params = [ $school_id, absint( $session['id'] ?? 0 ), 'active' ];

        if ( $class_id > 0 ) {
            $where   .= ' AND e.class_id = %d';
            $params[] = $class_id;
        }

        $rows = (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT st.first_name, st.last_name, st.gender, st.date_of_birth, st.admission_number
                 FROM {$enrolments} e
                 INNER JOIN {$students} st ON st.id = e.student_id
                 WHERE {$where} ORDER BY st.last_name ASC",
                $params
            ),
            ARRAY_A
        );

        nocache_headers();
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename=students-' . gmdate( 'Y-m-d' ) . '.csv' );

        $out = fopen( 'php://output', 'w' );

        // Only the four fields a school actually types. Admission number is included
        // for reference on an export but ignored on import — it is generated.
        fputcsv( $out, [ 'first_name', 'last_name', 'gender', 'date_of_birth', 'admission_number (generated, ignored on import)' ] );

        if ( empty( $rows ) ) {
            fputcsv( $out, [ 'Chidi', 'Nwosu', 'male', '2012-04-15', '' ] );
            fputcsv( $out, [ 'Ngozi', 'Eze', 'female', '2012-09-02', '' ] );
        }

        foreach ( $rows as $row ) {
            fputcsv( $out, [ $row['first_name'], $row['last_name'], $row['gender'], $row['date_of_birth'], $row['admission_number'] ] );
        }

        fclose( $out );
        exit;
    }

    public function import_students(): void {
        [ $school_id ] = $this->context( 'educbt_import_students' );

        $class_id = absint( $_POST['class_id'] ?? 0 );

        if ( ! Gate::allows( Capabilities::REGISTER_STUDENTS, [ 'class_id' => $class_id ] ) ) {
            $this->fail( 'You can only import into a class you hold.' );
        }

        if ( empty( $_FILES['csv']['tmp_name'] ) || ! is_uploaded_file( $_FILES['csv']['tmp_name'] ) ) {
            $this->fail( 'Choose a CSV file to import.' );
        }

        $handle = fopen( $_FILES['csv']['tmp_name'], 'r' );

        if ( ! $handle ) {
            $this->fail( 'That file could not be read.' );
        }

        $service  = new \EduCBTPro\Services\StudentRegistrationService();
        $header   = fgetcsv( $handle );
        $imported = 0;
        $failed   = [];
        $line     = 1;

        // Map by header name so column order does not matter — a school will reorder
        // the template, and failing on that would be needlessly brittle.
        $map = [];

        foreach ( (array) $header as $i => $name ) {
            $key = sanitize_key( str_replace( ' ', '_', trim( (string) $name ) ) );

            foreach ( [ 'first_name', 'last_name', 'gender', 'date_of_birth' ] as $known ) {
                if ( strpos( $key, $known ) === 0 ) {
                    $map[ $known ] = $i;
                }
            }
        }

        if ( ! isset( $map['first_name'], $map['last_name'] ) ) {
            fclose( $handle );
            $this->fail( 'The file needs first_name and last_name columns. Download the template to see the format.' );
        }

        while ( ( $row = fgetcsv( $handle ) ) !== false ) {
            $line++;

            $first = trim( (string) ( $row[ $map['first_name'] ] ?? '' ) );
            $last  = trim( (string) ( $row[ $map['last_name'] ] ?? '' ) );

            if ( $first === '' && $last === '' ) {
                continue; // blank line
            }

            $result = $service->register(
                $school_id,
                [
                    'first_name'    => $first,
                    'last_name'     => $last,
                    'gender'        => isset( $map['gender'] ) ? trim( (string) ( $row[ $map['gender'] ] ?? '' ) ) : '',
                    'date_of_birth' => isset( $map['date_of_birth'] ) ? trim( (string) ( $row[ $map['date_of_birth'] ] ?? '' ) ) : '',
                    'class_id'      => $class_id,
                ]
            );

            if ( ! empty( $result['success'] ) ) {
                $imported++;
            } else {
                $failed[] = sprintf( 'Line %d (%s %s)', $line, $first, $last );
            }
        }

        fclose( $handle );

        $this->succeed(
            [
                'type'     => 'students_imported',
                'imported' => $imported,
                'failed'   => array_slice( $failed, 0, 10 ),
                'failures' => count( $failed ),
            ]
        );
    }

    public function update_class(): void {
        [ $school_id ] = $this->context( 'educbt_update_class' );

        if ( ! Gate::allows( Capabilities::MANAGE_CLASSES ) ) {
            $this->fail( 'You do not have permission to edit classes.' );
        }

        global $wpdb;

        $class_id = absint( $_POST['class_id'] ?? 0 );
        $arm      = strtoupper( sanitize_text_field( (string) wp_unslash( $_POST['arm'] ?? '' ) ) );
        $capacity = absint( $_POST['capacity'] ?? 0 );

        $table = \EduCBTPro\Core\Schema::table( 'classes' );

        $class = (array) $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d AND school_id = %d", $class_id, $school_id ),
            ARRAY_A
        );

        if ( empty( $class ) ) {
            $this->fail( 'That class could not be found.' );
        }

        $level_code = (string) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT code FROM ' . \EduCBTPro\Core\Schema::table( 'class_levels' ) . ' WHERE id = %d',
                absint( $class['level_id'] )
            )
        );

        $wpdb->update(
            $table,
            [
                'arm'           => $arm,
                'display_name'  => trim( $level_code . ( $arm !== '' ? ' ' . $arm : '' ) ),
                'capacity'      => $capacity,
                'department_id' => absint( $_POST['department_id'] ?? 0 ) ?: null,
            ],
            [ 'id' => $class_id, 'school_id' => $school_id ],
            [ '%s', '%s', '%d', '%d' ],
            [ '%d', '%d' ]
        );

        $this->succeed( [ 'type' => 'class_updated' ] );
    }

    /**
     * Remove a class — refused while anyone is still in it.
     */
    public function remove_class(): void {
        [ $school_id ] = $this->context( 'educbt_remove_class' );

        if ( ! Gate::allows( Capabilities::MANAGE_CLASSES ) ) {
            $this->fail( 'You do not have permission to remove classes.' );
        }

        global $wpdb;

        $class_id = absint( $_POST['class_id'] ?? 0 );

        $enrolled = absint(
            $wpdb->get_var(
                $wpdb->prepare(
                    'SELECT COUNT(*) FROM ' . \EduCBTPro\Core\Schema::table( 'enrollments' ) .
                    " WHERE class_id = %d AND status = 'active'",
                    $class_id
                )
            )
        );

        if ( $enrolled > 0 ) {
            $this->fail(
                sprintf(
                    '%d student(s) are still in that class. Move them to another class first — removing it would leave them enrolled in nothing.',
                    $enrolled
                )
            );
        }

        $papers = absint(
            $wpdb->get_var(
                $wpdb->prepare(
                    'SELECT COUNT(*) FROM ' . \EduCBTPro\Core\Schema::table( 'exam_papers' ) .
                    " WHERE class_id = %d AND status <> 'cancelled'",
                    $class_id
                )
            )
        );

        if ( $papers > 0 ) {
            $this->fail( sprintf( '%d paper(s) are set for that class. Cancel them first.', $papers ) );
        }

        $wpdb->update(
            \EduCBTPro\Core\Schema::table( 'classes' ),
            [ 'status' => 'archived' ],
            [ 'id' => $class_id, 'school_id' => $school_id ],
            [ '%s' ],
            [ '%d', '%d' ]
        );

        $this->succeed( [ 'type' => 'class_removed' ] );
    }

    public function save_promotion_rules(): void {
        [ $school_id ] = $this->context( 'educbt_save_promotion_rules' );

        if ( ! Gate::allows( Capabilities::RUN_PROMOTION ) ) {
            $this->fail( 'You do not have permission to change promotion rules.' );
        }

        // Arrives as an array from the tag picker; still accepts a comma string so an
        // older bookmarked form or a scripted call does not break.
        $raw = wp_unslash( $_POST['must_pass_codes'] ?? [] );

        if ( is_string( $raw ) ) {
            $raw = explode( ',', $raw );
        }

        $codes = array_values(
            array_unique(
                array_filter(
                    array_map(
                        static fn( $c ): string => strtoupper( trim( (string) $c ) ),
                        (array) $raw
                    )
                )
            )
        );

        ( new \EduCBTPro\Services\PromotionService() )->set_rules(
            $school_id,
            absint( $_POST['level_id'] ?? 0 ),
            [
                'pass_mark'           => (float) ( $_POST['pass_mark'] ?? 40 ),
                'promote_average'     => (float) ( $_POST['promote_average'] ?? 45 ),
                'trial_average'       => (float) ( $_POST['trial_average'] ?? 40 ),
                'min_subjects_passed' => absint( $_POST['min_subjects_passed'] ?? 6 ),
                'must_pass_codes'     => $codes,
                'require_core'        => ! empty( $_POST['require_core'] ),
            ]
        );

        $this->succeed( [ 'type' => 'rules_saved' ] );
    }

    public function reset_student_password(): void {
        [ $school_id ] = $this->context( 'educbt_reset_student_password' );

        $student_id = absint( $_POST['student_id'] ?? 0 );

        if ( ! Gate::allows( Capabilities::RESET_STUDENT_PASSWORD, [ 'student_id' => $student_id ] ) ) {
            $this->fail( 'You do not have permission to reset this student.' );
        }

        $result = ( new StudentRegistrationService() )->reset_password( $school_id, $student_id );

        if ( empty( $result['success'] ) ) {
            $this->fail( 'That student could not be found.' );
        }

        $this->succeed(
            [
                'type'             => 'reset',
                'admission_number' => (string) $result['username'],
                'password'         => (string) $result['initial_password'],
            ]
        );
    }

    // ---------------------------------------------------------------

    /**
     * @return array{0:int,1:Scope}
     */
    private function context( string $nonce_action ): array {
        if ( ! is_user_logged_in() ) {
            wp_safe_redirect( wp_login_url() );
            exit;
        }

        check_admin_referer( $nonce_action );

        $school_id = absint( ( new TenantContext() )->get_school_id() ?? 0 );

        if ( $school_id <= 0 ) {
            $this->fail( 'Your account is not linked to a school.' );
        }

        return [ $school_id, new Scope() ];
    }

    /**
     * Turn a machine error code into something a school secretary can act on.
     *
     * @param array<int|string,string> $errors
     */
    private function readable( array $errors ): string {
        $map = [
            'first_name'     => 'First name is required.',
            'last_name'      => 'Surname is required.',
            'required'       => 'This field is required.',
            'too_short'      => 'That name is too short.',
            'in_future'      => 'The date of birth cannot be in the future.',
            'invalid'        => 'That value is not valid.',
            'class_full'     => 'That class has reached its capacity.',
            'class_not_found' => 'Please choose a class.',
            'student_record_could_not_be_saved' => 'The student record could not be saved — the database rejected it. Deactivate and reactivate the plugin to apply pending schema updates, then try again.',
            'no_current_session' => 'No academic session is set. Set one under Settings first.',
            'email'          => 'That email address is not valid.',
            'duplicate_class' => 'that class already exists.',
            'admission_number_taken' => 'That student ID is already in use. Choose another, or leave it blank to have one generated.',
            'class_could_not_be_saved' => 'the database rejected it — deactivate and reactivate the plugin to apply pending schema updates, then try again.',
            'invalid_arm'     => 'the arm must be a letter, such as A or B.',
            'level_not_found' => 'choose a class level.',
            'department_not_allowed_for_junior' => 'junior classes do not have departments.',
            'class_and_subject_required' => 'Choose both a class and a subject.',
            'class_required' => 'Choose a class.',
            'department_required' => 'Choose a department.',
            'subject_required'    => 'Choose a subject.',
            'series_required'     => 'Choose an examination.',
            'duration_required'   => 'Set how long the paper runs for.',
            'duration_too_short'  => 'A paper must run for at least 5 minutes.',
            'duration_too_long'   => 'A paper cannot run for more than 5 hours.',
            'invalid_schedule'    => 'Choose a valid date and time.',
            'question_count_required' => 'Say how many questions the paper should have.',
            'not_composed'        => 'the paper has no usable questions yet. Make sure questions are approved and have options with correct answers marked.',
            'no_invigilator'      => 'no invigilator is assigned.',
            'no_duration'         => 'the paper has no duration.',
            'paper_already_started' => 'this paper has already been started and cannot be published again.',
            'no_correct_answer'   => 'Mark which option is correct.',
            'question_needs_text_or_image' => 'The question needs text or an image.',
            'at_least_two_options_required' => 'Give at least two options.',
            'duplicate_option_text' => 'Two options are identical.',
            'correct_answer_marked_on_empty_option' => 'The correct answer is marked on an empty option.',
            'nothing_compiled'   => 'nothing has been compiled for this class yet.',
            'reason_required_to_reverse' => 'Give a reason for reversing an approved result.',
            'class_in_mixed_states' => 'this class is part-way through a change; compile it again first.',
            'no_results_to_move' => 'there are no results for this class yet.',
            'duplicate_paper_same_day' => 'a paper for this subject and class already exists on the same day. If this is intentional, submit again to confirm.',
            'registration_closed' => 'Subject registration is closed for this session. Speak to your class teacher if you need to change something.',
            'not_enrolled' => 'You are not enrolled in a class for the current session.',
            'subject_not_available_to_you' => 'One or more of the selected subjects is not available to your class.',
            'cannot_drop_a_subject_already_assessed' => 'You cannot drop a subject you have already been examined or marked in.',
            'no_subjects' => 'Please choose at least one subject.',
            'unknown_subject_in_selection' => 'One of the selected subjects could not be found.',
            'too_few_subjects' => 'You have chosen too few subjects.',
            'too_many_subjects' => 'You have chosen too many subjects.',
            'subject_wrong_stage' => 'One or more subjects are not available for your level.',
            'subject_outside_department' => 'One or more subjects are outside your department.',
            'too_few_departmental' => 'You need more departmental subjects.',
            'missing_compulsory' => 'A required subject is missing from your selection.',
        ];

        $out = [];

        foreach ( $errors as $field => $code ) {
            $code = (string) $code;

            // Some errors carry a detail after a colon, e.g.
            // "missing_compulsory:English Language" or "too_few_subjects:3/9".
            // Split the prefix off, look up the prefix in the map for the
            // generic message, then append the detail when it adds information.
            $detail = '';
            $prefix = $code;

            if ( strpos( $code, ':' ) !== false ) {
                [ $prefix, $detail ] = explode( ':', $code, 2 );
            }

            $key = is_string( $field ) ? $field : $prefix;

            if ( isset( $map[ $prefix ] ) ) {
                $msg = $map[ $prefix ];
            } elseif ( isset( $map[ $key ] ) ) {
                $msg = $map[ $key ];
            } else {
                $msg = ucfirst( str_replace( '_', ' ', $prefix ) );
            }

            // If the detail is a subject name (contains a space) it is more
            // useful appended to the message than shown as a raw code.
            if ( $detail !== '' ) {
                if ( $prefix === 'missing_compulsory' ) {
                    $msg = 'You must include ' . $detail . '.';
                } elseif ( $prefix === 'too_few_subjects' ) {
                    $msg = 'You have chosen too few subjects (' . $detail . '). You need at least the minimum for your level.';
                } elseif ( $prefix === 'too_many_subjects' ) {
                    $msg = 'You have chosen too many subjects (' . $detail . '). Reduce your selection to the maximum for your level.';
                } elseif ( $prefix === 'too_few_departmental' ) {
                    $msg = 'You need more subjects from your department (' . $detail . ').';
                } elseif ( strpos( $detail, ' ' ) !== false ) {
                    // Subject name or human-readable detail
                    $msg .= ' (' . $detail . ')';
                } else {
                    // Single-token detail (e.g. a code) — show as prefix — detail
                    $msg = $detail . ' — ' . $msg;
                }
            }

            $out[] = $msg;
        }

        return implode( ' ', array_unique( $out ) ) ?: 'Something went wrong.';
    }

    private function fail( string $message, string $redirect = '', array $context = [] ): void {
        set_transient( 'educbt_portal_error_' . get_current_user_id(), $message, 60 );
        if ( ! empty( $context ) ) {
            set_transient( 'educbt_portal_error_ctx_' . get_current_user_id(), $context, 60 );
        }
        $this->back( $redirect );
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function succeed( array $payload, string $redirect = '' ): void {
        // Credentials go in a transient, never the URL: a query string ends up in
        // browser history and in the server access log.
        set_transient( 'educbt_portal_result_' . get_current_user_id(), $payload, 300 );
        $this->back( $redirect );
    }

    /**
     * Return to the form the user submitted.
     *
     * wp_get_referer() alone is not enough: it returns false when the browser sends
     * no Referer header, and the user then lands on the dashboard wondering why
     * nothing happened. The hidden _wp_http_referer that wp_nonce_field() writes is
     * checked first because it travels with the POST and cannot be stripped by a
     * privacy setting or a proxy.
     */
    private function back( string $override = '' ): void {
        $target = $override;

        if ( ! empty( $_POST['_wp_http_referer'] ) ) {
            $target = (string) wp_unslash( $_POST['_wp_http_referer'] );
        }

        if ( $target === '' ) {
            $target = (string) ( wp_get_referer() ?: '' );
        }

        // Only ever return inside the portal; a referer is user-supplied.
        if ( $target === '' || strpos( wp_parse_url( $target, PHP_URL_PATH ) ?? '', '/portal/' ) === false ) {
            $target = \EduCBTPro\Core\AdminLockdown::portal_url();
        }

        wp_safe_redirect( $target );
        exit;
    }

    /**
     * Read and clear the last result for this user.
     *
     * @return array{result:array<string,mixed>|null,error:string}
     */
    public static function flash(): array {
        $user_id = get_current_user_id();

        $result = get_transient( 'educbt_portal_result_' . $user_id );
        $error  = get_transient( 'educbt_portal_error_' . $user_id );

        delete_transient( 'educbt_portal_result_' . $user_id );
        delete_transient( 'educbt_portal_error_' . $user_id );

        $ctx = get_transient( 'educbt_portal_error_ctx_' . $user_id );
        delete_transient( 'educbt_portal_error_ctx_' . $user_id );

        return [
            'result' => is_array( $result ) ? $result : null,
            'error'  => is_string( $error ) ? $error : '',
            'context' => is_array( $ctx ) ? $ctx : [],
        ];
    }

    /**
     * Delete an exam paper (soft delete — sets status to cancelled).
     * Only school management (MANAGE_PAPERS) can delete.
     */
    public function delete_paper(): void {
        [ $school_id ] = $this->context( 'educbt_delete_paper' );

        if ( ! Gate::allows( Capabilities::MANAGE_PAPERS ) ) {
            $this->fail( 'You do not have permission to delete papers.' );
        }

        $paper_id = absint( $_POST['paper_id'] ?? 0 );

        if ( $paper_id <= 0 ) {
            $this->fail( 'Invalid paper.' );
        }

        global $wpdb;
        $papers_table   = \EduCBTPro\Core\Schema::table( 'exam_papers' );
        $attempts_table = \EduCBTPro\Core\Schema::table( 'attempts' );

        $has_attempts = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$attempts_table} WHERE paper_id = %d AND school_id = %d",
                $paper_id,
                $school_id
            )
        );

        if ( $has_attempts > 0 ) {
            $this->fail( sprintf( 'Cannot delete: %d student(s) have already started or submitted this exam.', $has_attempts ) );
        }

        $wpdb->update(
            $papers_table,
            [ 'status' => 'cancelled' ],
            [ 'id' => $paper_id, 'school_id' => $school_id ],
            [ '%s' ],
            [ '%d', '%d' ]
        );

        $this->succeed( [ 'type' => 'deleted' ] );
    }

    /**
     * Delete an examination series and all its papers (if no attempts exist).
     */
    public function delete_examination(): void {
        [ $school_id ] = $this->context( 'educbt_delete_examination' );

        if ( ! Gate::allows( Capabilities::MANAGE_PAPERS ) ) {
            $this->fail( 'You do not have permission to delete an examination.' );
        }

        $series_id = absint( $_POST['series_id'] ?? 0 );

        if ( $series_id <= 0 ) {
            $this->fail( 'Invalid examination.' );
        }

        global $wpdb;
        $series_table   = \EduCBTPro\Core\Schema::table( 'exam_series' );
        $papers_table   = \EduCBTPro\Core\Schema::table( 'exam_papers' );
        $attempts_table = \EduCBTPro\Core\Schema::table( 'attempts' );
        $pq_table       = \EduCBTPro\Core\Schema::table( 'paper_questions' );
        $inv_table      = \EduCBTPro\Core\Schema::table( 'paper_invigilators' );

        // Check no student has attempted any paper in this series
        $has_attempts = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$attempts_table} a
                 INNER JOIN {$papers_table} p ON p.id = a.paper_id
                 WHERE p.series_id = %d AND p.school_id = %d",
                $series_id, $school_id
            )
        );

        if ( $has_attempts > 0 ) {
            $this->fail( sprintf( 'Cannot delete: %d student(s) have already taken exams in this examination.', $has_attempts ) );
        }

        // Get all paper IDs for this series
        $paper_ids = (array) $wpdb->get_col(
            $wpdb->prepare(
                "SELECT id FROM {$papers_table} WHERE series_id = %d AND school_id = %d",
                $series_id, $school_id
            )
        );

        // Delete paper questions and invigilators
        foreach ( $paper_ids as $pid ) {
            $wpdb->delete( $pq_table, [ 'paper_id' => (int) $pid ], [ '%d' ] );
            $wpdb->delete( $inv_table, [ 'paper_id' => (int) $pid ], [ '%d' ] );
        }

        // Cancel all papers
        $wpdb->update(
            $papers_table,
            [ 'status' => 'cancelled' ],
            [ 'series_id' => $series_id, 'school_id' => $school_id ],
            [ '%s' ],
            [ '%d', '%d' ]
        );

        // Delete the series
        $wpdb->delete( $series_table, [ 'id' => $series_id, 'school_id' => $school_id ], [ '%d', '%d' ] );

        $this->succeed( [ 'type' => 'examination_deleted' ] );
    }

    /**
     * Reschedule an exam paper to a new date/time.
     * Only school management (MANAGE_PAPERS) can reschedule.
     */
    public function reschedule_paper(): void {
        [ $school_id ] = $this->context( 'educbt_reschedule_paper' );

        if ( ! Gate::allows( Capabilities::MANAGE_PAPERS ) ) {
            $this->fail( 'You do not have permission to reschedule papers.' );
        }

        $paper_id          = absint( $_POST['paper_id'] ?? 0 );
        $new_scheduled_raw = sanitize_text_field( (string) ( $_POST['new_scheduled_at'] ?? '' ) );

        if ( $paper_id <= 0 || '' === $new_scheduled_raw ) {
            $this->fail( 'Please choose a valid new date and time.' );
        }

        // Convert datetime-local (YYYY-MM-DDTHH:MM) to MySQL datetime.
        $new_scheduled_at = str_replace( 'T', ' ', $new_scheduled_raw ) . ':00';
        $new_scheduled_at = get_gmt_from_date( $new_scheduled_at );

        if ( ! $new_scheduled_at || strtotime( $new_scheduled_at ) < 0 ) {
            $this->fail( 'That date and time is not valid.' );
        }

        global $wpdb;
        $papers_table = \EduCBTPro\Core\Schema::table( 'exam_papers' );

        $class_id   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT class_id FROM {$papers_table} WHERE id = %d", $paper_id ) );
        $duration_s = (int) $wpdb->get_var( $wpdb->prepare( "SELECT duration_seconds FROM {$papers_table} WHERE id = %d", $paper_id ) );

        // A generated schedule is a starting point. Duration and invigilator are
        // adjusted from the same place as the time, because in practice they are
        // decided together and a separate screen for each is a separate errand.
        $new_duration_minutes = absint( $_POST['duration_minutes'] ?? 0 );

        if ( $new_duration_minutes > 0 ) {
            $duration_s = min( 300, max( 5, $new_duration_minutes ) ) * 60;
        }

        $clash = ( new \EduCBTPro\Services\ExamPaperService() )->find_clash(
            $school_id,
            $class_id,
            $new_scheduled_at,
            $duration_s,
            $paper_id
        );

        if ( $clash ) {
            $this->fail( sprintf(
                'Cannot reschedule: clashes with %s for %s at %s.',
                $clash['subject_name'] ?? 'another exam',
                $clash['class_name'] ?? 'a class',
                wp_date( 'g:ia', strtotime( (string) ($clash['scheduled_at'] ?? 'now') . ' UTC' ) )
            ) );
        }

        // Optional closes_at — when the exam window hard-closes.
        $closes_at_raw = sanitize_text_field( (string) ( $_POST['closes_at'] ?? '' ) );
        $update_fields = [
            'scheduled_at'     => $new_scheduled_at,
            'duration_seconds' => $duration_s,
        ];
        $update_format = [ '%s', '%d' ];

        if ( $closes_at_raw !== '' ) {
            $closes_at = str_replace( 'T', ' ', $closes_at_raw ) . ':00';
            $closes_at = get_gmt_from_date( $closes_at );
            if ( $closes_at && strtotime( $closes_at ) > 0 ) {
                $update_fields['closes_at'] = $closes_at;
                $update_format[] = '%s';
            }
        } else {
            // Clear closes_at if the field was left blank.
            $update_fields['closes_at'] = null;
            $update_format[] = '%s';
        }

        $wpdb->update(
            $papers_table,
            $update_fields,
            [ 'id' => $paper_id, 'school_id' => $school_id ],
            $update_format,
            [ '%d', '%d' ]
        );

        // Invigilator: one per paper here. Sending 0 clears it.
        $invigilator_id  = absint( $_POST['invigilator_id'] ?? 0 );
        $invigilators    = \EduCBTPro\Core\Schema::table( 'paper_invigilators' );

        if ( isset( $_POST['invigilator_id'] ) ) {
            // A hand-picked invigilator is held to the same two rules the generator
            // follows, because the reasons do not stop applying when a human clicks.
            if ( $invigilator_id > 0 ) {
                $timetable = new \EduCBTPro\Services\ExamTimetableService();

                if ( $timetable->teaches_paper_subject( $school_id, $invigilator_id, $paper_id ) ) {
                    $this->fail( 'That member of staff teaches this subject to a class sitting this paper, so they cannot invigilate it.' );
                }

                $clash = $timetable->invigilator_conflict( $school_id, $invigilator_id, $new_scheduled_at, $paper_id );

                if ( $clash ) {
                    $this->fail(
                        sprintf(
                            'They are already invigilating %s at %s. One person, one hall.',
                            (string) ( $clash['subject_name'] ?? 'another paper' ),
                            wp_date( 'D j M, g:ia', strtotime( (string) ($clash['scheduled_at'] ?? '') . ' UTC' ) )
                        )
                    );
                }
            }

            $wpdb->delete( $invigilators, [ 'paper_id' => $paper_id, 'school_id' => $school_id ], [ '%d', '%d' ] );

            if ( $invigilator_id > 0 ) {
                $wpdb->insert(
                    $invigilators,
                    [
                        'school_id' => $school_id,
                        'paper_id'  => $paper_id,
                        'staff_id'      => $invigilator_id,
                        'assigned_mode' => 'manual',
                    ],
                    [ '%d', '%d', '%d', '%s' ]
                );
            }
        }

        $this->succeed( [ 'type' => 'rescheduled' ] );
    }

    /**
     * Toggle exam prep (question submission) on or off.
     * Only school management can toggle this.
     */
    public function toggle_exam_prep(): void {
        [ $school_id ] = $this->context( 'educbt_toggle_exam_prep' );

        if ( ! Gate::allows( Capabilities::MANAGE_PAPERS ) ) {
            $this->fail( 'You do not have permission to toggle exam prep.' );
        }

        $svc     = new \EduCBTPro\Services\SchoolService();
        $enabled = ! $svc->is_exam_prep_enabled( $school_id );
        $svc->set_exam_prep_enabled( $school_id, $enabled );

        // Notify teaching staff that exam prep is now open/closed.
        $notif_svc = new \EduCBTPro\Services\NotificationService();
        $staff_table = \EduCBTPro\Core\Schema::table( 'staff' );
        global $wpdb;
        $teachers = (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT wp_user_id FROM {$staff_table} WHERE school_id = %d AND wp_user_id IS NOT NULL AND status = 'active'",
                $school_id
            ),
            ARRAY_A
        );
        $title = $enabled ? 'Exam prep is now open' : 'Exam prep is now closed';
        $body  = $enabled
            ? 'You can now submit questions for the upcoming exams. Visit the Question Bank to get started.'
            : 'Question submission is now closed. Contact the exam officer if you need changes.';
        $notif_type = $enabled
            ? \EduCBTPro\Services\NotificationService::EXAM_PREP_OPENED
            : \EduCBTPro\Services\NotificationService::ANNOUNCEMENT;
        foreach ( $teachers as $teacher ) {
            $uid = absint( $teacher['wp_user_id'] ?? 0 );
            if ( $uid > 0 ) {
                $notif_svc->notify( $school_id, $uid, $notif_type, $title, $body, '' );
            }
        }

        $this->succeed( [ 'type' => $enabled ? 'exam_prep_opened' : 'exam_prep_closed' ] );
    }

    /**
     * A class teacher updating a student's basic profile — name, parent contact,
     * address, medical notes. Scoped so a teacher can only touch students in a
     * class they hold.
     */
    public function update_student_profile(): void {
        [ $school_id, $scope ] = $this->context( 'educbt_update_student_profile' );

        $student_id = absint( $_POST['student_id'] ?? 0 );

        if ( $student_id <= 0 ) {
            $this->fail( 'No student selected.' );
        }

        global $wpdb;

        // Security: verify this student is in a class the teacher holds.
        if ( ! $scope->is_school_wide() ) {
            $assign_table = \EduCBTPro\Core\Schema::table( 'staff_assignments' );
            $enrollments   = \EduCBTPro\Core\Schema::table( 'enrollments' );
            $staff_id      = (int) $scope->actor()['id'];

            $in_my_class = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$enrollments} e
                     INNER JOIN {$assign_table} a ON a.class_id = e.class_id
                       AND a.staff_id = %d AND a.assignment_type = 'class_teacher' AND a.status = 'active'
                     WHERE e.student_id = %d AND e.status = 'active'",
                    $staff_id, $student_id
                )
            );

            if ( $in_my_class === 0 ) {
                $this->fail( 'You can only update students in a class you are the class teacher of.' );
            }
        }

        $stu_table = $wpdb->prefix . 'educbt_students';

        $updated = $wpdb->update(
            $stu_table,
            [
                'first_name'     => sanitize_text_field( (string) wp_unslash( $_POST['first_name'] ?? '' ) ),
                'last_name'       => sanitize_text_field( (string) wp_unslash( $_POST['last_name'] ?? '' ) ),
                'full_name'       => trim(
                    sanitize_text_field( (string) wp_unslash( $_POST['first_name'] ?? '' ) ) . ' ' .
                    sanitize_text_field( (string) wp_unslash( $_POST['last_name'] ?? '' ) )
                ),
                'parent_information' => sanitize_text_field( (string) wp_unslash( $_POST['parent_information'] ?? '' ) ),
                'parent_phone'   => sanitize_text_field( (string) wp_unslash( $_POST['parent_phone'] ?? '' ) ),
                'parent_email'   => sanitize_email( (string) wp_unslash( $_POST['parent_email'] ?? '' ) ),
                'address'        => sanitize_textarea_field( (string) wp_unslash( $_POST['address'] ?? '' ) ),
            ],
            [ 'id' => $student_id, 'school_id' => $school_id ],
            [ '%s', '%s', '%s', '%s', '%s', '%s', '%s' ],
            [ '%d', '%d' ]
        );

        $redirect = esc_url_raw( (string) wp_unslash( $_POST['redirect_to'] ?? '' ) );
        if ( empty( $redirect ) ) {
            $redirect = home_url( '/portal/teacher/students/' );
        }

        if ( $updated === false ) {
            $this->fail( 'Could not update the student record.', $redirect );
        }

        $first = sanitize_text_field( (string) wp_unslash( $_POST['first_name'] ?? '' ) );
        $last  = sanitize_text_field( (string) wp_unslash( $_POST['last_name'] ?? '' ) );
        $this->succeed( [ 'type' => 'student_updated', 'name' => trim( $first . ' ' . $last ) ], $redirect );
    }

    /**
     * A class teacher adds a student to their own class from the My Students page.
     * The student is created with a 'pending_approval' enrolment status so the
     * school office / principal must approve before the student is official.
     */
    public function teacher_add_student(): void {
        [ $school_id, $scope ] = $this->context( 'educbt_teacher_add_student' );

        $class_id = absint( $_POST['class_id'] ?? 0 );

        // Verify this teacher is the class teacher of this class.
        if ( ! $scope->is_school_wide() ) {
            $actor    = $scope->actor();
            $staff_id = (int) $actor['id'];

            global $wpdb;
            $assign_table = \EduCBTPro\Core\Schema::table( 'staff_assignments' );

            $holds = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$assign_table}
                     WHERE school_id = %d AND staff_id = %d AND class_id = %d
                       AND assignment_type = 'class_teacher' AND status = 'active'",
                    $school_id, $staff_id, $class_id
                )
            );

            if ( $holds === 0 ) {
                $this->fail( 'You can only add students to a class you are the class teacher of.' );
            }
        }

        $first_name = sanitize_text_field( (string) wp_unslash( $_POST['first_name'] ?? '' ) );
        $last_name  = sanitize_text_field( (string) wp_unslash( $_POST['last_name'] ?? '' ) );

        if ( $first_name === '' || $last_name === '' ) {
            $this->fail( 'First name and last name are required.' );
        }

        $full_name = trim( $first_name . ' ' . $last_name );

        // Handle passport photo upload from device
        $photo_url = '';
        if ( ! empty( $_FILES['photo']['name'] ) && isset( $_FILES['photo']['error'] ) && $_FILES['photo']['error'] === UPLOAD_ERR_OK ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';

            $check_image = @getimagesize( $_FILES['photo']['tmp_name'] );
            if ( false !== $check_image ) {
                $movefile = wp_handle_upload( $_FILES['photo'], [ 'test_form' => false ] );
                if ( $movefile && ! isset( $movefile['error'] ) ) {
                    $photo_url = (string) $movefile['url'];
                }
            }
        }

        // Generate an admission number.
        $stu_table = $wpdb->prefix . 'educbt_students';
        $existing_count = (int) $wpdb->get_var(
            $wpdb->prepare( "SELECT COUNT(*) FROM {$stu_table} WHERE school_id = %d", $school_id )
        );
        $admission_number = 'EDU' . str_pad( (string) ( $existing_count + 1 ), 5, '0', STR_PAD_LEFT );
        $registration_number = $admission_number . '-' . $school_id;

        // Insert the student record.
        $wpdb->insert(
            $stu_table,
            [
                'school_id'            => $school_id,
                'admission_number'     => $admission_number,
                'registration_number'  => $registration_number,
                'student_id'           => $admission_number,
                'first_name'           => $first_name,
                'last_name'            => $last_name,
                'full_name'            => $full_name,
                'gender'               => sanitize_text_field( (string) wp_unslash( $_POST['gender'] ?? '' ) ),
                'date_of_birth'        => sanitize_text_field( (string) wp_unslash( $_POST['date_of_birth'] ?? '' ) ) ?: null,
                'parent_phone'         => sanitize_text_field( (string) wp_unslash( $_POST['parent_phone'] ?? '' ) ),
                'parent_email'         => sanitize_email( (string) wp_unslash( $_POST['parent_email'] ?? '' ) ),
                'passport_photo'       => $photo_url,
                'status'               => 'pending_approval',
            ],
            [ '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
        );

        $student_id = (int) $wpdb->insert_id;

        if ( $student_id === 0 ) {
            $this->fail( 'Could not create the student record.' );
        }

        // Create a pending enrolment — not active, so it won't appear in exams etc.
        $enrollments = \EduCBTPro\Core\Schema::table( 'enrollments' );
        $year        = new \EduCBTPro\Services\AcademicYearService();
        $session     = $year->current_session( $school_id );
        $session_id  = (int) ( $session['id'] ?? 0 );

        $wpdb->insert(
            $enrollments,
            [
                'school_id'  => $school_id,
                'student_id' => $student_id,
                'class_id'   => $class_id,
                'session_id' => $session_id,
                'status'     => 'pending_approval',
                'enrolled_at'=> current_time( 'mysql' ),
            ],
            [ '%d', '%d', '%d', '%d', '%s', '%s' ]
        );

        $redirect = esc_url_raw( (string) wp_unslash( $_POST['redirect_to'] ?? '' ) );
        if ( empty( $redirect ) ) {
            $redirect = home_url( '/portal/teacher/students/' );
        }

        $this->succeed(
            [ 'type' => 'student_added_pending', 'message' => 'Student added as pending approval. The school office must approve the enrolment.' ],
            $redirect
        );
    }

    /**
     * Duplicate an approved question as a new draft so the teacher can
     * create a variant without retyping everything.
     */
    public function duplicate_question(): void {
        [ $school_id ] = $this->context( 'educbt_duplicate_question' );

        $question_id = absint( $_POST['question_id'] ?? 0 );
        if ( $question_id === 0 ) {
            $this->fail( 'Invalid question.' );
        }

        global $wpdb;
        $q_table  = $wpdb->prefix . 'educbt_questions';
        $o_table  = \EduCBTPro\Core\Schema::table( 'question_options' );

        // Fetch the source question
        $src = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$q_table} WHERE id = %d AND school_id = %d",
                $question_id,
                $school_id
            ),
            ARRAY_A
        );

        if ( ! $src ) {
            $this->fail( 'Question not found.' );
        }

        // Insert as a new draft
        $wpdb->insert(
            $q_table,
            [
                'school_id'         => $school_id,
                'subject_id'        => (int) $src['subject_id'],
                'class_level'       => $src['class_level'],
                'question_text'     => $src['question_text'],
                'question_type'     => $src['question_type'],
                'marks'             => $src['marks'],
                'approval_status'   => 'pending',
                'created_by_staff'  => get_current_user_id(),
                'status'            => 'active',
                'created_at'        => current_time( 'mysql', true ),
            ],
            [ '%d', '%d', '%s', '%s', '%s', '%f', '%s', '%d', '%s', '%s' ]
        );

        $new_id = (int) $wpdb->insert_id;

        // Copy options if objective question
        $options = (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT option_key, option_text, is_correct, sort_order FROM {$o_table} WHERE question_id = %d ORDER BY sort_order ASC",
                $question_id
            ),
            ARRAY_A
        );

        foreach ( $options as $opt ) {
            $wpdb->insert(
                $o_table,
                [
                    'question_id' => $new_id,
                    'option_key' => $opt['option_key'],
                    'option_text' => $opt['option_text'],
                    'is_correct'  => (int) $opt['is_correct'],
                    'sort_order'  => (int) $opt['sort_order'],
                ],
                [ '%d', '%s', '%s', '%d', '%d' ]
            );
        }

        $this->succeed( [ 'type' => 'duplicated', 'new_id' => $new_id ] );
    }

    /**
     * Flag a notification as resolved. The user acknowledges it.
     */
    public function flag_notification(): void {
        [ $school_id ] = $this->context( 'educbt_flag_notification' );

        $notif_id = absint( $_POST['notification_id'] ?? 0 );
        if ( $notif_id === 0 ) {
            $this->fail( 'Invalid notification.' );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'educbt_notifications';
        $wpdb->update(
            $table,
            [ 'is_flagged' => 1 ],
            [ 'id' => $notif_id, 'school_id' => $school_id ],
            [ '%d' ],
            [ '%d', '%d' ]
        );

        $this->succeed( [ 'type' => 'flagged' ] );
    }

    /**
     * Report an issue with a notification — sends a notification to school admins.
     */
    public function report_issue(): void {
        [ $school_id ] = $this->context( 'educbt_report_issue' );

        $notif_id = absint( $_POST['notification_id'] ?? 0 );
        if ( $notif_id === 0 ) {
            $this->fail( 'Invalid notification.' );
        }

        global $wpdb;
        $notif_table = $wpdb->prefix . 'educbt_notifications';

        // Fetch the original notification
        $notif = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT title, body, user_id FROM {$notif_table} WHERE id = %d AND school_id = %d",
                $notif_id,
                $school_id
            ),
            ARRAY_A
        );

        if ( ! $notif ) {
            $this->fail( 'Notification not found.' );
        }

        // Find school admins to notify about the issue
        $admins = get_users( [ 'role__in' => [ 'educbt_school_admin', 'administrator' ], 'number' => 20 ] );

        foreach ( $admins as $admin ) {
            $wpdb->insert(
                $notif_table,
                [
                    'school_id'  => $school_id,
                    'user_id'    => $admin->ID,
                    'title'      => 'Issue reported: ' . (string) ( $notif['title'] ?? 'Notification' ),
                    'body'       => 'A staff member reported an issue with this notification: ' . (string) ( $notif['body'] ?? '' ),
                    'type'       => 'issue_report',
                    'created_at' => current_time( 'mysql', true ),
                ],
                [ '%d', '%d', '%s', '%s', '%s', '%s' ]
            );
        }

        $this->succeed( [ 'type' => 'issue_reported' ] );
    }

    /**
     * Save a staff signature (digital canvas or uploaded image).
     */
    public function save_signature(): void {
        [ $school_id ] = $this->context( 'educbt_save_signature' );

        if ( ! Gate::allows( Capabilities::ENTER_SCORES ) ) {
            $this->fail( 'You do not have permission to save signatures.' );
        }

        $role        = sanitize_key( (string) ( $_POST['signature_role'] ?? 'class_teacher' ) );
        $display_name = sanitize_text_field( (string) ( $_POST['display_name'] ?? '' ) );
        $staff_id    = absint( $_POST['staff_id'] ?? get_current_user_id() );
        $sig_type    = sanitize_key( (string) ( $_POST['signature_type'] ?? 'digital' ) );

        if ( ! in_array( $role, [ 'class_teacher', 'principal', 'exam_officer' ], true ) ) {
            $this->fail( 'Invalid signature role.' );
        }

        if ( $display_name === '' ) {
            $this->fail( 'Display name is required.' );
        }

        $sig_data = '';

        if ( $sig_type === 'text' ) {
            // Text signature — typed name rendered in a script font on report sheets
            $typed = sanitize_text_field( (string) wp_unslash( $_POST['signature_data'] ?? $_POST['signature_text'] ?? '' ) );
            if ( $typed !== '' ) {
                $sig_data = $typed;
            }
        } elseif ( $sig_type === 'digital' ) {
            // Canvas data URL
            $data_url = (string) wp_unslash( $_POST['signature_data'] ?? '' );
            if ( strpos( $data_url, 'data:image' ) === 0 ) {
                $sig_data = esc_url_raw( $data_url );
            }
        } else {
            // Uploaded file
            if ( ! empty( $_FILES['signature_file']['tmp_name'] ) ) {
                $file = $_FILES['signature_file'];
                $allowed = [ 'image/png', 'image/jpeg', 'image/gif', 'image/svg+xml' ];
                $ftype = wp_check_filetype( $file['name'] );
                if ( ! in_array( $file['type'], $allowed, true ) && ! in_array( $ftype['type'] ?? '', $allowed, true ) ) {
                    $this->fail( 'Only PNG, JPEG, GIF, or SVG images are allowed.' );
                }
                if ( $file['size'] > 2 * MB_IN_BYTES ) {
                    $this->fail( 'Signature image must be under 2 MB.' );
                }
                $upload = wp_handle_upload( $file, [ 'test_form' => false ] );
                if ( ! empty( $upload['error'] ) ) {
                    $this->fail( 'Upload failed: ' . $upload['error'] );
                }
                $sig_data = esc_url_raw( $upload['url'] );
            }
        }

        if ( $sig_data === '' ) {
            $this->fail( 'No signature data received. Please draw or upload a signature.' );
        }

        $service = new \EduCBTPro\Services\SignatureService();
        $result = $service->save( $school_id, $staff_id, $role, $display_name, $sig_data, $sig_type );

        if ( ! $result ) {
            $this->fail( 'Could not save signature.' );
        }

        $this->succeed( [ 'type' => 'saved' ] );
    }

    /**
     * Save remark ranges for auto-remarks.
     */
    public function save_remarks(): void {
        [ $school_id ] = $this->context( 'educbt_save_remarks' );

        // Both principals (MANAGE_SCHOOL) and class teachers (REMARK_RESULTS) can
        // manage remark ranges — principals set the school-wide defaults, class
        // teachers set their own ranges.
        if ( ! Gate::allows( Capabilities::MANAGE_SCHOOL ) && ! Gate::allows( Capabilities::REMARK_RESULTS ) ) {
            $this->fail( 'You do not have permission to manage remark ranges.' );
        }

        $roles     = (array) ( $_POST['roles'] ?? [] );
        $min_avgs  = (array) ( $_POST['min_avgs'] ?? [] );
        $max_avgs  = (array) ( $_POST['max_avgs'] ?? [] );
        $remarks   = (array) ( $_POST['remarks'] ?? [] );

        $service = new \EduCBTPro\Services\RemarkService();

        $saved = 0;
        for ( $i = 0; $i < count( $roles ); $i++ ) {
            if ( empty( $remarks[ $i ] ) ) { continue; }

            $role = sanitize_key( (string) $roles[ $i ] );
            if ( ! in_array( $role, [ 'class_teacher', 'principal' ], true ) ) { continue; }

            $min = (float) $min_avgs[ $i ];
            $max = (float) $max_avgs[ $i ];
            $remark = sanitize_textarea_field( (string) $remarks[ $i ] );

            if ( $min < 0 || $max > 100 || $min >= $max || $remark === '' ) { continue; }

            $service->save_range( $school_id, $role, $min, $max, $remark );
            $saved++;
        }

        $this->succeed( [ 'type' => 'saved', 'count' => $saved ] );
    }

    /**
     * Delete a remark range.
     */
    public function delete_remark_range(): void {
        [ $school_id ] = $this->context( 'educbt_delete_remark_range' );

        if ( ! Gate::allows( Capabilities::MANAGE_SCHOOL ) && ! Gate::allows( Capabilities::REMARK_RESULTS ) ) {
            $this->fail( 'You do not have permission to delete remark ranges.' );
        }

        $range_id = absint( $_POST['range_id'] ?? 0 );
        if ( $range_id <= 0 ) {
            $this->fail( 'Invalid remark range.' );
        }

        $service = new \EduCBTPro\Services\RemarkService();
        $service->delete_range( $school_id, $range_id );

        $this->succeed( [ 'type' => 'deleted' ] );
    }

    /**
     * Save an individual student's remark (class teacher override).
     */
    public function save_student_remark(): void {
        [ $school_id ] = $this->context( 'educbt_save_student_remark' );

        if ( ! Gate::allows( Capabilities::REMARK_RESULTS ) ) {
            $this->fail( 'You do not have permission to edit student remarks.' );
        }

        $student_id = absint( $_POST['student_id'] ?? 0 );
        $term_id    = absint( $_POST['term_id'] ?? 0 );
        $role       = sanitize_key( (string) ( $_POST['role'] ?? 'class_teacher' ) );
        $remark     = sanitize_textarea_field( (string) ( $_POST['remark'] ?? '' ) );

        if ( $student_id <= 0 || $term_id <= 0 ) {
            $this->fail( 'Invalid student or term.' );
        }

        if ( ! in_array( $role, [ 'class_teacher', 'principal' ], true ) ) {
            $this->fail( 'Invalid remark role.' );
        }

        // Scope check — the teacher must be the class teacher of this student's class
        if ( ! Gate::allows( Capabilities::REMARK_RESULTS, [ 'student_id' => $student_id ] ) ) {
            $this->fail( 'You can only edit remarks for students in your own class.' );
        }

        $service = new \EduCBTPro\Services\RemarkService();
        $result  = $service->save_student_remark( $school_id, $student_id, $term_id, $role, $remark );

        if ( ! $result ) {
            $this->fail( 'Could not save remark.' );
        }

        $this->succeed( [ 'type' => 'saved' ] );
    }
    
    /**
     * Move a single student to a different class (individual promotion/demotion).
     */
    public function move_student(): void {
        [ $school_id ] = $this->context( 'educbt_move_student' );

        $student_id  = absint( $_POST['student_id'] ?? 0 );
        $to_class_id = absint( $_POST['to_class_id'] ?? 0 );
        $outcome     = sanitize_text_field( (string) ( $_POST['outcome'] ?? '' ) );
        $reason      = sanitize_text_field( (string) ( $_POST['reason'] ?? '' ) );

        $result = ( new \EduCBTPro\Services\PromotionService() )->move_student(
            $school_id, $student_id, $to_class_id, $outcome, $reason, get_current_user_id()
        );

        if ( $result['success'] ) {
            $this->succeed( [ 'type' => 'student_moved', 'message' => 'Student moved successfully.' ] );
        } else {
            $this->fail( 'Could not move student: ' . (string) ( $result['error'] ?? 'unknown' ) );
        }
    }

    /**
     * Start a new academic session or term from the dashboard button.
     */
    public function start_new_term(): void {
        [ $school_id ] = $this->context( 'educbt_start_new_term' );

        $year       = new \EduCBTPro\Services\AcademicYearService();
        $session_id = absint( $_POST['session_id'] ?? 0 );
        $term_id    = absint( $_POST['term_id'] ?? 0 );

        if ( $session_id > 0 ) {
            $year->set_current_session( $school_id, $session_id );
        }

        if ( $term_id > 0 ) {
            $year->set_current_term( $school_id, $term_id );
        }

        if ( $session_id > 0 && $term_id > 0 ) {
            $this->succeed( [ 'type' => 'session_term_updated', 'message' => 'Session and term updated successfully. All staff dashboards now reflect the change.' ] );
        } elseif ( $session_id > 0 ) {
            $this->succeed( [ 'type' => 'session_updated', 'message' => 'New academic session started. All staff dashboards now reflect the new session.' ] );
        } elseif ( $term_id > 0 ) {
            $this->succeed( [ 'type' => 'term_updated', 'message' => 'Current term updated. All staff dashboards now reflect the new term.' ] );
        } else {
            $this->fail( 'Please select a valid session or term.' );
        }
    }

}

