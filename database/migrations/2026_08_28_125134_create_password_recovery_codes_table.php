<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'password_recovery_codes',
            function (Blueprint $table) {
                $table->id();

                $table->foreignId('user_id')
                    ->constrained()
                    ->cascadeOnDelete();

                $table->string('code_hash', 64);

                $table->timestamp('expires_at');

                $table->timestamp('verified_at')
                    ->nullable();

                $table->timestamp('used_at')
                    ->nullable();

                $table->timestamps();

                $table->index([
                    'user_id',
                    'created_at',
                ]);

                $table->index([
                    'user_id',
                    'code_hash',
                ]);
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'password_recovery_codes'
        );
    }
};