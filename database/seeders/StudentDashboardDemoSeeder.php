<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Center;
use App\Models\Person;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use App\Support\Enums\AccountStatus;
use App\Support\Enums\StudentStatus;
use App\Support\Enums\SystemRole;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use App\Support\Enums\BranchStatus;
use RuntimeException;

class StudentDashboardDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException(
                'StudentDashboardDemoSeeder cannot run in production.'
            );
        }

        DB::transaction(function (): void {
            $center = Center::query()
                ->orderBy('id')
                ->first();

            if ($center === null) {
                throw new RuntimeException(
                    'No Center exists in the database.'
                );
            }

            $branch = Branch::withoutGlobalScopes()
                ->where('center_id', $center->id)
                ->orderBy('id')
                ->first();

            if ($branch === null) {
                $branch = Branch::withoutGlobalScopes()
                    ->create([
                        'center_id' => $center->id,
                        'name' => 'Main Branch',
                        'code' => 'MAIN',
                        'phone' => '0590000000',
                        'email' => 'main.branch@lcms.test',
                        'address' => 'Main Branch',
                        'working_hours' => [
                            'monday' => [
                                'opens_at' => '08:00',
                                'closes_at' => '20:00',
                            ],
                            'tuesday' => [
                                'opens_at' => '08:00',
                                'closes_at' => '20:00',
                            ],
                            'wednesday' => [
                                'opens_at' => '08:00',
                                'closes_at' => '20:00',
                            ],
                            'thursday' => [
                                'opens_at' => '08:00',
                                'closes_at' => '20:00',
                            ],
                            'friday' => [
                                'opens_at' => '08:00',
                                'closes_at' => '20:00',
                            ],
                            'saturday' => [
                                'opens_at' => '08:00',
                                'closes_at' => '20:00',
                            ],
                            'sunday' => [
                                'opens_at' => '08:00',
                                'closes_at' => '20:00',
                            ],
                        ],
                        'status' => BranchStatus::Active,
                    ]);
            }

            $studentRole = Role::query()
                ->where(
                    'code',
                    SystemRole::Student->value
                )
                ->first();

            if ($studentRole === null) {
                throw new RuntimeException(
                    'Student role does not exist.'
                );
            }

            $email = 'student.dashboard.demo@lcms.test';
            $loginIdentifier = '99000001';

            $person = Person::withoutGlobalScopes()
                ->firstOrCreate(
                    [
                        'center_id' => $center->id,
                        'email' => $email,
                    ],
                    [
                        'national_id_number' => '990000001',
                        'full_name' => 'Mohammad Demo Student',
                        'date_of_birth' => '2003-01-15',
                        'city_of_residence' => 'Gaza',
                        'phone_number' => '0590000000',
                    ]
                );

            $user = User::withoutGlobalScopes()
                ->where(
                    'account_login_identifier',
                    $loginIdentifier
                )
                ->first();

            if ($user === null) {
                $user = User::withoutGlobalScopes()
                    ->create([
                        'name' => $person->full_name,
                        'email' => $email,
                        'center_id' => $center->id,
                        'person_id' => $person->id,
                        'role_id' => $studentRole->id,
                        'account_login_identifier' =>
                        $loginIdentifier,
                        'recovery_email' => $email,
                        'status' => AccountStatus::Active,
                        'password' => Hash::make(
                            'Student@12345'
                        ),
                    ]);

                $user->forceFill([
                    'email_verified_at' => now(),
                    'must_change_password' => false,
                    'password_changed_at' => now(),
                    'locked_until' => null,
                    'deactivated_at' => null,
                ])->save();
            } else {
                if (
                    $user->center_id !== $center->id
                    || $user->person_id !== $person->id
                ) {
                    throw new RuntimeException(
                        'Login identifier 99000001 already belongs to another account.'
                    );
                }

                $user->forceFill([
                    'role_id' => $studentRole->id,
                    'status' => AccountStatus::Active,
                    'password' => Hash::make(
                        'Student@12345'
                    ),
                    'email_verified_at' => now(),
                    'must_change_password' => false,
                    'locked_until' => null,
                    'deactivated_at' => null,
                ])->save();
            }

            $student = Student::withoutGlobalScopes()
                ->where('center_id', $center->id)
                ->where('person_id', $person->id)
                ->first();

            if ($student === null) {
                Student::withoutGlobalScopes()
                    ->create([
                        'center_id' => $center->id,
                        'branch_id' => $branch->id,
                        'person_id' => $person->id,
                        'user_id' => $user->id,
                        'status' => StudentStatus::Active,
                        'archived_at' => null,
                    ]);
            } else {
                if (
                    $student->user_id !== null
                    && $student->user_id !== $user->id
                ) {
                    throw new RuntimeException(
                        'Demo Person is already linked to another User.'
                    );
                }

                $student->forceFill([
                    'branch_id' => $branch->id,
                    'user_id' => $user->id,
                    'status' => StudentStatus::Active,
                    'archived_at' => null,
                ])->save();
            }

            $this->command?->newLine();
            $this->command?->info(
                'Student dashboard demo account created.'
            );
            $this->command?->line(
                "Center: {$center->name}"
            );
            $this->command?->line(
                "Branch: {$branch->name}"
            );
            $this->command?->line(
                "Login ID: {$loginIdentifier}"
            );
            $this->command?->line(
                'Password: Student@12345'
            );
        });
    }
}
