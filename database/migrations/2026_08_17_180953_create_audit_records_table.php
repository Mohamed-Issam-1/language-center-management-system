<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_records', function (Blueprint $table) {
            $table->id();

            /*
             * Platform-level records may not belong to a Center.
             */
            $table->foreignId('center_id')
                ->nullable();

            /*
             * Center-wide records may not belong to one Branch.
             */
            $table->unsignedBigInteger('branch_id')
                ->nullable();

            /*
             * The responsible authenticated User Account.
             */
            $table->foreignId('actor_user_id');

            /*
             * Snapshot of the actor's fixed role at event time.
             */
            $table->string('actor_role', 50);

            /*
             * Stable application-defined action identifier.
             *
             * Examples:
             * center.activated
             * user_account.deactivated
             * branch_manager.assigned
             */
            $table->string('action_type', 100);

            /*
             * Snapshot identifying the affected business record.
             */
            $table->string('subject_type', 100);
            $table->unsignedBigInteger('subject_id');

            /*
             * Change snapshots are nullable because not every
             * audit event represents a before/after mutation.
             */
            $table->json('before_values')
                ->nullable();

            $table->json('after_values')
                ->nullable();

            /*
             * Additional non-core context may be stored without
             * changing the audit schema for every new module.
             */
            $table->json('metadata')
                ->nullable();

            $table->timestamp('occurred_at');

            /*
             * Audit history remains associated with its Center
             * and responsible User Account.
             */
            $table->foreign(
                'center_id',
                'audit_records_center_foreign'
            )
                ->references('id')
                ->on('centers')
                ->restrictOnDelete();

            $table->foreign(
                'actor_user_id',
                'audit_records_actor_user_foreign'
            )
                ->references('id')
                ->on('users')
                ->restrictOnDelete();

            /*
             * When a Branch is present, it must belong to the
             * same Center recorded by the audit event.
             *
             * branches(id, center_id) already has the required
             * unique key from the Branch assignment foundation.
             */
            $table->foreign(
                [
                    'branch_id',
                    'center_id',
                ],
                'audit_records_branch_center_foreign'
            )
                ->references([
                    'id',
                    'center_id',
                ])
                ->on('branches')
                ->restrictOnDelete();

            $table->index(
                [
                    'center_id',
                    'occurred_at',
                ],
                'audit_records_center_time_index'
            );

            $table->index(
                [
                    'center_id',
                    'branch_id',
                    'occurred_at',
                ],
                'audit_records_branch_time_index'
            );

            $table->index(
                [
                    'actor_user_id',
                    'occurred_at',
                ],
                'audit_records_actor_time_index'
            );

            $table->index(
                [
                    'action_type',
                    'occurred_at',
                ],
                'audit_records_action_time_index'
            );
            $table->index(
                [
                    'subject_type',
                    'subject_id',
                ],
                'audit_records_subject_index'
            );
        });

        DB::statement(
            'ALTER TABLE audit_records '
                . 'ADD CONSTRAINT audit_records_branch_requires_center_check '
                . 'CHECK (branch_id IS NULL OR center_id IS NOT NULL)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_records');
    }
};
