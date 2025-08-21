<?php

namespace App\Filament\Resources\NasResource\Pages;

use App\Filament\Resources\NasResource;
use Filament\Resources\Pages\CreateRecord;
use Log;

class CreateNas extends CreateRecord
{
    protected static string $resource = NasResource::class;

    public static function afterCreate()
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
