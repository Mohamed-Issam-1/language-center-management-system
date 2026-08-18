<?php

namespace App\Services\Audit;

use App\Models\AuditRecord;
use App\Models\Branch;
use App\Models\Center;
use App\Models\User;
use App\Support\Enums\SystemRole;
use App\Support\Tenancy\TenantContext;
use BackedEnum;
use DateTimeInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use JsonSerializable;
use LogicException;

class AuditRecorder
{
    private const REDACTED = '[REDACTED]';

    /**
     * Keys whose values must never be persisted in audit payloads.
     */
    private const SENSITIVE_KEYS = [
        'password',
        'password_confirmation',
        'password_hash',
        'password_digest',
        'secret_key',
        'private_key',
        'current_password',
        'new_password',
        'temporary_password',
        'one_time_password',
        'remember_token',
        'token',
        'access_token',
        'refresh_token',
        'api_token',
        'authorization',
        'cookie',
        'set_cookie',
        'secret',
        'client_secret',
        'api_secret',
        'api_key',
        'recovery_code',
        'recovery_codes',
        'otp',
        'otp_code',
        'verification_code',
    ];

    public function __construct(
        private readonly TenantContext $tenant
    ) {}

    public function record(
        User $actor,
        string $actionType,
        Model $subject,
        ?array $beforeValues = null,
        ?array $afterValues = null,
        ?array $metadata = null
    ): AuditRecord {
        /*
     * Re-read both actor and subject from the database.
     *
     * Audit identity and tenant ownership must never rely on
     * stale or locally mutated Eloquent model state.
     */
        $actor = $this->persistedActor(
            $actor
        );

        $subject = $this->persistedSubject(
            $subject
        );

        $role = $actor->systemRole();

        if ($role === null) {
            throw new LogicException(
                'The audit actor does not have a valid fixed system role.'
            );
        }

        $this->validateActionType(
            $actionType
        );

        $subjectId = (int) $subject->getKey();

        [
            $centerId,
            $branchId,
        ] = $this->resolveSubjectScope(
            $subject
        );

        $this->ensureActorAndSubjectMatchTenant(
            $actor,
            $role,
            $centerId
        );

        return AuditRecord::query()
            ->create([
                'center_id' => $centerId,
                'branch_id' => $branchId,
                'actor_user_id' => $actor->id,
                'actor_role' => $role->value,
                'action_type' => $actionType,
                'subject_type' => $subject->getTable(),
                'subject_id' => $subjectId,
                'before_values' =>
                $this->sanitizeNullablePayload(
                    $beforeValues
                ),
                'after_values' =>
                $this->sanitizeNullablePayload(
                    $afterValues
                ),
                'metadata' =>
                $this->sanitizeNullablePayload(
                    $metadata
                ),
                'occurred_at' => now(),
            ]);
    }

    private function persistedActor(
        User $actor
    ): User {
        if (
            ! $actor->exists
            || $actor->getKey() === null
        ) {
            throw new InvalidArgumentException(
                'The audit actor must be a persisted User Account.'
            );
        }

        $actorId = $actor->getKey();

        if (
            ! is_int($actorId)
            && ! (
                is_string($actorId)
                && ctype_digit($actorId)
            )
        ) {
            throw new InvalidArgumentException(
                'The audit actor must use a numeric identifier.'
            );
        }

        $persistedActor = User::query()
            ->with('role')
            ->find((int) $actorId);

        if ($persistedActor === null) {
            throw new InvalidArgumentException(
                'The audit actor no longer exists.'
            );
        }

        return $persistedActor;
    }

    private function validateActionType(
        string $actionType
    ): void {
        if (
            preg_match(
                '/\A[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)+\z/',
                $actionType
            ) !== 1
        ) {
            throw new InvalidArgumentException(
                'Audit action types must use lower-case dot notation such as "classroom.updated".'
            );
        }
    }

    private function persistedSubject(
        Model $subject
    ): Model {
        $subjectId = $this->resolveSubjectId(
            $subject
        );

        /*
     * Audit scope must come from persisted database state,
     * never from a stale or modified in-memory Model instance.
     */
        $persistedSubject = $subject
            ->newQueryWithoutScopes()
            ->find($subjectId);

        if ($persistedSubject === null) {
            throw new InvalidArgumentException(
                'The audited subject no longer exists.'
            );
        }

        return $persistedSubject;
    }
    private function resolveSubjectId(
        Model $subject
    ): int {
        if (
            ! $subject->exists
            || $subject->getKey() === null
        ) {
            throw new InvalidArgumentException(
                'The audited subject must be a persisted model.'
            );
        }

        $key = $subject->getKey();

        if (
            ! is_int($key)
            && ! (
                is_string($key)
                && ctype_digit($key)
            )
        ) {
            throw new InvalidArgumentException(
                'The audited subject must use a numeric identifier.'
            );
        }

        return (int) $key;
    }

