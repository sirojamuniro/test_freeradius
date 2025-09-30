<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RadiusService
{
    public function addUser($username, $password, $vendor, $ipAddress, $port, $secret, $bandwidthConfig = null)
    {
        $vendor = $vendor ?? 'mikrotik';
        $expireDate = Carbon::now()->addDays(30)->format('Y-m-d H:i:s');
        $fupLimit = 100 * 1024 * 1024 * 1024; // 100GB dalam bytes

        $defaultBandwidth = [
            'max_download' => '10M',
            'max_upload' => '10M', 
            'min_download' => '2M',
            'min_upload' => '2M'
        ];
        
        $bandwidth = array_merge($defaultBandwidth, $bandwidthConfig ?? []);
        $config = $this->getBandwidthConfigs($vendor, $bandwidth);

        DB::beginTransaction();
        try {
            // Insert/Update radcheck
            $this->createOrUpdateRadCheck($username, $password, $expireDate);
            
            // Insert/Update radreply  
            $this->createOrUpdateRadReply($username, $config, $fupLimit, $vendor);

            // Ensure NAS exists
            $this->ensureNasExists($ipAddress, $port, $secret);
            
            DB::commit();
            Log::info("User $username berhasil dibuat dengan vendor $vendor", [
                'username' => $username, 
                'vendor' => $vendor,
                'bandwidth' => $bandwidth
            ]);
            
            return "User $username berhasil dibuat untuk $vendor!";
        } catch (\Exception $e) {
            DB::rollback();
            Log::error("Gagal membuat user $username: " . $e->getMessage());
            throw $e;
        }
    }

    public function checkFUPAndApplyLimit()
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
                    'usage' => round($user->total_usage / (1024 * 1024 * 1024), 2) . ' GB',
                    'status' => $result ? 'FUP Applied' : 'Failed'
                ];
            } catch (\Exception $e) {
                Log::error("Error applying FUP for user {$user->username}: " . $e->getMessage());
                $processedUsers[] = [
                    'username' => $user->username,
                    'status' => 'Error: ' . $e->getMessage()
                ];
            }
        }
        
        return $processedUsers;
    }

    private function createOrUpdateRadCheck($username, $password, $expireDate)
    {
        $existingEntries = DB::table('radcheck')
            ->where('username', $username)
            ->whereIn('attribute', ['Cleartext-Password', 'Expiration'])
            ->pluck('attribute')
            ->toArray();

        $inserts = [];
        
        if (!in_array('Cleartext-Password', $existingEntries)) {
            $inserts[] = ['username' => $username, 'attribute' => 'Cleartext-Password', 'op' => ':=', 'value' => $password];
        } else {
            DB::table('radcheck')
                ->where('username', $username)
                ->where('attribute', 'Cleartext-Password')
                ->update(['value' => $password]);
        }

        if (!in_array('Expiration', $existingEntries)) {
            $inserts[] = ['username' => $username, 'attribute' => 'Expiration', 'op' => ':=', 'value' => $expireDate];
        } else {
            DB::table('radcheck')
                ->where('username', $username)
                ->where('attribute', 'Expiration')
                ->update(['value' => $expireDate]);
        }

        if (!empty($inserts)) {
            DB::table('radcheck')->insert($inserts);
        }
    }

    private function createOrUpdateRadReply($username, $config, $fupLimit, $vendor)
    {
        // Get existing attributes
        $existingAttributes = DB::table('radreply')
            ->where('username', $username)
            ->pluck('attribute')
            ->toArray();

        $inserts = [];
        $updates = [];

        // Vendor Type
        if (!in_array('Vendor-Type', $existingAttributes)) {
            $inserts[] = ['username' => $username, 'attribute' => 'Vendor-Type', 'op' => ':=', 'value' => $config['vendor_type']];
        } else {
            $updates['Vendor-Type'] = $config['vendor_type'];
        }

        // Vendor specific configurations
        if ($vendor === 'huawei') {
            $this->handleHuaweiConfig($username, $config, $fupLimit, $existingAttributes, $inserts, $updates);
        } else {
            $this->handleOtherVendorConfig($username, $config, $fupLimit, $existingAttributes, $inserts, $updates);
        }

        // Insert new attributes
        if (!empty($inserts)) {
            DB::table('radreply')->insert($inserts);
        }

        // Update existing attributes
        foreach ($updates as $attribute => $value) {
            DB::table('radreply')
                ->where('username', $username)
                ->where('attribute', $attribute)
                ->update(['value' => $value]);
        }
    }

    private function handleHuaweiConfig($username, $config, $fupLimit, $existingAttributes, &$inserts, &$updates)
    {
        $attributes = [
            $config['attribute_input'] => $config['input_speed'],
            $config['attribute_output'] => $config['output_speed'],
            'Huawei-Volume-Limit' => $fupLimit
        ];

        foreach ($attributes as $attribute => $value) {
            if (!in_array($attribute, $existingAttributes)) {
                $inserts[] = ['username' => $username, 'attribute' => $attribute, 'op' => ':=', 'value' => $value];
            } else {
                $updates[$attribute] = $value;
            }
        }
    }

    private function handleOtherVendorConfig($username, $config, $fupLimit, $existingAttributes, &$inserts, &$updates)
    {
        $attributes = ['Session-Timeout' => '86400'];

        if ($config['vendor_type'] === 'mikrotik') {
            $attributes[$config['attribute']] = $config['initial_speed'];
            $attributes['Mikrotik-Total-Limit'] = $fupLimit;
        } elseif ($config['vendor_type'] === 'cisco' || $config['vendor_type'] === 'juniper') {
            // Cisco/Juniper memiliki dua attributes (in dan out)
            $attributes[$config['attribute']] = $config['initial_speed_in'];
            $attributes[$config['attribute']] = $config['initial_speed_out'];
            
            // Insert keduanya secara terpisah karena attribute sama dengan value berbeda
            if (!in_array($config['attribute'], $existingAttributes)) {
                $inserts[] = ['username' => $username, 'attribute' => $config['attribute'], 'op' => ':=', 'value' => $config['initial_speed_in']];
                $inserts[] = ['username' => $username, 'attribute' => $config['attribute'], 'op' => ':=', 'value' => $config['initial_speed_out']];
            } else {
                // Update existing - perlu handle multiple values untuk attribute yang sama
                DB::table('radreply')
                    ->where('username', $username)
                    ->where('attribute', $config['attribute'])
                    ->delete();
                $inserts[] = ['username' => $username, 'attribute' => $config['attribute'], 'op' => ':=', 'value' => $config['initial_speed_in']];
                $inserts[] = ['username' => $username, 'attribute' => $config['attribute'], 'op' => ':=', 'value' => $config['initial_speed_out']];
            }
            unset($attributes[$config['attribute']]); // Remove from normal processing
        }

        foreach ($attributes as $attribute => $value) {
            if (!in_array($attribute, $existingAttributes)) {
                $inserts[] = ['username' => $username, 'attribute' => $attribute, 'op' => ':=', 'value' => $value];
            } else {
                $updates[$attribute] = $value;
            }
        }
    }

    private function applyFUP($username)
    {
        $userData = DB::table('radreply')
            ->where('username', $username)
            ->whereIn('attribute', ['Vendor-Type', 'Mikrotik-Rate-Limit', 'Cisco-AVPair', 'Juniper-AVPair', 'Huawei-Input-Peak-Rate', 'Huawei-Output-Peak-Rate'])
            ->get()
            ->keyBy('attribute');

        $vendorData = $userData->get('Vendor-Type');
        if (!$vendorData) {
            Log::warning("Vendor type tidak ditemukan untuk user: $username");
            return false;
        }

        $vendorType = $vendorData->value;
        $fupConfig = $this->getFUPConfigFromUserData($vendorType, $userData);
        
        if (!$fupConfig) {
            Log::warning("Tidak dapat menentukan konfigurasi FUP untuk user: $username");
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
                // Handle Cisco/Juniper multiple speeds atau single speed untuk MikroTik
                if (isset($fupConfig['speeds'])) {
                    // Multiple speeds (Cisco/Juniper)
                    DB::table('radreply')
                        ->where('username', $username)
                        ->where('attribute', $fupConfig['attribute'])
                        ->delete();
                    
                    foreach ($fupConfig['speeds'] as $speed) {
                        DB::table('radreply')->insert([
                            'username' => $username,
                            'attribute' => $fupConfig['attribute'],
                            'op' => ':=',
                            'value' => $speed
                        ]);
                    }
                } else {
                    // Single speed (MikroTik)
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
            Log::info("FUP berhasil diterapkan untuk user: $username dengan vendor: $vendorType");
            return true;
            
        } catch (\Exception $e) {
            DB::rollback();
            Log::error("Error menerapkan FUP untuk user $username: " . $e->getMessage());
            return false;
        }
    }

    private function sendCoA($username, $vendorType, $ipAddress, $port, $secret, $config)
    {
        $username = escapeshellarg($username);
        $ipAddress = escapeshellarg($ipAddress);
        $port = escapeshellarg($port);
        $secret = escapeshellarg($secret);
        
        $command = match ($vendorType) {
            'mikrotik' => "echo \"User-Name=$username,Mikrotik-Rate-Limit={$config['speed']}\" | radclient -x $ipAddress:$port coa $secret",
            'cisco' => isset($config['speeds']) 
                ? "echo \"User-Name=$username," . implode(',', array_map(fn($s) => "Cisco-AVPair='$s'", $config['speeds'])) . "\" | radclient -x $ipAddress:$port coa $secret"
                : "echo \"User-Name=$username,Cisco-AVPair='{$config['speed']}'\" | radclient -x $ipAddress:$port coa $secret",
            'juniper' => isset($config['speeds'])
                ? "echo \"User-Name=$username," . implode(',', array_map(fn($s) => "Juniper-AVPair='$s'", $config['speeds'])) . "\" | radclient -x $ipAddress:$port coa $secret"
                : "echo \"User-Name=$username,Juniper-AVPair='{$config['speed']}'\" | radclient -x $ipAddress:$port coa $secret",
            'huawei' => "echo \"User-Name=$username,Huawei-Input-Peak-Rate={$config['input_speed']},Huawei-Output-Peak-Rate={$config['output_speed']}\" | radclient -x $ipAddress:$port coa $secret",
            default => throw new \Exception("Vendor '$vendorType' tidak didukung untuk CoA."),
        };

        Log::info("Executing CoA command for user $username: $command");
        $output = shell_exec($command);
        Log::info("CoA Command Output for $username: \n$output");

        return !empty($output);
    }
    
    private function ensureNasExists($ipAddress, $port, $secret)
    {
        $exists = DB::table('nas')->where('nasname', $ipAddress)->exists();
        if (!$exists) {
            DB::table('nas')->insert([
                'nasname' => $ipAddress,
                'shortname' => 'auto_' . str_replace('.', '_', $ipAddress),
                'ports' => $port,
                'secret' => $secret,
                'type' => 'other',
                'server' => ''
            ]);
            Log::info("NAS entry created for $ipAddress:$port");
        } else {
            // Update existing NAS if needed
            DB::table('nas')
                ->where('nasname', $ipAddress)
                ->update([
                    'ports' => $port,
                    'secret' => $secret
                ]);
        }
    }
    
    private function getNasForUser($username)
    {
        return DB::table('radacct')
            ->join('nas', 'radacct.nasipaddress', '=', 'nas.nasname')
            ->where('radacct.username', $username)
            ->whereNull('radacct.acctstoptime')
            ->select('nas.nasname', 'nas.ports', 'nas.secret')
            ->first();
    }
    
    private function getBandwidthConfigs($vendor, $bandwidth)
    {
        return match($vendor) {
            'mikrotik', 'mikrotik_pppoe', 'mikrotik_hotspot' => [
                'initial_speed' => $bandwidth['max_download'] . '/' . $bandwidth['max_upload'],
                'fup_speed' => $bandwidth['min_download'] . '/' . $bandwidth['min_upload'],
                'attribute' => 'Mikrotik-Rate-Limit',
                'vendor_type' => 'mikrotik'
            ],
            'cisco' => [
                'initial_speed_in' => 'ip:sub-qos-policy-in=' . $bandwidth['max_download'],
                'initial_speed_out' => 'ip:sub-qos-policy-out=' . $bandwidth['max_upload'],
                'fup_speed_in' => 'ip:sub-qos-policy-in=' . $bandwidth['min_download'],
                'fup_speed_out' => 'ip:sub-qos-policy-out=' . $bandwidth['min_upload'],
                'attribute' => 'Cisco-AVPair',
                'vendor_type' => 'cisco'
            ],
            'juniper' => [
                'initial_speed_in' => 'logical-system-policer-template-in=' . $bandwidth['max_download'],
                'initial_speed_out' => 'logical-system-policer-template-out=' . $bandwidth['max_upload'],
                'fup_speed_in' => 'logical-system-policer-template-in=' . $bandwidth['min_download'],
                'fup_speed_out' => 'logical-system-policer-template-out=' . $bandwidth['min_upload'],
                'attribute' => 'Juniper-AVPair',
                'vendor_type' => 'juniper'
            ],
            'huawei' => [
                'input_speed' => $this->convertToBytes($bandwidth['max_upload']),
                'output_speed' => $this->convertToBytes($bandwidth['max_download']),
                'fup_input' => $this->convertToBytes($bandwidth['min_upload']),
                'fup_output' => $this->convertToBytes($bandwidth['min_download']),
                'attribute_input' => 'Huawei-Input-Peak-Rate',
                'attribute_output' => 'Huawei-Output-Peak-Rate',
                'vendor_type' => 'huawei'
            ],
            default => throw new \Exception("Vendor '$vendor' tidak didukung")
        };
    }
    
    private function convertToBytes($speed)
    {
        $speed = strtoupper(trim($speed));
        $multipliers = ['K' => 1000, 'M' => 1000000, 'G' => 1000000000];
        
        foreach ($multipliers as $suffix => $multiplier) {
            if (str_ends_with($speed, $suffix)) {
                return (string)((int)(floatval(rtrim($speed, $suffix)) * $multiplier));
            }
        }
        
        return (string)(int)$speed;
    }
    
    private function getFUPConfigFromUserData($vendorType, $userData)
    {
        switch ($vendorType) {
            case 'mikrotik':
                $currentSpeed = $userData->get('Mikrotik-Rate-Limit');
                if (!$currentSpeed) return null;
                
                if (strpos($currentSpeed->value, '/') !== false) {
                    [$download, $upload] = explode('/', $currentSpeed->value);
                    $fupDownload = $this->calculateFUPSpeed(trim($download));
                    $fupUpload = $this->calculateFUPSpeed(trim($upload));

                    return [
                        'attribute' => 'Mikrotik-Rate-Limit',
                        'speed' => $fupDownload . '/' . $fupUpload
                    ];
                }
                return null;
                
            case 'cisco':
                $speeds = $userData->where('attribute', 'Cisco-AVPair');
                if ($speeds->isEmpty()) return null;
                
                $fupSpeeds = [];
                foreach ($speeds as $speed) {
                    if (preg_match('/ip:sub-qos-policy-(in|out)=(\w+)/', $speed->value, $matches)) {
                        $direction = $matches[1];
                        $bandwidth = $matches[2];
                        $fupBandwidth = $this->calculateFUPSpeed($bandwidth);
                        $fupSpeeds[] = 'ip:sub-qos-policy-' . $direction . '=' . $fupBandwidth;
                    }
                }
                
                return $fupSpeeds ? [
                    'attribute' => 'Cisco-AVPair',
                    'speeds' => $fupSpeeds
                ] : null;
                
            case 'juniper':
                $speeds = $userData->where('attribute', 'Juniper-AVPair');
                if ($speeds->isEmpty()) return null;
                
                $fupSpeeds = [];
                foreach ($speeds as $speed) {
                    if (preg_match('/logical-system-policer-template-(in|out)=(\w+)/', $speed->value, $matches)) {
                        $direction = $matches[1];
                        $bandwidth = $matches[2];
                        $fupBandwidth = $this->calculateFUPSpeed($bandwidth);
                        $fupSpeeds[] = 'logical-system-policer-template-' . $direction . '=' . $fupBandwidth;
                    }
                }
                
                return $fupSpeeds ? [
                    'attribute' => 'Juniper-AVPair',
                    'speeds' => $fupSpeeds
                ] : null;
                
            case 'huawei':
                $inputSpeed = $userData->get('Huawei-Input-Peak-Rate');
                $outputSpeed = $userData->get('Huawei-Output-Peak-Rate');
                
                if (!$inputSpeed || !$outputSpeed) return null;
                
                $fupInput = (int)((int)$inputSpeed->value * 0.2); // 20% dari kecepatan asli
                $fupOutput = (int)((int)$outputSpeed->value * 0.2);
                
                return [
                    'input_attr' => 'Huawei-Input-Peak-Rate',
                    'output_attr' => 'Huawei-Output-Peak-Rate',
                    'input_speed' => (string)$fupInput,
                    'output_speed' => (string)$fupOutput
                ];
                
            default:
                return null;
        }
    }
    
    private function calculateFUPSpeed($originalSpeed)
    {
        $speed = strtoupper(trim($originalSpeed));
        
        if (preg_match('/^(\d+(?:\.\d+)?)([KMG])$/', $speed, $matches)) {
            $value = (float)$matches[1];
            $unit = $matches[2];
            
            $fupValue = $value * 0.2; // 20% dari kecepatan asli
            
            // Minimum 1K untuk mencegah 0 bandwidth
            if ($fupValue < 1) {
                if ($unit === 'M') {
                    $fupValue = max(1, (int)($fupValue * 1000));
                    return $fupValue . 'K';
                } elseif ($unit === 'G') {
                    $fupValue = max(1, (int)($fupValue * 1000));
                    return $fupValue . 'M';
                }
                return '1K'; // Fallback minimum
            }
            
            return (int)$fupValue . $unit;
        }
        
        // Fallback jika parsing gagal
        return '1M';
    }
}
