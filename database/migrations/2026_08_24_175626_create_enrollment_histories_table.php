<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'enrollment_histories',
            function (Blueprint $table) {
                $table->id();

                /*
                 * Added intentionally for tenant-safe foreign
                 * keys throughout the historical record.
                 */
                $table->foreignId(
                    'center_id'
                );

                $table->unsignedBigInteger(
                    'enrollment_id'
                );

                $table->unsignedBigInteger(
                    'from_class_id'
                )->nullable();

                $table->unsignedBigInteger(
                    'to_class_id'
                )->nullable();

                $table->unsignedBigInteger(
                    'performed_by_user_id'
                );

                $table->string(
                    'event_type',
                    50
                );

                $table->string(
                    'previous_status',
                    20
                )->nullable();

                $table->string(
                    'new_status',
                    20
                );

                $table->text(
                    'notes'
                )->nullable();

                $table->timestamp(
                    'occurred_at'
                );

                $table->foreign(
                    'center_id',
                    'enrollment_histories_center_foreign'
                )
                    ->references('id')
                    ->on('centers')
                    ->restrictOnDelete();

                /*
                 * History may reference only an Enrollment
                 * belonging to this Center.
                 */
                $table->foreign(
                    [
                        'enrollment_id',
                        'center_id',
                    ],
                    'enrollment_histories_enrollment_center_foreign'
                )
                    ->references([
                        'id',
                        'center_id',
                    ])
                    ->on('enrollments')
                    ->restrictOnDelete();

                /*
                 * Transfer source and destination Classes remain
                 * optional because not every history event is a
                 * transfer.
                 */
                $table->foreign(
                    [
                        'from_class_id',
                        'center_id',
                    ],
                    'enrollment_histories_from_class_center_foreign'
                )
                    ->references([
                        'id',
                        'center_id',
                    ])
                    ->on('course_classes')
                    ->restrictOnDelete();

                $table->foreign(
                    [
                        'to_class_id',
                        'center_id',
                    ],
                    'enrollment_histories_to_class_center_foreign'
                )
                    ->references([
                        'id',
                        'center_id',
                    ])
                    ->on('course_classes')
                    ->restrictOnDelete();

                /*
                 * The actor must belong to the same Center.
                 *
                 * Enrollment operations are performed by
                 * Center Owner or Branch Manager accounts.
                 */
                $table->foreign(
                    [
                        'performed_by_user_id',
                        'center_id',
                    ],
                    'enrollment_histories_actor_center_foreign'
                )
                    ->references([
                        'id',
                        'center_id',
                    ])
                    ->on('users')
                    ->restrictOnDelete();

                $table->index(
                    [
                        'center_id',
                        'enrollment_id',
                        'occurred_at',
                    ],
                    'enrollment_histories_enrollment_time_index'
                );

                $table->index(
                    [
                        'center_id',
                        'from_class_id',
                    ],
                    'enrollment_histories_from_class_index'
                );

                $table->index(
                    [
                        'center_id',
                        'to_class_id',
                    ],
                    'enrollment_histories_to_class_index'
                );

                $table->index(
                    [
                        'center_id',
                        'performed_by_user_id',
                    ],
                    'enrollment_histories_actor_index'
                );
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'enrollment_histories'
        );
    }
};
