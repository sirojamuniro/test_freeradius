<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

class RadiusService
{
    public function addUser(
        string $username,
        string $password,
        string $vendor,
        string $ipAddress,
        int $port,
        string $secret,
        ?array $bandwidthConfig = null,
        ?string $expiration = null
    ) {
        $expireDate = $this->resolveExpireDate($expiration);
        $fupLimit = 100 * 1024 * 1024 * 1024; // 100GB dalam bytes

        $defaultBandwidth = [
            'max_download' => '10M',
            'max_upload' => '10M',
            'min_download' => '2M',
            'min_upload' => '2M',
        ];

        $bandwidth = array_merge($defaultBandwidth, array_filter($bandwidthConfig ?? [], fn ($value) => ! is_null($value)));
        $config = $this->getBandwidthConfigs($vendor, $bandwidth);

        DB::beginTransaction();

        try {
            $this->createOrUpdateRadCheck($username, $password, $expireDate);
            $this->createOrUpdateRadReply($username, $config, $fupLimit);
            $this->syncNas([
                'nasname' => $ipAddress,
                'ports' => $port,
                'secret' => $secret,
                'shortname' => 'auto_'.str_replace('.', '_', $ipAddress),
                'type' => $vendor,
            ], false);

            DB::commit();

            Log::info("User {$username} berhasil dibuat dengan vendor {$vendor}", [
                'username' => $username,
                'vendor' => $vendor,
                'bandwidth' => $bandwidth,
            ]);

            return "User {$username} berhasil dibuat untuk {$vendor}!";
        } catch (\Throwable $exception) {
            DB::rollBack();

            Log::error("Gagal membuat user {$username}: ".$exception->getMessage(), [
                'username' => $username,
                'vendor' => $vendor,
            ]);

            throw $exception;
        }
    }

