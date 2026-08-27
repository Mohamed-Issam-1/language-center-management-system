<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * occurrence_date identifies the immutable recurring
         * Schedule occurrence that originally produced the Session.
         *
         * session_date remains mutable because an individual Session
         * may later be rescheduled to another calendar date.
         */
        Schema::table(
            'class_sessions',
            function (Blueprint $table) {
                $table->date(
                    'occurrence_date'
                )
                    ->nullable()
                    ->after(
                        'schedule_id'
                    );
            }
        );

        /*
         * Existing Sessions predate occurrence_date, so their
         * current Session date is their original occurrence.
         */
        DB::table(
            'class_sessions'
        )->update([
            'occurrence_date' =>
            DB::raw(
                'session_date'
            ),
        ]);

        Schema::table(
            'class_sessions',
            function (Blueprint $table) {
                $table->date(
                    'occurrence_date'
                )
                    ->nullable(false)
                    ->change();

                /*
                 * A recurring Schedule occurrence can only be
                 * materialized once even if the Session is later
                 * rescheduled to another date.
                 */
                $table->unique(
                    [
                        'center_id',
                        'schedule_id',
                        'occurrence_date',
                    ],
                    'class_sessions_schedule_occurrence_unique'
                );
            }
        );
    }

    public function down(): void
    {
        Schema::table(
            'class_sessions',
            function (Blueprint $table) {
                $table->dropUnique(
                    'class_sessions_schedule_occurrence_unique'
                );

                $table->dropColumn(
                    'occurrence_date'
                );
            }
        );
    }
};