<?php

namespace App\Http\Controllers;

use App\Services\RadiusService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

class Controller extends BaseController
{
    use AuthorizesRequests, DispatchesJobs, ValidatesRequests;

    protected $radiusService;

    public function __construct(RadiusService $radiusService)
    {
        $this->radiusService = $radiusService;
    }

    public function activateUser(Request $request)
    {
        $request->validate([
            'customer' => 'required|string|unique:radcheck,username',
            'password' => 'required|string|min:6',
            'vendor' => 'required|string|in:mikrotik,mikrotik_pppoe,mikrotik_hotspot,cisco,juniper,huawei',
            'ipAddress' => 'required|ip',
            'port' => 'required|integer|min:1|max:65535',
            'secret' => 'required|string|min:6',
            'max_download' => 'required|string',
            'max_upload' => 'required|string',
            'min_download' => 'nullable|string',
            'min_upload' => 'nullable|string',
            'expiration' => 'nullable|string',
        ]);

        try {
            $result = $this->radiusService->addUser(
                $request->customer,
                $request->password,
                $request->vendor,
                $request->ipAddress,
                $request->port,
                $request->secret,
                [
                    'max_download' => $request->max_download,
                    'max_upload' => $request->max_upload,
                    'min_download' => $request->min_download ?? '1M',
                    'min_upload' => $request->min_upload ?? '1M',
                ],
                $request->expiration
            );

            return response()->json([
                'success' => true,
                'message' => $result,
                'data' => [
                    'username' => $request->customer,
                    'vendor' => $request->vendor,
                    'bandwidth' => [
                        'download' => $request->max_download,
                        'upload' => $request->max_upload,
                    ],
                    'nas' => $request->ipAddress.':'.$request->port,
                    'expiration' => $request->expiration,
                ],
            ], 201);
        } catch (\Exception $e) {
            Log::error('Failed to activate user: '.$e->getMessage(), [
                'username' => $request->customer,
                'vendor' => $request->vendor,
                'ip' => $request->ipAddress,
            ]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function updateUser(Request $request, string $username)
    {
        $validated = $request->validate([
            'password' => 'required|string|min:6',
            'vendor' => 'required|string|in:mikrotik,mikrotik_pppoe,mikrotik_hotspot,cisco,juniper,huawei',
            'ipAddress' => 'required|ip',
            'port' => 'required|integer|min:1|max:65535',
            'secret' => 'required|string|min:4',
            'max_download' => 'required|string',
            'max_upload' => 'required|string',
            'min_download' => 'nullable|string',
            'min_upload' => 'nullable|string',
            'expiration' => 'nullable|string',
        ]);

        try {
            $result = $this->radiusService->updateUser($username, $validated);

            return response()->json([
                'success' => true,
                'message' => 'User package updated successfully',
                'data' => $result,
            ], 200);
        } catch (\Throwable $exception) {
            Log::error('Failed to update user: '.$exception->getMessage(), [
                'username' => $username,
                'vendor' => $validated['vendor'] ?? null,
            ]);

            return response()->json([
                'success' => false,
                'error' => $exception->getMessage(),
            ], 500);
        }
    }

    public function syncNas(Request $request)
    {
        $validated = $request->validate([
            'nasname' => 'required|string',
            'secret' => 'required|string|min:4',
            'ports' => 'nullable|integer|min:1|max:65535',
            'shortname' => 'nullable|string',
            'type' => 'nullable|string',
            'server' => 'nullable|string',
            'community' => 'nullable|string',
            'description' => 'nullable|string',
            'auth_port' => 'nullable|integer|min:1|max:65535',
            'acct_port' => 'nullable|integer|min:1|max:65535',
            'reload' => 'nullable|boolean',
        ]);

        try {
            $payload = Arr::except($validated, ['reload']);
            $result = $this->radiusService->syncNas($payload, (bool) ($validated['reload'] ?? true));

            return response()->json([
                'success' => true,
                'message' => 'NAS synchronised successfully',
                'data' => $result,
            ], 200);
        } catch (\Throwable $exception) {
            Log::error('Failed to sync NAS', [
                'payload' => $validated,
                'error' => $exception->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => $exception->getMessage(),
            ], 500);
        }
    }

    public function deleteNas(Request $request, string $nasname)
    {
        $validated = $request->validate([
            'disconnect_users' => 'nullable|boolean',
            'reload' => 'nullable|boolean',
        ]);

        try {
            $result = $this->radiusService->deleteNas(
                $nasname,
                (bool) ($validated['disconnect_users'] ?? true),
                (bool) ($validated['reload'] ?? true)
            );

            $status = $result['status'] ?? ($result['removed'] ? 200 : 404);

            return response()->json([
                'success' => $result['removed'],
                'message' => $result['message'] ?? ($result['removed'] ? 'NAS deleted successfully' : 'NAS not found'),
                'data' => $result,
            ], $status);
        } catch (\Throwable $exception) {
            Log::error('Failed to delete NAS', [
                'nasname' => $nasname,
                'error' => $exception->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => $exception->getMessage(),
            ], 500);
        }
    }

    public function deactivateNas(Request $request, string $nasname)
    {
        $validated = $request->validate([
            'disconnect_users' => 'nullable|boolean',
            'reload' => 'nullable|boolean',
        ]);

        try {
            $result = $this->radiusService->deactivateNas(
                $nasname,
                (bool) ($validated['disconnect_users'] ?? true),
                (bool) ($validated['reload'] ?? true)
            );

            $status = $result['status'] ?? 200;

            return response()->json([
                'success' => true,
                'message' => 'NAS deactivated successfully',
                'data' => $result,
            ], $status);
        } catch (\Throwable $exception) {
            Log::error('Failed to deactivate NAS', [
                'nasname' => $nasname,
                'error' => $exception->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => $exception->getMessage(),
            ], 500);
        }
    }

    public function activateNas(Request $request, string $nasname)
    {
        $validated = $request->validate([
            'disconnect_users' => 'nullable|boolean',
            'reload' => 'nullable|boolean',
        ]);
        set_time_limit(3600); // Extend execution time to 1 hour
        ini_set('memory_limit', '-1');

        try {
            $result = $this->radiusService->activateNas(
                $nasname,
                (bool) ($validated['disconnect_users'] ?? true),
                (bool) ($validated['reload'] ?? true)
            );

            $status = $result['status'] ?? 200;

            return response()->json([
                'success' => true,
                'message' => 'NAS activated successfully',
                'data' => $result,
            ], $status);
        } catch (\Throwable $exception) {
            Log::error('Failed to activate NAS', [
                'nasname' => $nasname,
                'error' => $exception->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => $exception->getMessage(),
            ], 500);
        }
    }

    public function listNasUsers(string $nasname)
    {
        try {
            $result = $this->radiusService->getActiveUsersForNas($nasname);

            return response()->json([
                'success' => true,
                'message' => 'Active NAS users retrieved',
                'data' => $result,
            ], 200);
        } catch (\Throwable $exception) {
            Log::error('Failed to list NAS users', [
                'nasname' => $nasname,
                'error' => $exception->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => $exception->getMessage(),
            ], 500);
        }
    }

    public function testNasConnection(Request $request)
    {
        $validated = $request->validate([
            'ip_address' => 'required|ip',
            'auth_port' => 'nullable|integer|min:1|max:65535',
            'acct_port' => 'nullable|integer|min:1|max:65535',
            'secret' => 'required|string|min:4',
            'timeout' => 'nullable|integer|min:1|max:30',
            'username' => 'nullable|string',
            'password' => 'nullable|string',
        ]);

        try {
            $result = $this->radiusService->testNasConnection(
                $validated['ip_address'],
                (int) ($validated['auth_port'] ?? 1812),
                (int) ($validated['acct_port'] ?? 1813),
                $validated['secret'],
                $validated['username'] ?? null,
                $validated['password'] ?? null,
                (int) ($validated['timeout'] ?? 5)
            );

            return response()->json([
                'success' => $result['reachable'],
                'message' => $result['message'],
                'data' => $result,
            ], $result['reachable'] ? 200 : 424);
        } catch (\Throwable $exception) {
            Log::error('Failed to test NAS connection', [
                'ip_address' => $validated['ip_address'],
                'error' => $exception->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => $exception->getMessage(),
            ], 500);
        }
    }

    public function reloadRadius()
    {
        try {
            $result = $this->radiusService->reloadFreeRadius();

            return response()->json([
                'success' => true,
                'message' => 'FreeRADIUS reloaded successfully',
                'data' => $result,
            ]);
        } catch (\Throwable $exception) {
            Log::error('FreeRADIUS reload failed via API', [
                'error' => $exception->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => $exception->getMessage(),
            ], 500);
        }
    }

    public function blockUser(Request $request)
    {
        $validated = $request->validate([
            'username' => 'required|string',
            'disconnect' => 'nullable|boolean',
        ]);

        try {
            $result = $this->radiusService->blockUser($validated['username'], (bool) ($validated['disconnect'] ?? true));

            return response()->json([
                'success' => true,
                'message' => 'User blocked successfully',
                'data' => $result,
            ]);
        } catch (\Throwable $exception) {
            Log::error('Failed to block user on FreeRADIUS', [
                'username' => $validated['username'],
                'error' => $exception->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => $exception->getMessage(),
            ], 500);
        }
    }

    public function unblockUser(Request $request)
    {
        $validated = $request->validate([
            'username' => 'required|string',
            'disconnect' => 'nullable|boolean',
        ]);

        try {
            $result = $this->radiusService->unblockUser($validated['username'], (bool) ($validated['disconnect'] ?? true));

            return response()->json([
                'success' => true,
                'message' => 'User unblocked successfully',
                'data' => $result,
            ]);
        } catch (\Throwable $exception) {
            Log::error('Failed to unblock user on FreeRADIUS', [
                'username' => $validated['username'],
                'error' => $exception->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => $exception->getMessage(),
            ], 500);
        }
    }

    public function disconnectUserSessions(Request $request)
    {
        $validated = $request->validate([
            'username' => 'required|string',
        ]);

        try {
            $result = $this->radiusService->disconnectUser($validated['username']);

            return response()->json([
                'success' => $result['success'],
                'message' => $result['success'] ? 'Disconnect command executed' : 'No active sessions to disconnect',
                'data' => $result,
            ]);
        } catch (\Throwable $exception) {
            Log::error('Failed to disconnect user sessions', [
                'username' => $validated['username'],
                'error' => $exception->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => $exception->getMessage(),
            ], 500);
        }
    }

    public function checkFUP()
    {
        try {
            $results = $this->radiusService->checkFUPAndApplyLimit();

            return response()->json([
                'success' => true,
                'message' => 'FUP check executed successfully',
                'processed_users' => count($results),
                'details' => $results,
            ]);
        } catch (\Exception $e) {
            Log::error('FUP check failed: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }
    // public function activateUser(Request $request)
    // {
    //     $customer = $request->customer;
    //     $createRadReply = null;
    //     $createNas = null;
    //     $createRad = null;
    //     $checkRad = RadCheck::where('username', $customer['customer_id'])->first();
    //     $checkRadReply = RadReply::where('username', $customer['customer_id'])->first();
    //     if (! $checkRad) {
    //         $createRad = RadCheck::create([
    //             'username' => $customer['customer_id'],
    //             'attribute' => 'Cleartext-Password',
    //             'op' => ':=',
    //             'value' => $customer['password'] ?? 'admin',
    //         ]);

    //     }
    //     if (! $checkRadReply) {
    //         $createRadReply = RadReply::create([
    //             'username' => $customer['customer_id'],
    //             'attribute' => 'Mikrotik-Rate-Limit',
    //             'op' => ':=',
    //             'value' => '2M/1M',
    //         ]);
    //     }
    //     $checkNas = Nas::where('nasname', $customer['router']['router_address'])->first();
    //     if (! $checkNas) {
    //         $createNas = Nas::create([
    //             'nasname' => $customer['router']['router_address'],
    //             'ports' => $customer['router']['router_port'],
    //             'secret' => $customer['router']['router_secret'],
    //         ]);
    //     }
    //     $checkRadReply->update(
    //         [

    //             'attribute' => 'Mikrotik-Rate-Limit',
    //             'op' => ':=',
    //             'value' => '5M/10M']
    //     );
    //     $username = escapeshellarg($checkRad->username ?? $createRad->username);
    //     $rateLimit = escapeshellarg($checkRadReply->value ?? $createRadReply->value); // Pastikan value sesuai format "15M/10M"
    //     $ipAddress = escapeshellarg($checkNas->nasname); // Bisa dijadikan ENV jika dinamis
    //     $port = escapeshellarg($checkNas->ports ?? $createNas->ports); // Bisa dijadikan ENV jika dinamis
    //     $secret = escapeshellarg($checkNas->ports ?? $createNas->ports); // Bisa dijadikan ENV jika dinamis
    //     $commandChangeBandwidth = "echo \"User-Name={$username}, Mikrotik-Rate-Limit={$rateLimit}\" | radclient -x 141.11.25.148:3799 coa BahanaRadius";
    //     // $commandDisconnect = "echo \"User-Name={$username}\" | radclient -x {$ipAddress}:{$port} disconnect {$secret}";
    //     Log::info('commandChangeBandwidth: '.$commandChangeBandwidth);
    //     $outputChangeBandwidth = shell_exec($commandChangeBandwidth);

    //     return $outputChangeBandwidth;
    // }
}
