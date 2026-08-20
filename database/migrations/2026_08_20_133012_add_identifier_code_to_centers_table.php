<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('centers', function (Blueprint $table) {
            /*
             * Stable two-digit identifier used by generated
             * LCMS account identifiers.
             *
             * Nullable temporarily for existing Center records.
             * New Center creation through the management service
             * will require a valid value from 01 through 99.
             */
            $table->char(
                'identifier_code',
                2
            )
                ->nullable()
                ->unique()
                ->after('code');
        });
    }

    public function down(): void
    {
        Schema::table('centers', function (Blueprint $table) {
            $table->dropUnique([
                'identifier_code',
            ]);

            $table->dropColumn(
                'identifier_code'
            );
        });
    }
};
