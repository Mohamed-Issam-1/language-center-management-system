<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table(
            'classrooms',
            function (Blueprint $table) {
                /*
                 * Course Classes belong to a Branch and may assign
                 * only a Classroom from that exact Branch and Center.
                 *
                 * This composite identity allows course_classes to
                 * enforce that rule directly at database level.
                 */
                $table->unique(
                    [
                        'id',
                        'branch_id',
                        'center_id',
                    ],
                    'classrooms_id_branch_center_unique'
                );
            }
        );
    }

    public function down(): void
    {
        Schema::table(
            'classrooms',
            function (Blueprint $table) {
                $table->dropUnique(
                    'classrooms_id_branch_center_unique'
                );
            }
        );
    }
};
