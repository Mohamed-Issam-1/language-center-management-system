<?php

namespace App\Services\Registration;

use App\Models\Person;
use App\Models\RegistrationRequest;
use App\Models\User;
use App\Support\Enums\SystemRole;

final readonly class RegistrationApprovalResult
{
    public function __construct(
        public RegistrationRequest $registrationRequest,
        public Person $person,
        public User $account,
        public SystemRole $role,
        public string $temporaryPassword,
        public string $recipientEmail
    ) {}
}
