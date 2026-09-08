<?php

namespace App\Filament\Resources\AuditRecords\Pages;

use App\Filament\Resources\AuditRecords\AuditRecordResource;
use Filament\Resources\Pages\ListRecords;

class ListAuditRecords extends ListRecords
{
    protected static string $resource =
    AuditRecordResource::class;
}