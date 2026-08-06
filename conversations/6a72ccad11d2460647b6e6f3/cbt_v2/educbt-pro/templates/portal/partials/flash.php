<?php
/**
 * Result / error banner shared by every portal form.
 *
 * Generated credentials are shown ONCE here and nowhere else — they are never
 * stored in readable form, so this banner is the only chance to write them down.
 *
 * @var array{result:array<string,mixed>|null,error:string} $flash
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( $flash['error'] !== '' ) : ?>
    <p class="educbt-note educbt-note--warn"><?php echo esc_html( $flash['error'] ); ?></p>
<?php endif;

$result = $flash['result'];

if ( ! is_array( $result ) ) {
    return;
}

$type = (string) ( $result['type'] ?? '' );
?>
<div class="educbt-card educbt-card--live">
    <?php if ( $type === 'student' ) : ?>
        <h2>Student registered</h2>
        <p><strong><?php echo esc_html( (string) $result['name'] ); ?></strong> has been enrolled.</p>
        <table class="educbt-table">
            <tr><td>Admission number (username)</td><td><code><?php echo esc_html( (string) $result['admission_number'] ); ?></code></td></tr>
            <tr><td>First password</td><td><code><?php echo esc_html( (string) $result['password'] ); ?></code></td></tr>
        </table>
        <p class="educbt-muted">The student must choose their own password the first time they sign in.</p>

        <?php if ( ! empty( $result['invite_token'] ) ) : ?>
            <div class="educbt-note" style="margin-top:14px;padding:14px">
                <p style="font-weight:600;margin-bottom:8px">Parent invitation link</p>
                <p class="educbt-muted" style="margin-bottom:10px">
                    Send this link to the parent. When they open it, they will set their own
                    password — you do not need to create one for them.
                </p>
                <p><code style="word-break:break-all;font-size:13px"><?php echo esc_html( home_url( '/portal/guardian/accept/?token=' . rawurlencode( (string) $result['invite_token'] ) ) ); ?></code></p>
            </div>
        <?php endif; ?>
    <?php elseif ( $type === 'invigilation_built' ) : ?>
        <h2><?php echo esc_html( sprintf( '%d paper(s) assigned', (int) $result['assigned'] ) ); ?></h2>
        <?php if ( ! empty( $result['unfilled'] ) ) : ?>
            <p class="educbt-note educbt-note--warn">
                Nobody could be found for these — every eligible member of staff either
                teaches the subject or is already in another hall:
            </p>
            <?php foreach ( (array) $result['unfilled'] as $item ) : ?>
                <p class="educbt-muted"><?php echo esc_html( (string) $item ); ?></p>
            <?php endforeach; ?>
        <?php else : ?>
            <p class="educbt-muted">Review it below and change anyone you need to.</p>
        <?php endif; ?>

    <?php elseif ( $type === 'invigilator_changed' ) : ?>
        <h2>Invigilator changed</h2>

    <?php elseif ( $type === 'data_repaired' ) : ?>
        <h2>Records repaired</h2>
        <p><?php echo esc_html( (string) $result['message'] ); ?></p>

    <?php elseif ( $type === 'notice_response' ) : ?>
        <h2><?php echo esc_html( (string) $result['label'] ); ?></h2>
        <p class="educbt-muted">The exam office has been told.</p>


    <?php elseif ( $type === 'staff' ) : ?>
        <h2>Staff member added</h2>
        <p><strong><?php echo esc_html( (string) $result['name'] ); ?></strong> has been added.</p>
        <table class="educbt-table">
            <tr><td>Staff number</td><td><code><?php echo esc_html( (string) $result['staff_number'] ); ?></code></td></tr>
            <tr><td>Username</td><td><code><?php echo esc_html( (string) $result['username'] ); ?></code></td></tr>
            <tr><td>Temporary password</td><td><code><?php echo esc_html( (string) $result['password'] ); ?></code></td></tr>
        </table>
        <p class="educbt-muted">Shown once. They will be asked to change it at first sign-in.</p>

    <?php elseif ( $type === 'reset' ) : ?>
        <h2>Password reset</h2>
        <table class="educbt-table">
            <tr><td>Username</td><td><code><?php echo esc_html( (string) $result['admission_number'] ); ?></code></td></tr>
            <tr><td>New password</td><td><code><?php echo esc_html( (string) $result['password'] ); ?></code></td></tr>
        </table>

    <?php elseif ( $type === 'guardian' ) : ?>
        <h2>Guardian linked</h2>
        <?php if ( ! empty( $result['invite_token'] ) ) : ?>
            <p>Send this invitation link to the guardian:</p>
            <p><code style="word-break:break-all"><?php echo esc_html( home_url( '/portal/guardian/accept/?token=' . rawurlencode( (string) $result['invite_token'] ) ) ); ?></code></p>
        <?php else : ?>
            <p>This guardian already had an account and has been linked to the student.</p>
        <?php endif; ?>

    <?php elseif ( $type === 'subject' ) : ?>
        <h2>Subject added</h2>
        <p><?php echo esc_html( $result['name'] . ' (' . $result['code'] . ')' ); ?></p>

    <?php elseif ( $type === 'question' ) : ?>
        <h2>Question saved</h2>
        <?php foreach ( (array) ( $result['warnings'] ?? [] ) as $w ) : ?>
            <p class="educbt-muted"><?php echo esc_html( str_replace( '_', ' ', (string) $w ) ); ?></p>
        <?php endforeach; ?>

    <?php elseif ( $type === 'import' ) : ?>
        <h2>Import complete</h2>
        <p><strong><?php echo esc_html( (string) (int) $result['imported'] ); ?></strong> question(s) ready.
        <?php if ( (int) ( $result['failed'] ?? 0 ) > 0 ) : ?>
            <?php echo esc_html( (string) (int) $result['failed'] ); ?> need attention.
        <?php endif; ?></p>
        <?php foreach ( (array) ( $result['errors'] ?? [] ) as $e ) : ?>
            <p class="educbt-muted">Line <?php echo esc_html( (string) ( $e['line'] ?? '?' ) ); ?>: <?php echo esc_html( implode( ', ', (array) ( $e['errors'] ?? [] ) ) ); ?></p>
        <?php endforeach; ?>

    <?php elseif ( $type === 'paper' ) : ?>
        <h2>Paper scheduled</h2>
        <?php if ( ! empty( $result['compose_error'] ) ) : ?>
            <p class="educbt-note educbt-note--warn">
                Could not fill the paper: <?php echo esc_html( str_replace( '_', ' ', (string) $result['compose_error'] ) ); ?>.
                Add more questions to the bank, then reschedule.
            </p>
        <?php else : ?>
            <p><?php echo esc_html( (string) (int) $result['composed'] ); ?> questions selected.</p>
        <?php endif; ?>
        <?php if ( ! empty( $result['access_code'] ) ) : ?>
            <table class="educbt-table"><tr><td>Access code</td><td><code><?php echo esc_html( (string) $result['access_code'] ); ?></code></td></tr></table>
            <p class="educbt-muted">The invigilator reads this out when the paper starts.</p>
        <?php endif; ?>
        <?php foreach ( (array) ( $result['warnings'] ?? [] ) as $w ) : ?>
            <p class="educbt-muted"><?php echo esc_html( str_replace( [ '_', ':' ], [ ' ', ': ' ], (string) $w ) ); ?></p>
        <?php endforeach; ?>

    <?php elseif ( $type === 'published' ) : ?>
        <h2>Paper published</h2>
        <p>Students in that class can now see it on their dashboard when the time comes.</p>

    <?php elseif ( $type === 'classes' ) : ?>
        <h2><?php echo esc_html( sprintf( '%d class(es) created', (int) $result['created'] ) ); ?></h2>
        <?php if ( ! empty( $result['skipped'] ) ) : ?>
            <p class="educbt-muted">Skipped: <?php echo esc_html( implode( ', ', (array) $result['skipped'] ) ); ?></p>
        <?php endif; ?>

    <?php elseif ( $type === 'scores' ) : ?>
        <h2><?php echo esc_html( sprintf( '%d score(s) saved', (int) $result['saved'] ) ); ?></h2>
        <?php if ( (int) ( $result['skipped'] ?? 0 ) > 0 ) : ?>
            <p class="educbt-muted"><?php echo esc_html( (string) (int) $result['skipped'] ); ?> could not be saved:</p>
            <?php foreach ( (array) ( $result['errors'] ?? [] ) as $e ) : ?>
                <p class="educbt-muted">Student <?php echo esc_html( (string) ( $e['student_id'] ?? '?' ) ); ?> — <?php echo esc_html( str_replace( '_', ' ', (string) ( $e['error'] ?? '' ) ) ); ?></p>
            <?php endforeach; ?>
        <?php endif; ?>

    <?php elseif ( $type === 'compiled' ) : ?>
        <h2>Results compiled</h2>
        <p><?php echo esc_html( sprintf( '%d subject(s) for %d student(s).', (int) $result['subjects'], (int) $result['students'] ) ); ?></p>
        <?php if ( (int) ( $result['gaps'] ?? 0 ) > 0 ) : ?>
            <p class="educbt-note educbt-note--warn" style="margin-top:10px">
                <?php echo esc_html( sprintf( '%d student-subject pairs are still missing marks.', (int) $result['gaps'] ) ); ?>
                Those students are compiled on what has been entered so far.
            </p>
        <?php endif; ?>

    <?php elseif ( $type === 'results_moved' ) : ?>
        <h2>Results <?php echo esc_html( (string) $result['to'] ); ?></h2>
        <p><?php echo esc_html( sprintf( '%d student record(s) updated.', (int) $result['count'] ) ); ?></p>
        <?php if ( (string) $result['to'] === 'published' ) : ?>
            <p class="educbt-muted">Students and parents can now see and print these results.</p>
        <?php endif; ?>

    <?php elseif ( $type === 'period' ) : ?>
        <h2>Period updated</h2>
        <p>The current <?php echo esc_html( implode( ' and ', (array) $result['changed'] ) ); ?> has been changed.</p>

    <?php elseif ( $type === 'session_added' ) : ?>
        <h2>Session added</h2>
        <p>Three terms were created for it.</p>

    <?php elseif ( $type === 'school_updated' ) : ?>
        <h2>School details saved</h2>

    <?php elseif ( $type === 'staff_updated' ) : ?>
        <h2>Staff member updated</h2>
        <p><?php echo esc_html( (string) $result['name'] ); ?></p>

    <?php elseif ( $type === 'staff_removed' ) : ?>
        <h2>Staff member stood down</h2>
        <?php if ( ! empty( $result['released'] ) ) : ?>
            <p class="educbt-note educbt-note--warn">
                These assignments are now vacant and need a replacement:
                <?php echo esc_html( implode( '; ', (array) $result['released'] ) ); ?>
            </p>
        <?php endif; ?>
        <p class="educbt-muted">Their record is archived, not deleted — results and questions they created stay intact.</p>

    <?php elseif ( $type === 'transcript' ) : ?>
        <h2>Transcript issued</h2>
        <p>Serial <code><?php echo esc_html( (string) $result['serial'] ); ?></code></p>
        <p><a class="educbt-btn educbt-btn--primary" href="<?php echo esc_url( home_url( '/portal/school/transcript-print/' ) ); ?>" target="_blank">Open and print it</a></p>
        <p class="educbt-muted">The link works once. Every copy issued is recorded against the student.</p>

    <?php elseif ( $type === 'promotion_proposed' ) : ?>
        <h2>Proposal ready</h2>
        <p class="educbt-muted">Nothing has moved yet. Review the exceptions, then commit.</p>
        <p><a class="educbt-btn educbt-btn--primary" href="<?php echo esc_url( add_query_arg( 'batch', (int) $result['batch_id'], home_url( '/portal/school/promotion/' ) ) ); ?>">Review the proposal</a></p>

    <?php elseif ( $type === 'promotion_overridden' ) : ?>
        <h2>Decision recorded</h2>
        <p><a class="educbt-btn" href="<?php echo esc_url( add_query_arg( 'batch', (int) $result['batch_id'], home_url( '/portal/school/promotion/' ) ) ); ?>">Back to the proposal</a></p>

    <?php elseif ( $type === 'promotion_committed' ) : ?>
        <h2>Promotion committed</h2>
        <p><?php echo esc_html( sprintf( '%d student(s) enrolled into next session, %d graduated.', (int) $result['enrolled'], (int) $result['graduated'] ) ); ?></p>

    <?php elseif ( $type === 'message_sent' ) : ?>
        <h2>Message sent</h2>

    <?php elseif ( $type === 'student_updated' ) : ?>
        <h2>Student updated</h2><p><?php echo esc_html( (string) $result['name'] ); ?></p>

    <?php elseif ( $type === 'student_withdrawn' ) : ?>
        <h2>Student withdrawn</h2>
        <p class="educbt-muted">Their record and results are kept.</p>

    <?php elseif ( $type === 'students_imported' ) : ?>
        <h2><?php echo esc_html( sprintf( '%d student(s) imported', (int) $result['imported'] ) ); ?></h2>
        <?php if ( (int) ( $result['failures'] ?? 0 ) > 0 ) : ?>
            <p class="educbt-note educbt-note--warn"><?php echo esc_html( (string) (int) $result['failures'] ); ?> row(s) failed:</p>
            <?php foreach ( (array) $result['failed'] as $f ) : ?>
                <p class="educbt-muted"><?php echo esc_html( (string) $f ); ?></p>
            <?php endforeach; ?>
        <?php endif; ?>

    <?php elseif ( $type === 'class_updated' ) : ?>
        <h2>Class updated</h2>

    <?php elseif ( $type === 'class_removed' ) : ?>
        <h2>Class removed</h2>

    <?php elseif ( $type === 'rules_saved' ) : ?>
        <h2>Promotion rules saved</h2>
        <p class="educbt-muted">They apply the next time you produce a proposal.</p>

    <?php elseif ( $type === 'theory_marked' ) : ?>
        <h2><?php echo esc_html( sprintf( '%d answer(s) marked', (int) $result['marked'] ) ); ?></h2>
        <?php if ( (int) ( $result['skipped'] ?? 0 ) > 0 ) : ?>
            <p class="educbt-muted"><?php echo esc_html( (string) (int) $result['skipped'] ); ?> left for later.</p>
        <?php endif; ?>
        <?php foreach ( (array) ( $result['errors'] ?? [] ) as $e ) : ?>
            <p class="educbt-note educbt-note--warn"><?php echo esc_html( (string) $e ); ?></p>
        <?php endforeach; ?>

    <?php elseif ( $type === 'passage_saved' ) : ?>
        <h2>Passage saved</h2>
        <p class="educbt-muted">Choose it above, then write the questions that hang off it.</p>

    <?php elseif ( $type === 'question_updated' ) : ?>
        <h2>Question updated</h2>

    <?php elseif ( $type === 'question_deleted' ) : ?>
        <h2>Question removed</h2>

    <?php elseif ( $type === 'assignments' ) : ?>
        <h2><?php echo esc_html( sprintf( '%d assignment(s) saved', (int) $result['saved'] ) ); ?></h2>
        <?php foreach ( (array) ( $result['problems'] ?? [] ) as $p ) : ?>
            <p class="educbt-muted"><?php echo esc_html( str_replace( '_', ' ', (string) $p ) ); ?></p>
        <?php endforeach; ?>

    <?php elseif ( $type === 'staff_reset' ) : ?>
        <h2>Password reset</h2>
        <p><?php echo esc_html( (string) $result['name'] ); ?></p>
        <table class="educbt-table">
            <tr><td>Username</td><td><code><?php echo esc_html( (string) $result['username'] ); ?></code></td></tr>
            <tr><td>New password</td><td><code><?php echo esc_html( (string) $result['password'] ); ?></code></td></tr>
        </table>
        <p class="educbt-muted">Shown once. They will be asked to change it at next sign-in.</p>

    <?php elseif ( $type === 'password_changed' ) : ?>
        <h2>Password changed</h2>

    <?php elseif ( $type === 'examination_created' ) : ?>
        <h2>Examination created</h2>
        <p><strong><?php echo esc_html( (string) $result['title'] ); ?></strong> is ready. Teachers can now submit questions against it.</p>
        <p class="educbt-muted">Build the timetable once their questions have been approved.</p>

    <?php elseif ( $type === 'timetable_generated' ) : ?>
        <h2>Timetable generated</h2>
        <p><?php echo esc_html( sprintf( '%d paper(s) scheduled', (int) $result['created'] ) ); ?><?php
            if ( (int) $result['skipped'] > 0 ) {
                echo esc_html( sprintf( ', %d already scheduled and left as they were', (int) $result['skipped'] ) );
            }
        ?>.</p>
        <p class="educbt-muted">Adjust any of them below, then notify class teachers.</p>

    <?php elseif ( $type === 'timetable_sent' ) : ?>
        <h2><?php echo esc_html( sprintf( 'Timetable sent to %d class teacher(s)', (int) $result['sent'] ) ); ?></h2>
        <p class="educbt-muted">Each received only their own class's schedule.</p>

    <?php elseif ( $type === 'questions_reviewed' ) : ?>
        <h2><?php echo esc_html( (string) $result['decision'] === 'approved' ? 'Questions approved' : 'Questions sent back for revision' ); ?></h2>
        <p>
            <?php echo esc_html( sprintf( '%d question(s) updated', (int) $result['changed'] ) ); ?><?php
            if ( (int) ( $result['sets'] ?? 0 ) > 0 ) {
                echo esc_html( sprintf( ' across %d question set(s)', (int) $result['sets'] ) );
            }
            ?>. The teacher has been notified.
        </p>
        <p class="educbt-muted"><?php echo esc_html( mysql2date( 'j M Y, g:ia', current_time( 'mysql' ) ) ); ?></p>
        <?php if ( (string) $result['decision'] !== 'approved' ) : ?>
            <p class="educbt-muted">The set now reads <strong>Returned for revision</strong> and the teacher can edit it again. It returns to review only when they resubmit.</p>
        <?php endif; ?>

    <?php elseif ( $type === 'reminder_sent' ) : ?>
        <h2>Reminder sent</h2>
        <p class="educbt-muted"><?php echo esc_html( (string) $result['message'] ); ?></p>

    <?php elseif ( $type === 'quotas_saved' ) : ?>
        <h2>Requirement saved</h2>

    <?php elseif ( $type === 'components_saved' ) : ?>
        <h2>Marking scheme saved</h2>
        <p class="educbt-muted">Weights total <?php echo esc_html( (string) (float) $result['total'] ); ?>.</p>

    <?php elseif ( $type === 'notice_sent' ) : ?>
        <h2><?php echo esc_html( sprintf( 'Notice sent to %d member(s) of staff', (int) $result['sent'] ) ); ?></h2>
        <?php if ( (int) ( $result['skipped'] ?? 0 ) > 0 ) : ?>
            <p class="educbt-muted"><?php echo esc_html( (string) (int) $result['skipped'] ); ?> had no portal account and were skipped.</p>
        <?php endif; ?>

    <?php elseif ( $type === 'ca_test' ) : ?>
        <h2>Class test created</h2>
        <?php if ( ! empty( $result['compose_error'] ) ) : ?>
            <p class="educbt-note educbt-note--warn">
                Could not fill it: <?php echo esc_html( str_replace( '_', ' ', (string) $result['compose_error'] ) ); ?>.
                Only approved questions can be drawn on — check your submission has been reviewed.
            </p>
        <?php else : ?>
            <p><?php echo esc_html( (string) (int) $result['composed'] ); ?> questions selected.
            Publish it below when you are ready for students to take it.</p>
        <?php endif; ?>

    <?php elseif ( $type === 'exam_prep_opened' ) : ?>
        <h2>Exam preparation opened</h2>
        <p class="educbt-muted">Teachers can now submit questions.</p>

    <?php elseif ( $type === 'exam_prep_closed' ) : ?>
        <h2>Exam preparation closed</h2>
        <p class="educbt-muted">New question submissions are paused until exam preparation is opened again.</p>

    <?php elseif ( $type === 'assignment' ) : ?>
        <h2>Assignment saved</h2>
        <p>The teacher now has access to that class or subject.</p>

    <?php elseif ( $type === 'student_added_pending' ) : ?>
        <h2>Student added</h2>
        <p><?php echo esc_html( (string) ( $result['message'] ?? 'Student added as pending approval.' ) ); ?></p>

    <?php endif; ?>
</div>
