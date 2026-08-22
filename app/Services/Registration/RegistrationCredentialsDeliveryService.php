<?php

namespace App\Services\Registration;

use App\Mail\RegistrationCredentialsMail;
use App\Models\User;
use App\Support\Enums\AccountStatus;
use DomainException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use InvalidArgumentException;

class RegistrationCredentialsDeliveryService
{
    public function deliver(
        RegistrationApprovalResult $result
    ): void {
        /*
         * Re-read the approved account from persisted state.
         *
         * Credential delivery must never trust mutable
         * in-memory account attributes.
         */
        $account =
            $this->persistedAccount(
                $result->account
            );

        if (
            $account->status
            !== AccountStatus::Active
        ) {
            throw new DomainException(
                'Registration credentials may be delivered only for an active User Account.'
            );
        }

        if (
            ! $account->must_change_password
        ) {
            throw new DomainException(
                'Registration credentials may be delivered only while a temporary password change is required.'
            );
        }

        $temporaryPassword =
            trim(
                $result->temporaryPassword
            );

        if ($temporaryPassword === '') {
            throw new DomainException(
                'Registration credentials require a temporary password.'
            );
        }

        /*
         * Prevent delivery of stale plaintext credentials.
         *
         * For example, if an administrator has already issued
         * a newer temporary password, an older ApprovalResult
         * must not be able to send the obsolete password.
         */
        if (
            ! Hash::check(
                $temporaryPassword,
                $account->password
            )
        ) {
            throw new DomainException(
                'The temporary password no longer matches the approved User Account.'
            );
        }

        $recipientEmail =
            Str::lower(
                trim(
                    (string)
                    $account->recovery_email
                )
            );

        if (
            $recipientEmail === ''
            || filter_var(
                $recipientEmail,
                FILTER_VALIDATE_EMAIL
            ) === false
        ) {
            throw new DomainException(
                'The approved User Account does not have a valid credential-delivery email address.'
            );
        }

        $accountLoginIdentifier =
            trim(
                (string)
                $account
                    ->account_login_identifier
            );

        if (
            $accountLoginIdentifier === ''
        ) {
            throw new DomainException(
                'The approved User Account does not have a login identifier.'
            );
        }

        /*
         * Send synchronously.
         *
         * Do not queue this message because the plaintext
         * temporary password must not be serialized into a
         * persistent queue payload.
         */
        Mail::to(
            $recipientEmail
        )->send(
            new RegistrationCredentialsMail(
                recipientName: $this->recipientName(
                    $account
                ),

                accountLoginIdentifier: $accountLoginIdentifier,

                temporaryPassword: $temporaryPassword,

                loginUrl: route('login')
            )
        );
    }

    private function persistedAccount(
        User $account
    ): User {
        if (
            ! $account->exists
            || $account->getKey() === null
        ) {
            throw new InvalidArgumentException(
                'Registration credentials require a persisted User Account.'
            );
        }

        $accountId =
            $account->getKey();

        if (
            ! is_int($accountId)
            && ! (
                is_string($accountId)
                && ctype_digit(
                    $accountId
                )
            )
        ) {
            throw new InvalidArgumentException(
                'The approved User Account must use a numeric identifier.'
            );
        }

        $persisted =
            User::withoutGlobalScopes()
            ->with('person')
            ->whereKey(
                (int) $accountId
            )
            ->first();

        if ($persisted === null) {
            throw new InvalidArgumentException(
                'The approved User Account no longer exists.'
            );
        }

        return $persisted;
    }

    private function recipientName(
        User $account
    ): string {
        $name =
            trim(
                (string)
                $account->person?->full_name
            );

        if ($name !== '') {
            return $name;
        }

        return 'LCMS User';
    }
}
