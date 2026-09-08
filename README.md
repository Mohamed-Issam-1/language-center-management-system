# Language Center Management System — LCMS

LCMS is a multi-tenant web application for managing the academic,
administrative, financial, and reporting operations of language centers.

This repository contains the Minimum Viable Product implementation.

## Project Status

MVP feature development is complete.

The project is currently in the final validation and release-readiness
milestone.

The main operational workflow is:

```text
Student Registration
→ Account Approval
→ Class Enrollment
→ Schedule and Sessions
→ Attendance
→ Fees and Payments
→ Reports and Dashboards
→ Audit Review

## MVP Scope

The MVP includes:

- Multi-tenant language-center management
- Authentication and account lifecycle management
- Account login identifiers
- Forced first-login password change
- Self-service password recovery using a 5-digit OTP
- Fixed role and permission management
- Center and branch management
- Classroom management
- Student management
- Teacher and finance-employee management
- Language, academic-level, and course management
- Course prerequisite management
- Course Class and enrollment management
- Schedule and session management
- Attendance recording and calculation
- Fees, installments, payments, allocations, balances, and reversals
- Essential academic, enrollment, attendance, and financial reports
- CSV and PDF report export
- Role-specific dashboards
- Append-only audit records
- Read-only audit-record review


## Deferred Features

The following features are not included in the MVP:

- Examination and grade management
- Certificate management
- Automated notifications and announcements
- AI-powered administrative assistant
- Runtime multilingual interfaces
- Arabic and right-to-left interface support
- External payment-gateway integration
- WhatsApp and SMS integrations
- Biometric attendance-device integration


## User Roles

LCMS uses six fixed system roles:

1. Platform Owner
2. Center Owner
3. Branch Manager
4. Finance Employee
5. Teacher
6. Student

Each User Account has exactly one fixed system role.

A Person may have more than one User Account when separate operational
roles are required. Permissions never combine automatically between
different accounts belonging to the same Person.

Authorization remains restricted by the authenticated account's role
and by platform, center, branch, class, student, financial, or record
scope where applicable.

## Interface Distribution

### Filament

Filament provides the administrative and operational interface for the
roles that are permitted to access the LCMS administration panel.

The current MVP exposes administrative resources for:

- Centers
- Center Owners
- Branches
- Classrooms
- Students
- Teachers
- Branch Managers
- Finance Employees
- User Accounts
- Languages
- Academic Levels
- Courses
- Course Classes
- Registration Requests
- Enrollments
- Class Schedules
- Class Sessions
- Attendance Statuses
- Attendances
- Enrollment Fees
- Payments
- Student Balances
- Reports
- Read-only Audit Record Review

The administration panel also includes role-aware dashboards and
administrative quick actions for:

- Platform Owner
- Center Owner
- Branch Manager
- Finance Employee

Resource visibility and actions remain constrained by the authenticated
account role, tenant context, branch context, persisted assignments, and
backend authorization rules.

Navigation visibility is never treated as the authorization boundary.

### React and Inertia

React and Inertia provide the custom account-facing application interface.

The current authenticated application includes:

- Login
- Logout
- Login success flow
- Teacher dashboard
- Student dashboard
- Profile management
- Forced first-login password change
- Password recovery using a 5-digit OTP
- Active-session management

Administrative roles are redirected from the application dashboard to the
authorized Filament panel.

Teacher and Student accounts use the custom application dashboard rather
than the internal Filament administration panel.

## Technology Stack

### Backend

- PHP 8.2+
- Laravel 12
- Eloquent ORM
- MySQL / MariaDB

### Administration

- Filament 5
- Livewire 4

### Frontend

- React 18
- TypeScript
- Inertia.js
- Tailwind CSS
- Vite

### Reporting

- CSV export
- Dompdf PDF export

### Testing

- PHPUnit
- Laravel unit tests
- Laravel feature tests
- Authorization tests
- Integration tests
- Filament and Livewire tests
- Release-flow tests

## Architecture Principles

- Backend authorization is authoritative.
- Frontend visibility is never considered authorization.
- Every center-owned record must remain inside its tenant boundary.
- Branch-scoped operations must validate authoritative branch context.
- Branch Manager access requires a valid active persisted assignment.
- Finance Employee branch access requires a valid active persisted assignment.
- Financial history follows historical financial ownership rather than only
  the student's current branch.
- Sensitive business mutations use transactions where required.
- Sensitive operations create append-only audit records.
- Audit records cannot be modified or deleted through normal Eloquent
  workflows.
- Audit payloads redact sensitive credentials and token values.
- Report exports rebuild their authorized dataset at export time.
- Public registration creates a pending Registration Request rather than a
  User Account directly.
- Administrative approval selects exactly one fixed system role.
- The first-release interface is English and left-to-right.
- The system structure remains localization-ready for future releases.

## Local Development Requirements

Install:

- PHP 8.2 or newer
- Composer
- MySQL or MariaDB compatible with the project schema
- Node.js
- npm

The exact PHP package requirements are defined in `composer.json`.

## Installation

Clone the repository:

```bash
git clone <repository-url>
cd language-center-management-system
```

Install backend dependencies:

```bash
composer install
```

Create the environment file.

On Windows:

```powershell
Copy-Item .env.example .env
```

On Linux or macOS:

```bash
cp .env.example .env
```

> Do not overwrite an existing `.env` unless you intentionally want to
> replace the local environment configuration.

Generate the application key for a newly created environment:

```bash
php artisan key:generate
```

Configure the MySQL connection in `.env`:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=lcms
DB_USERNAME=root
DB_PASSWORD=
```

Create the configured database before running migrations.

Run the database migrations:

