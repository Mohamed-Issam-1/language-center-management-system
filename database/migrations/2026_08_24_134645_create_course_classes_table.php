<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'course_classes',
            function (Blueprint $table) {
                $table->id();

                $table->foreignId(
                    'center_id'
                );

                $table->unsignedBigInteger(
                    'branch_id'
                );

                $table->unsignedBigInteger(
                    'course_id'
                );

                $table->unsignedBigInteger(
                    'assigned_classroom_id'
                );

                $table->unsignedBigInteger(
                    'assigned_teacher_id'
                );

                $table->string(
                    'class_code',
                    50
                );

                $table->string(
                    'name'
                );

                $table->date(
                    'start_date'
                );

                $table->date(
                    'end_date'
                );

                $table->unsignedInteger(
                    'capacity'
                );

                /*
                 * The approved model contains delivery_mode but
                 * currently defines no fixed enum values.
                 */
                $table->string(
                    'delivery_mode',
                    50
                );

                $table->string(
                    'class_status',
                    20
                )->default(
                    'planned'
                );

                $table->timestamps();

                /*
                 * Direct tenant ownership.
                 */
                $table->foreign(
                    'center_id',
                    'course_classes_center_foreign'
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
                    'course_classes_branch_center_foreign'
                )
                    ->references([
                        'id',
                        'center_id',
                    ])
                    ->on('branches')
                    ->restrictOnDelete();

                /*
                 * Course must belong to the same Center.
                 */
                $table->foreign(
                    [
                        'course_id',
                        'center_id',
                    ],
                    'course_classes_course_center_foreign'
                )
                    ->references([
                        'id',
                        'center_id',
                    ])
                    ->on('courses')
                    ->restrictOnDelete();

                /*
                 * Classroom must belong to both the same Branch
                 * and the same Center.
                 */
                $table->foreign(
                    [
                        'assigned_classroom_id',
                        'branch_id',
                        'center_id',
                    ],
                    'course_classes_classroom_branch_center_foreign'
                )
                    ->references([
                        'id',
                        'branch_id',
                        'center_id',
                    ])
                    ->on('classrooms')
                    ->restrictOnDelete();

                /*
                 * Teacher operational record must belong to the
                 * same Center.
                 */
                $table->foreign(
                    [
                        'assigned_teacher_id',
                        'center_id',
                    ],
                    'course_classes_teacher_center_foreign'
                )
                    ->references([
                        'id',
                        'center_id',
                    ])
                    ->on('teachers')
                    ->restrictOnDelete();

                /*
                 * Class codes identify Classes inside one Center.
                 */
                $table->unique(
                    [
                        'center_id',
                        'class_code',
                    ],
                    'course_classes_center_class_code_unique'
                );

                /*
                 * Useful for later Center-safe references from
                 * Enrollment, Schedule, Exam, and other modules.
                 */
                $table->unique(
                    [
                        'id',
                        'center_id',
                    ],
                    'course_classes_id_center_unique'
                );

                $table->index(
                    [
                        'center_id',
                        'branch_id',
                        'class_status',
                    ],
                    'course_classes_center_branch_status_index'
                );

                $table->index(
                    [
                        'center_id',
                        'course_id',
                        'class_status',
                    ],
                    'course_classes_center_course_status_index'
                );

                $table->index(
                    [
                        'center_id',
                        'assigned_teacher_id',
                        'class_status',
                    ],
                    'course_classes_center_teacher_status_index'
                );

                $table->index(
                    [
                        'center_id',
                        'assigned_classroom_id',
                    ],
                    'course_classes_center_classroom_index'
                );
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'course_classes'
        );
    }
};
