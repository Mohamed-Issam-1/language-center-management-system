<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table(
            'registration_requests',
            function (Blueprint $table) {
                $table->foreignId('person_id')
                    ->nullable()
                    ->after('center_id')
                    ->constrained('people')
                    ->restrictOnDelete();

                $table->foreignId('user_id')
                    ->nullable()
                    ->unique()
                    ->after('person_id')
                    ->constrained('users')
                    ->restrictOnDelete();

                $table->index(
                    ['center_id', 'person_id', 'pending_marker'],
                    'registration_requests_pending_person_index'
                );
            }
        );
    }

    public function down(): void
    {
        Schema::table(
            'registration_requests',
            function (Blueprint $table) {
                $table->dropIndex(
                    'registration_requests_pending_person_index'
                );

                $table->dropForeign([
                    'user_id',
                ]);

                $table->dropForeign([
                    'person_id',
                ]);

                $table->dropColumn([
                    'person_id',
                    'user_id',
                ]);
            }
        );
    }
};