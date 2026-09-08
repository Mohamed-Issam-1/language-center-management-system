<?php

namespace App\Services\Staff;

use App\Models\BranchManager;
use App\Models\Center;
use App\Models\FinanceEmployee;
use App\Models\Person;
use App\Models\Teacher;
use App\Models\User;
use App\Services\Accounts\AccountIdentifierGenerator;
use App\Services\Accounts\UserAccountManagementService;
use App\Support\Enums\StaffStatus;
use App\Support\Enums\SystemRole;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

final class StaffAccountProvisioningService
{
    public function __construct(
        private readonly UserAccountManagementService $accounts,
        private readonly AccountIdentifierGenerator $identifiers,
        private readonly StaffOperationalManagementService $staff
    ) {}

    /**
     * @return array{
     *     account: User,
     *     temporary_password: string
     * }
     */
    public function provisionTeacher(
        User $actor,
        Teacher $teacher,
        string $recoveryEmail
    ): array {
        return $this->provision(
            actor: $actor,
            record: $teacher,
            role: SystemRole::Teacher,
            modelClass: Teacher::class,
            label: 'Teacher',
            recoveryEmail: $recoveryEmail
        );
    }

    /**
     * @return array{
     *     account: User,
     *     temporary_password: string
     * }
     */
    public function provisionBranchManager(
        User $actor,
        BranchManager $branchManager,
        string $recoveryEmail
    ): array {
        return $this->provision(
            actor: $actor,
            record: $branchManager,
            role: SystemRole::BranchManager,
            modelClass: BranchManager::class,
            label: 'Branch Manager',
            recoveryEmail: $recoveryEmail
        );
    }

    /**
     * @return array{
     *     account: User,
     *     temporary_password: string
     * }
     */
    public function provisionFinanceEmployee(
        User $actor,
        FinanceEmployee $financeEmployee,
        string $recoveryEmail
    ): array {
        return $this->provision(
            actor: $actor,
            record: $financeEmployee,
            role: SystemRole::FinanceEmployee,
            modelClass: FinanceEmployee::class,
            label: 'Finance Employee',
            recoveryEmail: $recoveryEmail
        );
    }

    /**
     * @param Teacher|BranchManager|FinanceEmployee $record
     * @param class-string<Teacher|BranchManager|FinanceEmployee> $modelClass
     *
     * @return array{
     *     account: User,
     *     temporary_password: string
     * }
     */
    private function provision(
        User $actor,
        Model $record,
        SystemRole $role,
        string $modelClass,
        string $label,
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

        $temporaryPassword =
            Str::password(20);

        return DB::transaction(
            function () use (
                $actor,
                $record,
                $role,
                $modelClass,
                $label,
                $recoveryEmail,
                $temporaryPassword
            ): array {
                $recordId =
                    $this->numericModelId(
                        $record,
                        $label
                    );

                /*
                 * Never trust mutable in-memory Staff state.
                 */
                $record =
                    $modelClass::withoutGlobalScopes()
                    ->whereKey(
                        $recordId
                    )
                    ->lockForUpdate()
                    ->first();

                if ($record === null) {
                    throw new InvalidArgumentException(
                        $label
                            . ' record no longer exists.'
                    );
                }

                if (
                    $record->status
                    !== StaffStatus::Active
                ) {
                    throw new DomainException(
                        'A deactivated '
                            . $label
                            . ' cannot receive a new User Account.'
                    );
                }

                if (
                    $record->user_id
                    !== null
                ) {
                    throw new DomainException(
                        'The '
                            . $label
                            . ' already has a linked User Account.'
                    );
                }

                if (
                    $record->center_id
                    === null
                    || $record->person_id
                    === null
                ) {
                    throw new LogicException(
                        'The '
                            . $label
                            . ' does not have valid Center and Person relationships.'
                    );
                }

                $person =
                    Person::withoutGlobalScopes()
                    ->whereKey(
                        $record->person_id
                    )
                    ->where(
                        'center_id',
                        $record->center_id
                    )
                    ->lockForUpdate()
                    ->first();

                if ($person === null) {
                    throw new LogicException(
                        'The '
                            . $label
                            . ' Person identity no longer exists in the Staff Center.'
                    );
                }

                $nationalIdNumber =
                    trim(
                        (string)
                        $person
                            ->national_id_number
                    );

                if (
                    $nationalIdNumber
                    === ''
                ) {
                    throw new DomainException(
                        'The '
                            . $label
                            . ' Person must have a National ID Number before an account can be created.'
                    );
                }

                $center =
                    Center::query()
                    ->whereKey(
                        $record->center_id
                    )
                    ->lockForUpdate()
                    ->first();

                if ($center === null) {
                    throw new LogicException(
                        'The Staff Center no longer exists.'
                    );
                }

                /*
                 * Identifier allocation participates in this
                 * transaction. A later failure rolls it back.
                 */
                $identifier =
                    $this->identifiers
                    ->generate(
                        $center,
                        $role
                    );

                /*
                 * UserAccountManagementService remains the
                 * authoritative account boundary.
                 */
                $account =
                    $this->accounts
                    ->create(
                        actor: $actor,
                        center: $center,
                        nationalIdNumber: $nationalIdNumber,
                        role: $role,
                        accountLoginIdentifier: $identifier,
                        recoveryEmail: $recoveryEmail,
                        temporaryPassword: $temporaryPassword
                    );

                /*
                 * Operational linkage remains behind
                 * StaffOperationalManagementService.
                 *
                 * Branch assignment is intentionally NOT
                 * performed here.
                 */
                match ($role) {
                    SystemRole::Teacher =>
                    $this->staff
                        ->linkTeacherAccount(
                            $actor,
                            $record,
                            $account
                        ),

                    SystemRole::BranchManager =>
                    $this->staff
                        ->linkBranchManagerAccount(
                            $actor,
                            $record,
                            $account
                        ),

                    SystemRole::FinanceEmployee =>
                    $this->staff
                        ->linkFinanceEmployeeAccount(
                            $actor,
                            $record,
                            $account
                        ),

                    default =>
                    throw new LogicException(
                        'Unsupported Staff account role.'
                    ),
                };

                $account->refresh();

                $account->loadMissing([
                    'person',
                    'role',
                    'center',
                ]);

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
            || $model->getKey()
            === null
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
                && ctype_digit(
                    $key
                )
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