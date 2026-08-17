<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * Composite indexes allow assignment tables to enforce
         * that both the User Account and Branch belong to the
         * same Center stored on the assignment.
         */
        Schema::table('users', function (Blueprint $table) {
            $table->unique(
                ['id', 'center_id'],
                'users_id_center_unique'
            );
        });

        Schema::table('branches', function (Blueprint $table) {
            $table->unique(
                ['id', 'center_id'],
                'branches_id_center_unique'
            );
        });

        Schema::create(
            'branch_manager_assignments',
            function (Blueprint $table) {
                $table->id();

                $table->foreignId('center_id');

                $table->unsignedBigInteger('user_id');

                $table->unsignedBigInteger('branch_id');

                $table->timestamp('started_at');

                $table->timestamp('ended_at')
                    ->nullable();

                /*
                 * Active row:
                 * active_marker = 1
                 *
                 * Historical row:
                 * active_marker = NULL
                 *
                 * MySQL permits multiple NULL values in a unique
                 * index, allowing unlimited historical records
                 * while enforcing one current assignment.
                 */
                $table->unsignedTinyInteger(
                    'active_marker'
                )->nullable();

                $table->timestamps();

                $table->foreign(
                    ['user_id', 'center_id'],
                    'bm_assignments_user_center_foreign'
                )
                    ->references([
                        'id',
                        'center_id',
                    ])
                    ->on('users')
                    ->restrictOnDelete();

                $table->foreign(
                    ['branch_id', 'center_id'],
                    'bm_assignments_branch_center_foreign'
                )
                    ->references([
                        'id',
                        'center_id',
                    ])
                    ->on('branches')
                    ->restrictOnDelete();

                /*
                 * One active Branch assignment per
                 * Branch Manager account.
                 */
                $table->unique(
                    [
                        'user_id',
                        'active_marker',
                    ],
                    'bm_assignments_active_user_unique'
                );

                /*
                 * One active Branch Manager per Branch.
                 */
                $table->unique(
                    [
                        'branch_id',
                        'active_marker',
                    ],
                    'bm_assignments_active_branch_unique'
                );

                $table->index(
                    [
                        'center_id',
                        'branch_id',
                    ],
                    'bm_assignments_center_branch_index'
                );

                $table->index(
                    [
                        'center_id',
                        'ended_at',
                    ],
                    'bm_assignments_center_ended_index'
                );
            }
        );

        Schema::create(
            'finance_employee_assignments',
            function (Blueprint $table) {
                $table->id();

                $table->foreignId('center_id');

                $table->unsignedBigInteger('user_id');

                $table->unsignedBigInteger('branch_id');

                $table->timestamp('started_at');

                $table->timestamp('ended_at')
                    ->nullable();

                $table->unsignedTinyInteger(
                    'active_marker'
                )->nullable();

                $table->timestamps();

                $table->foreign(
                    ['user_id', 'center_id'],
                    'fe_assignments_user_center_foreign'
                )
                    ->references([
                        'id',
                        'center_id',
                    ])
                    ->on('users')
                    ->restrictOnDelete();

                $table->foreign(
                    ['branch_id', 'center_id'],
                    'fe_assignments_branch_center_foreign'
                )
                    ->references([
                        'id',
                        'center_id',
                    ])
                    ->on('branches')
                    ->restrictOnDelete();

                /*
                 * A Finance Employee account may have only
                 * one active Branch assignment.
                 */
                $table->unique(
                    [
                        'user_id',
                        'active_marker',
                    ],
                    'fe_assignments_active_user_unique'
                );

                /*
                 * Deliberately NO unique constraint on:
                 *
                 * branch_id + active_marker
                 *
                 * because a Branch may contain multiple active
                 * Finance Employees.
                 */
                $table->index(
                    [
                        'branch_id',
                        'active_marker',
                    ],
                    'fe_assignments_branch_active_index'
                );

                $table->index(
                    [
                        'center_id',
                        'ended_at',
                    ],
                    'fe_assignments_center_ended_index'
                );
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'finance_employee_assignments'
        );

        Schema::dropIfExists(
            'branch_manager_assignments'
        );

        Schema::table('branches', function (Blueprint $table) {
            $table->dropUnique(
                'branches_id_center_unique'
            );
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(
                'users_id_center_unique'
            );
        });
    }
};
