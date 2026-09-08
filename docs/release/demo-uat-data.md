# LCMS Demo and UAT Data

This document describes the deterministic demo dataset prepared for
LCMS release validation and User Acceptance Testing.

The dataset is intended only for local, testing, demonstration, and UAT
environments.

It must never be used as production data.

## Seeder

The demo dataset is created by:

```text
database/seeders/DemoSeeder.php
```

The seeder is intentionally not registered inside `DatabaseSeeder`.

This prevents demo data from being created automatically during normal
database seeding.

Run it explicitly only when demo/UAT data is required:

```bash
php artisan db:seed --class=DemoSeeder
```

## Safety Rules

`DemoSeeder` provides the following protections:

- refuses to run in the production environment
- uses deterministic fictional data
- does not use production information
- uses a reserved demo Center identifier
- refuses to overwrite incomplete existing demo data
- refuses to use an already occupied Center identifier
- safely skips creation when the complete demo scenario already exists
- creates the complete scenario inside a database transaction
- remains separate from the normal `DatabaseSeeder`
- preserves tenant and branch boundaries

## Demo Center

```text
Name:             LCMS Demo Language Center
Code:             LCMS-DEMO
Identifier Code:  98
Currency:         USD
Timezone:         Asia/Gaza
```

## Demo Branches

### Branch A

```text
Name:  Demo Branch A
Code:  DEMO-A
```

Branch A represents the primary operational UAT branch.

It contains:

- Branch Manager assignment
- Finance Employee assignment
- Classroom
- Student account
- Active Course Class
- Schedule
- Session
- Attendance
- Enrollment
- Enrollment Fee
- Installments
- Partial Payment
- Audit records

### Branch B

```text
Name:  Demo Branch B
Code:  DEMO-B
```

Branch B exists primarily for tenant/branch authorization validation.

It contains separate:

- Classroom
- Student
- Course Class
- Schedule
- Enrollment
- Financial obligation
- Audit record

Branch Manager A must not gain access to Branch B data.

## Demo Login Accounts

All demo login accounts use the same demo-only password:

```text
DemoPass123!
```

### Platform Owner

```text
Role:        Platform Owner
Login ID:    00000001
Email:       platform.owner@demo.lcms.test
Password:    DemoPass123!
```

### Center Owner

```text
Role:        Center Owner
Login ID:    98100001
Email:       center.owner@demo.lcms.test
Password:    DemoPass123!
```

### Branch Manager

```text
Role:        Branch Manager
Login ID:    98110001
Email:       branch.manager@demo.lcms.test
Password:    DemoPass123!
Assigned:    Demo Branch A
```

### Finance Employee

```text
Role:        Finance Employee
Login ID:    98210001
Email:       finance.employee@demo.lcms.test
Password:    DemoPass123!
Assigned:    Demo Branch A
```

### Teacher

```text
Role:        Teacher
Login ID:    98120001
Email:       teacher@demo.lcms.test
Password:    DemoPass123!
```

### Student

```text
Role:        Student
Login ID:    98130001
Email:       student.a@demo.lcms.test
Password:    DemoPass123!
Branch:      Demo Branch A
```

The account identifiers are generated through the actual
`AccountIdentifierGenerator` service rather than being manually assigned.

## Academic Demo Scenario

The demo academic catalog contains:

```text
Language
└── English
    └── Beginner A1
        └── English A1
```

Course configuration:

```text
Course:               English A1
Code:                 ENG-A1
Duration:             4 weeks
Total Hours:          16
Default Fee:          300 USD
Passing Grade:        60
Minimum Attendance:   75%
```

## Course Classes

### Branch A Class

```text
Code:       DEMO-CLASS-A
Name:       Demo English A1 - Branch A
Capacity:   20
Mode:       in_person
```

This class is linked to:

- Demo Branch A
- Demo Classroom A
- Demo Teacher
- Demo Student A enrollment

### Branch B Class

```text
Code:       DEMO-CLASS-B
Name:       Demo English A1 - Branch B
Capacity:   20
Mode:       in_person
```

This class exists to support branch-isolation validation.

## Enrollment Scenario

Branch A contains:

```text
Enrollment Number:  DEMO-ENR-A-001
Student:            Demo Student A
Class:              DEMO-CLASS-A
Status:             Active
Eligibility:        eligible
```

Branch B contains:

```text
Enrollment Number:  DEMO-ENR-B-001
Student:            Demo Student B
Class:              DEMO-CLASS-B
Status:             Active
Eligibility:        eligible
```

## Scheduling Scenario

Branch A contains an active Monday schedule:

```text
Start:  09:00
End:    10:30
```

Branch B contains an active Tuesday schedule:

```text
Start:  11:00
End:    12:30
```

Branch working hours permit these schedules.

## Attendance Scenario

The dataset contains active attendance statuses:

```text
Present = 100
Absent  = 0
```

Demo Student A has a recorded Present attendance entry for a completed
Branch A session.

The attendance record is attributed to the Demo Teacher account.

## Financial Scenario

Demo Student A has an active enrollment fee:

```text
Total Fee:  300 USD
```

The fee is divided into two installments:

```text
Installment 1: 150 USD
Installment 2: 150 USD
```

A partial posted payment exists:

```text
Receipt:        DEMO-RCT-0001
Amount:         100 USD
Method:         cash
Reference:      DEMO-PAYMENT-A
```

The payment allocates:

```text
100 USD
```

to the first installment.

The expected remaining financial obligation is therefore available for
UAT balance, installment, payment, receipt, and reporting scenarios.

Branch B also contains an unpaid financial obligation so that Branch A
users can be tested against Branch B financial isolation.

## Audit Scenario

The demo dataset creates sample audit records through the real
`AuditRecorder`.

Examples include:

```text
demo.center_seeded
demo.branch_seeded
demo.payment_seeded
```

Audit records are created with valid tenant ownership.

Expected authorization behavior:

- Center Owner can review own-center audit records.
- Branch Manager A can review Branch A audit records.
- Branch Manager A cannot review Branch B audit records.
- Finance Employee cannot access Audit Record Review.
- Teacher cannot access Audit Record Review.
- Student cannot access Audit Record Review.
- Platform Owner is not granted Audit Record Review in the MVP.

## Suggested UAT Usage

The dataset supports validation of:

- authentication
- role separation
- tenant isolation
- branch isolation
- staff assignments
- student access
- academic catalog
- class access
- enrollment visibility
- scheduling
- session management
- attendance
- student financial position
- payment receipt
- reporting
- audit review

## Resetting Demo Data

Do not manually delete individual demo records because the scenario
contains linked operational, financial, and audit history.

For automated tests, Laravel's testing database lifecycle handles
database reset.

For a disposable local/UAT database, recreate the database using the
approved environment setup process before reseeding.

Do not use manual `migrate:fresh` against any database that contains
important or non-disposable data.

## Important Warning

These credentials are public development fixtures stored in the source
repository.

They must never be reused for:

- production accounts
- real users
- staging environments containing sensitive data
- external services
- email accounts
- database accounts
- infrastructure credentials