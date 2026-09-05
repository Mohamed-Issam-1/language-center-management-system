<?php

namespace App\Services\Reports;

use App\Models\User;
use App\Support\Enums\SystemRole;
use Illuminate\Auth\Access\AuthorizationException;

final class DashboardReadService
{
    public function __construct(
        private readonly ReportReadService $reports
    ) {}

    /**
     * Compose the role-specific dashboard payload from
     * authoritative reporting services.
     *
     * Every dashboard receives a stable payload shape.
     * Sections outside the account's operational role are null.
     *
     * @return array{
     *     scope: array<string, mixed>,
     *     enrollments: array<string, mixed>|null,
     *     classes: array<string, mixed>|null,
     *     attendance: array<string, mixed>|null,
     *     finance: array<string, mixed>|null
     * }
     */
    public function forUser(
        User $actor
    ): array {
        $scope =
            $this->reports
            ->scope(
                $actor
            );

        $role =
            SystemRole::tryFrom(
                $scope['role']
            );

        if ($role === null) {
            throw new AuthorizationException(
                'The reporting role cannot be resolved.'
            );
        }

        return match ($role) {
            SystemRole::PlatformOwner =>
            $this->payload(
                scope: $scope,
                enrollments: $this->withoutScope(
                    $this->reports
                        ->enrollmentSummary(
                            $actor
                        )
                ),
                classes: $this->withoutScope(
                    $this->reports
                        ->classSummary(
                            $actor
                        )
                )
            ),

            SystemRole::CenterOwner,
            SystemRole::BranchManager =>
            $this->payload(
                scope: $scope,
                enrollments: $this->withoutScope(
                    $this->reports
                        ->enrollmentSummary(
                            $actor
                        )
                ),
                classes: $this->withoutScope(
                    $this->reports
                        ->classSummary(
                            $actor
                        )
                ),
                attendance: $this->withoutScope(
                    $this->reports
                        ->attendanceSummary(
                            $actor
                        )
                ),
                finance: $this->withoutScope(
                    $this->reports
                        ->financeSummary(
                            $actor
                        )
                )
            ),

            SystemRole::FinanceEmployee =>
            $this->payload(
                scope: $scope,
                finance: $this->withoutScope(
                    $this->reports
                        ->financeSummary(
                            $actor
                        )
                )
            ),

            SystemRole::Teacher =>
            $this->payload(
                scope: $scope,
                enrollments: $this->withoutScope(
                    $this->reports
                        ->enrollmentSummary(
                            $actor
                        )
                ),
                classes: $this->withoutScope(
                    $this->reports
                        ->classSummary(
                            $actor
                        )
                ),
                attendance: $this->withoutScope(
                    $this->reports
                        ->attendanceSummary(
                            $actor
                        )
                )
            ),

            SystemRole::Student =>
            $this->payload(
                scope: $scope,
                enrollments: $this->withoutScope(
                    $this->reports
                        ->enrollmentSummary(
                            $actor
                        )
                ),
                classes: $this->withoutScope(
                    $this->reports
                        ->classSummary(
                            $actor
                        )
                ),
                attendance: $this->withoutScope(
                    $this->reports
                        ->attendanceSummary(
                            $actor
                        )
                ),
                finance: $this->withoutScope(
                    $this->reports
                        ->financeSummary(
                            $actor
                        )
                )
            ),
        };
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed>|null $enrollments
     * @param array<string, mixed>|null $classes
     * @param array<string, mixed>|null $attendance
     * @param array<string, mixed>|null $finance
     *
     * @return array{
     *     scope: array<string, mixed>,
     *     enrollments: array<string, mixed>|null,
     *     classes: array<string, mixed>|null,
     *     attendance: array<string, mixed>|null,
     *     finance: array<string, mixed>|null
     * }
     */
    private function payload(
        array $scope,
        ?array $enrollments = null,
        ?array $classes = null,
        ?array $attendance = null,
        ?array $finance = null
    ): array {
        return [
            'scope' =>
            $scope,

            'enrollments' =>
            $enrollments,

            'classes' =>
            $classes,

            'attendance' =>
            $attendance,

            'finance' =>
            $finance,
        ];
    }

    /**
     * The dashboard already exposes the authoritative scope
     * once at the root level, so section-level copies are removed.
     *
     * @param array<string, mixed> $section
     *
     * @return array<string, mixed>
     */
    private function withoutScope(
        array $section
    ): array {
        unset(
            $section['scope']
        );

        return $section;
    }
}