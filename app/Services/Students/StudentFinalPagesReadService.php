<?php

namespace App\Services\Students;

use App\Models\Enrollment;
use App\Models\Payment;
use App\Models\Student;
use App\Models\User;
use App\Services\Finance\FinanceReadService;
use App\Support\Enums\EnrollmentStatus;
use App\Support\Enums\PaymentStatus;
use App\Support\Enums\SystemRole;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use RuntimeException;

final class StudentFinalPagesReadService
{
    public function __construct(
        private readonly FinanceReadService $finance,
        private readonly StudentDashboardReadService $studentDashboard,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function profileForUser(
        User $actor
    ): array {
        $student =
            $this->resolveStudent(
                $actor
            );

        $firstEnrollment =
            Enrollment::query()
                ->withoutGlobalScopes()
                ->where(
                    'center_id',
                    $student->center_id
                )
                ->where(
                    'student_id',
                    $student->id
                )
                ->orderBy(
                    'enrollment_date'
                )
                ->first();

        $activeCourses =
            Enrollment::query()
                ->withoutGlobalScopes()
                ->where(
                    'center_id',
                    $student->center_id
                )
                ->where(
                    'student_id',
                    $student->id
                )
                ->where(
                    'enrollment_status',
                    EnrollmentStatus::Active->value
                )
                ->count();

        $person =
            $student->person;

        return [
            'fullName' =>
                $person?->full_name
                ?: $actor->name
                ?: 'Student',

            'studentId' =>
                $actor->account_login_identifier
                ?: (string) $student->id,

            'roleLabel' =>
                'Student',

            'email' =>
                $person?->email
                ?: $actor->email
                ?: '',

            'phone' =>
                $person?->phone_number
                ?: '',

            /*
             * Person currently stores city_of_residence,
             * not a separate full postal-address column.
             */
            'address' =>
                $person?->city_of_residence
                ?: '',

            'enrolledOn' =>
                $firstEnrollment
                    ?->enrollment_date
                    ?->format(
                        'M j, Y'
                    )
                ?: $student
                    ->created_at
                    ?->format(
                        'M j, Y'
                    )
                ?: '—',

            'centerName' =>
                $student->center?->name
                ?: 'Language Center',

            'branchName' =>
                $student->branch?->name
                ?: 'Branch',

            'activeCourses' =>
                $activeCourses,

            /*
             * Multilingual preference storage has not
             * been implemented by the backend yet.
             */
            'displayLanguage' =>
                'English',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function paymentsForUser(
        User $actor
    ): array {
        $student =
            $this->resolveStudent(
                $actor
            );

        $balance =
            $this->finance
                ->studentBalance(
                    $actor,
                    $student
                );

        $totals =
            $balance['totals']
            ?? [];

        $currency =
            array_key_first(
                $totals
            );

        if ($currency === null) {
            $currency =
                strtoupper(
                    trim(
                        (string) (
                            $student
                                ->center
                                ?->operating_currency_code
                            ?: 'USD'
                        )
                    )
                );
        }

        $allRows =
            collect(
                $balance[
                    'installments'
                ] ?? []
            );

        $rows =
            $allRows
                ->filter(
                    fn (
                        array $row
                    ): bool =>
                    strtoupper(
                        (string) (
                            $row[
                                'currency_code'
                            ] ?? ''
                        )
                    ) === $currency
                )
                ->values();

        $courseNames = [];

        foreach (
            $rows
                ->pluck(
                    'enrollment_id'
                )
                ->unique()
                ->values()
            as $enrollmentId
        ) {
            $details =
                $this
                    ->studentDashboard
                    ->courseDetailsForUser(
                        $actor,
                        (int)
                        $enrollmentId
                    );

            $courseNames[
                (int) $enrollmentId
            ] =
                $details[
                    'course'
                ]['title']
                ?? 'Course';
        }

        $installmentIds =
            $rows
                ->pluck(
                    'installment_id'
                )
                ->map(
                    fn (
                        mixed $id
                    ): int =>
                    (int) $id
                )
                ->all();

        $payments =
            Payment::query()
                ->withoutGlobalScopes()
                ->where(
                    'center_id',
                    $student->center_id
                )
                ->where(
                    'student_id',
                    $student->id
                )
                ->where(
                    'status',
                    PaymentStatus::Posted->value
                )
                ->with([
                    'allocations' =>
                        fn (
                            $query
                        ) =>
                        $query
                            ->withoutGlobalScopes(),
                ])
                ->orderByDesc(
                    'paid_at'
                )
                ->orderByDesc('id')
                ->get();

        /*
         * A Fee Installment can technically have more
         * than one allocation. For the Student table,
         * use the latest posted receipt related to it.
         */
        $paymentByInstallment = [];

        foreach (
            $payments as $payment
        ) {
            foreach (
                $payment->allocations
                as $allocation
            ) {
                $installmentId =
                    (int)
                    $allocation
                        ->fee_installment_id;

                if (
                    ! in_array(
                        $installmentId,
                        $installmentIds,
                        true
                    )
                ) {
                    continue;
                }

                if (
                    isset(
                        $paymentByInstallment[
                            $installmentId
                        ]
                    )
                ) {
                    continue;
                }

                $paymentByInstallment[
                    $installmentId
                ] = $payment;
            }
        }

        $records =
            $rows
                ->map(
                    function (
                        array $row
                    ) use (
                        $courseNames,
                        $paymentByInstallment
                    ): array {
                        $installmentId =
                            (int)
                            $row[
                                'installment_id'
                            ];

                        $payment =
                            $paymentByInstallment[
                                $installmentId
                            ] ?? null;

                        $status =
                            match (
                                $row[
                                    'status'
                                ] ?? ''
                            ) {
                                'paid' =>
                                    'Paid',

                                'overdue' =>
                                    'Overdue',

                                default =>
                                    'Pending',
                            };

                        $receiptNumber =
                            $payment
                                ?->receipt_number;

                        return [
                            'id' =>
                                $installmentId,

                            'receiptNo' =>
                                $receiptNumber,

                            'receiptHref' =>
                                $receiptNumber
                                    ? route(
                                        'student.payments.receipt',
                                        [
                                            'receipt' =>
                                                $receiptNumber,
                                        ]
                                    )
                                    : null,

                            'course' =>
                                $courseNames[
                                    (int)
                                    $row[
                                        'enrollment_id'
                                    ]
                                ]
                                ?? 'Course',

                            'amount' =>
                                (float)
                                (
                                    $row[
                                        'amount'
                                    ] ?? 0
                                ),

                            'dueDate' =>
                                CarbonImmutable::parse(
                                    (string)
                                    $row[
                                        'due_date'
                                    ]
                                )
                                    ->format(
                                        'M j, Y'
                                    ),

                            'dueDateSort' =>
                                (string)
                                $row[
                                    'due_date'
                                ],

                            'paidDate' =>
                                $payment
                                    ?->paid_at
                                    ?->format(
                                        'M j, Y'
                                    ),

                            'method' =>
                                $payment
                                    ? $this
                                        ->paymentMethodLabel(
                                            $payment
                                                ->payment_method
                                        )
                                    : null,

                            'status' =>
                                $status,
                        ];
                    }
                )
                ->all();

        $currencyTotals =
            $totals[
                $currency
            ] ?? [
                'obligation' =>
                    '0.00',

                'paid' =>
                    '0.00',

                'balance' =>
                    '0.00',
            ];

        $overdue =
            $rows
                ->filter(
                    fn (
                        array $row
                    ): bool =>
                    (bool) (
                        $row[
                            'is_overdue'
                        ] ?? false
                    )
                )
                ->sum(
                    fn (
                        array $row
                    ): float =>
                    (float) (
                        $row[
                            'balance'
                        ] ?? 0
                    )
                );

        return [
            'student' =>
                $this
                    ->studentIdentity(
                        $actor,
                        $student
                    ),

            'center' =>
                $this
                    ->centerIdentity(
                        $student
                    ),

            'summary' => [
                'currency' =>
                    $currency,

                'totalFees' =>
                    (float)
                    $currencyTotals[
                        'obligation'
                    ],

                /*
                 * No persisted discount field currently
                 * exists in Finance.
                 */
                'discount' =>
                    0,

                'totalPaid' =>
                    (float)
                    $currencyTotals[
                        'paid'
                    ],

                'remaining' =>
                    (float)
                    $currencyTotals[
                        'balance'
                    ],

                'overdue' =>
                    (float)
                    $overdue,
            ],

            'installments' =>
                $records,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function receiptForUser(
        User $actor,
        string $receiptNumber
    ): array {
        $student =
            $this->resolveStudent(
                $actor
            );

        /*
         * FinanceReadService performs Payment policy and
         * Student ownership authorization.
         */
        $receipt =
            $this->finance
                ->receipt(
                    $actor,
                    $receiptNumber
                );

        if (
            (int)
            (
                $receipt[
                    'student'
                ]['id']
                ?? 0
            )
            !== $student->id
        ) {
            throw new AuthorizationException(
                'This receipt does not belong to the authenticated Student.'
            );
        }

        $allocation =
            collect(
                $receipt[
                    'allocations'
                ] ?? []
            )->first();

        if (
            ! is_array(
                $allocation
            )
        ) {
            throw new RuntimeException(
                'The Payment Receipt does not have an Enrollment allocation.'
            );
        }

        $enrollmentId =
            (int)
            $allocation[
                'enrollment_id'
            ];

        $courseDetails =
            $this
                ->studentDashboard
                ->courseDetailsForUser(
                    $actor,
                    $enrollmentId
                );

        $course =
            $courseDetails[
                'course'
            ];

        $information =
            collect(
                $course[
                    'information'
                ] ?? []
            )
                ->mapWithKeys(
                    fn (
                        array $row
                    ): array => [
                        (string)
                        $row[
                            'label'
                        ] =>
                            (string)
                            $row[
                                'value'
                            ],
                    ]
                );

        $enrollment =
            Enrollment::query()
                ->withoutGlobalScopes()
                ->whereKey(
                    $enrollmentId
                )
                ->where(
                    'center_id',
                    $student->center_id
                )
                ->where(
                    'student_id',
                    $student->id
                )
                ->first();

        if ($enrollment === null) {
            throw new AuthorizationException(
                'The Receipt Enrollment is unavailable.'
            );
        }

        $payment =
            Payment::query()
                ->withoutGlobalScopes()
                ->whereKey(
                    (int)
                    $receipt[
                        'payment_id'
                    ]
                )
                ->where(
                    'center_id',
                    $student->center_id
                )
                ->where(
                    'student_id',
                    $student->id
                )
                ->with([
                    'receivedBy' =>
                        fn (
                            $query
                        ) =>
                        $query
                            ->withoutGlobalScopes(),

                    'branch' =>
                        fn (
                            $query
                        ) =>
                        $query
                            ->withoutGlobalScopes(),
                ])
                ->first();

        if ($payment === null) {
            throw new AuthorizationException(
                'The Receipt Payment is unavailable.'
            );
        }

        $person =
            $student->person;

        $coursePayment =
            $course[
                'payment'
            ] ?? [];

        return [
            'student' =>
                $this
                    ->studentIdentity(
                        $actor,
                        $student
                    ),

            'center' =>
                $this
                    ->centerIdentity(
                        $student
                    ),

            'receipt' => [
                'receiptNo' =>
                    (string)
                    $receipt[
                        'receipt_number'
                    ],

                'currency' =>
                    strtoupper(
                        (string)
                        $receipt[
                            'currency_code'
                        ]
                    ),

                'issuedAt' =>
                    $payment
                        ->paid_at
                        ?->format(
                            'M j, Y'
                        )
                    ?: '—',

                'status' =>
                    'Paid',

                'issuedTo' => [
                    'name' =>
                        $person
                            ?->full_name
                        ?: $actor->name
                        ?: 'Student',

                    'studentId' =>
                        $actor
                            ->account_login_identifier
                        ?: (string)
                        $student->id,

                    'email' =>
                        $person
                            ?->email
                        ?: $actor
                            ->email
                        ?: '',

                    'phone' =>
                        $person
                            ?->phone_number
                        ?: '',
                ],

                'paymentDetails' => [
                    'paymentDate' =>
                        $payment
                            ->paid_at
                            ?->format(
                                'M j, Y'
                            )
                        ?: '—',

                    'dueDate' =>
                        CarbonImmutable::parse(
                            (string)
                            $allocation[
                                'installment_due_date'
                            ]
                        )->format(
                            'M j, Y'
                        ),

                    'method' =>
                        $this
                            ->paymentMethodLabel(
                                $payment
                                    ->payment_method
                            ),
                ],

                'course' => [
                    'name' =>
                        (string)
                        (
                            $course[
                                'title'
                            ]
                            ?? 'Course'
                        ),

                    'level' =>
                        $information
                            ->get(
                                'Level',
                                '—'
                            ),

                    'section' =>
                        $information
                            ->get(
                                'Section',
                                '—'
                            ),

                    'teacher' =>
                        $information
                            ->get(
                                'Teacher',
                                '—'
                            ),

                    'branch' =>
                        $information
                            ->get(
                                'Branch',
                                $student
                                    ->branch
                                    ?->name
                                ?: '—'
                            ),

                    'startDate' =>
                        $this
                            ->durationPart(
                                $information
                                    ->get(
                                        'Duration',
                                        ''
                                    ),
                                0
                            ),

                    'endDate' =>
                        $this
                            ->durationPart(
                                $information
                                    ->get(
                                        'Duration',
                                        ''
                                    ),
                                1
                            ),

                    'enrollmentDate' =>
                        $enrollment
                            ->enrollment_date
                            ?->format(
                                'M j, Y'
                            )
                        ?: '—',
                ],

                'payment' => [
                    'type' =>
                        'Tuition Installment',

                    'installmentAmount' =>
                        (float)
                        (
                            $allocation[
                                'amount'
                            ] ?? 0
                        ),

                    'totalCourseFees' =>
                        (float)
                        (
                            $coursePayment[
                                'totalFees'
                            ] ?? 0
                        ),

                    'totalPaidToDate' =>
                        (float)
                        (
                            $coursePayment[
                                'paid'
                            ] ?? 0
                        ),

                    'remainingBalance' =>
                        (float)
                        (
                            $coursePayment[
                                'remaining'
                            ] ?? 0
                        ),

                    'amountReceived' =>
                        (float)
                        (
                            $receipt[
                                'amount'
                            ] ?? 0
                        ),
                ],

                'receivedBy' => [
                    'name' =>
                        $payment
                            ->receivedBy
                            ?->name
                        ?: 'Administration Office',

                    'role' =>
                        'Administration Officer',

                    'branch' =>
                        $payment
                            ->branch
                            ?->name
                        ?: $student
                            ->branch
                            ?->name
                        ?: 'Branch',
                ],
            ],
        ];
    }

    private function resolveStudent(
        User $actor
    ): Student {
        if (
            $actor->systemRole()
            !== SystemRole::Student
        ) {
            throw new AuthorizationException(
                'Only a Student account may access Student portal data.'
            );
        }

        if (
            $actor->center_id
            === null
        ) {
            throw new AuthorizationException(
                'The Student account does not belong to a Center.'
            );
        }

        $student =
            Student::query()
                ->withoutGlobalScopes()
                ->where(
                    'center_id',
                    $actor->center_id
                )
                ->where(
                    'user_id',
                    $actor->id
                )
                ->with([
                    'center',

                    'branch' =>
                        fn (
                            $query
                        ) =>
                        $query
                            ->withoutGlobalScopes(),

                    'person' =>
                        fn (
                            $query
                        ) =>
                        $query
                            ->withoutGlobalScopes(),
                ])
                ->first();

        if ($student === null) {
            throw new AuthorizationException(
                'The Student record could not be resolved.'
            );
        }

        return $student;
    }

    /**
     * @return array{
     *     name: string,
     *     studentId: string
     * }
     */
    private function studentIdentity(
        User $actor,
        Student $student
    ): array {
        return [
            'name' =>
                $student
                    ->person
                    ?->full_name
                ?: $actor->name
                ?: 'Student',

            'studentId' =>
                $actor
                    ->account_login_identifier
                ?: (string)
                    $student->id,
        ];
    }

    /**
     * @return array{
     *     name: string,
     *     branch: string
     * }
     */
    private function centerIdentity(
        Student $student
    ): array {
        return [
            'name' =>
                $student
                    ->center
                    ?->name
                ?: 'Language Center',

            'branch' =>
                $student
                    ->branch
                    ?->name
                ?: 'Branch',
        ];
    }

    private function paymentMethodLabel(
        ?string $method
    ): string {
        if (
            $method === null
            || trim(
                $method
            ) === ''
        ) {
            return '—';
        }

        return ucwords(
            str_replace(
                [
                    '_',
                    '-',
                ],
                ' ',
                $method
            )
        );
    }

    private function durationPart(
        string $duration,
        int $index
    ): string {
        $parts =
            preg_split(
                '/\s+[–—-]\s+/u',
                trim(
                    $duration
                ),
                2
            );

        if (
            ! is_array(
                $parts
            )
        ) {
            return '—';
        }

        return trim(
            $parts[
                $index
            ] ?? '—'
        );
    }
}