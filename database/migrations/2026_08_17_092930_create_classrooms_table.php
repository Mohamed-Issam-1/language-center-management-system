<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'classrooms',
            function (Blueprint $table) {
                $table->id();

                $table->foreignId('center_id');

                $table->unsignedBigInteger(
                    'branch_id'
                );

                $table->string('name');

                $table->string(
                    'code',
                    50
                );

                $table->unsignedInteger(
                    'capacity'
                );

                $table->string(
                    'location'
                );

                $table->string(
                    'availability_status',
                    20
                );

                $table->string(
                    'status',
                    20
                );

                $table->timestamps();

                /*
                 * Classroom belongs directly to one Center.
                 */
                $table->foreign(
                    'center_id',
                    'classrooms_center_foreign'
                )
                    ->references('id')
                    ->on('centers')
                    ->restrictOnDelete();

                /*
                 * The composite FK prevents a Classroom from
                 * referencing a Branch belonging to a different
                 * Center.
                 *
                 * branches(id, center_id) is already backed by
                 * branches_id_center_unique.
                 */
                $table->foreign(
                    [
                        'branch_id',
                        'center_id',
                    ],
                    'classrooms_branch_center_foreign'
                )
                    ->references([
                        'id',
                        'center_id',
                    ])
                    ->on('branches')
                    ->restrictOnDelete();

                $table->index(
                    [
                        'center_id',
                        'branch_id',
                    ],
                    'classrooms_center_branch_index'
                );

                $table->index(
                    [
                        'center_id',
                        'status',
                    ],
                    'classrooms_center_status_index'
                );

                $table->index(
                    [
                        'branch_id',
                        'availability_status',
                        'status',
                    ],
                    'classrooms_branch_availability_status_index'
                );
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'classrooms'
        );
    }
};
