<?php

namespace App\Filament\Employee\Resources;

use App\Filament\Actions\TableActions\DownloadTimesheetAction;
use App\Filament\Employee\Resources\TimesheetResource\Pages;
use App\Models\Timesheet;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class TimesheetResource extends Resource
{
    protected static ?string $model = Timesheet::class;

    protected static ?string $navigationIcon = 'gmdi-document-scanner-o';

    protected static ?string $recordTitleAttribute = 'month';

    protected static ?int $navigationSort = -2;

    protected static bool $isGloballySearchable = false;

    public static function getRecordTitle(?Model $record): string|Htmlable|null
    {
        if ($record === null) {
            return null;
        }

        return Carbon::parse($record->month)->format('F Y');
    }

    public static function tableFilters(): array
    {
        return [
            Tables\Filters\Filter::make('year')
                ->query(fn ($query, array $data) => $query->when(is_numeric($data['year']), fn ($query) => $query->whereYear('month', $data['year'])))
                ->indicateUsing(fn (array $data) => is_numeric($data['year']) ? 'Year: '.$data['year'] : null)
                ->form([
                    Forms\Components\Select::make('year')
                        ->options(fn () => collect(range(now()->year, now()->year - 2, -1))->mapWithKeys(fn ($year) => [$year => $year]))
                        ->default(now()->year)
                        ->required()
                        ->rule('required'),
                ]),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->filters(static::tableFilters())
            ->columns([
                Tables\Columns\TextColumn::make('month')
                    ->extraCellAttributes(['class' => 'font-mono'])
                    ->state(fn (Timesheet $record) => Carbon::parse($record->month)->format('M Y'))
                    ->sortable(),
                Tables\Columns\TextColumn::make('period')
                    ->hidden(fn ($livewire) => $livewire->activeTab === 'Monthly')
                    ->extraCellAttributes(['class' => 'font-mono'])
                    ->sortable(query: fn (Builder $query, string $direction) => $query->orderBy('month', $direction)->orderBy('span')),
                Tables\Columns\TextColumn::make('days')
                    ->state(fn (Timesheet $record) => $record->days ?: null)
                    ->numeric()
                    ->alignEnd()
                    ->extraCellAttributes(['class' => 'font-mono']),
                Tables\Columns\TextColumn::make('undertime')
                    ->state(fn (Timesheet $record) => $record->undertime ?: null)
                    ->tooltip(fn (Timesheet $record) => $record->undertime ? $record->getUndertime(true) : null)
                    ->numeric()
                    ->alignEnd()
                    ->extraCellAttributes(['class' => 'font-mono'])
                    ->toggleable(),
                Tables\Columns\TextColumn::make('overtime')
                    ->state(fn (Timesheet $record) => $record->overtime ?: null)
                    ->tooltip(fn (Timesheet $record) => $record->overtime ? $record->getOvertime(true) : null)
                    ->numeric()
                    ->alignEnd()
                    ->extraCellAttributes(['class' => 'font-mono'])
                    ->toggleable(),
                Tables\Columns\TextColumn::make('missed')
                    ->state(fn (Timesheet $record) => $record->missed ?: null)
                    ->tooltip(fn (Timesheet $record) => $record->missed ? $record->getMissed(true) : null)
                    ->numeric()
                    ->alignEnd()
                    ->extraCellAttributes(['class' => 'font-mono'])
                    ->toggleable(),
                Tables\Columns\TextColumn::make('export.created_at')
                    ->label('Certified')
                    ->hidden(fn ($livewire) => $livewire->activeTab === 'Monthly')
                    ->since()
                    ->dateTimeTooltip(),
                Tables\Columns\TextColumn::make('leaderSigner.created_at')
                    ->label(str(settings('leader') ?? 'leader')->title())
                    ->hidden(fn ($livewire) => $livewire->activeTab === 'Monthly')
                    ->since()
                    ->dateTimeTooltip(),
                Tables\Columns\TextColumn::make('directorSigner.created_at')
                    ->label(str(settings('director') ?? 'director')->title())
                    ->hidden(fn ($livewire) => $livewire->activeTab === 'Monthly')
                    ->since()
                    ->dateTimeTooltip(),
            ])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    DownloadTimesheetAction::make()
                        ->label('Download')
                        ->color('gray')
                        ->hidden(fn ($livewire) => $livewire->activeTab === 'Monthly'),
                    Tables\Actions\DeleteAction::make()
                        ->hidden(fn (Timesheet $record) => $record->exports->isEmpty())
                        ->successNotificationTitle('Deleted successfully.')
                        ->modalDescription('This is a destructive action and will permanently delete this timesheet and all its certifications and or verifications.')
                        ->form([
                            TextInput::make('password')
                                ->password()
                                ->currentPassword()
                                ->dehydrated(false)
                                ->markAsRequired()
                                ->rule('required'),
                            Checkbox::make('confirmation')
                                ->label('I understand the consequences of this action')
                                ->markAsRequired()
                                ->dehydrated(false)
                                ->accepted()
                                ->validationMessages(['accepted' => 'You must confirm that you understand the consequences of this action.']),
                        ])
                        ->action(function (Tables\Actions\DeleteAction $action, Timesheet $record) {
                            if ($record->span === 'full') {
                                $record->export->delete();

                                $record->attachments()->lazyById()->each->delete();

                                $record->update([
                                    'certification' => null,
                                ]);
                            } else {
                                $record->delete();
                            }

                            $action->sendSuccessNotification();
                        })
                        ->hidden(fn ($livewire) => $livewire->activeTab === 'Monthly'),

                ]),
                Tables\Actions\ViewAction::make()
                    ->url(fn (Timesheet $record) => route('filament.employee.resources.timesheets.view', $record))
                    ->hidden(fn ($livewire) => $livewire->activeTab === 'Certified'),
            ])
            ->defaultSort(fn (Builder $query) => $query->orderBy('month', 'desc')->orderBy('span', 'desc'))
            ->deferLoading()
            ->recordAction(null)
            ->recordUrl(null);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTimesheets::route('/'),
            'view' => Pages\ViewTimesheet::route('/{record}'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('employee_id', Filament::auth()->id());
    }
}
