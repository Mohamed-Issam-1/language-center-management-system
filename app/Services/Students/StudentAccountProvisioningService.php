<?php

namespace App\Services\Students;

use App\Models\Center;
use App\Models\Student;
use App\Models\User;
use App\Services\Accounts\AccountIdentifierGenerator;
use App\Services\Accounts\UserAccountManagementService;
use App\Support\Enums\SystemRole;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

final class StudentAccountProvisioningService
{
    public function __construct(
        private readonly UserAccountManagementService $accounts,
        private readonly AccountIdentifierGenerator $identifiers
    ) {}

    /**
     * @return array{
     *     account: User,
     *     temporary_password: string
     * }
     */
    public function provision(
        User $actor,
        Student $student,
        string $recoveryEmail
    ): array {
        $recoveryEmail =
            Str::lower(
                trim(
                    $recoveryEmail
                )
            );

        if (
            $recoveryEmail === ''
            || filter_var(
                $recoveryEmail,
                FILTER_VALIDATE_EMAIL
            ) === false
        ) {
            throw new DomainException(
                'A valid recovery email address is required.'
            );
        }

        /*
         * Plaintext exists only during this immediate
         * provisioning / delivery workflow.
         */
        $temporaryPassword =
            Str::password(20);

        return DB::transaction(
            function () use (
                $actor,
                $student,
                $recoveryEmail,
                $temporaryPassword
            ): array {
                $studentId =
                    $this->numericModelId(
                        $student,
                        'Student'
                    );

                /*
                 * Never trust mutable in-memory Student
                 * ownership or Center state.
                 */
                $student =
                    Student::withoutGlobalScopes()
                    ->whereKey(
                        $studentId
                    )
                    ->lockForUpdate()
                    ->first();

                if ($student === null) {
                    throw new InvalidArgumentException(
                        'The Student record no longer exists.'
                    );
                }

                $center =
                    Center::query()
                    ->whereKey(
                        $student->center_id
                    )
                    ->first();

                if ($center === null) {
                    throw new LogicException(
                        'The Student references a missing language center.'
                    );
                }

                /*
                 * Identifier allocation is inside the same
                 * outer transaction.
                 *
                 * If account creation / authorization fails,
                 * sequence allocation rolls back too.
                 */
                $identifier =
                    $this->identifiers
                    ->generate(
                        $center,
                        SystemRole::Student
                    );

                /*
                 * This authoritative service:
                 * - re-reads actor and Student;
                 * - enforces Center / Branch authorization;
                 * - requires an Active Student;
                 * - prevents duplicate Student-role accounts;
                 * - creates and links the account atomically;
                 * - audits both account creation and linkage.
                 */
                $account =
                    $this->accounts
                    ->createStudentAccountForStudent(
                        actor: $actor,
                        student: $student,
                        accountLoginIdentifier: $identifier,
                        recoveryEmail: $recoveryEmail,
                        temporaryPassword: $temporaryPassword
                    );

                return [
                    'account' =>
                    $account,

                    'temporary_password' =>
                    $temporaryPassword,
                ];
            },
            3
        );
    }

    private function numericModelId(
        Model $model,
        string $label
    ): int {
        if (
            ! $model->exists
            || $model->getKey() === null
        ) {
            throw new InvalidArgumentException(
                $label
                    . ' must be a persisted record.'
            );
        }

        $key =
            $model->getKey();

        if (
            ! is_int($key)
            && ! (
                is_string($key)
                && ctype_digit($key)
            )
        ) {
            throw new InvalidArgumentException(
                $label
                    . ' must use a numeric identifier.'
            );
        }

        return (int) $key;
    }
}