<?php

namespace Tests\Feature\Tenancy;

use App\Models\Branch;
use App\Models\Center;
use App\Models\Classroom;
use App\Support\Enums\ClassroomAvailabilityStatus;
use App\Support\Enums\ClassroomStatus;
use App\Support\Tenancy\BranchContext;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClassroomFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_classroom_can_be_created_with_required_domain_fields(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $branch = Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $classroom = Classroom::factory()
            ->forBranch($branch)
            ->create([
                'name' => 'Classroom 101',
                'code' => 'CR-101',
                'capacity' => 25,
                'location' => 'First Floor',
                'availability_status' =>
                ClassroomAvailabilityStatus::Available,
                'status' =>
                ClassroomStatus::Active,
            ]);

        $this->assertSame(
            $center->id,
            $classroom->center_id
        );

        $this->assertSame(
            $branch->id,
            $classroom->branch_id
        );

        $this->assertSame(
            'Classroom 101',
            $classroom->name
        );

        $this->assertSame(
            'CR-101',
            $classroom->code
        );

        $this->assertSame(
            25,
            $classroom->capacity
        );

        $this->assertSame(
            'First Floor',
            $classroom->location
        );
    }

    public function test_classroom_casts_status_and_availability_to_enums(): void
    {
        $classroom = Classroom::factory()
            ->active()
            ->available()
            ->create();

        $this->assertSame(
            ClassroomStatus::Active,
            $classroom->status
        );

        $this->assertSame(
            ClassroomAvailabilityStatus::Available,
            $classroom->availability_status
        );

        $this->assertTrue(
            $classroom->isActive()
        );

        $this->assertTrue(
            $classroom->isAvailable()
        );
    }

    public function test_classroom_can_be_deactivated_and_unavailable(): void
    {
        $classroom = Classroom::factory()
            ->deactivated()
            ->unavailable()
            ->create();

        $this->assertSame(
            ClassroomStatus::Deactivated,
            $classroom->status
        );

        $this->assertSame(
            ClassroomAvailabilityStatus::Unavailable,
            $classroom->availability_status
        );

        $this->assertFalse(
            $classroom->isActive()
        );

        $this->assertFalse(
            $classroom->isAvailable()
        );
    }

    public function test_classroom_belongs_to_center_and_branch(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $branch = Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $classroom = Classroom::factory()
            ->forBranch($branch)
            ->create();

        $this->assertTrue(
            $classroom->center->is(
                $center
            )
        );

        $this->assertTrue(
            $classroom->branch->is(
                $branch
            )
        );

        $this->assertTrue(
            $center->classrooms
                ->contains($classroom)
        );

        $this->assertTrue(
            $branch->classrooms
                ->contains($classroom)
        );
    }

    public function test_database_rejects_classroom_branch_from_another_center(): void
    {
        $centerA = Center::factory()
            ->active()
            ->create();

        $centerB = Center::factory()
            ->active()
            ->create();

        $branchB = Branch::factory()
            ->for($centerB)
            ->active()
            ->create();

        $this->expectException(
            QueryException::class
        );

        Classroom::query()
            ->create([
                'center_id' => $centerA->id,
                'branch_id' => $branchB->id,
                'name' => 'Invalid Classroom',
                'code' => 'INVALID-1',
                'capacity' => 20,
                'location' => 'Invalid',
                'availability_status' =>
                ClassroomAvailabilityStatus::Available,
                'status' =>
                ClassroomStatus::Active,
            ]);
    }

    public function test_classroom_center_scope_excludes_other_centers(): void
    {
        $centerA = Center::factory()
            ->active()
            ->create();

        $branchA = Branch::factory()
            ->for($centerA)
            ->active()
            ->create();

        $classroomA = Classroom::factory()
            ->forBranch($branchA)
            ->create();

        $centerB = Center::factory()
            ->active()
            ->create();

        $branchB = Branch::factory()
            ->for($centerB)
            ->active()
            ->create();

        $classroomB = Classroom::factory()
            ->forBranch($branchB)
            ->create();

        app(TenantContext::class)
            ->establishCenterScope(
                $centerA
            );

        $ids = Classroom::query()
            ->forCurrentTenant()
            ->pluck('id')
            ->all();

        $this->assertContains(
            $classroomA->id,
            $ids
        );

        $this->assertNotContains(
            $classroomB->id,
            $ids
        );
    }

    public function test_classroom_branch_scope_restricts_to_assigned_branch(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $branchA = Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $branchB = Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $classroomA = Classroom::factory()
            ->forBranch($branchA)
            ->create();

        $classroomB = Classroom::factory()
            ->forBranch($branchB)
            ->create();

        app(TenantContext::class)
            ->establishCenterScope(
                $center
            );

        app(BranchContext::class)
            ->establishBranchScope(
                $branchA
            );

        $ids = Classroom::query()
            ->forCurrentBranch()
            ->pluck('id')
            ->all();

        $this->assertSame(
            [$classroomA->id],
            $ids
        );

        $this->assertNotContains(
            $classroomB->id,
            $ids
        );
    }

    public function test_center_wide_branch_context_returns_all_classrooms_in_current_center(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $branchA = Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $branchB = Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $classroomA = Classroom::factory()
            ->forBranch($branchA)
            ->create();

        $classroomB = Classroom::factory()
            ->forBranch($branchB)
            ->create();

        $otherCenter = Center::factory()
            ->active()
            ->create();

        $otherBranch = Branch::factory()
            ->for($otherCenter)
            ->active()
            ->create();

        $otherClassroom = Classroom::factory()
            ->forBranch($otherBranch)
            ->create();

        app(TenantContext::class)
            ->establishCenterScope(
                $center
            );

        app(BranchContext::class)
            ->establishCenterWideScope();

        $ids = Classroom::query()
            ->forCurrentBranch()
            ->orderBy('id')
            ->pluck('id')
            ->all();

        $this->assertContains(
            $classroomA->id,
            $ids
        );

        $this->assertContains(
            $classroomB->id,
            $ids
        );

        $this->assertNotContains(
            $otherClassroom->id,
            $ids
        );

        $this->assertCount(
            2,
            $ids
        );
    }
}
