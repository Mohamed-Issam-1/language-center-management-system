<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'languages',
            function (Blueprint $table) {
                $table->id();

                $table->foreignId(
                    'center_id'
                );

                $table->string(
                    'name'
                );

                $table->string(
                    'code',
                    50
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
                    'languages_center_foreign'
                )
                    ->references('id')
                    ->on('centers')
                    ->restrictOnDelete();

                /*
                 * Supports center-consistent composite foreign keys
                 * from Academic Levels and Courses.
                 */
                $table->unique(
                    [
                        'id',
                        'center_id',
                    ],
                    'languages_id_center_unique'
                );

                $table->index(
                    [
                        'center_id',
                        'status',
                    ],
                    'languages_center_status_index'
                );

                $table->index(
                    [
                        'center_id',
                        'code',
                    ],
                    'languages_center_code_index'
                );
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'languages'
        );
    }
};