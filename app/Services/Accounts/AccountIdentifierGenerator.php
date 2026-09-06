<?php

namespace App\Services\Accounts;

use App\Models\Center;
use App\Models\User;
use App\Support\Accounts\AccountIdentifierPolicy;
use App\Support\Enums\SystemRole;
use DomainException;
use Illuminate\Support\Facades\DB;
use LogicException;

class AccountIdentifierGenerator
{
    public function __construct(
        private readonly AccountIdentifierPolicy $policy
    ) {}

    public function generate(
        ?Center $center,
        SystemRole $role
    ): string {
        $centerCode =
            $this->validatedCenterCode(
                $center,
                $role
            );

        return DB::transaction(
            function () use (
                $centerCode,
                $role
            ): string {
                /*
                 * insertOrIgnore makes initialization safe when
                 * two processes attempt to allocate the first ID
                 * for the same Center/Role scope.
                 */
                DB::table(
                    'account_identifier_sequences'
                )->insertOrIgnore([
                    'center_identifier_code' =>
                    $centerCode,

                    'role_code' =>
                    $role->value,

                    'last_sequence' =>
                    0,

                    'created_at' =>
                    now(),

                    'updated_at' =>
                    now(),
                ]);

                $sequenceRow =
                    DB::table(
                        'account_identifier_sequences'
                    )
                    ->where(
                        'center_identifier_code',
                        $centerCode
                    )
                    ->where(
                        'role_code',
                        $role->value
                    )
                    ->lockForUpdate()
                    ->first();

                if ($sequenceRow === null) {
                    throw new LogicException(
                        'Unable to initialize the account identifier sequence.'
                    );
                }

                $sequence =
                    (int) $sequenceRow
                        ->last_sequence;

                $maximum =
                    $this->policy
                    ->maximumSequence(
                        $role
                    );

                /*
                 * Skip identifiers that may already exist from
                 * legacy/manual account creation.
                 *
                 * users.account_login_identifier remains the final
                 * platform-wide uniqueness constraint.
                 */
                do {
                    $sequence++;

                    if (
                        $sequence
                        > $maximum
                    ) {
                        throw new DomainException(
                            sprintf(
                                'The account identifier range for %s has been exhausted.',
                                $role->label()
                            )
                        );
                    }

                    $identifier =
                        $this->formatIdentifier(
                            $centerCode,
                            $role,
                            $sequence
                        );
                } while (
                    User::withoutGlobalScopes()
                    ->where(
                        'account_login_identifier',
                        $identifier
                    )
                    ->exists()
                );

                DB::table(
                    'account_identifier_sequences'
                )
                    ->where(
                        'center_identifier_code',
                        $centerCode
                    )
                    ->where(
                        'role_code',
                        $role->value
                    )
                    ->update([
                        'last_sequence' =>
                        $sequence,

                        'updated_at' =>
                        now(),
                    ]);

                return $identifier;
            },
            3
        );
    }

    private function validatedCenterCode(
        ?Center $center,
        SystemRole $role
    ): string {
        if (
            $role
            === SystemRole::PlatformOwner
        ) {
            return $this->policy
                ->centerCode(
                    $center,
                    $role
                );
        }

        if ($center === null) {
            return $this->policy
                ->centerCode(
                    null,
                    $role
                );
        }

        /*
         * Never generate from mutable/tampered in-memory
         * Center state.
         */
        $persistedCenter =
            Center::query()
            ->whereKey(
                $center->getKey()
            )
            ->firstOrFail();

        return $this->policy
            ->centerCode(
                $persistedCenter,
                $role
            );
    }

    private function formatIdentifier(
        string $centerCode,
        SystemRole $role,
        int $sequence
    ): string {
        $domain =
            $this->policy
            ->roleDomain(
                $role,
                $sequence
            );

        $sequenceWithinDomain =
            $this->policy
            ->sequenceWithinDomain(
                $role,
                $sequence
            );

        return $centerCode
            . $domain
            . str_pad(
                (string) $sequenceWithinDomain,
                4,
                '0',
                STR_PAD_LEFT
            );
    }
}
