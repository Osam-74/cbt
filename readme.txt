=== EduCBT Pro ===
Contributors: deodevs
Tags: education, cbt, school-management, exam, results
Requires at least: 6.8
Tested up to: 6.8
Requires PHP: 8.2
Stable tag: 3.2.3
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
