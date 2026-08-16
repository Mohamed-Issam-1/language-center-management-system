<?php

namespace Tests\Feature\Tenancy;

use App\Models\Branch;
use App\Models\Center;
use App\Support\Enums\BranchStatus;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BranchFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_branch_belongs_to_exactly_one_center(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $branch = Branch::factory()
            ->for($center)
            ->create();

        $this->assertTrue(
            $branch->center->is($center)
        );

        $this->assertSame(
            $center->id,
            $branch->center_id
        );
    }

    public function test_branch_status_is_cast_to_branch_status_enum(): void
    {
        $branch = Branch::factory()
            ->deactivated()
            ->create();

        $this->assertSame(
            BranchStatus::Deactivated,
            $branch->status
        );

        $this->assertFalse(
            $branch->isActive()
        );
    }

    public function test_active_branch_reports_itself_as_active(): void
    {
        $branch = Branch::factory()
            ->active()
            ->create();

        $this->assertSame(
            BranchStatus::Active,
            $branch->status
        );

        $this->assertTrue(
            $branch->isActive()
        );
    }

    public function test_branch_working_hours_are_stored_as_structured_json(): void
    {
        $workingHours = [
            'monday' => [
                'opens_at' => '08:00',
                'closes_at' => '16:00',
            ],
            'tuesday' => [
                'opens_at' => '09:00',
                'closes_at' => '17:00',
            ],
        ];

        $branch = Branch::factory()
            ->create([
                'working_hours' => $workingHours,
            ]);

        $branch->refresh();

        $this->assertSame(
            $workingHours,
            $branch->working_hours
        );
    }

    public function test_branch_code_must_be_unique_inside_the_same_center(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        Branch::factory()
            ->for($center)
            ->create([
                'code' => 'GAZA-01',
            ]);

        $this->expectException(
            QueryException::class
        );

        Branch::factory()
            ->for($center)
            ->create([
                'code' => 'GAZA-01',
            ]);
    }

    public function test_same_branch_code_can_exist_in_different_centers(): void
    {
        $centerA = Center::factory()
            ->active()
            ->create();

        $centerB = Center::factory()
            ->active()
            ->create();

        $branchA = Branch::factory()
            ->for($centerA)
            ->create([
                'code' => 'MAIN',
            ]);

        $branchB = Branch::factory()
            ->for($centerB)
            ->create([
                'code' => 'MAIN',
            ]);

        $this->assertNotSame(
            $branchA->center_id,
            $branchB->center_id
        );

        $this->assertSame(
            $branchA->code,
            $branchB->code
        );
    }

    public function test_branch_queries_can_be_restricted_to_current_center(): void
    {
        $centerA = Center::factory()
            ->active()
            ->create();

        $centerB = Center::factory()
            ->active()
            ->create();

        $branchA = Branch::factory()
            ->for($centerA)
            ->create();

        $branchB = Branch::factory()
            ->for($centerB)
            ->create();

        app(TenantContext::class)
            ->establishCenterScope($centerA);

        $ids = Branch::query()
            ->forCurrentTenant()
            ->pluck('id')
            ->all();

        $this->assertContains(
            $branchA->id,
            $ids
        );

        $this->assertNotContains(
            $branchB->id,
            $ids
        );

        $this->assertCount(
            1,
            $ids
        );
    }

    public function test_deactivating_branch_preserves_the_branch_record(): void
    {
        $branch = Branch::factory()
            ->active()
            ->create();

        $branch->update([
            'status' => BranchStatus::Deactivated,
        ]);

        $this->assertDatabaseHas(
            'branches',
            [
                'id' => $branch->id,
                'center_id' => $branch->center_id,
                'status' => BranchStatus::Deactivated->value,
            ]
        );

        $branch->refresh();

        $this->assertSame(
            BranchStatus::Deactivated,
            $branch->status
        );

        $this->assertFalse(
            $branch->isActive()
        );
    }

    public function test_center_exposes_its_own_branches_through_relationship(): void
    {
        $centerA = Center::factory()
            ->active()
            ->create();

        $centerB = Center::factory()
            ->active()
            ->create();

        $branchA = Branch::factory()
            ->for($centerA)
            ->create();

        $branchB = Branch::factory()
            ->for($centerB)
            ->create();

        $ids = $centerA
            ->branches()
            ->pluck('id')
            ->all();

        $this->assertContains(
            $branchA->id,
            $ids
        );

        $this->assertNotContains(
            $branchB->id,
            $ids
        );
    }
}
