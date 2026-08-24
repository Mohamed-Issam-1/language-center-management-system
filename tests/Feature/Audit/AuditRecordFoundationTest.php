<?php

namespace Tests\Feature\Audit;

use App\Models\AuditRecord;
use App\Models\Branch;
use App\Models\Center;
use App\Models\User;
use App\Support\Enums\SystemRole;
use App\Support\Tenancy\BranchContext;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class AuditRecordFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_record_stores_required_history_and_casts_payloads(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $actor = User::factory()
            ->create();

        $record = AuditRecord::factory()
            ->forCenter($center)
            ->create([
                'actor_user_id' => $actor->id,
                'actor_role' =>
                SystemRole::CenterOwner->value,
                'action_type' => 'center.updated',
                'subject_type' => 'center',
                'subject_id' => $center->id,
                'before_values' => [
                    'name' => 'Old Name',
                ],
                'after_values' => [
                    'name' => 'New Name',
                ],
                'metadata' => [
                    'source' => 'test',
                ],
            ]);

        $this->assertSame(
            $center->id,
            $record->center_id
        );

        $this->assertSame(
            $actor->id,
            $record->actor_user_id
        );

        $this->assertSame(
            'center.updated',
            $record->action_type
        );

        $this->assertSame(
            [
                'name' => 'Old Name',
            ],
            $record->before_values
        );

        $this->assertSame(
            [
                'name' => 'New Name',
            ],
            $record->after_values
        );

        $this->assertSame(
            [
                'source' => 'test',
            ],
            $record->metadata
        );

        $this->assertNotNull(
            $record->occurred_at
        );
    }

    public function test_audit_record_belongs_to_actor_center_and_branch(): void
    {
        $center = Center::factory()
            ->active()
            ->create();

        $branch = Branch::factory()
            ->for($center)
            ->active()
            ->create();

        $actor = User::factory()
            ->create();

        $record = AuditRecord::factory()
            ->forBranch($branch)
            ->create([
                'actor_user_id' => $actor->id,
            ]);

        $this->assertTrue(
            $record->center->is(
                $center
            )
        );

        $this->assertTrue(
            $record->branch->is(
                $branch
            )
        );

        $this->assertTrue(
            $record->actor->is(
                $actor
            )
        );
    }

    public function test_database_rejects_branch_from_another_center(): void
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

        $actor = User::factory()
            ->create();

        $this->expectException(
            QueryException::class
        );

        AuditRecord::query()
            ->create([
                'center_id' => $centerA->id,
                'branch_id' => $branchB->id,
                'actor_user_id' => $actor->id,
                'actor_role' =>
                SystemRole::CenterOwner->value,
                'action_type' => 'branch.updated',
                'subject_type' => 'branch',
                'subject_id' => $branchB->id,
                'occurred_at' => now(),
            ]);
    }

    public function test_database_rejects_branch_audit_without_center(): void
{
    $center = Center::factory()
        ->active()
        ->create();

    $branch = Branch::factory()
        ->for($center)
        ->active()
        ->create();

    $actor = User::factory()
        ->create();

    $this->expectException(
        QueryException::class
    );

    AuditRecord::query()
        ->create([
            'center_id' => null,
            'branch_id' => $branch->id,
            'actor_user_id' => $actor->id,
            'actor_role' =>
                SystemRole::CenterOwner->value,
            'action_type' => 'branch.updated',
            'subject_type' => 'branch',
            'subject_id' => $branch->id,
            'occurred_at' => now(),
        ]);
}

    public function test_center_scope_excludes_other_center_audit_records(): void
    {
        $centerA = Center::factory()
            ->active()
            ->create();

        $centerB = Center::factory()
            ->active()
            ->create();

        $recordA = AuditRecord::factory()
            ->forCenter($centerA)
            ->create();

        $recordB = AuditRecord::factory()
            ->forCenter($centerB)
            ->create();

        app(TenantContext::class)
            ->establishCenterScope(
                $centerA
            );

        $ids = AuditRecord::query()
            ->forCurrentTenant()
            ->pluck('id')
            ->all();

        $this->assertContains(
            $recordA->id,
            $ids
        );

        $this->assertNotContains(
            $recordB->id,
            $ids
        );
    }

    public function test_branch_scope_returns_only_assigned_branch_audit_records(): void
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

        $recordA = AuditRecord::factory()
            ->forBranch($branchA)
            ->create();

        $recordB = AuditRecord::factory()
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

        $ids = AuditRecord::query()
            ->forCurrentBranch()
            ->pluck('id')
            ->all();

        $this->assertSame(
            [$recordA->id],
            $ids
        );

        $this->assertNotContains(
            $recordB->id,
            $ids
        );
    }

    public function test_center_wide_branch_scope_returns_all_audit_records_in_center(): void
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

        $recordA = AuditRecord::factory()
            ->forBranch($branchA)
            ->create();

        $recordB = AuditRecord::factory()
            ->forBranch($branchB)
            ->create();

        $centerWideRecord = AuditRecord::factory()
            ->forCenter($center)
            ->create();

        $otherCenter = Center::factory()
            ->active()
            ->create();

        $otherRecord = AuditRecord::factory()
            ->forCenter($otherCenter)
            ->create();

        app(TenantContext::class)
            ->establishCenterScope(
                $center
            );

        app(BranchContext::class)
            ->establishCenterWideScope();

        $ids = AuditRecord::query()
            ->forCurrentBranch()
            ->orderBy('id')
            ->pluck('id')
            ->all();

        $this->assertContains(
            $recordA->id,
            $ids
        );

        $this->assertContains(
            $recordB->id,
            $ids
        );

        $this->assertContains(
            $centerWideRecord->id,
            $ids
        );

        $this->assertNotContains(
            $otherRecord->id,
            $ids
        );

        $this->assertCount(
            3,
            $ids
        );
    }

    public function test_audit_record_cannot_be_modified_through_eloquent(): void
    {
        $record = AuditRecord::factory()
            ->create([
                'action_type' => 'center.created',
            ]);

        try {
            $record->update([
                'action_type' => 'tampered',
            ]);

            $this->fail(
                'Audit record modification was not rejected.'
            );
        } catch (LogicException) {
            // Expected.
        }

        $this->assertDatabaseHas(
            'audit_records',
            [
                'id' => $record->id,
                'action_type' => 'center.created',
            ]
        );
    }

    public function test_audit_record_cannot_be_deleted_through_eloquent(): void
    {
        $record = AuditRecord::factory()
            ->create();

        try {
            $record->delete();

            $this->fail(
                'Audit record deletion was not rejected.'
            );
        } catch (LogicException) {
            // Expected.
        }

        $this->assertDatabaseHas(
            'audit_records',
            [
                'id' => $record->id,
            ]
        );
    }

    public function test_actor_user_cannot_be_deleted_while_audit_history_references_account(): void
    {
        $actor = User::factory()
            ->create();

        AuditRecord::factory()
            ->create([
                'actor_user_id' => $actor->id,
            ]);

        $this->expectException(
            QueryException::class
        );

        $actor->delete();
    }
}