```bash
php artisan migrate
```

Seed the fixed system roles:

```bash
php artisan db:seed --class=RoleSeeder
```

The normal `DatabaseSeeder` must not be expected to create demo/UAT
operational data.

Install frontend dependencies:

```bash
npm install
```

Build the frontend:

```bash
npm run build
```

For local development:

```bash
composer run dev
```

## Demo and UAT Data

A deterministic demo dataset is available through:

```text
database/seeders/DemoSeeder.php
```

It is intentionally separate from the normal `DatabaseSeeder`.

Run it only against a disposable local/demo/UAT database:

```bash
php artisan db:seed --class=DemoSeeder
```

Do not run `DemoSeeder` against the normal local database or any environment
containing important data unless that use is explicitly intended.

The seeder:

- refuses the production environment
- uses deterministic fictional data
- creates six fixed-role demo accounts
- creates two branches for branch-isolation validation
- creates representative enrollment, scheduling, attendance, finance,
  reporting, and audit data
- detects incomplete existing demo state
- protects reserved demo identifiers
- is idempotent when the complete expected demo state already exists

Complete demo/UAT documentation is available at:

```text
docs/release/demo-uat-data.md
```

## Production Environment

The committed `.env.example` is intended as a safe local-development
starting point.

Production deployments must use their own untracked `.env` file and must not
copy local development values unchanged.

At minimum, configure production with values equivalent to:

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-production-domain.example

LOG_LEVEL=warning

SESSION_DRIVER=database
SESSION_LIFETIME=1440
SESSION_SECURE_COOKIE=true
SESSION_HTTP_ONLY=true
SESSION_SAME_SITE=lax

MAIL_MAILER=smtp
MAIL_HOST=<production-mail-host>
MAIL_PORT=<production-mail-port>
MAIL_USERNAME=<production-mail-username>
MAIL_PASSWORD=<production-mail-password>
MAIL_ENCRYPTION=<production-mail-encryption>
MAIL_FROM_ADDRESS=<production-from-address>
MAIL_FROM_NAME="${APP_NAME}"
```

Production must use:

- an environment-specific `APP_KEY`
- production database credentials stored outside Git
- a real mail transport rather than `MAIL_MAILER=log`
- HTTPS
- secure session cookies
- appropriate logging configuration
- environment-specific secrets that are never committed

Registration personal pictures are intentionally stored on Laravel's private
`local` disk.

They are not exposed through the public filesystem disk and are delivered
only through the application's authorized controller.

Therefore:

```bash
php artisan storage:link
```

is not required for registration personal pictures in the current LCMS MVP.

The application health endpoint is available at:

```text
/up
```

## Testing

The automated test suite uses a separate MySQL testing database.

Review `phpunit.xml` and ensure the configured testing database exists before
running the suite.

The release process uses `lcms_testing` as the isolated testing database.

Run the full backend suite:

```bash
composer test
```

Run an individual real test file with:

```bash
php artisan test tests/Feature/<ActualTestFile>.php
```

Do not use the example placeholder literally; replace it with an existing
test path.

Build the frontend independently:

```bash
npm run build
```

Check PHP dependencies for known security advisories:

```bash
composer audit
```

Validate Composer metadata:

```bash
composer validate
```

Check frontend dependencies for known security advisories:

```bash
npm audit
```

Release validation also includes targeted authorization, tenant-isolation,
branch-isolation, lifecycle, database, Filament, operational-flow, and
authentication-recovery tests.

## Database Schema Snapshot

The repository includes:

```text
database/schema/mysql-schema.sql
```

This schema snapshot is versioned intentionally to reduce database setup time
during automated testing and clean-environment validation.

It must remain synchronized with the current migration state whenever the
database structure changes.

The schema snapshot is part of the release artifact and must remain under
version control.

## Backup and Restore

Release backup and restore procedures are documented in:

```text
docs/release/backup-restore.md
```

The release procedure covers:

- MySQL/MariaDB backup
- private storage backup
- isolated database restore
- restored schema and data validation
- integrity comparison
- rollback safety

Backups must remain outside the Git repository and outside the public web
root.

Never restore directly over an active production database without first
validating the backup in an isolated restore database.

## Security Notes

Never commit:

- `.env`
- application secrets
- database passwords
- mail credentials
- API credentials
- private keys
- generated recovery codes
- OTP values
- authentication tokens
- production database dumps
- private user files

The public registration workflow creates a pending Registration Request.

It does not create a User Account directly.

An authorized reviewer selects exactly one system role and completes the
approval workflow before the operational account is created.

Approved accounts use an 8-digit account login identifier.

Accounts created through the registration workflow may require a forced
first-login password change.

Password recovery starts from the account login identifier and sends a
short-lived, single-use 5-digit OTP to that account's recovery email.

Authentication, tenant context, branch context, account lifecycle, and
backend permission checks remain authoritative regardless of which frontend
elements are visible.

## Release Readiness

Release validation, UAT, backup/restore checks, regression testing, and the
final release gate are documented under:

```text
docs/release/
```

Current release documentation includes:

```text
docs/release/release-readiness.md
docs/release/demo-uat-data.md
docs/release/uat-checklist.md
docs/release/backup-restore.md
```

The main release-readiness document is:

```text
docs/release/release-readiness.md
```

Release-readiness work must not silently introduce additional functional
scope.

rather than being represented as manually UAT-tested functionality.

## Current Branch Workflow

Development follows:

```text
feature branch
→ Pull Request
→ develop
```

The `main` branch is not updated directly during feature development.

Release-validation work is currently performed on:

```text
feature/release-readiness
```

No staging, commit, push, merge, or release tag is performed without explicit
approval.