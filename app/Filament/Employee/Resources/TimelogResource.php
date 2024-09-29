<?php

namespace App\Filament\Employee\Resources;

use App\Enums\TimelogMode;
use App\Enums\TimelogState;
use App\Filament\Employee\Resources\TimelogResource\Pages;
use App\Models\Timelog;
use Filament\Forms\Components\DateTimePicker;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Filters\Indicator;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

class TimelogResource extends Resource
{
    protected static ?string $model = Timelog::class;

    protected static ?string $navigationIcon = 'gmdi-alarm-on-o';

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(function (Builder $query) {
                $query->whereHas('employee', fn ($query) => $query->where('employees.id', Auth::id()));

                $query->with('original');
            })
            ->columns([
                Tables\Columns\TextColumn::make('scanner.name')
                    ->searchable()
                    ->sortable()
                    ->extraAttributes(['class' => 'font-mono']),
                Tables\Columns\TextColumn::make('time')
                    ->searchable()
                    ->dateTime('Y-m-d H:i:s')
                    ->sortable()
                    ->extraAttributes(['class' => 'font-mono']),
                Tables\Columns\TextColumn::make('uid')
                    ->label('UID')
                    ->searchable(query: fn ($query, $search) => $query->whereUid($search)),
                Tables\Columns\TextColumn::make('state'),
                Tables\Columns\TextColumn::make('mode'),
                Tables\Columns\TextColumn::make('recast')
                    ->alignEnd()
                    ->label('Rectified')
                    ->badge()
                    ->tooltip(fn (Timelog $record) => $record->recast ? $record->original->state->getLabel() : null)
                    ->icon(fn (Timelog $record) => $record->recast ? 'heroicon-o-exclamation-triangle' : 'heroicon-o-shield-check')
                    ->color(fn (Timelog $record) => $record->recast ? 'warning' : 'primary')
                    ->state(fn (Timelog $record) => $record->recast ? 'Yes' : 'No'),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('recast')
                    ->label('Rectified')
                    ->native(false)
                    ->queries(
                        true: fn ($query) => $query->where('recast', true),
                        false: fn ($query) => $query->where('recast', false),
                    ),
                Tables\Filters\Filter::make('time')
                    ->form([
                        DateTimePicker::make('from')
                            ->seconds(false),
                        DateTimePicker::make('until')
                            ->seconds(false),
                    ])
                    ->query(function ($query, array $data) {
                        $query->when($data['from'], fn ($q, $d) => $q->where('time', '>=', Carbon::parse($d)->format('Y-m-d H:i:s')));
                        $query->when($data['until'], fn ($q, $d) => $q->where('time', '<=', Carbon::parse($d)->format('Y-m-d H:i:s')));
                    })
                    ->indicateUsing(function (array $data) {
                        $indicators = [];

                        if (isset($data['from'])) {
                            $indicators[] = Indicator::make('From: '.Carbon::parse($data['from'])->format('Y-m-d H:i'))
                                ->removeField('from');
                        }

                        if (isset($data['until'])) {
                            $indicators[] = Indicator::make('Until: '.Carbon::parse($data['until'])->format('Y-m-d H:i'))
                                ->removeField('until');
                        }

                        return $indicators;
                    }),
                Tables\Filters\SelectFilter::make('scanner')
                    ->relationship('scanner', 'name', fn ($query) => $query->whereHas('employees', fn ($query) => $query->where('employees.id', Auth::id()))->reorder()->orderBy('priority', 'desc')->orderBy('name'))
                    ->searchable()
                    ->multiple()
                    ->preload(),
                Tables\Filters\SelectFilter::make('mode')
                    ->options(TimelogMode::class)
                    ->multiple()
                    ->searchable(),
                Tables\Filters\SelectFilter::make('state')
                    ->options(TimelogState::class)
                    ->multiple()
                    ->searchable(),
            ])
            ->actions([

            ])
            ->bulkActions([

            ])
            ->defaultSort('time', 'desc')
            ->recordAction(null)
            ->recordUrl(null);
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
            'index' => Pages\ListTimelogs::route('/'),
        ];
    }
}
