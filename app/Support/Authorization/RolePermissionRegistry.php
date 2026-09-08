<?php

namespace App\Support\Authorization;

use App\Support\Enums\SystemPermission;
use App\Support\Enums\SystemRole;

final class RolePermissionRegistry
{
    /**
     * @return array<SystemPermission>
     */
    public static function permissionsFor(
        SystemRole $role
    ): array {
        return match ($role) {
            SystemRole::PlatformOwner => [
                SystemPermission::ManageCenters,
                SystemPermission::ManageCenterOwnerAccounts,
                SystemPermission::ViewReports,
            ],

            SystemRole::CenterOwner => [
                SystemPermission::ManageBranches,
                SystemPermission::ManageClassrooms,
                SystemPermission::ManageClasses,
                SystemPermission::ManageEnrollments,
                SystemPermission::ManageSchedules,
                SystemPermission::ViewSchedules,
                SystemPermission::ManageAcademicStructure,
                SystemPermission::ManageStaffAccounts,
                SystemPermission::ManageStudentAccounts,
                SystemPermission::ManageStudentRecords,
                SystemPermission::ViewAttendance,
                SystemPermission::ManageAttendanceSettings,
                SystemPermission::ViewStudentRecords,
                SystemPermission::ViewFinancialData,
                SystemPermission::ManageFinancialOperations,
                SystemPermission::ViewAuditRecords,
                SystemPermission::ViewReports,
            ],

            SystemRole::BranchManager => [
                SystemPermission::ManageClassrooms,
                SystemPermission::ManageClasses,
                SystemPermission::ManageEnrollments,
                SystemPermission::ManageSchedules,
                SystemPermission::ViewSchedules,
                SystemPermission::ManageStudentAccounts,
                SystemPermission::ManageStudentRecords,
                SystemPermission::ManageAttendance,
                SystemPermission::ViewAttendance,
                SystemPermission::ViewStudentRecords,
                SystemPermission::ViewFinancialData,
                SystemPermission::ManageFinancialOperations,
                SystemPermission::ViewAuditRecords,
                SystemPermission::ViewReports,
            ],

            SystemRole::FinanceEmployee => [
                SystemPermission::ViewFinancialData,
                SystemPermission::ManageFinancialOperations,
                SystemPermission::ViewReports,
            ],

            SystemRole::Teacher => [
                SystemPermission::ViewStudentRecords,
                SystemPermission::ViewSchedules,
                SystemPermission::ViewReports,
                SystemPermission::ManageAttendance,
                SystemPermission::ViewAttendance,
            ],

            SystemRole::Student => [
                SystemPermission::ViewStudentRecords,
                SystemPermission::ViewFinancialData,
                SystemPermission::ViewReports,
                SystemPermission::ViewAttendance,
            ],
        };
    }

    public static function allows(
        SystemRole $role,
        SystemPermission $permission
    ): bool {
        return in_array(
            $permission,
            self::permissionsFor($role),
            true
        );
    }
}