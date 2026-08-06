=== EduCBT Pro ===
Contributors: deodevs
Tags: education, cbt, school-management, exam, results
Requires at least: 6.8
Tested up to: 6.8
Requires PHP: 8.2
Stable tag: 3.4.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Enterprise multi-school CBT, school management, results processing, timetable automation, and academic intelligence for WordPress.

== Description ==

EduCBT Pro is an enterprise-ready education platform for WordPress that combines:

- Computer Based Testing (CBT)
- School and academic structure management
- Exam timetable scheduling and safeguards
- Question bank and submission verification workflows
- Result processing and reporting
- Integrity monitoring and risk-aware administration

It is designed for schools that need role-based control for administrators, teachers, students, and parents while keeping school data logically isolated.

= Key Features =

- Multi-school (tenant-aware) architecture
- Role and capability based access controls
- Student, teacher, class, and subject management
- Exam creation, question assignment, and CBT attempt lifecycle
- Subject-based timetable rules with daily cap safeguards
- Objective question verification with minimum threshold workflows
- Duplicate question preview and cleanup tools
- Intelligent pending-submission notifications for teachers
- Exam integrity events, threshold settings, and reporting
- REST API namespace for portal and integration workflows

= Privacy and Data Handling =

- This plugin stores education data inside your WordPress database.
- No third-party tracking service is required for core functionality.
- If you add custom integrations, you are responsible for legal disclosure and consent where required.

== Installation ==

1. Upload the plugin folder to your WordPress plugins directory.
2. Activate EduCBT Pro from the Plugins screen.
3. Ensure your environment meets requirements:
   - WordPress 6.8+
   - PHP 8.2+
   - MySQL 8+ or MariaDB 10.6+
4. Open the EduCBT Pro admin menu and configure your school context.
5. Set up classes, subjects, users, questions, exams, and timetable.

== Frequently Asked Questions ==

= Does EduCBT Pro support more than one school instance? =

Yes. The plugin is built with tenant-aware structures so school data can be scoped safely.

= Can teachers submit questions in batches? =

Yes. Objective questions can be submitted in batches, verified against minimum thresholds, and monitored for pending submission counts.

= Does verification affect theory questions? =

No. Objective verification and duplicate cleanup workflows are designed not to affect theory questions.

= Can I preview duplicates before deleting them? =

Yes. The admin duplicate-cleanup workflow includes preview and dry-run metrics before purge.

= Is there a frontend portal theme? =

Yes. EduCBT Frontend Portal theme is available for student/staff-facing workflows.

== Screenshots ==

1. EduCBT Pro admin dashboard
2. Exam timetable management and preview
3. Question verification and teacher attribution report
4. Integrity events and threshold operations
5. Frontend portal pages for login, exams, and dashboard

== Changelog ==

= 3.4.0 =
- Question sets are now scoped to class LEVEL plus department, not to individual arms. JS1 A and JS1 B sit the same paper, so a teacher entered identical questions once per arm and reviewers saw duplicates. The scope selector now offers "JS1" or "SS1 Science", matching the Create Class screen.
- A migration merges existing per-arm sets: the earliest survives, every question from its siblings is re-pointed at it and renumbered, and the emptied siblings are removed. No question is deleted.
- A teacher assigned to any one arm of a level may author that level's questions, since arms of the same level often have different teachers for a subject.
- class_id is retained on a set only as a representative arm for paper generation; it no longer identifies the set. Older links using class_id are resolved to their level.
- Examinations are now created for a session and term, with no subject, date, duration or question count asked up front — the schedule is an output of the process, not its input.
- Timetables are generated from the question sets actually approved for that examination's session and term, laid across weekday slots, skipping weekends. Re-running adds newly approved papers and leaves hand-adjusted entries alone.
- Added "Notify class teachers", which sends each class teacher only their own class's schedule.
- Exam overview now reads "Create examination" rather than "Schedule a paper".

= 3.3.1 =
- Portal pages now send no-cache headers and set DONOTCACHEPAGE. Every portal page is per-user, but nothing opted out of caching, so a host page cache (LiteSpeed on most shared hosts) served stale rendered pages — which is why the unread badge came back, new or deleted classes did not appear, and fixes looked like they had not applied.
- Portal pages now warn a school admin when a copy of the template in the active theme is shadowing the plugin's own, which silently defeats plugin updates.
- Normalised withdraw_set()/delete_set() to (school, set, actor) to match every other method; they took (school, actor, set), a transposition the type system could not catch.
- Report issue box no longer opens by itself: it relied on the `hidden` attribute, which loses to any CSS rule setting display on a form.
- Theory questions can now be edited in full — sub-questions and marking guide were missing from the edit form, so opening a theory question hid half of what had been entered. The sub-question total is reported, never written over the main question's marks.
- A partial question update can no longer wipe options or sub-questions it was not given.
- Unread notification preview added to every role's overview.
- Login page now shows "Welcome" instead of the site name, and the students/staff/parents hint has been removed.

