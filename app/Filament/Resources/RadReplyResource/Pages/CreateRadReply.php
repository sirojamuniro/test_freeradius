<?php

namespace App\Filament\Resources\RadReplyResource\Pages;

use App\Filament\Resources\RadReplyResource;
use App\Models\Nas;
use App\Models\RadAcct;
use Filament\Resources\Pages\CreateRecord;

class CreateRadReply extends CreateRecord
{
    protected static string $resource = RadReplyResource::class;

    protected function afterCreate(): void
    {
        $checkRadAcct = RadAcct::where('username', $this->record->username)->firstOrFail();
        $checkNas = Nas::where('nasname', $checkRadAcct->nasipaddress)->firstOrFail();
        $username = escapeshellarg($this->record->username);
        $rateLimit = escapeshellarg($this->record->value); // Pastikan value sesuai format "15M/10M"
        $attribute = escapeshellarg($this->record->attribute);
        $op = escapeshellarg($this->record->op);
        $ipAddress = $checkNas->nasname; // Bisa dijadikan ENV jika dinamis
        $port = $checkNas->ports; // Bisa dijadikan ENV jika dinamis
        $secret = $checkNas->secret; // Bisa dijadikan ENV jika dinamis

        // Perintah radclient
        $command = "echo \"User-Name={$username}, {$attribute}{$op}'{$rateLimit}'\" | radclient -x {$ipAddress}:{$port} coa {$secret}";

        // Eksekusi perintah
        shell_exec($command);
    }
}
