<?php

use App\Support\Enums\AccountStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * Required so the users table can enforce that a referenced
         * Person belongs to the same Center as the User Account.
         */
        Schema::table('people', function (Blueprint $table) {
            $table->unique(
                ['id', 'center_id'],
                'people_id_center_unique'
            );
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('center_id')
                ->nullable()
                ->after('id');

            $table->foreignId('person_id')
                ->nullable()
                ->after('center_id');

            $table->foreignId('role_id')
                ->after('person_id');

            $table->string('account_login_identifier')
                ->unique()
                ->after('role_id');

            $table->string('recovery_email')
                ->after('email');

            $table->string('status', 20)
                ->default(AccountStatus::Active->value)
                ->index()
                ->after('recovery_email');

            $table->unsignedSmallInteger('failed_login_attempts')
                ->default(0)
                ->after('status');

            $table->timestamp('locked_until')
                ->nullable()
                ->after('failed_login_attempts');

            $table->timestamp('last_login_at')
                ->nullable()
                ->after('locked_until');

            $table->timestamp('password_changed_at')
                ->nullable()
                ->after('last_login_at');

            $table->timestamp('deactivated_at')
                ->nullable()
                ->after('password_changed_at');

            $table->unique(
                ['center_id', 'person_id', 'role_id'],
                'users_center_person_role_unique'
            );

            $table->index(
                ['center_id', 'status'],
                'users_center_status_index'
            );
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreign('center_id', 'users_center_id_foreign')
                ->references('id')
                ->on('centers')
                ->restrictOnDelete();

            $table->foreign('role_id', 'users_role_id_foreign')
                ->references('id')
                ->on('roles')
                ->restrictOnDelete();

            /*
             * Composite FK:
             *
             * users.person_id + users.center_id
             * must reference the same Person/Center pair.
             */
            $table->foreign(
                ['person_id', 'center_id'],
                'users_person_center_foreign'
            )
                ->references(['id', 'center_id'])
                ->on('people')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign('users_person_center_foreign');
            $table->dropForeign('users_center_id_foreign');
            $table->dropForeign('users_role_id_foreign');

            $table->dropUnique('users_center_person_role_unique');
            $table->dropUnique('users_account_login_identifier_unique');
            $table->dropIndex('users_center_status_index');
            $table->dropIndex('users_status_index');

            $table->dropColumn([
                'center_id',
                'person_id',
                'role_id',
                'account_login_identifier',
                'recovery_email',
                'status',
                'failed_login_attempts',
                'locked_until',
                'last_login_at',
                'password_changed_at',
                'deactivated_at',
            ]);
        });

        Schema::table('people', function (Blueprint $table) {
            $table->dropUnique('people_id_center_unique');
        });
    }
};