= 3.3.0 =
- Minimum question rule now works. The page read quota keys that did not exist, and the server ignored quotas entirely and applied one school-wide number (20) to both exam types, so a school setting 4 theory questions was still refused until it had 20.
- Objective and theory now submit together as one paper. Submitting either checks both halves, names whichever is short, and submits both.
- Notifications rebuilt: subject line only, Open to read (which marks it read, fixing the stuck unread counter), then Review and Report issue. Review deep-links to the exact subject in the question bank.
- Theory: sub-question totals no longer overwrite the main question's marks, and "+ Add sub-question" no longer appears to erase everything already typed.
- Creating a class that was previously removed now works. The row is only soft-deleted, so the unique key silently rejected the insert while the duplicate check saw nothing.
- Send back for revision now moves the question set to "Returned for revision", records reviewer, comment and timestamp, appends a timestamped history entry, and confirms what happened.
- "Marking" now reads "Marking Status" for principals and exam officers, with a per-teacher outstanding list and a Notify teacher button that only sends a reminder.
- Live Exam Sessions no longer tells school-wide roles that nothing is running "for your subjects" — they have no subjects.
- Recent activity now records question submissions, review decisions, withdrawals and class creation, which were all missing.
- Student IDs can now be typed in. One is generated only when the field is left blank, and the ID can be edited later — the login username moves with it.

= 3.2.9 =
- Fixed the correct answer being mapped by position in a filtered list. Leaving any option blank shifted the answer key onto the wrong option or off the end entirely, which refused the save with "Mark which option is correct" on a form that plainly had one marked. Correctness now reads from each option row itself.
- Duplicate options are now rejected on save and on edit, and only one answer key can ever be stored per question.
- Added a repair migration that collapses already-duplicated option lists back to one row per option, renumbers the keys and leaves a single correct answer standing.
- Region C also de-duplicates on render, so no stale row can display a doubled list.
- Reads are now sent uncacheable. A host or browser cache serving a previous copy of the question list made a successful save look like nothing happened: the row was written, the reload returned the old list and the count never moved.
- "+ Add option" and the remove button no longer wipe every option already typed.

= 3.2.8 =
- Fixed Region C never listing saved questions. The two GET calls that load a question set were built by concatenating a query string onto rest_url(); on sites using plain permalinks that produced a URL with two "?" characters, so WordPress matched no route and returned 404. Every POST/PUT/DELETE has a clean path, which is why saving worked while the list stayed empty. URLs are now assembled correctly for both permalink forms.
- Region C reload failures are no longer swallowed silently — the page reports them instead of freezing on the empty state.
- Returning to a subject/class with existing work now confirms the resume: "Resumed draft — N questions already saved".
- Changing subject now clears the previous scope's draft from the screen instead of leaving stale questions visible.
- The class dropdown no longer stacks a duplicate change listener on every subject change, which was firing several overlapping loads per selection.
- Empty state now distinguishes "choose a subject and class" from "no questions yet in this set".

= 3.2.7 =
- Fixed fatal error "Call to undefined method SchoolService::get_settings()" when creating a question set — the real accessor is get_school_academic_settings().
- Swept the codebase for the same class of bug and fixed 11 further calls to methods that do not exist, each of which was a live fatal on its route:
  - Broadsheet REST endpoint called three generate_*_broadsheet() methods; rewritten against the real build()/to_rows() API using relational ids.
  - Promotion REST + admin screens called create_promotion()/list_promotions(); rewritten against propose()/review().
  - Transcript REST + admin screens called create_transcript()/list_transcripts(); rewritten against issue()/issuance_history().
  - Notification REST + admin screens called create_notification()/list_for_recipient()/get_unread_count(); rewritten against notify()/inbox()/unread_count().

= 3.2.6 =
- Fixed fatal error "Class EduCBTPro\Frontend\Schema not found" in PortalRouter — the Core\Schema class was never imported, taking down every portal page for users without an educbt_users row.
- Fixed "Save failed" in the Question Bank: term resolution no longer depends on terms.is_current being set, and now stays scoped to the current session.
- REST failures now reach the browser with their real message instead of a bare "Save failed — retry".
- Fixed an infinite retry loop when committing pasted or imported questions: one rejected row re-posted itself forever.
- Pasted/imported objective questions with no answer key are now blocked at preview instead of being submitted and silently rejected.
- Edits that would strip an objective question's answer key are now refused, and failed edits report why.
- Pending migrations now run even when the phase version has not changed, and a catch-up migration guarantees the question bank columns exist.
- Fixed the questions approval index, which named subject_id before that column was created and so was never applied.

= 2.1.0 =
- Added question submission verification enhancements for objective workflows.
- Added teacher-attributed pending-submission reporting and alerting.
- Added duplicate objective-question preview and purge tools with dry-run counts.
- Added timetable automation hardening and deterministic subject-to-exam mapping support.
- Improved integrity monitoring settings and admin operational experience.

= 2.0.0 =
- Expanded tenant-aware core modules and service/repository architecture.
- Added CBT exam lifecycle, results flow, and analytics-oriented services.
- Added admin management pages and REST API coverage for core entities.

= 1.0.0 =
- Initial release of EduCBT Pro foundational modules.

== Upgrade Notice ==

= 2.1.0 =
Recommended update for schools using objective-question verification and teacher submission workflows. Includes operational hardening and safer duplicate-management utilities.
