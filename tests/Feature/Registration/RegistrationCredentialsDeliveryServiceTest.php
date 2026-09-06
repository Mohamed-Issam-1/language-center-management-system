<?php

namespace Tests\Feature\Registration;

use App\Mail\RegistrationCredentialsMail;
use App\Models\Center;
use App\Models\Person;
use App\Models\RegistrationRequest;
use App\Models\Role;
use App\Models\User;
use App\Services\Registration\RegistrationApprovalResult;
use App\Services\Registration\RegistrationCredentialsDeliveryService;
use App\Support\Enums\AccountStatus;
use App\Support\Enums\SystemRole;
use Database\Seeders\RoleSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class RegistrationCredentialsDeliveryServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(
            RoleSeeder::class
        );
    }

    public function test_registration_credentials_are_sent_synchronously_to_persisted_account_email(): void
    {
        Mail::fake();

        $result =
            $this->approvalResult();

        $this->service()->deliver(
            $result
        );

        Mail::assertSent(
            RegistrationCredentialsMail::class,
            function (
                RegistrationCredentialsMail $mail
            ): bool {
                return $mail->hasTo(
                    'approved@example.test'
                )
                    && $mail
                    ->recipientName
                    === 'Approved User'
                    && $mail
                    ->accountLoginIdentifier
                    === '01120001'
                    && $mail
                    ->temporaryPassword
                    === 'Temporary!123'
                    && $mail
                    ->loginUrl
                    === route('login');
            }
        );

        Mail::assertNotQueued(
            RegistrationCredentialsMail::class
        );
    }

    public function test_mail_contains_login_identifier_temporary_password_and_first_login_instructions(): void
    {
        Mail::fake();

        $result =
            $this->approvalResult();

        $this->service()->deliver(
            $result
        );

        Mail::assertSent(
            RegistrationCredentialsMail::class,
            function (
                RegistrationCredentialsMail $mail
            ): bool {
                $html =
                    $mail->render();

                return str_contains(
                    $html,
                    '01120001'
                )
                    && str_contains(
                        $html,
                        'Temporary!123'
                    )
                    && str_contains(
                        $html,
                        route('login')
                    )
                    && str_contains(
                        $html,
                        'change the temporary password'
                    )
                    && str_contains(
                        $html,
                        'signed out automatically'
                    )
                    && str_contains(
                        $html,
                        'Sign in again'
                    );
            }
        );
    }

    public function test_delivery_uses_persisted_account_state_instead_of_tampered_memory_state(): void
    {
        Mail::fake();

        $result =
            $this->approvalResult();

        /*
         * These local mutations must not affect delivery.
         */
        $result->account
            ->recovery_email =
            'attacker@example.test';

        $result->account
            ->account_login_identifier =
            '99999999';

        $result->person
            ->full_name =
            'Tampered Name';

        $this->service()->deliver(
            $result
        );

        Mail::assertSent(
            RegistrationCredentialsMail::class,
            function (
                RegistrationCredentialsMail $mail
            ): bool {
                return $mail->hasTo(
                    'approved@example.test'
                )
                    && ! $mail->hasTo(
                        'attacker@example.test'
                    )
                    && $mail
                    ->accountLoginIdentifier
                    === '01120001'
                    && $mail
                    ->recipientName
                    === 'Approved User';
            }
        );
    }

    public function test_stale_temporary_password_is_not_delivered(): void
    {
        Mail::fake();

        $result =
            $this->approvalResult();

        /*
         * Simulate a newer temporary credential being issued
         * after the ApprovalResult was created.
         */
        $result->account->forceFill([
            'password' =>
            Hash::make(
                'NewTemporary!456'
            ),
        ])->save();

        try {
            $this->service()->deliver(
                $result
            );

            $this->fail(
                'Expected stale temporary password delivery to be rejected.'
            );
        } catch (DomainException) {
            $this->assertTrue(true);
        }

        Mail::assertNothingSent();
    }

    public function test_deactivated_account_credentials_are_not_delivered(): void
    {
        Mail::fake();

        $result =
            $this->approvalResult();

        $result->account->forceFill([
            'status' =>
            AccountStatus::Deactivated,

            'deactivated_at' =>
            now(),
        ])->save();

        try {
            $this->service()->deliver(
                $result
            );

            $this->fail(
                'Expected deactivated account credential delivery to be rejected.'
            );
        } catch (DomainException) {
            $this->assertTrue(true);
        }

        Mail::assertNothingSent();
    }

    public function test_credentials_are_not_delivered_after_required_password_change_is_cleared(): void
    {
        Mail::fake();

        $result =
            $this->approvalResult();

        $result->account->forceFill([
            'must_change_password' =>
            false,
        ])->save();

        try {
            $this->service()->deliver(
                $result
            );

            $this->fail(
                'Expected credential delivery to be rejected after temporary-password requirement was cleared.'
            );
        } catch (DomainException) {
            $this->assertTrue(true);
        }

        Mail::assertNothingSent();
    }

    private function service(): RegistrationCredentialsDeliveryService
    {
        return app(
            RegistrationCredentialsDeliveryService::class
        );
    }

    private function approvalResult(): RegistrationApprovalResult
    {
        $center =
            Center::factory()
            ->create([
                'identifier_code' =>
                '01',
            ]);

        $person =
            Person::factory()
            ->create([
                'center_id' =>
                $center->id,

                'national_id_number' =>
                '900001001',

                'full_name' =>
                'Approved User',

                'email' =>
                'approved@example.test',
            ]);

        $role =
            Role::query()
            ->where(
                'code',
                SystemRole::Teacher->value
            )
            ->firstOrFail();

        $account =
            User::factory()
            ->create([
                'center_id' =>
                $center->id,

                'person_id' =>
                $person->id,

                'role_id' =>
                $role->id,

                'account_login_identifier' =>
                '01120001',

                'recovery_email' =>
                'approved@example.test',

                'status' =>
                AccountStatus::Active,

                'password' =>
                Hash::make(
                    'Temporary!123'
                ),
            ]);

        $account->forceFill([
            'must_change_password' =>
            true,

            'temporary_password_used_at' =>
            null,

            'deactivated_at' =>
            null,
        ])->save();

        $request =
            RegistrationRequest::factory()
            ->create([
                'center_id' =>
                $center->id,

                'national_id_number' =>
                '900001001',

                'full_name' =>
                'Approved User',

                'email' =>
                'approved@example.test',

                'selected_role_id' =>
                $role->id,
            ]);

        return new RegistrationApprovalResult(
            registrationRequest: $request,

            person: $person,

            account: $account->refresh(),

            role: SystemRole::Teacher,

            temporaryPassword: 'Temporary!123',

            recipientEmail: 'approved@example.test'
        );
    }
}
