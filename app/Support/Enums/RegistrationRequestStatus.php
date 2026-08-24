<?php

namespace App\Support\Enums;

enum RegistrationRequestStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
}