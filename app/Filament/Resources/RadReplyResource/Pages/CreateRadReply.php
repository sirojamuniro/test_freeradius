<?php

namespace App\Filament\Resources\RadReplyResource\Pages;

use App\Filament\Resources\RadReplyResource;
use App\Models\Nas;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateRadReply extends CreateRecord
{
    protected static string $resource = RadReplyResource::class;

    protected function afterCreate(Model $record): void
    {
        $checkNas = Nas::where('username', $record->username)->firstOrFail();
        $username = escapeshellarg($record->username);
        $rateLimit = escapeshellarg($record->value); // Pastikan value sesuai format "15M/10M"
        $ipAddress = $checkNas->nasname; // Bisa dijadikan ENV jika dinamis
        $port = $checkNas->ports; // Bisa dijadikan ENV jika dinamis
        $secret = $checkNas->secret; // Bisa dijadikan ENV jika dinamis

        // Perintah radclient
        $command = "echo \"User-Name={$username}, Mikrotik-Rate-Limit='{$rateLimit}'\" | radclient -x {$ipAddress}:{$port} coa {$secret}";

        // Eksekusi perintah
        shell_exec($command);
    }
}
