<?php

namespace App\Filament\Resources\InstructorResource\RelationManagers;

use App\Filament\Resources\InstructorResource;
use App\Models\Payout;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Read-only payout history for an instructor.
 */
class PayoutsRelationManager extends RelationManager
{
    protected static string $relationship = 'payouts';

    protected static ?string $title = 'Payout history';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('batch.period_key')
                    ->label('Period')
                    ->sortable(),
                Tables\Columns\TextColumn::make('amount_minor')
                    ->label('Amount')
                    ->formatStateUsing(fn ($state) => InstructorResource::egp($state))
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        Payout::STATUS_PAID => 'success',
                        Payout::STATUS_FAILED => 'danger',
                        Payout::STATUS_UNKNOWN => 'warning',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('attempts')->label('Attempts'),
                Tables\Columns\TextColumn::make('external_payout_ref')
                    ->label('Provider ref')
                    ->default('—')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('last_checked_at')
                    ->label('Last update')
                    ->dateTime()
                    ->placeholder('—'),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options([
                    Payout::STATUS_PENDING => 'Pending',
                    Payout::STATUS_PROCESSING => 'Processing',
                    Payout::STATUS_PAID => 'Paid',
                    Payout::STATUS_FAILED => 'Failed',
                    Payout::STATUS_UNKNOWN => 'Unknown',
                ]),
            ]);
    }

    public function isReadOnly(): bool
    {
        return true;
    }
}
