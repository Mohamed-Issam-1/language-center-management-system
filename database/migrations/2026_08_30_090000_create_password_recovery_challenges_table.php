<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'password_recovery_challenges',
            function (Blueprint $table): void {
                $table->id();

                $table->foreignId('user_id')
                    ->constrained()
                    ->cascadeOnDelete();

                $table->string('code_hash', 64);

                $table->unsignedSmallInteger('attempts')
                    ->default(0);

                $table->unsignedSmallInteger('max_attempts')
                    ->default(5);

                $table->dateTime('expires_at');

                $table->dateTime('verified_at')
                    ->nullable();

                $table->dateTime('used_at')
                    ->nullable();

                $table->timestamps();

                $table->index([
                    'user_id',
                    'created_at',
                ]);

                $table->index([
                    'user_id',
                    'expires_at',
                ]);
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'password_recovery_challenges'
        );
    }
};