    private function resolveExpireDate(?string $expiration): string
    {
        $timezone = config('app.timezone', 'UTC');

        if ($expiration) {
            $formats = ['M d Y H:i:s', 'd M Y H:i:s', 'Y-m-d H:i:s'];

            foreach ($formats as $format) {
                try {
                    $parsed = Carbon::createFromFormat($format, $expiration, $timezone);

                    return $parsed->setTimezone($timezone)->format('M d Y H:i:s');
                } catch (\Throwable $exception) {
                    // Try next format
                }
            }

            try {
                return Carbon::parse($expiration, $timezone)
                    ->setTimezone($timezone)
                    ->format('M d Y H:i:s');
            } catch (\Throwable $exception) {
                Log::warning('Unable to parse provided expiration, falling back to default interval', [
                    'expiration' => $expiration,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return Carbon::now($timezone)->addDays(30)->format('M d Y H:i:s');
    }

    public function checkFUPAndApplyLimit(): array
    {
        $fupLimit = 100 * 1024 * 1024 * 1024; // 100GB

        $users = DB::table('radacct')
            ->select('username', DB::raw('SUM(acctinputoctets + acctoutputoctets) as total_usage'))
            ->whereNull('acctstoptime')
            ->groupBy('username')
            ->having('total_usage', '>=', $fupLimit)
            ->get();

        $processedUsers = [];

        foreach ($users as $user) {
            try {
                $result = $this->applyFUP($user->username);

                $processedUsers[] = [
                    'username' => $user->username,
                    'usage' => round($user->total_usage / (1024 * 1024 * 1024), 2).' GB',
                    'status' => $result ? 'FUP Applied' : 'Failed',
                ];
            } catch (\Throwable $exception) {
                Log::error("Error applying FUP for user {$user->username}: ".$exception->getMessage());

                $processedUsers[] = [
                    'username' => $user->username,
                    'status' => 'Error: '.$exception->getMessage(),
                ];
            }
        }

        return $processedUsers;
    }

    public function syncNas(array $payload, bool $reload = true): array
    {
        $nasname = $payload['nasname'] ?? null;
        $secret = $payload['secret'] ?? null;

        if (! $nasname || ! $secret) {
            throw new \InvalidArgumentException('NAS sync requires both nasname (IP address) and secret.');
        }

        $authPort = $payload['auth_port'] ?? null;
        $acctPort = $payload['acct_port'] ?? null;

        $data = [
            'nasname' => $nasname,
            'shortname' => $payload['shortname'] ?? $this->generateNasShortname($nasname, $payload['shortname'] ?? null),
            'type' => $payload['type'] ?? 'other',
            'ports' => (int) ($payload['ports'] ?? 3799),
            'secret' => $secret,
            'server' => $payload['server'] ?? '',
            'community' => $payload['community'] ?? null,
            'description' => $payload['description'] ?? null,
        ];

        $exists = DB::table('nas')->where('nasname', $nasname)->exists();

        DB::table('nas')->updateOrInsert(['nasname' => $nasname], $data);

        Log::info('NAS entry synchronised', [
            'nasname' => $nasname,
            'created' => ! $exists,
            'ports' => $data['ports'],
            'auth_port' => $authPort,
            'acct_port' => $acctPort,
        ]);

        $result = [
            'nasname' => $nasname,
            'created' => ! $exists,
            'ports' => $data['ports'],
            'auth_port' => $authPort,
            'acct_port' => $acctPort,
        ];

        if ($reload) {
            $result['reload'] = $this->reloadFreeRadius();
        }

        return $result;
    }

    public function reloadFreeRadius(): array
    {
        $command = config('radius.reload_command', 'sudo systemctl reload freeradius');
        $process = Process::fromShellCommandline($command);
        $process->run();

        $output = trim($process->getOutput());
        $errorOutput = trim($process->getErrorOutput());

        if (! $process->isSuccessful()) {
            Log::error('FreeRADIUS reload failed', [
                'command' => $command,
                'error' => $errorOutput,
            ]);

            throw new \RuntimeException('FreeRADIUS reload failed: '.($errorOutput ?: 'Unknown error'));
        }

        Log::info('FreeRADIUS reloaded successfully', [
            'command' => $command,
            'output' => $output,
        ]);

        return [
            'output' => $output,
        ];
    }

    public function blockUser(string $username, bool $disconnect = true): array
    {
        DB::table('radcheck')->updateOrInsert(
            ['username' => $username, 'attribute' => 'Auth-Type'],
            ['op' => ':=', 'value' => 'Reject']
        );

        Log::info('Radius block applied for user', ['username' => $username]);

        $result = ['blocked' => true];

        if ($disconnect) {
            $result['disconnect'] = $this->disconnectUser($username);
        }

        return $result;
    }

    public function unblockUser(string $username, bool $disconnect = true): array
    {
        $removed = DB::table('radcheck')
            ->where('username', $username)
            ->where('attribute', 'Auth-Type')
            ->where('value', 'Reject')
            ->delete();

        Log::info('Radius unblock executed', ['username' => $username, 'removed' => $removed]);

        $result = ['unblocked' => $removed > 0];

        if ($disconnect) {
            $result['disconnect'] = $this->disconnectUser($username);
        }

        return $result;
    }

    public function userIsBlocked(string $username): bool
    {
        return DB::table('radcheck')
            ->where('username', $username)
            ->where('attribute', 'Auth-Type')
            ->where('value', 'Reject')
            ->exists();
    }

    public function disconnectUser(string $username): array
    {
        $sessions = $this->getActiveSessionsWithNas($username);

        if ($sessions->isEmpty()) {
            return [
                'success' => false,
                'sessions' => [],
                'message' => 'No active sessions',
            ];
        }

        $details = [];
        $success = true;

        foreach ($sessions as $session) {
            $commandResult = $this->runRadclientCommand(
                $session->nasname,
                $session->ports,
                $session->secret,
                $username,
                [],
                'disconnect'
            );

            $success = $success && $commandResult['success'];
            $details[] = array_merge($commandResult, [
                'nas' => $session->nasname,
                'ports' => $session->ports,
            ]);
        }

        return [
            'success' => $success,
            'sessions' => $details,
        ];
    }

    private function createOrUpdateRadCheck(string $username, string $password, string $expireDate): void
    {
        $existingEntries = DB::table('radcheck')
            ->where('username', $username)
            ->whereIn('attribute', ['Cleartext-Password', 'Expiration'])
            ->pluck('attribute')
            ->toArray();

        $inserts = [];

        if (! in_array('Cleartext-Password', $existingEntries, true)) {
            $inserts[] = [
                'username' => $username,
                'attribute' => 'Cleartext-Password',
                'op' => ':=',
                'value' => $password,
            ];
        } else {
            DB::table('radcheck')
                ->where('username', $username)
                ->where('attribute', 'Cleartext-Password')
                ->update(['value' => $password]);
        }

        if (! in_array('Expiration', $existingEntries, true)) {
            $inserts[] = [
                'username' => $username,
                'attribute' => 'Expiration',
                'op' => ':=',
                'value' => $expireDate,
            ];
        } else {
            DB::table('radcheck')
                ->where('username', $username)
                ->where('attribute', 'Expiration')
                ->update(['value' => $expireDate]);
        }

        if (! empty($inserts)) {
            DB::table('radcheck')->insert($inserts);
        }
    }

    private function createOrUpdateRadReply(string $username, array $config, int $fupLimit): void
    {
        DB::table('radreply')
            ->where('username', $username)
            ->whereIn('attribute', [
                'Vendor-Type',
                'Mikrotik-Total-Limit',
                'Session-Timeout',
            ])
            ->delete();

        $attributes = [];

        if ($config['vendor_type'] === 'huawei') {
            $attributes[$config['attribute_input']] = [(string) $config['input_speed']];
            $attributes[$config['attribute_output']] = [(string) $config['output_speed']];
            $attributes['Huawei-Volume-Limit'] = [(string) $fupLimit];
        } elseif ($config['vendor_type'] === 'mikrotik') {
            $attributes[$config['attribute']] = [$config['initial_speed']];
        } elseif (in_array($config['vendor_type'], ['cisco', 'juniper'], true)) {
            $attributes[$config['attribute']] = [
                $config['initial_speed_in'],
                $config['initial_speed_out'],
            ];
        }

        $this->syncRadReplyAttributes($username, $attributes);
    }

    private function applyFUP(string $username): bool
    {
        $userData = DB::table('radreply')
            ->where('username', $username)
            ->whereIn('attribute', [
                'Mikrotik-Rate-Limit',
                'Cisco-AVPair',
                'Juniper-AVPair',
                'Huawei-Input-Peak-Rate',
                'Huawei-Output-Peak-Rate',
            ])
            ->get();

        $vendorType = $this->detectVendorTypeFromRadReply($userData);

        if (! $vendorType) {
            Log::warning("Vendor type tidak dapat ditentukan untuk user: {$username}");

            return false;
        }

        $fupConfig = $this->getFUPConfigFromUserData($vendorType, $userData);

        if (! $fupConfig) {
            Log::warning("Tidak dapat menentukan konfigurasi FUP untuk user: {$username}");

            return false;
        }

        try {
            DB::beginTransaction();

            if ($vendorType === 'huawei') {
                DB::table('radreply')
                    ->where('username', $username)
                    ->where('attribute', $fupConfig['input_attr'])
                    ->update(['value' => $fupConfig['input_speed']]);

                DB::table('radreply')
                    ->where('username', $username)
                    ->where('attribute', $fupConfig['output_attr'])
                    ->update(['value' => $fupConfig['output_speed']]);
            } else {
                if (isset($fupConfig['speeds'])) {
                    DB::table('radreply')
                        ->where('username', $username)
                        ->where('attribute', $fupConfig['attribute'])
                        ->delete();

                    foreach ($fupConfig['speeds'] as $speed) {
                        DB::table('radreply')->insert([
                            'username' => $username,
                            'attribute' => $fupConfig['attribute'],
                            'op' => ':=',
                            'value' => $speed,
                        ]);
                    }
                } else {
                    DB::table('radreply')
                        ->where('username', $username)
                        ->where('attribute', $fupConfig['attribute'])
                        ->update(['value' => $fupConfig['speed']]);
                }
            }

            $nasData = $this->getNasForUser($username);

            if ($nasData) {
                $this->sendCoA($username, $vendorType, $nasData->nasname, $nasData->ports, $nasData->secret, $fupConfig);
            }

            DB::commit();

            Log::info("FUP berhasil diterapkan untuk user: {$username} dengan vendor: {$vendorType}");

            return true;
        } catch (\Throwable $exception) {
            DB::rollBack();

            Log::error("Error menerapkan FUP untuk user {$username}: ".$exception->getMessage());

            return false;
        }
    }

    private function sendCoA(
        string $username,
        string $vendorType,
        string $ipAddress,
        ?int $port,
        string $secret,
        array $config
    ): bool {
        $attributes = match ($vendorType) {
            'mikrotik' => ['Mikrotik-Rate-Limit' => [$config['speed']]],
            'cisco' => ['Cisco-AVPair' => $config['speeds'] ?? [$config['speed'] ?? null]],
            'juniper' => ['Juniper-AVPair' => $config['speeds'] ?? [$config['speed'] ?? null]],
            'huawei' => [
                'Huawei-Input-Peak-Rate' => [$config['input_speed']],
                'Huawei-Output-Peak-Rate' => [$config['output_speed']],
            ],
            default => throw new \InvalidArgumentException("Vendor '{$vendorType}' tidak didukung untuk CoA."),
        };

        $commandResult = $this->runRadclientCommand($ipAddress, $port, $secret, $username, $attributes, 'coa');

        if (! $commandResult['success']) {
            Log::error('CoA command failed', array_merge($commandResult, ['username' => $username]));

            return false;
        }

        Log::info('CoA command executed successfully', array_merge($commandResult, ['username' => $username]));

        return true;
    }

    private function getNasForUser(string $username): ?object
    {
        return DB::table('radacct')
            ->join('nas', 'radacct.nasipaddress', '=', 'nas.nasname')
            ->where('radacct.username', $username)
            ->whereNull('radacct.acctstoptime')
            ->select('nas.nasname', 'nas.ports', 'nas.secret')
            ->first();
    }

    private function getBandwidthConfigs(string $vendor, array $bandwidth): array
    {
        return match ($vendor) {
            'mikrotik', 'mikrotik_pppoe', 'mikrotik_hotspot' => [
                'initial_speed' => $bandwidth['max_download'].'/'.$bandwidth['max_upload'],
                'fup_speed' => $bandwidth['min_download'].'/'.$bandwidth['min_upload'],
                'attribute' => 'Mikrotik-Rate-Limit',
                'vendor_type' => 'mikrotik',
            ],
            'cisco' => [
                'initial_speed_in' => 'ip:sub-qos-policy-in='.$bandwidth['max_download'],
                'initial_speed_out' => 'ip:sub-qos-policy-out='.$bandwidth['max_upload'],
                'fup_speed_in' => 'ip:sub-qos-policy-in='.$bandwidth['min_download'],
                'fup_speed_out' => 'ip:sub-qos-policy-out='.$bandwidth['min_upload'],
                'attribute' => 'Cisco-AVPair',
                'vendor_type' => 'cisco',
            ],
            'juniper' => [
                'initial_speed_in' => 'logical-system-policer-template-in='.$bandwidth['max_download'],
                'initial_speed_out' => 'logical-system-policer-template-out='.$bandwidth['max_upload'],
                'fup_speed_in' => 'logical-system-policer-template-in='.$bandwidth['min_download'],
                'fup_speed_out' => 'logical-system-policer-template-out='.$bandwidth['min_upload'],
                'attribute' => 'Juniper-AVPair',
                'vendor_type' => 'juniper',
            ],
            'huawei' => [
                'input_speed' => $this->convertToBytes($bandwidth['max_upload']),
                'output_speed' => $this->convertToBytes($bandwidth['max_download']),
                'fup_input' => $this->convertToBytes($bandwidth['min_upload']),
                'fup_output' => $this->convertToBytes($bandwidth['min_download']),
                'attribute_input' => 'Huawei-Input-Peak-Rate',
                'attribute_output' => 'Huawei-Output-Peak-Rate',
                'vendor_type' => 'huawei',
            ],
            default => throw new \RuntimeException("Vendor '{$vendor}' tidak didukung"),
        };
    }

    private function syncRadReplyAttributes(string $username, array $attributes): void
    {
        $managedAttributes = $this->managedRadReplyAttributes();
        $targetAttributes = array_keys($attributes);

        DB::table('radreply')
            ->where('username', $username)
            ->whereIn('attribute', $managedAttributes)
            ->whereNotIn('attribute', $targetAttributes)
            ->delete();

        $existing = DB::table('radreply')
            ->where('username', $username)
            ->whereIn('attribute', $managedAttributes)
            ->get()
            ->groupBy('attribute');

        foreach ($attributes as $attribute => $values) {
            $values = array_values(array_unique(array_map('strval', (array) $values)));
            $currentValues = $existing->get($attribute, collect())->pluck('value')->toArray();

            if (! empty($currentValues)) {
                DB::table('radreply')
                    ->where('username', $username)
                    ->where('attribute', $attribute)
                    ->whereNotIn('value', $values)
                    ->delete();
            }

            foreach ($values as $value) {
                DB::table('radreply')->updateOrInsert(
                    [
                        'username' => $username,
                        'attribute' => $attribute,
                        'value' => $value,
                    ],
                    ['op' => ':=']
                );
            }
        }
    }

    private function managedRadReplyAttributes(): array
    {
        return [
            'Mikrotik-Rate-Limit',
            'Cisco-AVPair',
            'Juniper-AVPair',
            'Huawei-Input-Peak-Rate',
            'Huawei-Output-Peak-Rate',
            'Huawei-Volume-Limit',
        ];
    }

    private function convertToBytes(string $speed): string
    {
        $speed = strtoupper(trim($speed));
        $multipliers = ['K' => 1000, 'M' => 1000000, 'G' => 1000000000];

        foreach ($multipliers as $suffix => $multiplier) {
            if (str_ends_with($speed, $suffix)) {
                return (string) ((int) (floatval(rtrim($speed, $suffix)) * $multiplier));
            }
        }

        return (string) (int) $speed;
    }

    private function detectVendorTypeFromRadReply(Collection $userData): ?string
    {
        if ($userData->firstWhere('attribute', 'Mikrotik-Rate-Limit')) {
            return 'mikrotik';
        }

        if ($userData->firstWhere('attribute', 'Cisco-AVPair')) {
            return 'cisco';
        }

        if ($userData->firstWhere('attribute', 'Juniper-AVPair')) {
            return 'juniper';
        }

        if ($userData->firstWhere('attribute', 'Huawei-Input-Peak-Rate')
            || $userData->firstWhere('attribute', 'Huawei-Output-Peak-Rate')) {
            return 'huawei';
        }

        return null;
    }

    private function getFUPConfigFromUserData(string $vendorType, Collection $userData): ?array
    {
        switch ($vendorType) {
            case 'mikrotik':
                $currentSpeed = $userData->firstWhere('attribute', 'Mikrotik-Rate-Limit');

                if (! $currentSpeed) {
                    return null;
                }

                if (str_contains($currentSpeed->value, '/')) {
                    [$download, $upload] = explode('/', $currentSpeed->value);
                    $fupDownload = $this->calculateFUPSpeed(trim($download));
                    $fupUpload = $this->calculateFUPSpeed(trim($upload));

                    return [
                        'attribute' => 'Mikrotik-Rate-Limit',
                        'speed' => $fupDownload.'/'.$fupUpload,
                    ];
                }

                return null;

            case 'cisco':
                $speeds = $userData->where('attribute', 'Cisco-AVPair');

                if ($speeds->isEmpty()) {
                    return null;
                }

                $fupSpeeds = [];

                foreach ($speeds as $speed) {
                    if (preg_match('/ip:sub-qos-policy-(in|out)=(\w+)/', $speed->value, $matches)) {
                        $direction = $matches[1];
                        $bandwidth = $matches[2];
                        $fupBandwidth = $this->calculateFUPSpeed($bandwidth);
                        $fupSpeeds[] = 'ip:sub-qos-policy-'.$direction.'='.$fupBandwidth;
                    }
                }

                return $fupSpeeds ? [
                    'attribute' => 'Cisco-AVPair',
                    'speeds' => $fupSpeeds,
                ] : null;

            case 'juniper':
                $speeds = $userData->where('attribute', 'Juniper-AVPair');

                if ($speeds->isEmpty()) {
                    return null;
                }

                $fupSpeeds = [];

                foreach ($speeds as $speed) {
                    if (preg_match('/logical-system-policer-template-(in|out)=(\w+)/', $speed->value, $matches)) {
                        $direction = $matches[1];
                        $bandwidth = $matches[2];
                        $fupBandwidth = $this->calculateFUPSpeed($bandwidth);
                        $fupSpeeds[] = 'logical-system-policer-template-'.$direction.'='.$fupBandwidth;
                    }
                }

                return $fupSpeeds ? [
                    'attribute' => 'Juniper-AVPair',
                    'speeds' => $fupSpeeds,
                ] : null;

            case 'huawei':
                $inputSpeed = $userData->firstWhere('attribute', 'Huawei-Input-Peak-Rate');
                $outputSpeed = $userData->firstWhere('attribute', 'Huawei-Output-Peak-Rate');

                if (! $inputSpeed || ! $outputSpeed) {
                    return null;
                }

                $fupInput = (int) ((int) $inputSpeed->value * 0.2);
                $fupOutput = (int) ((int) $outputSpeed->value * 0.2);

                return [
                    'input_attr' => 'Huawei-Input-Peak-Rate',
                    'output_attr' => 'Huawei-Output-Peak-Rate',
                    'input_speed' => (string) $fupInput,
                    'output_speed' => (string) $fupOutput,
                ];

            default:
                return null;
        }
    }

    private function calculateFUPSpeed(string $originalSpeed): string
    {
        $speed = strtoupper(trim($originalSpeed));

        if (preg_match('/^(\d+(?:\.\d+)?)([KMG])$/', $speed, $matches)) {
            $value = (float) $matches[1];
            $unit = $matches[2];
            $fupValue = $value * 0.2;

            if ($fupValue < 1) {
                if ($unit === 'M') {
                    $fupValue = max(1, (int) ($fupValue * 1000));

                    return $fupValue.'K';
                }

                if ($unit === 'G') {
                    $fupValue = max(1, (int) ($fupValue * 1000));

                    return $fupValue.'M';
                }

                return '1K';
            }

            return (int) $fupValue.$unit;
        }

        return '1M';
    }

    private function generateNasShortname(string $nasname, ?string $customShortname = null): string
    {
        if ($customShortname) {
            return $customShortname;
        }

        $sanitised = preg_replace('/[^A-Za-z0-9]+/', '_', $nasname);

        return 'auto_'.Str::lower(trim($sanitised, '_'));
    }

    private function getActiveSessionsWithNas(string $username): Collection
    {
        return DB::table('radacct')
            ->join('nas', 'radacct.nasipaddress', '=', 'nas.nasname')
            ->where('radacct.username', $username)
            ->whereNull('radacct.acctstoptime')
            ->select('nas.nasname', 'nas.ports', 'nas.secret')
            ->get();
    }

    private function runRadclientCommand(
        string $ipAddress,
        ?int $port,
        string $secret,
        string $username,
        array $attributes,
        string $command
    ): array {
        $port ??= 3799;
        $inputLines = ["User-Name={$username}"];

        foreach ($attributes as $attribute => $values) {
            foreach (array_filter((array) $values) as $value) {
                $inputLines[] = "{$attribute}={$value}";
            }
        }

        $process = Process::fromShellCommandline(sprintf('radclient -x %s:%s %s %s', $ipAddress, $port, $command, $secret));
        $process->setInput(implode("\n", $inputLines)."\n");
        $process->run();

        $output = trim($process->getOutput());
        $errorOutput = trim($process->getErrorOutput());
        $success = $process->isSuccessful();

        return [
            'success' => $success,
            'output' => $output,
            'error' => $errorOutput,
            'ip' => $ipAddress,
            'port' => $port,
            'command' => sprintf('radclient -x %s:%s %s ****', $ipAddress, $port, $command),
        ];
    }
}
