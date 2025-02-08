<?php

namespace App\Filament\Resources;

use App\Filament\Resources\RadCheckResource\Pages;
use App\Models\Nas;
use App\Models\RadCheck;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Form;
use Filament\Resources\Resource;
use Filament\Resources\Table;
use Filament\Tables;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;

class RadCheckResource extends Resource
{
    protected static ?string $model = RadCheck::class;

    protected static ?string $navigationIcon = 'heroicon-o-collection';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                TextInput::make('username')->required(),
                TextInput::make('attribute')->required(),
                TextInput::make('op')->default(':='), // Operator
                TextInput::make('value')->required(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->sortable(),
                TextColumn::make('username')->searchable(),
                TextColumn::make('attribute'),
                TextColumn::make('op'),
                TextColumn::make('value'),
            ])
            ->filters([])
            ->actions([
                Tables\Actions\EditAction::make(),
                Action::make('block')
                    ->label(fn ($record) => $record->attribute === 'Auth-Type' && $record->value === 'Reject' ? 'Unblock' : 'Block')
                    ->icon(fn ($record) => $record->attribute === 'Auth-Type' && $record->value === 'Reject' ? 'heroicon-o-check-circle' : 'heroicon-o-ban')
                    ->color(fn ($record) => $record->attribute === 'Auth-Type' && $record->value === 'Reject' ? 'success' : 'danger')
                    ->action(fn ($record) => self::toggleBlockUser($record)),
            ])
            ->bulkActions([
                Tables\Actions\DeleteBulkAction::make(),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRadChecks::route('/'),
            'create' => Pages\CreateRadCheck::route('/create'),
            'edit' => Pages\EditRadCheck::route('/{record}/edit'),
        ];
    }

    protected static function toggleBlockUser($record)
    {
        $checkNas = Nas::where('username', $record->username)->firstOrFail();
        $ipAddress = $checkNas->nasname;
        $port = $checkNas->ports;
        $secret = $checkNas->secret;
        $isBlocked = $record->attribute === 'Auth-Type' && $record->value === 'Reject';

        if ($isBlocked) {
            // Jika user sedang diblokir, hapus blokirnya
            RadCheck::where('username', $record->username)
                ->where('attribute', 'Auth-Type')
                ->where('value', 'Reject')
                ->delete();
            $command = "echo \"User-Name={$record->username}\" | radclient -x {$ipAddress}:{$port} disconnect {$secret}";

            // Menjalankan perintah menggunakan shell_exec atau exec
            $output = shell_exec($command);
        } else {
            // Jika user belum diblokir, tambahkan aturan untuk memblokir
            RadCheck::create([
                'username' => $record->username,
                'username' => $record->username,
                'attribute' => 'Auth-Type',
                'op' => ':=',
                'value' => 'Reject',
            ]);
            $command = "echo \"User-Name={$record->username}\" | radclient -x {$ipAddress}:{$port} disconnect {$secret}";

            // Menjalankan perintah menggunakan shell_exec atau exec
            $output = shell_exec($command);
        }
    }
}
