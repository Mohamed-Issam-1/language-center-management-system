<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'class_schedules',
            function (Blueprint $table) {
                $table->id();

                $table->foreignId(
                    'center_id'
                );

                $table->unsignedBigInteger(
                    'class_id'
                );

                $table->unsignedBigInteger(
                    'classroom_id'
                );

                $table->unsignedBigInteger(
                    'teacher_id'
                );

                /*
                 * ISO-8601 weekday:
                 * Monday = 1 ... Sunday = 7.
                 */
                $table->unsignedTinyInteger(
                    'day_of_week'
                );

                $table->time(
                    'start_time'
                );

                $table->time(
                    'end_time'
                );

                $table->date(
                    'effective_from'
                );

                $table->date(
                    'effective_until'
                );

                $table->string(
                    'status',
                    20
                )->default(
                    'active'
                );

                $table->timestamps();

                $table->foreign(
                    'center_id',
                    'class_schedules_center_foreign'
                )
                    ->references('id')
                    ->on('centers')
                    ->restrictOnDelete();

                /*
                 * Class must belong to the same Center.
                 */
                $table->foreign(
                    [
                        'class_id',
                        'center_id',
                    ],
                    'class_schedules_class_center_foreign'
                )
                    ->references([
                        'id',
                        'center_id',
                    ])
                    ->on('course_classes')
                    ->restrictOnDelete();

                /*
                 * Classroom must belong to the same Center.
                 *
                 * Exact Branch matching remains a domain/service
                 * rule because branch_id is intentionally not
                 * duplicated on class_schedules.
                 */
                $table->foreign(
                    [
                        'classroom_id',
                        'center_id',
                    ],
                    'class_schedules_classroom_center_foreign'
                )
                    ->references([
                        'id',
                        'center_id',
                    ])
                    ->on('classrooms')
                    ->restrictOnDelete();

                /*
                 * Teacher must belong to the same Center.
                 */
                $table->foreign(
                    [
                        'teacher_id',
                        'center_id',
                    ],
                    'class_schedules_teacher_center_foreign'
                )
                    ->references([
                        'id',
                        'center_id',
                    ])
                    ->on('teachers')
                    ->restrictOnDelete();

                /*
                 * Center-safe references for Sessions and later
                 * dependent modules.
                 */
                $table->unique(
                    [
                        'id',
                        'center_id',
                    ],
                    'class_schedules_id_center_unique'
                );

                /*
                 * A Session references both its Schedule and Class.
                 * This composite identity prevents a Session from
                 * pairing a Schedule with a different Class.
                 */
                $table->unique(
                    [
                        'id',
                        'class_id',
                        'center_id',
                    ],
                    'class_schedules_id_class_center_unique'
                );

                $table->index(
                    [
                        'center_id',
                        'class_id',
                        'status',
                    ],
                    'class_schedules_center_class_status_index'
                );

                $table->index(
                    [
                        'center_id',
                        'teacher_id',
                        'day_of_week',
                        'status',
                    ],
                    'class_schedules_teacher_day_status_index'
                );

                $table->index(
                    [
                        'center_id',
                        'classroom_id',
                        'day_of_week',
                        'status',
                    ],
                    'class_schedules_room_day_status_index'
                );
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'class_schedules'
        );
    }
};
