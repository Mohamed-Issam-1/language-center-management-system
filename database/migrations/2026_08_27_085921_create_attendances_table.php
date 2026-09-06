<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'attendances',
            function (Blueprint $table) {
                $table->id();

                $table->foreignId(
                    'center_id'
                );

                $table->unsignedBigInteger(
                    'session_id'
                );

                $table->unsignedBigInteger(
                    'enrollment_id'
                );

                $table->unsignedBigInteger(
                    'attendance_status_id'
                );

                $table->unsignedBigInteger(
                    'recorded_by_user_id'
                );

                $table->unsignedInteger(
                    'late_minutes'
                )->default(0);

                $table->string(
                    'excuse',
                    255
                )->nullable();

                $table->text(
                    'notes'
                )->nullable();

                /*
                * Explicit CURRENT_TIMESTAMP prevents MySQL legacy
                * first-TIMESTAMP behavior from implicitly adding
                * ON UPDATE CURRENT_TIMESTAMP.
                *
                * recorded_at identifies the original Attendance
                * recording time and must never change on updates.
                */
                $table->timestamp(
                    'recorded_at'
                )->useCurrent();

                $table->timestamp(
                    'updated_at'
                )->nullable();

                $table->foreign(
                    'center_id',
                    'attendances_center_foreign'
                )
                    ->references('id')
                    ->on('centers')
                    ->restrictOnDelete();

                /*
                 * Session must belong to this same Center.
                 */
                $table->foreign(
                    [
                        'session_id',
                        'center_id',
                    ],
                    'attendances_session_center_foreign'
                )
                    ->references([
                        'id',
                        'center_id',
                    ])
                    ->on('class_sessions')
                    ->restrictOnDelete();

                /*
                 * Enrollment must belong to this same Center.
                 */
                $table->foreign(
                    [
                        'enrollment_id',
                        'center_id',
                    ],
                    'attendances_enrollment_center_foreign'
                )
                    ->references([
                        'id',
                        'center_id',
                    ])
                    ->on('enrollments')
                    ->restrictOnDelete();

                /*
                 * Attendance Status must belong to this Center.
                 */
                $table->foreign(
                    [
                        'attendance_status_id',
                        'center_id',
                    ],
                    'attendances_status_center_foreign'
                )
                    ->references([
                        'id',
                        'center_id',
                    ])
                    ->on('attendance_statuses')
                    ->restrictOnDelete();

                /*
                 * The actor recording Attendance must be a
                 * Center-scoped User Account in this same Center.
                 */
                $table->foreign(
                    [
                        'recorded_by_user_id',
                        'center_id',
                    ],
                    'attendances_recorder_center_foreign'
                )
                    ->references([
                        'id',
                        'center_id',
                    ])
                    ->on('users')
                    ->restrictOnDelete();

                /*
                 * One Enrollment may have only one Attendance
                 * record for one concrete Class Session.
                 */
                $table->unique(
                    [
                        'center_id',
                        'session_id',
                        'enrollment_id',
                    ],
                    'attendances_session_enrollment_unique'
                );

                /*
                 * Supports future Center-safe historical
                 * references.
                 */
                $table->unique(
                    [
                        'id',
                        'center_id',
                    ],
                    'attendances_id_center_unique'
                );

                $table->index(
                    [
                        'center_id',
                        'enrollment_id',
                    ],
                    'attendances_center_enrollment_index'
                );

                $table->index(
                    [
                        'center_id',
                        'attendance_status_id',
                    ],
                    'attendances_center_status_index'
                );

                $table->index(
                    [
                        'center_id',
                        'recorded_by_user_id',
                    ],
                    'attendances_center_recorder_index'
                );
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'attendances'
        );
    }
};
