<?php

namespace App\Support\Enums;

enum ClassroomAvailabilityStatus: string
{
    case Available = 'available';

    case Unavailable = 'unavailable';
}
