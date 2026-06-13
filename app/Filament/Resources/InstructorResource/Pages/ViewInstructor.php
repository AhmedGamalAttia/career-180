<?php

namespace App\Filament\Resources\InstructorResource\Pages;

use App\Filament\Resources\InstructorResource;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Pages\ViewRecord;

class ViewInstructor extends ViewRecord
{
    protected static string $resource = InstructorResource::class;

    // Read-only: no edit action.
    protected function getHeaderActions(): array
    {
        return [];
    }

    public function infolist(Infolist $infolist): Infolist
    {
        $egp = fn ($state) => InstructorResource::egp($state);

        return $infolist->schema([
            Section::make('Instructor')
                ->columns(2)
                ->schema([
                    TextEntry::make('name'),
                    TextEntry::make('email'),
                    TextEntry::make('payout_account_ref')->label('Payout account')->default('—'),
                ]),
            Section::make('Balance')
                ->description('Earned − Paid − In-flight = Outstanding. Source of truth is the ledger; these are the cached projection figures.')
                ->columns(4)
                ->schema([
                    TextEntry::make('balance.lifetime_vested_minor')->label('Earned')->formatStateUsing($egp),
                    TextEntry::make('balance.lifetime_paid_minor')->label('Paid')->formatStateUsing($egp),
                    TextEntry::make('balance.in_flight_minor')->label('In flight')->color('warning')->formatStateUsing($egp),
                    TextEntry::make('balance.available_minor')->label('Outstanding')->weight('bold')->color('success')->formatStateUsing($egp),
                ]),
        ]);
    }
}
