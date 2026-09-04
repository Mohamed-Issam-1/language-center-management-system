<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'fee_installments',
            function (Blueprint $table) {
                $table->id();

                $table->foreignId(
                    'center_id'
                );

                $table->unsignedBigInteger(
                    'branch_id'
                );

                $table->unsignedBigInteger(
                    'enrollment_fee_id'
                );

                /*
                 * Installments are ordered inside one Fee.
                 */
                $table->unsignedSmallInteger(
                    'sequence_number'
                );

                $table->date(
                    'due_date'
                );

                $table->decimal(
                    'amount',
                    12,
                    2
                );

                $table->timestamps();

                $table->foreign(
                    'center_id',
                    'fee_installments_center_foreign'
                )
                    ->references('id')
                    ->on('centers')
                    ->restrictOnDelete();

                $table->foreign(
                    [
                        'branch_id',
                        'center_id',
                    ],
                    'fee_installments_branch_center_foreign'
                )
                    ->references([
                        'id',
                        'center_id',
                    ])
                    ->on('branches')
                    ->restrictOnDelete();

                /*
                 * The Installment is forced to remain in the
                 * exact same Center and Branch as its Fee.
                 */
                $table->foreign(
                    [
                        'enrollment_fee_id',
                        'branch_id',
                        'center_id',
                    ],
                    'fee_installments_fee_branch_center_foreign'
                )
                    ->references([
                        'id',
                        'branch_id',
                        'center_id',
                    ])
                    ->on('enrollment_fees')
                    ->restrictOnDelete();

                $table->unique(
                    [
                        'center_id',
                        'enrollment_fee_id',
                        'sequence_number',
                    ],
                    'fee_installments_fee_sequence_unique'
                );

                /*
                 * Supports exact Branch-safe references from
                 * Payment Allocation.
                 */
                $table->unique(
                    [
                        'id',
                        'branch_id',
                        'center_id',
                    ],
                    'fee_installments_id_branch_center_unique'
                );

                $table->index(
                    [
                        'center_id',
                        'branch_id',
                        'due_date',
                    ],
                    'fee_installments_branch_due_index'
                );

                $table->index(
                    [
                        'center_id',
                        'enrollment_fee_id',
                    ],
                    'fee_installments_center_fee_index'
                );
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'fee_installments'
        );
    }
};
