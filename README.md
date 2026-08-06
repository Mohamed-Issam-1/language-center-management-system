# Language Center Management System — LCMS

LCMS is a multi-tenant web application for managing the academic,
administrative, and financial operations of language centers.

This repository contains the Minimum Viable Product implementation.

## Project Status

MVP under development.

The MVP focuses on completing the following operational workflow:

Student Registration
→ Class Enrollment
→ Schedule and Sessions
→ Attendance
→ Fees and Payments
→ Reports

## MVP Scope

The first release includes:

- Multi-tenant language-center management
- Authentication and account management
- Role and permission management
- Branch and classroom management
- Student management
- Teacher and finance-employee management
- Language, level, and course management
- Class and enrollment management
- Schedule and session management
- Attendance recording and calculation
- Fees, installments, payments, balances, and receipts
- Essential reports and role-specific dashboards
- Audit records for sensitive operations

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

The MVP uses six fixed roles:

1. Platform Owner
2. Center Owner
3. Branch Manager
4. Finance Employee
5. Teacher
6. Student

A user may have more than one role. Effective permissions are calculated
from all assigned roles while platform, center, branch, class, and
record-level restrictions remain enforced.

## Technology Stack

### Backend

- PHP
- Laravel
- Eloquent ORM
- MySQL

### Administrative Interfaces

- Filament

### Customized Interfaces

- React
- TypeScript
- Inertia.js
- Tailwind CSS
- Vite

### Testing

- PHPUnit
- Laravel unit, feature, integration, and authorization tests

## Interface Distribution

Filament is used for internal administrative and record-management
workflows, including:

- Centers and branches
- Classrooms
- User accounts and roles
- Staff and student records
- Courses and classes
- Enrollments
- Fees, installments, and payments
- Reports
- Audit-record review

React and Inertia are used for customized role-specific interfaces,
particularly:

- Teacher schedules
- Teacher attendance workflows
- Student dashboards
- Student schedules
- Student attendance information
- Student financial information

## Architecture Principles

- The application uses a multi-tenant architecture.
- Tenant and branch isolation must be enforced by the backend.
- Frontend visibility is not considered authorization.
- Business logic should be separated from interface code.
- Critical writes should use validation, database constraints, and
  transactions.
- Sensitive operations should create audit records.
- The first-release interface is English and left-to-right.
- The interface structure should remain ready for future localization.

## Local Development Requirements

Install:

- PHP according to `composer.json`
- Composer
- MySQL
- Node.js
- npm

## Installation

Clone the repository:

```bash
git clone <repository-url>
cd language-center-management-system