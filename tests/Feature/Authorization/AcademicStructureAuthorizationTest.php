<?php

namespace Tests\Feature\Authorization;

use App\Models\AcademicLevel;
use App\Models\Center;
use App\Models\Course;
use App\Models\Language;
use App\Models\Person;
use App\Models\Role;
use App\Models\User;
use App\Support\Enums\AccountStatus;
use App\Support\Enums\SystemPermission;
use App\Support\Enums\SystemRole;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class AcademicStructureAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(
            RoleSeeder::class
        );
    }

    public function test_only_center_owner_receives_academic_structure_management_permission(): void
    {
        $centerOwner = $this->createUserForRole(
            SystemRole::CenterOwner
        );

        $this->assertTrue(
            $centerOwner->hasPermission(
                SystemPermission::ManageAcademicStructure
            )
        );

        foreach (
            [
                SystemRole::PlatformOwner,
                SystemRole::BranchManager,
                SystemRole::FinanceEmployee,
                SystemRole::Teacher,
                SystemRole::Student,
            ] as $role
        ) {
            $user = $this->createUserForRole(
                $role
            );

            $this->assertFalse(
                $user->hasPermission(
                    SystemPermission::ManageAcademicStructure
                )
            );
        }
    }

    public function test_center_owner_can_create_academic_structure_records(): void
    {
        $owner = $this->createUserForRole(
            SystemRole::CenterOwner
        );

        $this->assertTrue(
            Gate::forUser($owner)
                ->allows(
                    'create',
                    Language::class
                )
        );

        $this->assertTrue(
            Gate::forUser($owner)
                ->allows(
                    'create',
                    AcademicLevel::class
                )
        );

        $this->assertTrue(
            Gate::forUser($owner)
                ->allows(
                    'create',
                    Course::class
                )
        );
    }

    public function test_center_owner_can_manage_academic_records_inside_own_center(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $owner = $this->createUserForRole(
            SystemRole::CenterOwner,
            $center
        );

        $language = Language::factory()
            ->for($center)
            ->create();

        $level = AcademicLevel::factory()
            ->forLanguage($language)
            ->create();

        $course = Course::factory()
            ->forAcademicLevel($level)
            ->create();

        foreach (
            [
                'update',
                'archive',
                'restore',
            ] as $ability
        ) {
            $this->assertTrue(
                Gate::forUser($owner)
                    ->allows(
                        $ability,
                        $language
                    )
            );

            $this->assertTrue(
                Gate::forUser($owner)
                    ->allows(
                        $ability,
                        $level
                    )
            );

            $this->assertTrue(
                Gate::forUser($owner)
                    ->allows(
                        $ability,
                        $course
                    )
            );
        }
    }

    public function test_center_owner_cannot_manage_academic_records_from_another_center(): void
    {
        $centerA = Center::factory()
            ->active()
            ->create();

        $centerB = Center::factory()
            ->active()
            ->create();

        $ownerA = $this->createUserForRole(
            SystemRole::CenterOwner,
            $centerA
        );

        $languageB = Language::factory()
            ->for($centerB)
            ->create();

        $levelB = AcademicLevel::factory()
            ->forLanguage($languageB)
            ->create();

        $courseB = Course::factory()
            ->forAcademicLevel($levelB)
            ->create();

        $this->assertFalse(
            Gate::forUser($ownerA)
                ->allows(
                    'update',
                    $languageB
                )
        );

        $this->assertFalse(
            Gate::forUser($ownerA)
                ->allows(
                    'archive',
                    $levelB
                )
        );

        $this->assertFalse(
            Gate::forUser($ownerA)
                ->allows(
                    'restore',
                    $courseB
                )
        );
    }

    public function test_branch_manager_cannot_manage_academic_structure_even_inside_same_center(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $manager = $this->createUserForRole(
            SystemRole::BranchManager,
            $center
        );

        $language = Language::factory()
            ->for($center)
            ->create();

        $level = AcademicLevel::factory()
            ->forLanguage($language)
            ->create();

        $course = Course::factory()
            ->forAcademicLevel($level)
            ->create();

        $this->assertFalse(
            Gate::forUser($manager)
                ->allows(
                    'create',
                    Language::class
                )
        );

        $this->assertFalse(
            Gate::forUser($manager)
                ->allows(
                    'update',
                    $language
                )
        );

        $this->assertFalse(
            Gate::forUser($manager)
                ->allows(
                    'archive',
                    $level
                )
        );

        $this->assertFalse(
            Gate::forUser($manager)
                ->allows(
                    'restore',
                    $course
                )
        );
    }

    public function test_platform_owner_cannot_manage_center_academic_catalog(): void
    {
        $platformOwner = $this->createUserForRole(
            SystemRole::PlatformOwner
        );

        $this->assertFalse(
            Gate::forUser($platformOwner)
                ->allows(
                    'create',
                    Language::class
                )
        );

        $this->assertFalse(
            Gate::forUser($platformOwner)
                ->allows(
                    'create',
                    AcademicLevel::class
                )
        );

        $this->assertFalse(
            Gate::forUser($platformOwner)
                ->allows(
                    'create',
                    Course::class
                )
        );
    }

    private function createUserForRole(
        SystemRole $role,
        ?Center $center = null
    ): User {
        if (
            $role === SystemRole::PlatformOwner
        ) {
            return User::factory()
                ->create([
                    'center_id' => null,
                    'person_id' => null,
                    'role_id' =>
                    $this->role($role)->id,
                    'status' =>
                    AccountStatus::Active,
                ]);
        }

        $center ??= Center::factory()
            ->active()
            ->create();

        $person = Person::factory()
            ->for($center)
            ->create();

        return User::factory()
            ->create([
                'center_id' => $center->id,
                'person_id' => $person->id,
                'role_id' =>
                $this->role($role)->id,
                'status' =>
                AccountStatus::Active,
            ]);
    }

    private function role(
        SystemRole $role
    ): Role {
        return Role::query()
            ->where(
                'code',
                $role->value
            )
            ->firstOrFail();
    }
}
