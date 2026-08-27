<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'class_sessions',
            function (Blueprint $table) {
                $table->id();

                $table->foreignId(
                    'center_id'
                );

                $table->unsignedBigInteger(
                    'class_id'
                );

                $table->unsignedBigInteger(
                    'schedule_id'
                );

                $table->unsignedBigInteger(
                    'classroom_id'
                );

                $table->unsignedBigInteger(
                    'teacher_id'
                );

                $table->date(
                    'session_date'
                );

                $table->time(
                    'start_time'
                );

                $table->time(
                    'end_time'
                );

                /*
                 * Generated Sessions may not yet have a topic.
                 */
                $table->string(
                    'topic'
                )->nullable();

                $table->string(
                    'session_status',
                    20
                )->default(
                    'scheduled'
                );

                $table->string(
                    'cancellation_reason'
                )->nullable();

                $table->timestamps();

                $table->foreign(
                    'center_id',
                    'class_sessions_center_foreign'
                )
                    ->references('id')
                    ->on('centers')
                    ->restrictOnDelete();

                /*
                 * Direct Class/Center integrity.
                 */
                $table->foreign(
                    [
                        'class_id',
                        'center_id',
                    ],
                    'class_sessions_class_center_foreign'
                )
                    ->references([
                        'id',
                        'center_id',
                    ])
                    ->on('course_classes')
                    ->restrictOnDelete();

                /*
                 * Schedule, Class, and Center must all agree.
                 */
                $table->foreign(
                    [
                        'schedule_id',
                        'class_id',
                        'center_id',
                    ],
                    'class_sessions_schedule_class_center_foreign'
                )
                    ->references([
                        'id',
                        'class_id',
                        'center_id',
                    ])
                    ->on('class_schedules')
                    ->restrictOnDelete();

                $table->foreign(
                    [
                        'classroom_id',
                        'center_id',
                    ],
                    'class_sessions_classroom_center_foreign'
                )
                    ->references([
                        'id',
                        'center_id',
                    ])
                    ->on('classrooms')
                    ->restrictOnDelete();

                $table->foreign(
                    [
                        'teacher_id',
                        'center_id',
                    ],
                    'class_sessions_teacher_center_foreign'
                )
                    ->references([
                        'id',
                        'center_id',
                    ])
                    ->on('teachers')
                    ->restrictOnDelete();

                /*
                 * A weekly Schedule generates at most one Session
                 * for one calendar date.
                 */
                $table->unique(
                    [
                        'center_id',
                        'schedule_id',
                        'session_date',
                    ],
                    'class_sessions_schedule_date_unique'
                );

                /*
                 * Attendance will later require a Center-safe
                 * Session reference.
                 */
                $table->unique(
                    [
                        'id',
                        'center_id',
                    ],
                    'class_sessions_id_center_unique'
                );

                $table->index(
                    [
                        'center_id',
                        'class_id',
                        'session_date',
                        'session_status',
                    ],
                    'class_sessions_class_date_status_index'
                );

                $table->index(
                    [
                        'center_id',
                        'teacher_id',
                        'session_date',
                        'session_status',
                    ],
                    'class_sessions_teacher_date_status_index'
                );

                $table->index(
                    [
                        'center_id',
                        'classroom_id',
                        'session_date',
                        'session_status',
                    ],
                    'class_sessions_room_date_status_index'
                );
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'class_sessions'
        );
    }
};
