<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'course_prerequisites',
            function (Blueprint $table) {
                $table->id();

                $table->foreignId(
                    'center_id'
                );

                $table->unsignedBigInteger(
                    'course_id'
                );

                $table->unsignedBigInteger(
                    'prerequisite_course_id'
                );

                /*
                 * Kept nullable until the approved values for
                 * prerequisite requirement types are defined.
                 *
                 * The approved data model contains this attribute,
                 * but the current SRS does not define its enum.
                 */
                $table->string(
                    'requirement_type',
                    50
                )->nullable();

                $table->timestamps();

                $table->foreign(
                    'center_id',
                    'course_prerequisites_center_foreign'
                )
                    ->references('id')
                    ->on('centers')
                    ->restrictOnDelete();

                /*
                 * Both Courses must belong to the same Center.
                 */
                $table->foreign(
                    [
                        'course_id',
                        'center_id',
                    ],
                    'course_prerequisites_course_center_foreign'
                )
                    ->references([
                        'id',
                        'center_id',
                    ])
                    ->on('courses')
                    ->restrictOnDelete();

                $table->foreign(
                    [
                        'prerequisite_course_id',
                        'center_id',
                    ],
                    'course_prerequisites_prerequisite_center_foreign'
                )
                    ->references([
                        'id',
                        'center_id',
                    ])
                    ->on('courses')
                    ->restrictOnDelete();

                /*
                 * The same prerequisite cannot be attached twice
                 * to the same Course.
                 */
                $table->unique(
                    [
                        'center_id',
                        'course_id',
                        'prerequisite_course_id',
                    ],
                    'course_prerequisites_pair_unique'
                );

                $table->index(
                    [
                        'center_id',
                        'prerequisite_course_id',
                    ],
                    'course_prerequisites_reverse_index'
                );
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'course_prerequisites'
        );
    }
};
