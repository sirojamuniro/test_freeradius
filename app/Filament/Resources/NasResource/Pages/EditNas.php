<?php

namespace App\Filament\Resources\NasResource\Pages;

use App\Filament\Resources\NasResource;
use Filament\Pages\Actions;
use Filament\Resources\Pages\EditRecord;
use Log;

class EditNas extends EditRecord
{
    protected static string $resource = NasResource::class;

    protected function getActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    public static function afterSave($record)
    {
        self::reloadFreeRadius();
    }

    protected static function reloadFreeRadius()
    {
        // Jalankan perintah untuk reload FreeRADIUS
        $command = 'sudo systemctl reload freeradius'; // Sesuaikan jika menggunakan service lain
        $output = shell_exec($command);

        // Logging output untuk debugging
        Log::info('FreeRADIUS Reloaded: '.$output);
    }
}
