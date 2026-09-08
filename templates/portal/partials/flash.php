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

    <?php elseif ( $type === 'moderated' ) : ?>
        <h2>Results moderated &amp; recompiled</h2>
        <p><?php echo esc_html( sprintf( '%d score(s) updated — %d subject(s) for %d student(s) recompiled.', (int) $result['updated'], (int) $result['subjects'], (int) $result['students'] ) ); ?></p>
        <?php if ( ! empty( $result['errors'] ) ) : ?>
            <p class="educbt-note educbt-note--warn" style="margin-top:10px">
                Some scores were out of range and skipped:
            </p>
            <?php foreach ( (array) $result['errors'] as $e ) : ?>
                <p class="educbt-muted"><?php echo esc_html( (string) $e ); ?></p>
            <?php endforeach; ?>
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
        <p><a class="educbt-btn educbt-btn--primary" href="<?php echo esc_url( home_url( '/portal/school/transcript-print/' ) ); ?>" target="_blank">Download transcript</a></p>
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

    <?php // Every succeed() type needs a branch here. A type with no branch renders
          // an empty notice box — the user sees a blank panel and cannot tell
          // whether the action worked. ?>
    <?php elseif ( $type === 'saved' ) : ?>
        <h2>Saved</h2>

    <?php elseif ( $type === 'duplicated' ) : ?>
        <h2>Question duplicated</h2>
        <p class="educbt-muted">The copy sits directly below the original, ready to edit.</p>

    <?php elseif ( $type === 'flagged' ) : ?>
        <h2>Flagged for review</h2>

    <?php elseif ( $type === 'issue_report' || $type === 'issue_reported' ) : ?>
        <h2>Report sent</h2>
        <p class="educbt-muted">The exam office has been notified.</p>

    <?php elseif ( $type === 'attempt_reset' ) : ?>
        <h2>Attempt reset</h2>
        <p>The student can now sit this paper again.</p>

    <?php elseif ( $type === 'examination_deleted' ) : ?>
        <h2>Examination deleted</h2>
        <p>Its papers and timetable entries have been removed.</p>

    <?php elseif ( $type === 'student_approved' ) : ?>
        <h2>Student approved</h2>
        <p>They are now on the class register and can sign in.</p>

    <?php elseif ( $type === 'student_activated' ) : ?>
        <h2>Student reinstated</h2>

    <?php elseif ( $type === 'student_deactivated' ) : ?>
        <h2>Student suspended</h2>
        <p class="educbt-muted">They cannot sign in until the suspension is lifted.</p>

    <?php elseif ( $type === 'submission_deleted' ) : ?>
        <h2>Submission deleted</h2>
        <p>The question set and every question in it have been removed.</p>
        <p class="educbt-muted">If this was a mistake, use <strong>Restore missing questions</strong> under Question bank safekeeping — the last saved copy can be put back.</p>

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

    <?php elseif ( $type === 'assignment_dropped' ) : ?>
        <h2>Assignment dropped</h2>
        <p>The subject assignment has been removed from this teacher.</p>
        <p class="educbt-muted">They will no longer appear as a subject teacher for that class.</p>

    <?php elseif ( $type === 'timetable_generated' ) : ?>
        <h2>Timetable generated</h2>
        <p class="educbt-muted">
            <?php echo esc_html( sprintf( 'Laid out across %d day(s), Monday to Friday.', (int) ( $result['days'] ?? 0 ) ) ); ?>
            <?php if ( (int) ( $result['unplaced'] ?? 0 ) > 0 ) : ?>
                <strong><?php echo esc_html( sprintf( '%d paper(s) could not be placed', (int) $result['unplaced'] ) ); ?></strong>
                — every slot in the fortnight already has one of those classes sitting something. Add a slot or spread the start date.
            <?php endif; ?>
        </p>
        <p><?php echo esc_html( sprintf( '%d paper(s) scheduled', (int) $result['created'] ) ); ?><?php
            if ( (int) $result['skipped'] > 0 ) {
                echo esc_html( sprintf( ', %d already scheduled and left as they were', (int) $result['skipped'] ) );
            }
        ?>.</p>
        <p class="educbt-muted">Adjust any of them below, then notify class teachers.</p>

    <?php elseif ( $type === 'timetable_sent' ) : ?>
        <h2><?php echo esc_html( sprintf( 'Timetable sent to %d class teacher(s)', (int) $result['sent'] ) ); ?></h2>
        <p class="educbt-muted">Each received only their own class's schedule.</p>

    <?php elseif ( $type === 'subjects_registered' ) : ?>
        <h2>Subjects saved</h2>
        <p><?php echo esc_html( sprintf( '%d subject(s) now registered for this session.', (int) $result['total'] ) ); ?></p>
        <p class="educbt-muted">Registered subjects decide which CA tests and examinations appear on the student's portal.</p>

    <?php elseif ( $type === 'class_subjects_registered' ) : ?>
        <h2>Class registered</h2>
        <p><?php echo esc_html( sprintf(
            '%d compulsory subject(s) applied to %d of %d student(s).',
            (int) $result['subjects'], (int) $result['students'], (int) $result['total']
        ) ); ?></p>
        <p class="educbt-muted">Students already holding the full core set were left untouched, as were everyone's electives.</p>

    <?php elseif ( $type === 'student_status' ) : ?>
        <h2><?php echo esc_html( (string) $result['name'] ); ?> <?php echo esc_html( (string) $result['action'] ); ?></h2>
        <p class="educbt-muted">The record is kept, including any results already compiled.</p>

    <?php elseif ( $type === 'vault_backup' ) : ?>
        <h2>Question bank backed up</h2>
        <p><?php echo esc_html( sprintf( '%d question(s) across %d set(s) copied to safe storage.', (int) $result['questions'], (int) $result['sets'] ) ); ?></p>
        <p class="educbt-muted">The copy is kept outside the plugin's own tables, so it survives a reinstall.</p>

    <?php elseif ( $type === 'vault_restore' ) : ?>
        <h2>Question bank restored</h2>
        <p><?php echo esc_html( sprintf( '%d question(s) put back across %d set(s).', (int) $result['restored'], (int) $result['sets'] ) ); ?>
        <?php if ( (int) $result['skipped'] > 0 ) : ?>
            <?php echo esc_html( sprintf( '%d were already present and were left untouched.', (int) $result['skipped'] ) ); ?>
        <?php endif; ?></p>

    <?php elseif ( $type === 'subjects_refreshed' ) : ?>
        <h2>Subject list updated</h2>
        <p><?php echo esc_html( sprintf(
            '%d standard subject(s) added. %d retired because they carry existing records. %d unused subject(s) removed.',
            (int) $result['added'], (int) $result['retired'], (int) $result['removed']
        ) ); ?></p>
        <p class="educbt-muted">Retired subjects no longer appear for new registration, but past results that reference them stay readable.</p>

    <?php elseif ( $type === 'subject_updated' ) : ?>
        <h2>Subject updated</h2>
        <p><?php echo esc_html( (string) $result['name'] ); ?> (<?php echo esc_html( (string) $result['code'] ); ?>)</p>

    <?php elseif ( $type === 'subject_deleted' ) : ?>
        <h2>Subject removed</h2>
        <p><?php echo esc_html( (string) $result['name'] ); ?> has been deleted.</p>

    <?php elseif ( $type === 'subject_retired' ) : ?>
        <h2>Subject retired</h2>
        <p><?php echo esc_html( (string) $result['name'] ); ?> is no longer offered.</p>
        <p class="educbt-muted">
            It was not deleted because <?php echo esc_html( (string) (int) $result['records'] ); ?> existing
            record(s) refer to it — results, scores or questions. Deleting it would have made those
            records unreadable, so it is withdrawn from future use and the history stays intact.
        </p>

    <?php elseif ( $type === 'practice_created' ) : ?>
        <h2>Practice exam created</h2>
        <p>Teachers can now add practice questions at any time, and students can sit it whenever they like.</p>
        <p class="educbt-muted">It is never scheduled or reviewed, and its marks do not count towards results.</p>

    <?php elseif ( $type === 'series_deleted' ) : ?>
        <h2><?php echo esc_html( (string) $result['title'] ); ?> deleted</h2>
        <p>Its papers and timetable entries have been removed.</p>
        <p class="educbt-muted">The questions teachers wrote for it are still in the question bank.</p>

    <?php elseif ( $type === 'question_window' ) : ?>
        <?php if ( (string) $result['state'] === 'closed' ) : ?>
            <h2>Question bank closed</h2>
            <p>Teachers can no longer set questions. Open it again when the next assessment is ready.</p>
        <?php else : ?>
            <h2>Question bank open</h2>
            <p>Teachers are now setting questions for <strong><?php echo esc_html( (string) $result['title'] ); ?></strong>.</p>
            <p class="educbt-muted">Anything they set goes to this one. Whatever was open before is now closed.</p>
        <?php endif; ?>

    <?php elseif ( $type === 'ca_window_created' ) : ?>
        <h2>Assessment window opened</h2>
        <p>Teachers have been notified and can now write their objective questions for it.</p>
        <p class="educbt-muted">Compose the papers once questions are in, then publish and set the timetable.</p>

    <?php elseif ( $type === 'ca_window_composed' ) : ?>
        <h2>Assessment papers composed</h2>
        <p><?php echo esc_html( sprintf( '%d paper(s) created.', (int) $result['created'] ) ); ?>
        <?php if ( (int) $result['skipped'] > 0 ) : ?>
            <?php echo esc_html( sprintf( '%d skipped.', (int) $result['skipped'] ) ); ?>
        <?php endif; ?></p>
        <?php if ( ! empty( $result['short'] ) ) : ?>
            <p class="educbt-muted">
                Not composed because too few questions were written for the number each student must answer:
                <strong><?php echo esc_html( implode( '; ', (array) $result['short'] ) ); ?></strong>.
                Shortening those papers silently would mark one class out of a different total from another.
            </p>
        <?php endif; ?>

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
            <div class="educbt-note educbt-note--warn" style="padding:16px">
                <p style="font-weight:600;margin-bottom:8px">The test was created but could not be filled with questions.</p>
                <p class="educbt-muted" style="margin-bottom:10px">
                    <?php
                    $err = (string) $result['compose_error'];
                    if ( strpos( $err, 'insufficient_usable_questions' ) === 0 ) :
                        echo 'There are not enough approved questions with options and correct answers marked for this subject. ';
                        echo 'Go to the ';
                        echo '<a href="' . esc_url( home_url( '/portal/exams/questions/' ) ) . '">question bank</a>';
                        echo ', make sure your questions have at least 2 options each with the correct answer marked, ';
                        echo 'and that they have been approved. Then come back here and click "Fill questions".';
                    else :
                        echo esc_html( str_replace( '_', ' ', $err ) );
                    endif;
                    ?>
                </p>
                <p class="educbt-muted" style="margin-bottom:0">You can still see the test in your list below. Click <strong>Fill questions</strong> once your questions are ready.</p>
            </div>
        <?php else : ?>
            <p><?php echo esc_html( (string) (int) $result['composed'] ); ?> questions were selected from your approved question bank.</p>
            <p>Click <strong>Publish to students</strong> below when you are ready for students to take it.</p>
        <?php endif; ?>

    <?php elseif ( $type === 'recomposed' ) : ?>
        <h2>Questions filled</h2>
        <p><?php echo esc_html( (string) (int) $result['selected'] ); ?> questions were pulled from the approved question bank.</p>
        <p class="educbt-muted">Now click <strong>Publish to students</strong> to make the test available.</p>

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


    <?php elseif ( $type === 'deleted' ) : ?>
        <h2>Paper deleted</h2>
        <p class="educbt-muted">The paper has been removed. If students had already started, it was left intact for their records.</p>

    <?php elseif ( $type === 'unpublished' ) : ?>
        <h2>Paper unpublished</h2>
        <p class="educbt-muted">Students can no longer see this paper. You can republish it any time.</p>

    <?php elseif ( $type === 'access_code_regenerated' ) : ?>
        <h2>Access code regenerated</h2>
        <table class="educbt-table"><tr><td>New access code</td><td><code style="font-size:16px;font-weight:700;letter-spacing:2px"><?php echo esc_html( (string) $result['access_code'] ); ?></code></td></tr></table>
        <p class="educbt-muted">The old code no longer works. Share this new one with the invigilator.</p>

    <?php elseif ( $type === 'rescheduled' ) : ?>
        <h2>Paper rescheduled</h2>
        <p class="educbt-muted"><?php echo esc_html( (string) ( $result['message'] ?? 'The paper has been moved.' ) ); ?></p>

    <?php elseif ( $type === 'recomposed' ) : ?>
        <h2>Paper recomposed</h2>
        <p class="educbt-muted"><?php echo esc_html( sprintf( '%d questions selected.', (int) ( $result['selected'] ?? 0 ) ) ); ?></p>

    <?php endif; ?>
</div>
