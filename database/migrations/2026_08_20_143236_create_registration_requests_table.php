<?php

use App\Support\Enums\RegistrationRequestStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'registration_requests',
            function (Blueprint $table) {
                $table->id();

                $table->foreignId('center_id')
                    ->constrained()
                    ->restrictOnDelete();

                /*
                 * Personal information submitted by the person.
                 *
                 * No Person or User record exists yet at this
                 * stage.
                 */
                $table->string(
                    'national_id_number',
                    50
                );

                $table->string('full_name');

                $table->date(
                    'date_of_birth'
                );

                $table->string(
                    'city_of_residence',
                    150
                );

                $table->string('email');

                $table->string(
                    'phone_number',
                    50
                );

                $table->string(
                    'personal_picture_path',
                    2048
                )->nullable();

                /*
                 * Registration lifecycle.
                 */
                $table->string(
                    'status',
                    20
                )
                    ->default(
                        RegistrationRequestStatus::Pending->value
                    );

                /*
                 * The person does not choose their System Role.
                 *
                 * An administrator may select the Role during
                 * review before final approval.
                 */
                $table->foreignId(
                    'selected_role_id'
                )
                    ->nullable()
                    ->constrained('roles')
                    ->restrictOnDelete();

                /*
                 * Reviewer information remains empty while the
                 * request is waiting for administrative review.
                 */
                $table->foreignId(
                    'reviewed_by_user_id'
                )
                    ->nullable()
                    ->constrained('users')
                    ->restrictOnDelete();

                $table->timestamp(
                    'reviewed_at'
                )->nullable();

                $table->text(
                    'rejection_reason'
                )->nullable();

                /*
                 * Exactly one Pending request for the same
                 * National ID may exist in the same Center.
                 *
                 * MySQL allows multiple NULL values in a unique
                 * key, so completed historical requests set this
                 * marker to NULL.
                 */
                $table->unsignedTinyInteger(
                    'pending_marker'
                )
                    ->nullable()
                    ->default(1);

                $table->timestamps();

                $table->unique(
                    [
                        'center_id',
                        'national_id_number',
                        'pending_marker',
                    ],
                    'registration_requests_pending_identity_unique'
                );

                $table->index(
                    [
                        'center_id',
                        'status',
                        'created_at',
                    ],
                    'registration_requests_center_status_index'
                );
            }
        );

        /*
         * Keep lifecycle status and the duplicate-prevention
         * marker consistent even when data is written outside
         * the application service layer.
         */
        DB::statement(
            'ALTER TABLE registration_requests '
            . 'ADD CONSTRAINT registration_requests_pending_marker_check '
            . 'CHECK ('
            . "(status = 'pending' AND pending_marker = 1) "
            . 'OR '
            . "(status IN ('approved', 'rejected') AND pending_marker IS NULL)"
            . ')'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'registration_requests'
        );
    }
};