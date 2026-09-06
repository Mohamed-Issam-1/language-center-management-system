<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('people', function (Blueprint $table) {
            $table->id();

            $table->foreignId('center_id')
                ->constrained()
                ->restrictOnDelete();

            $table->string('national_id_number', 50);

            $table->timestamps();

            $table->unique(
                ['center_id', 'national_id_number'],
                'people_center_national_id_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('people');
    }
};
