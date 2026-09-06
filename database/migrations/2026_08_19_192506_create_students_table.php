<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * Student records may optionally be linked to one
         * role-specific Student User Account.
         *
         * This composite unique key lets the students table
         * enforce that a linked User belongs to the same Person
         * and Center as the Student record.
         */
        Schema::table(
            'users',
            function (Blueprint $table) {
                $table->unique(
                    [
                        'id',
                        'person_id',
                        'center_id',
                    ],
                    'users_id_person_center_unique'
                );
            }
        );

        Schema::create(
            'students',
            function (Blueprint $table) {
                $table->id();

                $table->foreignId(
                    'center_id'
                );

                $table->unsignedBigInteger(
                    'branch_id'
                );

                $table->unsignedBigInteger(
                    'person_id'
                );

                /*
                 * A Student record may exist before a login
                 * account is created.
                 *
                 * Once linked, one Student account belongs to
                 * only one Student record.
                 */
                $table->unsignedBigInteger(
                    'user_id'
                )->nullable();

                $table->string(
                    'status',
                    20
                )->default('active');

                $table->timestamp(
                    'archived_at'
                )->nullable();

                $table->timestamps();

                /*
                 * Student belongs directly to one Center.
                 */
                $table->foreign(
                    'center_id',
                    'students_center_foreign'
                )
                    ->references('id')
                    ->on('centers')
                    ->restrictOnDelete();

                /*
                 * Prevent a Student from referencing a Branch
                 * belonging to another Center.
                 */
                $table->foreign(
                    [
                        'branch_id',
                        'center_id',
                    ],
                    'students_branch_center_foreign'
                )
                    ->references([
                        'id',
                        'center_id',
                    ])
                    ->on('branches')
                    ->restrictOnDelete();

                /*
                 * Prevent a Student from referencing a Person
                 * belonging to another Center.
                 */
                $table->foreign(
                    [
                        'person_id',
                        'center_id',
                    ],
                    'students_person_center_foreign'
                )
                    ->references([
                        'id',
                        'center_id',
                    ])
                    ->on('people')
                    ->restrictOnDelete();

                /*
                 * When a User Account is linked, it must belong
                 * to exactly the same Person and Center as the
                 * Student record.
                 *
                 * Role validation remains a service/policy rule:
                 * only a Student-role account may be linked.
                 */
                $table->foreign(
                    [
                        'user_id',
                        'person_id',
                        'center_id',
                    ],
                    'students_user_person_center_foreign'
                )
                    ->references([
                        'id',
                        'person_id',
                        'center_id',
                    ])
                    ->on('users')
                    ->restrictOnDelete();

                /*
                 * FR-114:
                 * no Person may have more than one Student
                 * record within the same language center.
                 */
                $table->unique(
                    [
                        'center_id',
                        'person_id',
                    ],
                    'students_center_person_unique'
                );

                /*
                 * A role-specific Student User Account can be
                 * linked to at most one Student record.
                 *
                 * MySQL permits multiple NULL values here,
                 * allowing Student records without login accounts.
                 */
                $table->unique(
                    'user_id',
                    'students_user_unique'
                );

                /*
                 * Useful for future center-consistent foreign keys
                 * from enrollment, attendance, finance, etc.
                 */
                $table->unique(
                    [
                        'id',
                        'center_id',
                    ],
                    'students_id_center_unique'
                );

                $table->index(
                    [
                        'center_id',
                        'branch_id',
                        'status',
                    ],
                    'students_center_branch_status_index'
                );

                $table->index(
                    [
                        'center_id',
                        'status',
                    ],
                    'students_center_status_index'
                );
            }
        );
    }

    public function down(): void
    {
        /*
         * Drop the dependent Student table before removing the
         * composite User key referenced by its foreign key.
         */
        Schema::dropIfExists(
            'students'
        );

        Schema::table(
            'users',
            function (Blueprint $table) {
                $table->dropUnique(
                    'users_id_person_center_unique'
                );
            }
        );
    }
};
