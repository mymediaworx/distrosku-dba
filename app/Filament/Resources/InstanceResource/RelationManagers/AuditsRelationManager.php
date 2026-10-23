<?php

namespace App\Filament\Resources\InstanceResource\RelationManagers;

use App\Filament\Resources\AuditLogResource;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;

class AuditsRelationManager extends RelationManager
{
    protected static string $relationship = 'audits';

    protected static ?string $title = 'Audit log';

    protected static ?string $icon = 'heroicon-o-clipboard-document-list';

    public function table(Table $table): Table
    {
        return AuditLogResource::table($table);
    }
}
