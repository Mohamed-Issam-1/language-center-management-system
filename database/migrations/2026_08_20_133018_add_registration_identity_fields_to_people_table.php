<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('people', function (Blueprint $table) {
            /*
             * Shared personal identity information.
             *
             * These columns are temporarily nullable because
             * existing LCMS account/student workflows may create
             * a Person using only Center + National ID.
             *
             * The public registration workflow will enforce the
             * required fields through application validation.
             */
            $table->string('full_name')
                ->nullable()
                ->after('national_id_number');

            $table->date('date_of_birth')
                ->nullable()
                ->after('full_name');

            $table->string(
                'city_of_residence',
                150
            )
                ->nullable()
                ->after('date_of_birth');

            $table->string('email')
                ->nullable()
                ->after('city_of_residence');

            $table->string(
                'phone_number',
                50
            )
                ->nullable()
                ->after('email');

            $table->string(
                'personal_picture_path',
                2048
            )
                ->nullable()
                ->after('phone_number');
        });
    }

    public function down(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->dropColumn([
                'full_name',
                'date_of_birth',
                'city_of_residence',
                'email',
                'phone_number',
                'personal_picture_path',
            ]);
        });
    }
};
