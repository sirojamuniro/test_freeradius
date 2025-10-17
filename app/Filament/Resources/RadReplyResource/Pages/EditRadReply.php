<?php

namespace App\Filament\Resources\RadReplyResource\Pages;

use App\Filament\Resources\RadReplyResource;
use App\Models\Nas;
use App\Models\RadAcct;
use Filament\Pages\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

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
        $radAcct = RadAcct::where('username', $this->record->username)->first();

        if (! $radAcct) {
            Log::warning('No active radacct session found for username; skipping CoA/Disconnect.', [
                'username' => $this->record->username,
            ]);

            return;
        }

        $nas = Nas::where('nasname', $radAcct->nasipaddress)->first();

        if (! $nas) {
            Log::warning('NAS entry not found while trying to apply CoA.', [
                'username' => $this->record->username,
                'nasname' => $radAcct->nasipaddress,
            ]);

            return;
        }

        $this->runRadclientCommand(
            nasAddress: $nas->nasname,
            port: (int) $nas->ports,
            secret: $nas->secret,
            inputLines: [
                'User-Name='.$this->record->username,
                'Mikrotik-Rate-Limit='.$this->record->value,
            ],
            action: 'coa'
        );

        $this->runRadclientCommand(
            nasAddress: $nas->nasname,
            port: (int) $nas->ports,
            secret: $nas->secret,
            inputLines: [
                'User-Name='.$this->record->username,
            ],
            action: 'disconnect'
        );
    }

    protected function runRadclientCommand(string $nasAddress, int $port, string $secret, array $inputLines, string $action): void
    {
        $command = sprintf(
            'radclient -x %s %s %s',
            escapeshellarg($nasAddress.':'.$port),
            $action,
            escapeshellarg($secret)
        );

        $process = Process::fromShellCommandline($command);
        $process->setInput(implode("\n", array_filter($inputLines))."\n");
        $process->run();

        $output = trim($process->getOutput());
        $errorOutput = trim($process->getErrorOutput());

        if ($process->isSuccessful()) {
            Log::info('radclient command executed successfully.', [
                'command' => $command,
                'action' => $action,
                'output' => $output,
            ]);
        } else {
            Log::warning('radclient command failed.', [
                'command' => $command,
                'action' => $action,
                'exit_code' => $process->getExitCode(),
                'output' => $output,
                'error_output' => $errorOutput,
            ]);
        }
    }
}
