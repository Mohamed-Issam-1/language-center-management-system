<?php

namespace App\Filament\Resources\ClassSessions\Pages;

use App\Filament\Resources\ClassSessions\ClassSessionResource;
use Filament\Resources\Pages\ListRecords;

class ListClassSessions extends ListRecords
{
    protected static string $resource =
    ClassSessionResource::class;
}