<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class RadiusService
{
    public function addUser($username, $password, $vendor, $ipAddress, $port, $secret)
    {
        $vendor = $vendor ?? 'mikrotik';
        // Set tanggal kedaluwarsa (30 hari dari sekarang)
        $expireDate = Carbon::now()->addDays(30)->format('Y-m-d H:i:s');
        $fupLimit = 100 * 1024 * 1024 * 1024; // 100GB dalam bytes

        // Konfigurasi bandwidth awal & FUP berdasarkan vendor
        $bandwidthConfigs = [
            'mikrotik' => [
                'max_speed' => '10M/15M',
                'min_speed' => '10M/20M',
                'fup_max' => '5M/5M',
                'fup_min' => '2M/2M',
                'attribute' => 'Mikrotik-Rate-Limit',
            ],
            'cisco' => [
                'max_speed' => 'ip:sub-rate-limit=10000000 15000000',
                'min_speed' => 'ip:sub-rate-limit=10000000 20000000',
                'fup_max' => 'ip:sub-rate-limit=5000000 5000000',
                'fup_min' => 'ip:sub-rate-limit=2000000 2000000',
                'attribute' => 'Cisco-AVPair',
            ],
            'juniper' => [
                'max_speed' => 'ip:sub-rate-limit=10000000 15000000',
                'min_speed' => 'ip:sub-rate-limit=10000000 20000000',
                'fup_max' => 'ip:sub-rate-limit=5000000 5000000',
                'fup_min' => 'ip:sub-rate-limit=2000000 2000000',
                'attribute' => 'Juniper-AVPair',
            ],
            'huawei' => [
                'max_speed' => '10000000', // 10Mbps
                'min_speed' => '15000000', // 15Mbps
                'fup_max' => '5000000', // 5Mbps
                'fup_min' => '2000000', // 2Mbps
                'attribute_input' => 'Huawei-Input-Peak-Rate',
                'attribute_output' => 'Huawei-Output-Peak-Rate',
            ],
        ];

        if (! isset($bandwidthConfigs[$vendor])) {
            throw new \Exception('Vendor tidak ditemukan. Default: Mikrotik.');
        }

        $config = $bandwidthConfigs[$vendor];

        // Periksa apakah user sudah ada di `radcheck`
        $userExists = DB::table('radcheck')->where('username', $username)->exists();
        if (! $userExists) {
            DB::table('radcheck')->insert([
                ['username' => $username, 'attribute' => 'Cleartext-Password', 'op' => ':=', 'value' => $password],
                ['username' => $username, 'attribute' => 'Expiration', 'op' => ':=', 'value' => $expireDate],
            ]);
        }

        // Periksa apakah user sudah ada di `radreply`
        $replyExists = DB::table('radreply')->where('username', $username)->exists();
        if (! $replyExists) {
            if ($vendor === 'huawei') {
                DB::table('radreply')->insert([
                    ['username' => $username, 'attribute' => $config['attribute_input'], 'op' => ':=', 'value' => $config['max_speed']],
                    ['username' => $username, 'attribute' => $config['attribute_output'], 'op' => ':=', 'value' => $config['min_speed']],
                    ['username' => $username, 'attribute' => 'Mikrotik-Total-Limit', 'op' => ':=', 'value' => $fupLimit],
                    ['username' => $username, 'attribute' => $config['attribute_input'], 'op' => ':=', 'value' => $config['fup_max']],
                    ['username' => $username, 'attribute' => $config['attribute_output'], 'op' => ':=', 'value' => $config['fup_min']],
                ]);
            } else {
                DB::table('radreply')->insert([
                    ['username' => $username, 'attribute' => $config['attribute'], 'op' => ':=', 'value' => $config['max_speed']],
                    ['username' => $username, 'attribute' => $config['attribute'], 'op' => ':=', 'value' => $config['min_speed']],
                    ['username' => $username, 'attribute' => 'Mikrotik-Total-Limit', 'op' => ':=', 'value' => $fupLimit],
                    ['username' => $username, 'attribute' => $config['attribute'], 'op' => ':=', 'value' => $config['fup_max']],
                    ['username' => $username, 'attribute' => $config['attribute'], 'op' => ':=', 'value' => $config['fup_min']],
                ]);
            }
        }

        return "User $username berhasil dibuat untuk $vendor!";
    }

    public function checkFUPAndApplyLimit()
    {
        // Ambil semua user yang ada di radacct
        $users = DB::table('radacct')
            ->select('username', DB::raw('SUM(acctinputoctets + acctoutputoctets) as total_usage'))
            ->groupBy('username')
            ->get();

        foreach ($users as $user) {
            $fupLimit = 100 * 1024 * 1024 * 1024; // 100GB
            if ($user->total_usage >= $fupLimit) {
                // Kurangi kecepatan setelah melewati FUP
                $this->applyFUP($user->username);
            }
        }
    }

    private function applyFUP($username)
    {
        // Ambil vendor user dari `radreply`
        $userData = DB::table('radreply')->where('username', $username)->first();

        if (! $userData || ! isset($userData->attribute)) {
            \Log::warning("User tidak ditemukan di radreply: $username");

            return;
        }

        $vendor = $userData->attribute;

        $fupConfigs = [
            'Mikrotik-Rate-Limit' => '5M/5M',
            'Cisco-AVPair' => 'ip:sub-rate-limit=5000000 5000000',
            'Juniper-AVPair' => 'ip:sub-rate-limit=5000000 5000000',
        ];

        if (! isset($fupConfigs[$vendor])) {
            \Log::warning("Vendor tidak ditemukan untuk user: $username");

            return;
        }

        // Update `radreply` untuk menurunkan kecepatan setelah FUP
        DB::table('radreply')
            ->where('username', $username)
            ->where('attribute', $vendor)
            ->update(['value' => $fupConfigs[$vendor]]);

        // Kirim CoA ke perangkat
        $this->sendCoA($username, $vendor, '192.168.1.1', 3799, 'radius_secret', $fupConfigs[$vendor]);
    }

    private function sendCoA($username, $vendor, $ipAddress, $port, $secret, $speed)
    {
        $command = match ($vendor) {
            'Mikrotik-Rate-Limit' => "echo \"User-Name={$username}, Mikrotik-Rate-Limit='{$speed}'\" | radclient -x {$ipAddress}:{$port} coa {$secret}",
            'Cisco-AVPair' => "echo \"User-Name={$username}, Cisco-AVPair='ip:sub-rate-limit={$speed}'\" | radclient -x {$ipAddress}:{$port} coa {$secret}",
            'Juniper-AVPair' => "echo \"User-Name={$username}, Juniper-AVPair='ip:sub-rate-limit={$speed}'\" | radclient -x {$ipAddress}:{$port} coa {$secret}",
            default => throw new \Exception('Vendor tidak didukung.'),
        };

        $output = shell_exec($command);
        \Log::info("Command Output: \n$output");

        return $output ? "FUP diterapkan untuk {$username}" : 'Gagal menerapkan FUP.';
    }
}
