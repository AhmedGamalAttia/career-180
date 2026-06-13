<?php

namespace App\Filament\Resources;

use App\Filament\Resources\InstructorResource\Pages;
use App\Filament\Resources\InstructorResource\RelationManagers\PayoutsRelationManager;
use App\Models\Instructor;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Read-only operational view of instructor money positions. The figures come
 * from the instructor_balances projection (rebuildable from the ledger), so this
 * screen never mutates financial data — it only reports it.
 */
class InstructorResource extends Resource
{
    protected static ?string $model = Instructor::class;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationLabel = 'Instructor Earnings';

    /** Format integer minor units (piasters) as an EGP amount. */
    public static function egp(?int $minor): string
    {
        return 'EGP '.number_format(($minor ?? 0) / 100, 2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('email')
                    ->searchable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('balance.lifetime_vested_minor')
                    ->label('Earned')
                    ->formatStateUsing(fn ($state) => static::egp($state))
                    ->sortable(),
                Tables\Columns\TextColumn::make('balance.lifetime_paid_minor')
                    ->label('Paid')
                    ->formatStateUsing(fn ($state) => static::egp($state))
                    ->sortable(),
                Tables\Columns\TextColumn::make('balance.in_flight_minor')
                    ->label('In flight')
                    ->formatStateUsing(fn ($state) => static::egp($state))
                    ->color('warning')
                    ->sortable(),
                Tables\Columns\TextColumn::make('balance.available_minor')
                    ->label('Outstanding')
                    ->formatStateUsing(fn ($state) => static::egp($state))
                    ->weight('bold')
                    ->color('success')
                    ->sortable(),
            ])
            ->defaultSort('name')
            ->actions([
                Tables\Actions\ViewAction::make(),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            PayoutsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListInstructors::route('/'),
            'view' => Pages\ViewInstructor::route('/{record}'),
        ];
    }

    // Read-only resource: no create / edit / delete.
    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }
}
