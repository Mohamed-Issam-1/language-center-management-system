<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'enrollments',
            function (Blueprint $table) {
                $table->id();

                $table->foreignId(
                    'center_id'
                );

                $table->unsignedBigInteger(
                    'student_id'
                );

                /*
                 * Kept as class_id to follow the approved
                 * Enrollment data model.
                 *
                 * It references course_classes.id.
                 */
                $table->unsignedBigInteger(
                    'class_id'
                );

                $table->string(
                    'enrollment_number',
                    50
                );

                $table->date(
                    'enrollment_date'
                );

                $table->string(
                    'enrollment_status',
                    20
                )->default(
                    'active'
                );

                /*
                 * The approved data model contains this field,
                 * but no fixed eligibility enum has been defined.
                 */
                $table->string(
                    'eligibility_status',
                    50
                );

                $table->date(
                    'withdrawal_date'
                )->nullable();

                $table->string(
                    'withdrawal_reason'
                )->nullable();

                $table->timestamps();

                $table->foreign(
                    'center_id',
                    'enrollments_center_foreign'
                )
                    ->references('id')
                    ->on('centers')
                    ->restrictOnDelete();

                /*
                 * Student must belong to the same Center.
                 */
                $table->foreign(
                    [
                        'student_id',
                        'center_id',
                    ],
                    'enrollments_student_center_foreign'
                )
                    ->references([
                        'id',
                        'center_id',
                    ])
                    ->on('students')
                    ->restrictOnDelete();

                /*
                 * Course Class must belong to the same Center.
                 */
                $table->foreign(
                    [
                        'class_id',
                        'center_id',
                    ],
                    'enrollments_class_center_foreign'
                )
                    ->references([
                        'id',
                        'center_id',
                    ])
                    ->on('course_classes')
                    ->restrictOnDelete();

                /*
                * Enrollment numbers are business identifiers scoped
                * to one Center, not globally across the platform.
                */
                $table->unique(
                    [
                        'center_id',
                        'enrollment_number',
                    ],
                    'enrollments_center_number_unique'
                );

                /*
                 * FR-190:
                 * The same Student may never have two Enrollment
                 * records for the same Class.
                 *
                 * Historical terminal status does not reopen this
                 * Student/Class combination.
                 */
                $table->unique(
                    [
                        'center_id',
                        'student_id',
                        'class_id',
                    ],
                    'enrollments_student_class_unique'
                );

                /*
                 * Supports Center-safe references from Enrollment
                 * History, Attendance, Finance, and later modules.
                 */
                $table->unique(
                    [
                        'id',
                        'center_id',
                    ],
                    'enrollments_id_center_unique'
                );

                /*
                 * Used for capacity checks.
                 */
                $table->index(
                    [
                        'center_id',
                        'class_id',
                        'enrollment_status',
                    ],
                    'enrollments_center_class_status_index'
                );

                /*
                 * Used for Student history and prerequisite checks.
                 */
                $table->index(
                    [
                        'center_id',
                        'student_id',
                        'enrollment_status',
                    ],
                    'enrollments_center_student_status_index'
                );
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'enrollments'
        );
    }
};
