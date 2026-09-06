<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'account_identifier_sequences',
            function (Blueprint $table) {
                $table->id();

                /*
                 * Snapshot of the immutable two-digit Center
                 * identifier used in generated account IDs.
                 *
                 * Platform scope reserves 00.
                 */
                $table->char(
                    'center_identifier_code',
                    2
                );

                /*
                 * Stable SystemRole enum value.
                 *
                 * Examples:
                 * teacher
                 * student
                 * finance_employee
                 */
                $table->string(
                    'role_code',
                    50
                );

                /*
                 * Last allocated logical sequence.
                 *
                 * Student may reach 79,992 because its sequence
                 * spans role-domain blocks 13 through 20.
                 */
                $table->unsignedInteger(
                    'last_sequence'
                )->default(0);

                $table->timestamps();

                $table->unique(
                    [
                        'center_identifier_code',
                        'role_code',
                    ],
                    'account_identifier_sequences_scope_unique'
                );
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'account_identifier_sequences'
        );
    }
};
