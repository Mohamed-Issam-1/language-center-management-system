<?php

namespace App\Services\Registration;

use App\Models\User;
use App\Services\Accounts\UserAccountManagementService;
use Illuminate\Support\Str;

class RegistrationCredentialsReissueService
{
    public function __construct(
        private readonly UserAccountManagementService $accounts,
        private readonly RegistrationCredentialsDeliveryService $delivery
    ) {}

    public function reissue(
        User $actor,
        User $account
    ): void {
        /*
         * Always generate a completely new temporary password.
         *
         * The previous temporary credential is never retrieved,
         * reused, or stored as plaintext.
         */
        $temporaryPassword =
            Str::password(
                20
            );

        /*
         * UserAccountManagementService is the authoritative
         * account-management boundary.
         *
         * It re-reads the target account, enforces the correct
         * Platform / Center / Branch authorization, requires an
         * active account, replaces the password hash, resets
         * lock state, requires first-login password change, and
         * writes the audit record.
         */
        $account =
            $this->accounts
            ->issueTemporaryPassword(
                $actor,
                $account,
                $temporaryPassword
            );

        /*
         * issueTemporaryPassword() has committed before email
         * delivery begins.
         *
         * Do not wrap password issuance and external email
         * delivery in one database transaction.
         */
        $this->delivery
            ->deliverAccountCredentials(
                $account,
                $temporaryPassword
            );
    }
}
