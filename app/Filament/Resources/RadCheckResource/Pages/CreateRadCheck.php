<?php

namespace App\Filament\Resources\RadCheckResource\Pages;

use App\Filament\Resources\RadCheckResource;
use Filament\Resources\Pages\CreateRecord;

class CreateRadCheck extends CreateRecord
{
    protected static string $resource = RadCheckResource::class;

    protected function afterCreate(): void
    {
        // Misalnya kita baru saja membuat user baru
        $username = $this->record->username;
        $password = $this->record->value; // biasanya attribute = Cleartext-Password
        $attribute = $this->record->attribute;
        $op = $this->record->op;

        // Di radcheck cukup tersimpan, tidak perlu langsung push ke NAS
        // tapi kalau mau "paksa disconnect" user lama dengan username yg sama (misalnya update password), bisa:

        // Cari user aktif di radacct
        $onlineSessions = \App\Models\RadAcct::where('username', $username)
            ->whereNull('acctstoptime')
            ->get();

        foreach ($onlineSessions as $session) {
            $nas = \App\Models\Nas::where('nasname', $session->nasipaddress)->first();
            if ($nas) {
                $ipAddress = $nas->nasname;
                $port = $nas->ports;
                $secret = $nas->secret;

                // Kirim perintah disconnect (Packet of Disconnect)
                $cmd = "echo \"User-Name={$username}\" | radclient -x {$ipAddress}:{$port} disconnect {$secret}";
                shell_exec($cmd);
            }
        }
    }
}
