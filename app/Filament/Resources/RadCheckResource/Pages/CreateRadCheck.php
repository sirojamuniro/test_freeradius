<?php

namespace App\Filament\Resources\RadCheckResource\Pages;

use App\Filament\Resources\RadCheckResource;
use Filament\Resources\Pages\CreateRecord;

class CreateRadCheck extends CreateRecord
{
    protected static string $resource = RadCheckResource::class;

    protected function afterCreate(): void
    {
        $username = $this->record->username;

        if (! $username) {
            return;
        }

        app(\App\Services\RadiusService::class)->disconnectUser($username);
    }
}
