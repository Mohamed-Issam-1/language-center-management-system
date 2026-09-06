<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'payments',
            function (Blueprint $table) {
                $table->id();

                $table->foreignId(
                    'center_id'
                );

                /*
                 * Payment is posted inside one operational
                 * Branch and may only allocate against Fees
                 * belonging to that exact Branch.
                 */
                $table->unsignedBigInteger(
                    'branch_id'
                );

                $table->unsignedBigInteger(
                    'student_id'
                );

                /*
                 * Receipt is represented by an immutable Payment
                 * business identifier in the MVP.
                 */
                $table->string(
                    'receipt_number',
                    50
                );

                /*
                * Client-generated operation key used to make Payment
                * submission idempotent.
                *
                * Retrying the exact financial operation must not create
                * a duplicate posted Payment.
                */
                $table->string(
                    'idempotency_key',
                    100
                );

                $table->decimal(
                    'amount',
                    12,
                    2
                );

                /*
                 * Historical currency snapshot.
                 */
                $table->string(
                    'currency_code',
                    10
                );

                /*
                 * No fixed Payment Method enum is approved yet.
                 * Validation belongs to the Finance Service.
                 */
                $table->string(
                    'payment_method',
                    50
                );

                /*
                * Payment time is an explicit financial fact supplied
                * and validated by the Finance domain service.
                *
                * DATETIME avoids MySQL's implicit first-TIMESTAMP
                * CURRENT_TIMESTAMP behavior.
                */
                $table->dateTime(
                    'paid_at'
                );

                $table->unsignedBigInteger(
                    'received_by_user_id'
                );

                $table->string(
                    'reference',
                    100
                )->nullable();

                $table->text(
                    'notes'
                )->nullable();

                $table->string(
                    'status',
                    20
                )->default(
                    'posted'
                );

                /*
                 * Posted Payments are never edited into another
                 * financial meaning.
                 *
                 * Correction is represented by reversal while
                 * preserving the original Payment.
                 */
                $table->timestamp(
                    'reversed_at'
                )->nullable();

                $table->unsignedBigInteger(
                    'reversed_by_user_id'
                )->nullable();

                $table->string(
                    'reversal_reason',
                    255
                )->nullable();

                $table->timestamps();

                $table->foreign(
                    'center_id',
                    'payments_center_foreign'
                )
                    ->references('id')
                    ->on('centers')
                    ->restrictOnDelete();

                $table->foreign(
                    [
                        'branch_id',
                        'center_id',
                    ],
                    'payments_branch_center_foreign'
                )
                    ->references([
                        'id',
                        'center_id',
                    ])
                    ->on('branches')
                    ->restrictOnDelete();

                /*
                 * Student must belong to this Center.
                 *
                 * The Student's current branch is intentionally
                 * not part of this foreign key because Finance
                 * history follows the original financial Branch,
                 * not future Student reassignment.
                 */
                $table->foreign(
                    [
                        'student_id',
                        'center_id',
                    ],
                    'payments_student_center_foreign'
                )
                    ->references([
                        'id',
                        'center_id',
                    ])
                    ->on('students')
                    ->restrictOnDelete();

                $table->foreign(
                    [
                        'received_by_user_id',
                        'center_id',
                    ],
                    'payments_receiver_center_foreign'
                )
                    ->references([
                        'id',
                        'center_id',
                    ])
                    ->on('users')
                    ->restrictOnDelete();

                $table->foreign(
                    [
                        'reversed_by_user_id',
                        'center_id',
                    ],
                    'payments_reverser_center_foreign'
                )
                    ->references([
                        'id',
                        'center_id',
                    ])
                    ->on('users')
                    ->restrictOnDelete();

                $table->unique(
                    [
                        'center_id',
                        'receipt_number',
                    ],
                    'payments_center_receipt_unique'
                );

                $table->unique(
                    [
                        'center_id',
                        'idempotency_key',
                    ],
                    'payments_center_idempotency_unique'
                );

                /*
                 * Allows Allocation to prove exact Center and
                 * Branch ownership through one composite FK.
                 */
                $table->unique(
                    [
                        'id',
                        'branch_id',
                        'center_id',
                    ],
                    'payments_id_branch_center_unique'
                );

                $table->index(
                    [
                        'center_id',
                        'branch_id',
                        'status',
                        'paid_at',
                    ],
                    'payments_branch_status_date_index'
                );

                $table->index(
                    [
                        'center_id',
                        'student_id',
                        'status',
                    ],
                    'payments_student_status_index'
                );
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'payments'
        );
    }
};
