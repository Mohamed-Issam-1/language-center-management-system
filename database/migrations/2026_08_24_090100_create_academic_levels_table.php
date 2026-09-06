<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'academic_levels',
            function (Blueprint $table) {
                $table->id();

                $table->foreignId(
                    'center_id'
                );

                $table->unsignedBigInteger(
                    'language_id'
                );

                $table->string(
                    'name'
                );

                $table->string(
                    'code',
                    50
                );

                $table->unsignedInteger(
                    'sequence_number'
                );

                $table->string(
                    'description'
                )->nullable();

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
                    'academic_levels_center_foreign'
                )
                    ->references('id')
                    ->on('centers')
                    ->restrictOnDelete();

                /*
                 * Prevent a Level from referencing a Language
                 * belonging to another Center.
                 */
                $table->foreign(
                    [
                        'language_id',
                        'center_id',
                    ],
                    'academic_levels_language_center_foreign'
                )
                    ->references([
                        'id',
                        'center_id',
                    ])
                    ->on('languages')
                    ->restrictOnDelete();

                /*
                 * Required later so Courses can enforce that the
                 * selected Academic Level belongs to the selected
                 * Language and Center.
                 */
                $table->unique(
                    [
                        'id',
                        'language_id',
                        'center_id',
                    ],
                    'academic_levels_id_language_center_unique'
                );

                /*
                 * Academic Level sequence is unique inside one
                 * Language.
                 */
                $table->unique(
                    [
                        'language_id',
                        'sequence_number',
                    ],
                    'academic_levels_language_sequence_unique'
                );

                $table->index(
                    [
                        'center_id',
                        'status',
                    ],
                    'academic_levels_center_status_index'
                );

                $table->index(
                    [
                        'center_id',
                        'language_id',
                        'status',
                    ],
                    'academic_levels_center_language_status_index'
                );
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'academic_levels'
        );
    }
};