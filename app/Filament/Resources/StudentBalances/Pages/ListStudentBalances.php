<?php

namespace App\Filament\Resources\StudentBalances\Pages;

use App\Filament\Resources\StudentBalances\StudentBalanceResource;
use Filament\Resources\Pages\ListRecords;

class ListStudentBalances extends ListRecords
{
    protected static string $resource =
    StudentBalanceResource::class;
}