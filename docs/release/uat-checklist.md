LCMS M7-H User Acceptance Testing Checklist

## Final UAT Disposition

This checklist was originally prepared before the LCMS administrative UI
gap was closed.

The final administrative implementation now includes dedicated Filament
resources for Students, Classrooms, Languages, Academic Levels, Courses,
Course Classes, staff administration, User Accounts, Centers, Branches,
and other approved MVP administration surfaces.

Therefore, the historical "Current UI Scope Review" section below no longer
describes the final release candidate.

Final UAT execution used the isolated database:

```text
lcms_ui10_uat_20260908

Environment

Use only the disposable UAT database:

lcms_ui10_uat_20260908

Expected application URL:

http://127.0.0.1:8002

Do not run DemoSeeder against the normal lcms database.

Demo password for the seeded accounts:

DemoPass123!

Result Values

For every scenario record one of:

PASS — behavior matches the expected result.

FAIL — behavior is incorrect but UAT can continue.

BLOCKER — critical/high issue that prevents release or prevents the scenario from continuing.

N/A — no current user-facing surface exists or the scenario is intentionally outside the approved MVP UI scope.

When a result is FAIL or BLOCKER, record:

account/role

exact page/route

exact steps

expected result

actual result

screenshot if useful

whether the issue is reproducible

Seeded Accounts

Role

Login ID

Email

Password

Platform Owner

00000001

platform.owner@demo.lcms.test

DemoPass123!

Center Owner

98100001

center.owner@demo.lcms.test

DemoPass123!

Branch Manager

98110001

branch.manager@demo.lcms.test

DemoPass123!

Finance Employee

98210001

finance.employee@demo.lcms.test

DemoPass123!

Teacher

98120001

teacher@demo.lcms.test

DemoPass123!

Student

98130001

student.a@demo.lcms.test

DemoPass123!

Branch Manager and Finance Employee are assigned to Demo Branch A.

UAT Scenarios

UAT-01 — Application and Authentication Entry

Role: Guest

Steps:

Open /.

Open /login.

Open /admin/login.

Confirm /admin/login redirects to the authoritative LCMS login flow.

Enter an invalid account identifier/password.

Confirm authentication fails without exposing sensitive details.

Expected:

application loads

LCMS login page is usable

Filament login does not create a second authentication flow

invalid credentials are rejected safely

Result: PENDING

Notes:

UAT-02 — Seeded Account Login

Roles:

Platform Owner

Center Owner

Branch Manager

Finance Employee

Teacher

Student

Steps:

Login with each seeded account.

Confirm the account can reach its intended application/dashboard surface.

Confirm logout works.

Confirm another account can then login normally.

Expected:

valid credentials authenticate

role separation is preserved

logout ends the session

Result: PENDING

Notes:

UAT-03 — Admin Panel Resource Visibility

Roles:

Center Owner

Branch Manager

Finance Employee

Teacher

Student

Platform Owner

Steps:

For each role, open /admin.

Record the resources/navigation items that are visible.

Open only resources presented to that role.

Confirm the role does not unexpectedly gain administrative resources outside its authorization.

Current Filament resources in this release:

Attendance Statuses

Attendances

Audit Records

Class Schedules

Class Sessions

Enrollment Fees

Enrollments

Payments

Registration Requests

Student Balances

Reports page

Expected:

authorized resources work

unauthorized resources are hidden or denied

there is no cross-role privilege escalation

Result: PENDING

Notes:

UAT-04 — Branch Isolation

Role: Branch Manager (98110001)

Seeded assignment:

Demo Branch A

Steps:

Login as Branch Manager.

Inspect every accessible branch-scoped resource.

Confirm Branch A data is visible where expected.

Attempt to locate Branch B records through lists, filters, direct navigation, and detail pages where practical.

Seeded Branch B examples include:

DEMO-CLASS-B

DEMO-ENR-B-001

Branch B schedule

Branch B financial obligation

Branch B audit data

Expected:

Branch Manager A remains scoped to Branch A

Branch B operational/financial/audit data is not exposed

Result: PENDING

Notes:

UAT-05 — Registration Request Submission

Role: Guest

Use unique fictional data in the disposable UAT database, for example:

Full name:            UAT New Student
National ID:          999000001
Date of birth:        2000-01-01
City:                 Gaza
Email:                uat.new.student@example.test
Phone:                +970599000001

Steps:

Open /register.

Select/use the demo center if the form requests a center.

Submit the registration request.

Confirm the request is accepted as pending.

Do not expect a User to be created yet.

Expected:

public submission creates a pending Registration Request

public registration does not directly create an active account

Result: PENDING

Notes:

UAT-06 — Registration Review and Approval

Role: Center Owner (98100001)

Steps:

Login as Center Owner.

Open /admin/registration-requests.

Locate the UAT New Student request.

Review the submitted identity details.

Approve it using the Student role and Demo Branch A when the UI requests them.

Confirm the request leaves pending state.

Confirm credentials delivery is triggered.

Expected:

administrative approval creates/reuses the Person as designed

exactly one role is selected

the Student account/operational record is created

an 8-digit account login identifier is generated

credential delivery is triggered

Result: PENDING

Generated Login ID:

<record here>

Notes:

UAT-07 — Credential Delivery and Forced First Password Change

Role: Newly approved UAT Student

Steps:

Inspect the local mail log for the credential email.

Login with the generated account identifier and temporary password.

Confirm the first-login password lifecycle is enforced.

Set a new UAT-only password.

Logout.

Login again with the new password.

Suggested UAT-only password:

UatChanged123!

Expected:

temporary credentials work once as designed

forced password change occurs before normal protected access

the new password works after logout/re-login

Result: PENDING

Notes:

UAT-08 — Password Recovery OTP

Role: Newly approved UAT Student

Steps:

Open /forgot-password.

Start recovery using the account login identifier.

Inspect the local mail log for the recovery email.

Confirm a 5-digit OTP is delivered.

Open the verification step.

Enter the OTP.

Set a new password.

Login using the recovered password.

Suggested UAT-only password:

UatRecovered123!

Expected:

recovery starts from account identifier

OTP is sent to the account recovery email

OTP is 5 digits

valid OTP allows password reset

the new password authenticates

Result: PENDING

Notes:

UAT-09 — Enrollment Visibility

Roles:

Center Owner

Branch Manager

Seeded Branch A enrollment:

DEMO-ENR-A-001

Seeded Branch B enrollment:

DEMO-ENR-B-001

Steps:

Open /admin/enrollments.

Confirm seeded Branch A enrollment is usable/visible for authorized roles.

Confirm Branch Manager A cannot access Branch B enrollment.

Open the Branch A enrollment detail page.

Expected:

enrollment data renders correctly

branch isolation is preserved

Result: PENDING

Notes:

UAT-10 — Schedule and Session Visibility

Roles:

Center Owner

Branch Manager

Teacher where permitted

Steps:

Open Class Schedules.

Inspect Demo Branch A schedule.

Open Class Sessions.

Inspect the seeded completed/scheduled session data.

Confirm Branch Manager A cannot access Branch B schedule/session data.

Expected:

schedule/session pages render

correct seeded values are visible

branch boundaries remain enforced

Result: PENDING

Notes:

UAT-11 — Attendance

Roles:

Center Owner

Branch Manager

Teacher where permitted

Seeded state:

Present = 100
Absent  = 0
Demo Student A has a Present entry for a completed Branch A session

Steps:

Open Attendance Statuses if authorized.

Open Attendances.

Locate the seeded Demo Student A attendance.

Confirm displayed status/session/student information is correct.

Confirm Branch A users cannot access Branch B data.

Expected:

seeded attendance renders correctly

attendance permissions are respected

branch isolation remains intact

Result: PENDING

Notes:

UAT-12 — Fees, Installments, Payments and Balance

Roles:

Center Owner

Finance Employee

Branch Manager where permitted

Seeded Branch A financial state:

Enrollment fee: 300 USD
Installment 1:  150 USD
Installment 2:  150 USD
Posted payment: 100 USD
Receipt:        DEMO-RCT-0001
Reference:      DEMO-PAYMENT-A

Steps:

Open Enrollment Fees.

Locate Demo Student A.

Confirm the 300 USD fee.

Confirm installment information.

Open Payments.

Confirm the posted 100 USD payment and receipt/reference.

Open Student Balances.

Confirm the remaining obligation is consistent with the seeded scenario.

Confirm Branch A users cannot see Branch B financial data.

Expected:

financial amounts are internally consistent

payment/allocation information renders correctly

financial authorization and branch isolation are preserved

Result: PENDING

Notes:

UAT-13 — Reports and Exports

Roles:

Center Owner

Branch Manager where permitted

Finance Employee where permitted

Steps:

Open /admin/reports.

Load available report sections.

Apply representative filters.

Test CSV export.

Test PDF export.

Open exported files and verify they are non-empty and readable.

Confirm report data respects tenant/branch scope.

Expected:

report page loads

filters work

CSV export works

PDF export works

exported data does not cross authorization boundaries

Result: PENDING

Notes:

UAT-14 — Audit Record Review

Known expected authorization behavior:

Center Owner can review own-center Audit Records.

Branch Manager A can review Branch A Audit Records only.

Finance Employee cannot access Audit Record Review.

Teacher cannot access Audit Record Review.

Student cannot access Audit Record Review.

Platform Owner is not granted Audit Record Review in the current MVP.

Steps:

Login as Center Owner and open /admin/audit-records.

Confirm own-center records render.

Login as Branch Manager A and confirm Branch A-only visibility.

Attempt access with Finance Employee, Teacher, Student and Platform Owner.

Expected:

audit review follows the matrix above

unauthorized roles are hidden/denied

Branch B audit records are not exposed to Branch Manager A

Result: PENDING

Notes:

UAT-15 — Session Management

Role: Any authenticated demo account

Steps:

Login.

Open /account/sessions.

Confirm the current session is displayed as designed.

If another disposable session exists, test session revocation.

Confirm normal logout still works afterward.

Expected:

session-management page works

users cannot manage another user's sessions

logout remains functional

Result: PENDING

Notes:

UAT-16 — Direct Unauthorized URL Checks

Roles:

Finance Employee

Teacher

Student

Platform Owner

Steps:

While logged in with an unauthorized role, manually try:
/admin/audit-records

For other resources hidden from that role, try the direct route once.

Record whether access is denied or safely redirected.

Expected:

hiding navigation is not the only protection

direct unauthorized URLs fail closed

Result: PENDING

Notes:

Current UI Scope Review

The M7-H release plan also names these domains for representative UAT:

Students

Classrooms

Academic Catalog

Course Classes

However, the current route/resource inventory does not show dedicated
user-facing Filament resources/routes for:

Students

Classrooms

Languages

Academic Levels

Courses

Course Classes

These domains have backend/service regression evidence from earlier M7 stages,
but they cannot be accepted as manually UI-tested from the currently exposed
routes.

Before M7-H is marked DONE, classify this deliberately:

A. Intended MVP UI scope:
   Missing user-facing surfaces are a release BLOCKER.

B. Intentionally backend-only / deferred UI scope:
   Mark these UAT rows N/A and document the deferred UI scope in M7-I.

Do not silently mark these domains PASS based only on automated tests.

UAT-SCOPE-01 — Students UI

Result: PENDING

Decision/Notes:

UAT-SCOPE-02 — Classrooms UI

Result: PENDING

Decision/Notes:

UAT-SCOPE-03 — Academic Catalog UI

Result: PENDING

Decision/Notes:

UAT-SCOPE-04 — Course Classes UI

Result: PENDING

Decision/Notes:

Defect Log

ID

Severity

Role

Scenario

Expected

Actual

Status

UAT-D01













Severity:

Critical

High

Medium

Low

M7-H Acceptance Gate

M7-H can be marked DONE only when:

all required exposed UAT scenarios are executed

no unresolved Critical defect exists

no unresolved High defect exists

branch/tenant isolation checks pass

registration approval and credential lifecycle pass

password recovery OTP flow passes

finance/report/audit representative flows pass

the four current UI-scope questions are explicitly classified

any intentionally deferred UI scope is documented for M7-I

the application remains on the disposable UAT database only during UAT

the normal lcms database remains unchanged

UAT cleanup is completed after review