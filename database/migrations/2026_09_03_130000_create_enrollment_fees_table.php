<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'enrollment_fees',
            function (Blueprint $table) {
                $table->id();

                $table->foreignId(
                    'center_id'
                );

                /*
                 * The financial obligation belongs to the
                 * operational Branch of the Enrollment's Class.
                 *
                 * This is a historical snapshot. It must not
                 * change merely because the Student is later
                 * assigned to another Branch.
                 */
                $table->unsignedBigInteger(
                    'branch_id'
                );

                /*
                 * MVP rule:
                 * one financial Fee belongs to one Enrollment.
                 */
                $table->unsignedBigInteger(
                    'enrollment_id'
                );

                /*
                 * Snapshot of the approved Enrollment fee.
                 *
                 * Course.default_fee may be used as the default
                 * when the Fee is created, but later Course
                 * changes must not rewrite historical Finance.
                 */
                $table->decimal(
                    'amount',
                    12,
                    2
                );

                /*
                 * Snapshot of Center.operating_currency_code.
                 */
                $table->string(
                    'currency_code',
                    10
                );

                $table->string(
                    'status',
                    20
                )->default(
                    'active'
                );

                $table->unsignedBigInteger(
                    'created_by_user_id'
                );

                /*
                 * Financial records are preserved historically.
                 *
                 * A Fee is voided rather than deleted.
                 */
                $table->unsignedBigInteger(
                    'voided_by_user_id'
                )->nullable();

                $table->timestamp(
                    'voided_at'
                )->nullable();

                $table->string(
                    'void_reason',
                    255
                )->nullable();

                $table->timestamps();

                $table->foreign(
                    'center_id',
                    'enrollment_fees_center_foreign'
                )
                    ->references('id')
                    ->on('centers')
                    ->restrictOnDelete();

                /*
                 * Branch must belong to the same Center.
                 */
                $table->foreign(
                    [
                        'branch_id',
                        'center_id',
                    ],
                    'enrollment_fees_branch_center_foreign'
                )
                    ->references([
                        'id',
                        'center_id',
                    ])
                    ->on('branches')
                    ->restrictOnDelete();

                /*
                 * Enrollment must belong to the same Center.
                 *
                 * Matching the Fee Branch to the Enrollment
                 * Class Branch remains a Finance Service rule
                 * because Enrollment does not persist branch_id.
                 */
                $table->foreign(
                    [
                        'enrollment_id',
                        'center_id',
                    ],
                    'enrollment_fees_enrollment_center_foreign'
                )
                    ->references([
                        'id',
                        'center_id',
                    ])
                    ->on('enrollments')
                    ->restrictOnDelete();

                /*
                 * Financial actors must be accounts from this
                 * same Center.
                 */
                $table->foreign(
                    [
                        'created_by_user_id',
                        'center_id',
                    ],
                    'enrollment_fees_creator_center_foreign'
                )
                    ->references([
                        'id',
                        'center_id',
                    ])
                    ->on('users')
                    ->restrictOnDelete();

                $table->foreign(
                    [
                        'voided_by_user_id',
                        'center_id',
                    ],
                    'enrollment_fees_voider_center_foreign'
                )
                    ->references([
                        'id',
                        'center_id',
                    ])
                    ->on('users')
                    ->restrictOnDelete();

                /*
                 * One Enrollment receives at most one Fee in
                 * the MVP.
                 *
                 * Voiding a Fee does not reopen the Enrollment
                 * for a second historical Fee record.
                 */
                $table->unique(
                    [
                        'center_id',
                        'enrollment_id',
                    ],
                    'enrollment_fees_center_enrollment_unique'
                );

                /*
                 * Supports Branch-safe child references from
                 * Fee Installments.
                 */
                $table->unique(
                    [
                        'id',
                        'branch_id',
                        'center_id',
                    ],
                    'enrollment_fees_id_branch_center_unique'
                );

                $table->index(
                    [
                        'center_id',
                        'branch_id',
                        'status',
                    ],
                    'enrollment_fees_center_branch_status_index'
                );

                $table->index(
                    [
                        'center_id',
                        'status',
                    ],
                    'enrollment_fees_center_status_index'
                );
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'enrollment_fees'
        );
    }
};
