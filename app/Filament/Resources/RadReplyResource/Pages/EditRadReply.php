<?php

namespace App\Filament\Resources\RadReplyResource\Pages;

use App\Filament\Resources\RadReplyResource;
use App\Models\Nas;
use App\Models\RadAcct;
use Filament\Pages\Actions;
use Filament\Resources\Pages\EditRecord;
use Log;

class EditRadReply extends EditRecord
{
    protected static string $resource = RadReplyResource::class;

    protected function getActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    protected function afterSave(): void
    {
        $checkRadAcct = RadAcct::where('username', $this->record->username)->firstOrFail();
        $checkNas = Nas::where('nasname', $checkRadAcct->nasipaddress)->firstOrFail();
        $username = escapeshellarg($this->record->username);
        $rateLimit = escapeshellarg($this->record->value); // Pastikan value sesuai format "15M/10M"
        $ipAddress = escapeshellarg($checkNas->nasname); // Bisa dijadikan ENV jika dinamis
        $port = escapeshellarg($checkNas->ports); // Bisa dijadikan ENV jika dinamis
        $secret = escapeshellarg($checkNas->secret); // Bisa dijadikan ENV jika dinamis
        $commandChangeBandwidth = "echo \"User-Name={$username}, Mikrotik-Rate-Limit={$rateLimit}\" | radclient -x {$ipAddress}:{$port} coa {$secret}";
        $commandDisconnect= "echo \"User-Name={$username}\" | radclient -x {$ipAddress}:{$port} disconnect {$secret}";
        // $commandDisconnect = "echo \"User-Name={$username}\" | radclient -x {$ipAddress}:{$port} disconnect {$secret}";
        Log::info('commandChangeBandwidth: '.$commandChangeBandwidth);
        $outputChangeBandwidth = shell_exec($commandChangeBandwidth);
        $outputdisconnect = shell_exec($commandDisconnect);
        // $outputDisconnect = shell_exec($commandDisconnect);
        if ($outputChangeBandwidth) {
            Log::info("Command Output: \n".$outputChangeBandwidth);
        } else {
            Log::warning('Perintah gagal atau tidak ada output.');
        }
    }
}
