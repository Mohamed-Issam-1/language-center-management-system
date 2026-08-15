<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('centers', function (Blueprint $table) {
            $table->string('operating_currency_code', 10)
                ->nullable()
                ->after('timezone');
        });
    }

    public function down(): void
    {
        Schema::table('centers', function (Blueprint $table) {
            $table->dropColumn('operating_currency_code');
        });
    }
};
