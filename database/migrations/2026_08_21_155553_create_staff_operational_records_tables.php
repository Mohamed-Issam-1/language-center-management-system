<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'teachers',
            function (Blueprint $table) {
                $table->id();

                $table->foreignId('center_id');

                $table->unsignedBigInteger(
                    'person_id'
                );

                $table->unsignedBigInteger(
                    'user_id'
                )->nullable();

                $table->string(
                    'status',
                    20
                )->default('active');

                $table->timestamp(
                    'deactivated_at'
                )->nullable();

                $table->timestamps();

                $table->foreign(
                    'center_id',
                    'teachers_center_foreign'
                )
                    ->references('id')
                    ->on('centers')
                    ->restrictOnDelete();

                $table->foreign(
                    [
                        'person_id',
                        'center_id',
                    ],
                    'teachers_person_center_foreign'
                )
                    ->references([
                        'id',
                        'center_id',
                    ])
                    ->on('people')
                    ->restrictOnDelete();

                $table->foreign(
                    [
                        'user_id',
                        'person_id',
                        'center_id',
                    ],
                    'teachers_user_person_center_foreign'
                )
                    ->references([
                        'id',
                        'person_id',
                        'center_id',
                    ])
                    ->on('users')
                    ->restrictOnDelete();

                $table->unique(
                    [
                        'center_id',
                        'person_id',
                    ],
                    'teachers_center_person_unique'
                );

                $table->unique(
                    'user_id',
                    'teachers_user_unique'
                );

                $table->unique(
                    [
                        'id',
                        'center_id',
                    ],
                    'teachers_id_center_unique'
                );

                $table->index(
                    [
                        'center_id',
                        'status',
                    ],
                    'teachers_center_status_index'
                );
            }
        );

        Schema::create(
            'branch_managers',
            function (Blueprint $table) {
                $table->id();

                $table->foreignId('center_id');

                $table->unsignedBigInteger(
                    'person_id'
                );

                $table->unsignedBigInteger(
                    'user_id'
                )->nullable();

                $table->string(
                    'status',
                    20
                )->default('active');

                $table->timestamp(
                    'deactivated_at'
                )->nullable();

                $table->timestamps();

                $table->foreign(
                    'center_id',
                    'branch_managers_center_foreign'
                )
                    ->references('id')
                    ->on('centers')
                    ->restrictOnDelete();

                $table->foreign(
                    [
                        'person_id',
                        'center_id',
                    ],
                    'branch_managers_person_center_foreign'
                )
                    ->references([
                        'id',
                        'center_id',
                    ])
                    ->on('people')
                    ->restrictOnDelete();

                $table->foreign(
                    [
                        'user_id',
                        'person_id',
                        'center_id',
                    ],
                    'branch_managers_user_person_center_foreign'
                )
                    ->references([
                        'id',
                        'person_id',
                        'center_id',
                    ])
                    ->on('users')
                    ->restrictOnDelete();

                $table->unique(
                    [
                        'center_id',
                        'person_id',
                    ],
                    'branch_managers_center_person_unique'
                );

                $table->unique(
                    'user_id',
                    'branch_managers_user_unique'
                );

                $table->unique(
                    [
                        'id',
                        'center_id',
                    ],
                    'branch_managers_id_center_unique'
                );

                $table->index(
                    [
                        'center_id',
                        'status',
                    ],
                    'branch_managers_center_status_index'
                );
            }
        );

        Schema::create(
            'finance_employees',
            function (Blueprint $table) {
                $table->id();

                $table->foreignId('center_id');

                $table->unsignedBigInteger(
                    'person_id'
                );

                $table->unsignedBigInteger(
                    'user_id'
                )->nullable();

                $table->string(
                    'status',
                    20
                )->default('active');

                $table->timestamp(
                    'deactivated_at'
                )->nullable();

                $table->timestamps();

                $table->foreign(
                    'center_id',
                    'finance_employees_center_foreign'
                )
                    ->references('id')
                    ->on('centers')
                    ->restrictOnDelete();

                $table->foreign(
                    [
                        'person_id',
                        'center_id',
                    ],
                    'finance_employees_person_center_foreign'
                )
                    ->references([
                        'id',
                        'center_id',
                    ])
                    ->on('people')
                    ->restrictOnDelete();

                $table->foreign(
                    [
                        'user_id',
                        'person_id',
                        'center_id',
                    ],
                    'finance_employees_user_person_center_foreign'
                )
                    ->references([
                        'id',
                        'person_id',
                        'center_id',
                    ])
                    ->on('users')
                    ->restrictOnDelete();

                $table->unique(
                    [
                        'center_id',
                        'person_id',
                    ],
                    'finance_employees_center_person_unique'
                );

                $table->unique(
                    'user_id',
                    'finance_employees_user_unique'
                );

                $table->unique(
                    [
                        'id',
                        'center_id',
                    ],
                    'finance_employees_id_center_unique'
                );

                $table->index(
                    [
                        'center_id',
                        'status',
                    ],
                    'finance_employees_center_status_index'
                );
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'finance_employees'
        );

        Schema::dropIfExists(
            'branch_managers'
        );

        Schema::dropIfExists(
            'teachers'
        );
    }
};
