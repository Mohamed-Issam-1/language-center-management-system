<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'payment_allocations',
            function (Blueprint $table) {
                $table->id();

                $table->foreignId(
                    'center_id'
                );

                $table->unsignedBigInteger(
                    'branch_id'
                );

                $table->unsignedBigInteger(
                    'payment_id'
                );

                $table->unsignedBigInteger(
                    'fee_installment_id'
                );

                $table->decimal(
                    'amount',
                    12,
                    2
                );

                $table->timestamps();

                $table->foreign(
                    'center_id',
                    'payment_allocations_center_foreign'
                )
                    ->references('id')
                    ->on('centers')
                    ->restrictOnDelete();

                $table->foreign(
                    [
                        'branch_id',
                        'center_id',
                    ],
                    'payment_allocations_branch_center_foreign'
                )
                    ->references([
                        'id',
                        'center_id',
                    ])
                    ->on('branches')
                    ->restrictOnDelete();

                /*
                 * Allocation must belong to the exact same
                 * Center and Branch as its Payment.
                 */
                $table->foreign(
                    [
                        'payment_id',
                        'branch_id',
                        'center_id',
                    ],
                    'payment_allocations_payment_branch_center_foreign'
                )
                    ->references([
                        'id',
                        'branch_id',
                        'center_id',
                    ])
                    ->on('payments')
                    ->restrictOnDelete();

                /*
                 * Allocation must also belong to the exact same
                 * Center and Branch as its Fee Installment.
                 *
                 * This prevents cross-branch allocation at the
                 * database layer.
                 */
                $table->foreign(
                    [
                        'fee_installment_id',
                        'branch_id',
                        'center_id',
                    ],
                    'payment_allocations_installment_branch_center_foreign'
                )
                    ->references([
                        'id',
                        'branch_id',
                        'center_id',
                    ])
                    ->on('fee_installments')
                    ->restrictOnDelete();

                /*
                 * One Payment/Installment pair has one Allocation
                 * row. Additional amount belongs in that row,
                 * not duplicated rows.
                 */
                $table->unique(
                    [
                        'center_id',
                        'payment_id',
                        'fee_installment_id',
                    ],
                    'payment_allocations_payment_installment_unique'
                );

                $table->unique(
                    [
                        'id',
                        'branch_id',
                        'center_id',
                    ],
                    'payment_allocations_id_branch_center_unique'
                );

                $table->index(
                    [
                        'center_id',
                        'branch_id',
                        'fee_installment_id',
                    ],
                    'payment_allocations_installment_index'
                );

                $table->index(
                    [
                        'center_id',
                        'payment_id',
                    ],
                    'payment_allocations_payment_index'
                );
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'payment_allocations'
        );
    }
};
