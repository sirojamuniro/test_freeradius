<?php

namespace App\Filament\Resources\RadCheckResource\Pages;

use App\Filament\Resources\RadCheckResource;
use Filament\Pages\Actions;
use Filament\Resources\Pages\ListRecords;

class ListRadChecks extends ListRecords
{
    protected static string $resource = RadCheckResource::class;

    protected function getActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
