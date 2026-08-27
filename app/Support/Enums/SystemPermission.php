<?php

namespace App\Support\Enums;

enum SystemPermission: string
{
    case ManageCenters = 'manage_centers';

    case ManageBranches = 'manage_branches';

    case ManageClassrooms = 'manage_classrooms';

    case ManageClasses = 'manage_classes';

    case ManageEnrollments = 'manage_enrollments';

    case ManageSchedules = 'manage_schedules';

    case ViewSchedules = 'view_schedules';

    case ManageAcademicStructure = 'manage_academic_structure';

    case ManageCenterOwnerAccounts = 'manage_center_owner_accounts';

    case ManageStaffAccounts = 'manage_staff_accounts';

    case ManageStudentAccounts = 'manage_student_accounts';

    case ManageStudentRecords = 'manage_student_records';

    case ViewStudentRecords = 'view_student_records';

    case ViewFinancialData = 'view_financial_data';

    case ManageFinancialOperations = 'manage_financial_operations';

    case ViewReports = 'view_reports';
}
