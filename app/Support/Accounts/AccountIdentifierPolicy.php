<?php

namespace App\Support\Accounts;

use App\Models\Center;
use App\Support\Enums\SystemRole;
use DomainException;

class AccountIdentifierPolicy
{
    public const PLATFORM_CENTER_CODE = '00';

    public const PLATFORM_OWNER_DOMAIN = 0;
    public const CENTER_OWNER_DOMAIN = 10;
    public const BRANCH_MANAGER_DOMAIN = 11;
    public const TEACHER_DOMAIN = 12;

    public const STUDENT_FIRST_DOMAIN = 13;
    public const STUDENT_LAST_DOMAIN = 20;

    public const FINANCE_EMPLOYEE_DOMAIN = 21;

    public const SEQUENCE_PER_DOMAIN = 9999;

    public const STUDENT_DOMAIN_COUNT = 8;

    public const STUDENT_MAX_SEQUENCE =
    self::SEQUENCE_PER_DOMAIN
        * self::STUDENT_DOMAIN_COUNT;

    public function centerCode(
        ?Center $center,
        SystemRole $role
    ): string {
        if (
            $role === SystemRole::PlatformOwner
        ) {
            if ($center !== null) {
                throw new DomainException(
                    'Platform Owner identifiers cannot belong to a language center.'
                );
            }

            return self::PLATFORM_CENTER_CODE;
        }

        if ($center === null) {
            throw new DomainException(
                'A language center is required for this account role.'
            );
        }

        $identifierCode =
            $center->identifier_code;

        if (
            ! is_string($identifierCode)
            || ! preg_match(
                '/^(0[1-9]|[1-9][0-9])$/',
                $identifierCode
            )
        ) {
            throw new DomainException(
                'The language center must have a valid two-digit identifier code from 01 through 99.'
            );
        }

        return $identifierCode;
    }

    public function maximumSequence(
        SystemRole $role
    ): int {
        return $role === SystemRole::Student
            ? self::STUDENT_MAX_SEQUENCE
            : self::SEQUENCE_PER_DOMAIN;
    }

    public function roleDomain(
        SystemRole $role,
        int $sequence
    ): string {
        if ($sequence < 1) {
            throw new DomainException(
                'Account identifier sequence must be positive.'
            );
        }

        $domain = match ($role) {
            SystemRole::PlatformOwner =>
            self::PLATFORM_OWNER_DOMAIN,

            SystemRole::CenterOwner =>
            self::CENTER_OWNER_DOMAIN,

            SystemRole::BranchManager =>
            self::BRANCH_MANAGER_DOMAIN,

            SystemRole::Teacher =>
            self::TEACHER_DOMAIN,

            SystemRole::FinanceEmployee =>
            self::FINANCE_EMPLOYEE_DOMAIN,

            SystemRole::Student =>
            $this->studentDomain(
                $sequence
            ),
        };

        return str_pad(
            (string) $domain,
            2,
            '0',
            STR_PAD_LEFT
        );
    }

    public function sequenceWithinDomain(
        SystemRole $role,
        int $sequence
    ): int {
        if (
            $sequence < 1
            || $sequence
            > $this->maximumSequence(
                $role
            )
        ) {
            throw new DomainException(
                'Account identifier sequence is outside the allowed range.'
            );
        }

        if (
            $role !== SystemRole::Student
        ) {
            return $sequence;
        }

        return (
            ($sequence - 1)
            % self::SEQUENCE_PER_DOMAIN
        ) + 1;
    }

    private function studentDomain(
        int $sequence
    ): int {
        if (
            $sequence < 1
            || $sequence
            > self::STUDENT_MAX_SEQUENCE
        ) {
            throw new DomainException(
                'The Student account identifier range has been exhausted.'
            );
        }

        $domainOffset = intdiv(
            $sequence - 1,
            self::SEQUENCE_PER_DOMAIN
        );

        return self::STUDENT_FIRST_DOMAIN
            + $domainOffset;
    }
}
