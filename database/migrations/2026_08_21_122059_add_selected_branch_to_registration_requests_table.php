<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table(
            'registration_requests',
            function (Blueprint $table) {
                /*
                 * Selected only during administrative review.
                 *
                 * Required later by the review service for:
                 * - Branch Manager
                 * - Finance Employee
                 * - Student
                 *
                 * It remains NULL for:
                 * - Center Owner
                 * - Teacher
                 */
                $table->unsignedBigInteger(
                    'selected_branch_id'
                )
                    ->nullable()
                    ->after('selected_role_id');

                /*
                 * The selected Branch must belong to the same
                 * Center as the Registration Request.
                 *
                 * branches(id, center_id) already has the required
                 * unique key from the staff assignment foundation.
                 */
                $table->foreign(
                    [
                        'selected_branch_id',
                        'center_id',
                    ],
                    'registration_requests_branch_center_foreign'
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
                        'selected_branch_id',
                    ],
                    'registration_requests_center_branch_index'
                );
            }
        );
    }

    public function down(): void
    {
        Schema::table(
            'registration_requests',
            function (Blueprint $table) {
                $table->dropForeign(
                    'registration_requests_branch_center_foreign'
                );

                $table->dropIndex(
                    'registration_requests_center_branch_index'
                );

                $table->dropColumn(
                    'selected_branch_id'
                );
            }
        );
    }
};
