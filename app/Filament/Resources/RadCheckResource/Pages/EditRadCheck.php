<?php

namespace App\Filament\Resources\RadCheckResource\Pages;

use App\Filament\Resources\RadCheckResource;
use Filament\Pages\Actions;
use Filament\Resources\Pages\EditRecord;

class EditRadCheck extends EditRecord
{
    protected static string $resource = RadCheckResource::class;

    protected function getActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }


}
