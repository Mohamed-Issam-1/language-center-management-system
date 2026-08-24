<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'courses',
            function (Blueprint $table) {
                $table->id();

                $table->foreignId(
                    'center_id'
                );

                $table->unsignedBigInteger(
                    'language_id'
                );

                $table->unsignedBigInteger(
                    'academic_level_id'
                );

                $table->string(
                    'name'
                );

                $table->string(
                    'code',
                    50
                );

                $table->text(
                    'description'
                )->nullable();

                /*
                 * Approved Course duration.
                 */
                $table->unsignedInteger(
                    'duration_weeks'
                );

                /*
                 * Decimal allows courses such as 37.5 hours.
                 */
                $table->decimal(
                    'total_hours',
                    8,
                    2
                );

                /*
                 * Monetary value uses the Center operating currency.
                 *
                 * Currency itself is not duplicated on every Course.
                 */
                $table->decimal(
                    'default_fee',
                    12,
                    2
                );

                /*
                 * Approved academic policy attributes from the
                 * system data model.
                 */
                $table->decimal(
                    'passing_grade',
                    5,
                    2
                );

                $table->decimal(
                    'minimum_attendance',
                    5,
                    2
                );

                $table->string(
                    'status',
                    20
                )->default('active');

                $table->timestamp(
                    'archived_at'
                )->nullable();

                $table->timestamps();

                $table->foreign(
                    'center_id',
                    'courses_center_foreign'
                )
                    ->references('id')
                    ->on('centers')
                    ->restrictOnDelete();

                /*
                 * Course Language must belong to the same Center.
                 */
                $table->foreign(
                    [
                        'language_id',
                        'center_id',
                    ],
                    'courses_language_center_foreign'
                )
                    ->references([
                        'id',
                        'center_id',
                    ])
                    ->on('languages')
                    ->restrictOnDelete();

                /*
                 * Enforce all three facts at database level:
                 *
                 * - Academic Level exists.
                 * - Academic Level belongs to selected Language.
                 * - Academic Level belongs to selected Center.
                 */
                $table->foreign(
                    [
                        'academic_level_id',
                        'language_id',
                        'center_id',
                    ],
                    'courses_academic_level_language_center_foreign'
                )
                    ->references([
                        'id',
                        'language_id',
                        'center_id',
                    ])
                    ->on('academic_levels')
                    ->restrictOnDelete();

                /*
                 * Supports center-consistent prerequisite foreign
                 * keys and future Class foreign keys.
                 */
                $table->unique(
                    [
                        'id',
                        'center_id',
                    ],
                    'courses_id_center_unique'
                );

                $table->index(
                    [
                        'center_id',
                        'status',
                    ],
                    'courses_center_status_index'
                );

                $table->index(
                    [
                        'center_id',
                        'language_id',
                        'academic_level_id',
                    ],
                    'courses_center_academic_structure_index'
                );

                $table->index(
                    [
                        'center_id',
                        'code',
                    ],
                    'courses_center_code_index'
                );
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'courses'
        );
    }
};