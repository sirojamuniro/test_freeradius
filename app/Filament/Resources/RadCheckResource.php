<?php

namespace App\Filament\Resources;

use App\Filament\Resources\RadCheckResource\Pages;
use App\Models\RadCheck;
use App\Services\RadiusService;
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
                    ->label(fn ($record) => self::userIsBlocked($record->username) ? 'Unblock' : 'Block')
                    ->icon(fn ($record) => self::userIsBlocked($record->username) ? 'heroicon-o-check-circle' : 'heroicon-o-ban')
                    ->color(fn ($record) => self::userIsBlocked($record->username) ? 'success' : 'danger')
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

    protected static function toggleBlockUser($record): void
    {
        $username = $record->username;

        if (! $username) {
            return;
        }

        /** @var RadiusService $service */
        $service = app(RadiusService::class);

        if ($service->userIsBlocked($username)) {
            $service->unblockUser($username);
        } else {
            $service->blockUser($username);
        }
    }

    protected static function userIsBlocked(?string $username): bool
    {
        if (! $username) {
            return false;
        }

        /** @var RadiusService $service */
        $service = app(RadiusService::class);

        return $service->userIsBlocked($username);
    }
}
