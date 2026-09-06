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
                 * Required by Center-safe references from
                 * Scheduling and later Center-owned modules.
                 *
                 * The existing:
                 * (id, branch_id, center_id)
                 * cannot back a foreign key using:
                 * (id, center_id).
                 */
                $table->unique(
                    [
                        'id',
                        'center_id',
                    ],
                    'classrooms_id_center_unique'
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
                    'classrooms_id_center_unique'
                );
            }
        );
    }
};