    /**
     * Resolve tenant ownership exclusively from the persisted
     * audited subject.
     *
     * Request-supplied Center or Branch identifiers are never
     * accepted by the recorder.
     *
     * @return array{0: ?int, 1: ?int}
     */
    private function resolveSubjectScope(
        Model $subject
    ): array {
        if ($subject instanceof Center) {
            return [
                (int) $subject->id,
                null,
            ];
        }

        if ($subject instanceof Branch) {
            return [
                $this->nullableInteger(
                    $subject->center_id
                ),
                (int) $subject->id,
            ];
        }

        $centerId = $this->nullableInteger(
            $subject->getAttribute(
                'center_id'
            )
        );

        $branchId = $this->nullableInteger(
            $subject->getAttribute(
                'branch_id'
            )
        );

        if (
            $branchId !== null
            && $centerId === null
        ) {
            throw new LogicException(
                'A branch-scoped audited subject must also belong to a Center.'
            );
        }

        return [
            $centerId,
            $branchId,
        ];
    }

    private function ensureActorAndSubjectMatchTenant(
        User $actor,
        SystemRole $role,
        ?int $subjectCenterId
    ): void {
        if (! $this->tenant->isEstablished()) {
            throw new LogicException(
                'Tenant context must be established before recording an audit event.'
            );
        }

        if ($role === SystemRole::PlatformOwner) {
            if ($actor->center_id !== null) {
                throw new AuthorizationException(
                    'A Platform Owner audit actor must be platform-scoped.'
                );
            }

            if (! $this->tenant->isPlatformScoped()) {
                throw new AuthorizationException(
                    'Platform Owner audit events require platform tenant scope.'
                );
            }

            return;
        }

        if (! $this->tenant->isCenterScoped()) {
            throw new AuthorizationException(
                'Center account audit events require center tenant scope.'
            );
        }

        $tenantCenterId = $this->tenant
            ->centerId();

        if ($tenantCenterId === null) {
            throw new LogicException(
                'The established Center tenant context does not contain a Center identifier.'
            );
        }

        if ($actor->center_id !== $tenantCenterId) {
            throw new AuthorizationException(
                'The audit actor does not belong to the current Center tenant.'
            );
        }

        if ($subjectCenterId === null) {
            throw new AuthorizationException(
                'A Center-scoped account cannot record an audit event for a platform-scoped subject.'
            );
        }

        if ($subjectCenterId !== $tenantCenterId) {
            throw new AuthorizationException(
                'The audited subject is outside the current Center tenant.'
            );
        }
    }

    private function nullableInteger(
        mixed $value
    ): ?int {
        if ($value === null) {
            return null;
        }

        if (
            is_int($value)
            || (
                is_string($value)
                && ctype_digit($value)
            )
        ) {
            return (int) $value;
        }

        throw new LogicException(
            'Audit scope identifiers must be numeric.'
        );
    }

    private function sanitizeNullablePayload(
        ?array $payload
    ): ?array {
        if ($payload === null) {
            return null;
        }

        return $this->sanitizeArray(
            $payload
        );
    }

    private function sanitizeArray(
        array $payload
    ): array {
        $sanitized = [];

        foreach (
            $payload as $key => $value
        ) {
            if (
                is_string($key)
                && $this->isSensitiveKey(
                    $key
                )
            ) {
                $sanitized[$key] =
                    self::REDACTED;

                continue;
            }

            $sanitized[$key] =
                $this->sanitizeValue(
                    $value
                );
        }

        return $sanitized;
    }

    private function sanitizeValue(
        mixed $value
    ): mixed {
        if (is_array($value)) {
            return $this->sanitizeArray(
                $value
            );
        }

        if ($value instanceof BackedEnum) {
            return $value->value;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format(
                DATE_ATOM
            );
        }

        if ($value instanceof Arrayable) {
            return $this->sanitizeArray(
                $value->toArray()
            );
        }

        if ($value instanceof JsonSerializable) {
            return $this->sanitizeValue(
                $value->jsonSerialize()
            );
        }

        if (
            $value === null
            || is_scalar($value)
        ) {
            return $value;
        }

        throw new InvalidArgumentException(
            'Audit payload values must be JSON-serializable scalar, array, enum, date, Arrayable, or JsonSerializable values.'
        );
    }

    private function isSensitiveKey(
        string $key
    ): bool {
        $normalized = strtolower(
            preg_replace(
                '/[^a-z0-9]+/i',
                '_',
                trim($key)
            ) ?? ''
        );

        if (
            in_array(
                $normalized,
                self::SENSITIVE_KEYS,
                true
            )
        ) {
            return true;
        }

        if (
            str_ends_with(
                $normalized,
                '_password'
            )
        ) {
            return true;
        }

        if (
            str_ends_with(
                $normalized,
                '_token'
            )
        ) {
            return true;
        }

        if (
            str_ends_with(
                $normalized,
                '_secret'
            )
        ) {
            return true;
        }

        return false;
    }
}
