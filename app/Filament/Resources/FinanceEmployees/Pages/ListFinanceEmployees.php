<?php

namespace App\Filament\Resources\FinanceEmployees\Pages;

use App\Filament\Resources\FinanceEmployees\FinanceEmployeeResource;
use Filament\Resources\Pages\ListRecords;

class ListFinanceEmployees extends ListRecords
{
    protected static string $resource =
    FinanceEmployeeResource::class;
}