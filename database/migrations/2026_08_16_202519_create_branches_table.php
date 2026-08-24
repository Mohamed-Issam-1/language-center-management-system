<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $table) {
            $table->id();

            $table->foreignId('center_id')
                ->constrained('centers')
                ->restrictOnDelete();

            $table->string('name');

            $table->string('code', 50);

            $table->string('phone', 50)
                ->nullable();

            $table->string('email')
                ->nullable();

            $table->text('address')
                ->nullable();

            $table->json('working_hours')
                ->nullable();

            $table->string('status', 20);

            $table->timestamps();

            $table->unique(
                [
                    'center_id',
                    'code',
                ],
                'branches_center_code_unique'
            );

            $table->index(
                [
                    'center_id',
                    'status',
                ],
                'branches_center_status_index'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branches');
    }
};
