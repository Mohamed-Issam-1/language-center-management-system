<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'attendance_statuses',
            function (Blueprint $table) {
                $table->id();

                $table->foreignId(
                    'center_id'
                );

                $table->string(
                    'name',
                    100
                );

                $table->string(
                    'code',
                    50
                );

                /*
                 * Defines how much this Attendance Status
                 * contributes to the attendance percentage.
                 *
                 * Examples:
                 * Present = 100.00
                 * Absent = 0.00
                 *
                 * The allowed business range will be validated
                 * by the management service.
                 */
                $table->decimal(
                    'contribution_value',
                    5,
                    2
                );

                $table->boolean(
                    'is_active'
                )->default(true);

                $table->timestamps();

                $table->foreign(
                    'center_id',
                    'attendance_statuses_center_foreign'
                )
                    ->references('id')
                    ->on('centers')
                    ->restrictOnDelete();

                /*
                 * Status codes are Center-specific.
                 */
                $table->unique(
                    [
                        'center_id',
                        'code',
                    ],
                    'attendance_statuses_center_code_unique'
                );

                /*
                 * Allows Center-safe references from Attendance.
                 */
                $table->unique(
                    [
                        'id',
                        'center_id',
                    ],
                    'attendance_statuses_id_center_unique'
                );

                $table->index(
                    [
                        'center_id',
                        'is_active',
                    ],
                    'attendance_statuses_center_active_index'
                );
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'attendance_statuses'
        );
    }
};
