<?php

namespace App\Http\Controllers;

use App\Models\Nas;
use App\Models\RadCheck;
use App\Models\RadReply;
use App\Services\RadiusService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Log;

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
        // $request->validate([
        //     'username' => 'required|string|unique:radcheck,username',
        //     'password' => 'required|string|min:6',
        //     'vendor' => 'required|string|in:mikrotik,cisco,juniper',
        //     'ipAddress' => 'required|ip',
        //     'port' => 'required|integer',
        //     'secret' => 'required|string',
        // ]);
        $customer = $request->customer;

        try {
            $result = $this->radiusService->addUser(
                $request->customer,
                $request->password,
                $request->vendor,
                $request->ipAddress,
                $request->port,
                $request->secret
            );

            return response()->json(['message' => $result], 201);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function checkFUP()
    {
        try {
            $this->radiusService->checkFUPAndApplyLimit();

            return response()->json(['message' => 'FUP check executed successfully']);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
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
