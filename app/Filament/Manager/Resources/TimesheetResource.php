<?php

namespace App\Filament\Manager\Resources;

use App\Filament\Actions\TableActions\BulkAction\CertifyTimesheetAction as BulkActionCertifyTimesheetAction;
use App\Filament\Actions\TableActions\BulkAction\DownloadTimesheetBulkAction;
use App\Filament\Actions\TableActions\DownloadTimesheetAction;
use App\Filament\Filters\OfficeFilter;
use App\Filament\Filters\StatusFilter;
use App\Filament\Manager\Resources\TimesheetResource\Pages;
use App\Models\Employee;
use App\Models\Timesheet;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

class TimesheetResource extends Resource
{
    protected static ?string $model = Timesheet::class;

    protected static ?string $navigationIcon = 'gmdi-document-scanner-o';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                //
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(function (Builder $query) {
                $query->with([
                    'employee',
                    'records' => fn ($query) => $query->whereNot('punch', '[]'),
                    'exports' => fn ($query) => $query->select(['exports.id', 'exportable_id', 'exportable_type', 'details']),
                    'exports.signers',
                ]);
            })
            ->columns([
                Tables\Columns\TextColumn::make('employee.uid')
                    ->label('UID')
                    ->visible(fn () => in_array(Filament::getCurrentPanel()->getId(), ['manager']))
                    ->extraCellAttributes(['class' => 'uppercase'])
                    ->searchable(query: fn (Builder $query, string $search) => $query->whereHas('employee', fn ($query) => $query->where('uid', 'like', "$search")))
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('employee.name')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('employee.status')
                    ->label('Status')
                    ->limit(24)
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->getStateUsing(function (Timesheet $record): string {
                        return str($record->employee->status?->value)
                            ->title()
                            ->when($record->employee->substatus?->value, function ($status) use ($record) {
                                return $status->append(" ({$record->employee->substatus->value})")->replace('_', '-')->title();
                            });
                    }),
                Tables\Columns\TextColumn::make('employee.offices.code')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->visible(fn () => in_array(Filament::getCurrentPanel()->getId(), ['manager']))
                    ->formatStateUsing(function (Timesheet $record) {
                        $offices = $record->employee->offices->map(function ($office) {
                            return str($office->code)
                                ->when($office->pivot->current, function ($code) {
                                    return <<<HTML
                                        <span class="text-sm text-custom-600 dark:text-custom-400" style="--c-400:var(--primary-400);--c-600:var(--primary-600);">$code</span>
                                    HTML;
                                });
                        })->join(', ');

                        return str($offices)->toHtmlString();
                    }),
                Tables\Columns\TextColumn::make('period')
                    ->extraCellAttributes(['class' => 'font-mono'])
                    ->sortable(query: fn (Builder $query, string $direction) => $query->orderBy('month', $direction)->orderBy('span'))
                    ->toggleable(),
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
                    ->label('Employee Certified')
                    ->since()
                    ->dateTimeTooltip(),
                Tables\Columns\TextColumn::make('leaderSigner.created_at')
                    ->label(str(settings('leader') ?? 'leader')->title())
                    ->since()
                    ->dateTimeTooltip(),
                Tables\Columns\TextColumn::make('directorSigner.created_at')
                    ->label(str(settings('director') ?? 'director')->title())
                    ->since()
                    ->dateTimeTooltip(),
            ])
            ->filters([
                OfficeFilter::make()
                    ->relationship('employee')
                    ->hidden(fn () => in_array(Filament::getCurrentPanel()->getId(), ['director', 'leader'])),
                Tables\Filters\Filter::make('month')
                    ->form([
                        Forms\Components\TextInput::make('month')
                            ->type('month'),
                    ])
                    ->query(function (Builder $query, array $data) {
                        $query->when($data['month'] ?? null, fn ($query) => $query->where('month', Carbon::parse($data['month'])->format('Y-m-01')));
                    })
                    ->indicateUsing(function (array $data) {
                        if (empty($data['month'])) {
                            return null;
                        }

                        return 'Month: '.Carbon::parse($data['month'])->format('F Y');
                    }),
                Tables\Filters\Filter::make('period')
                    ->form([
                        Forms\Components\Select::make('period')
                            ->placeholder('All')
                            ->options([
                                '1st' => 'First half',
                                '2nd' => 'Second half',
                                'full' => 'Full month',
                            ]),
                    ])
                    ->query(function (Builder $query, array $data) {
                        $query->when(isset($data['period']) && $data['period'], fn ($query) => $query->where('span', $data['period']));
                    })
                    ->indicateUsing(function (array $data) {
                        if (empty($data['period'])) {
                            return null;
                        }

                        return 'Period: '.match ($data['period']) {
                            '1st' => 'First half',
                            '2nd' => 'Second half',
                            'full' => 'Full month',
                            default => $data['period'],
                        };
                    }),
                StatusFilter::make()
                    ->relationship('employee')
                    ->columnSpan(2)
                    ->columns(2)
                    ->single(),
            ], FiltersLayout::AboveContent)
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\Action::make('notify')
                        ->visible(fn () => in_array(Filament::getCurrentPanel()->getId(), ['director', 'leader']))
                        ->requiresConfirmation()
                        ->icon('heroicon-o-bell')
                        ->modalIcon('heroicon-o-bell')
                        ->modalDescription('Notify the employee about their timesheet. This does nothing but send a notification.')
                        ->form([
                            Forms\Components\Select::make('type')
                                ->label('Type')
                                ->placeholder('Select a type')
                                ->options([
                                    'success' => 'Success',
                                    'info' => 'Information',
                                    'warning' => 'Warning',
                                    'danger' => 'Error',
                                ])
                                ->required(),
                            Forms\Components\Textarea::make('message')
                                ->label('Message')
                                ->placeholder('Enter a message')
                                ->rule('required')
                                ->markAsRequired(),
                        ])
                        ->action(function (Timesheet $record, array $data) {
                            Notification::make()
                                ->{$data['type']}()
                                ->title("Timesheet {$record->period}")
                                ->body(function () use ($data) {
                                    $user = user();

                                    $message = <<<HTML
                                        <div>{$data['message']}</div>

                                        <div class="italic">
                                            - $user->name
                                        </div>
                                    HTML;

                                    return str($message)->toHtmlString();
                                })
                                ->sendToDatabase($record->employee, true)
                                ->toBroadcast($record->employee);

                            Notification::make()
                                ->success()
                                ->title('Notification sent')
                                ->send();
                        }),
                    DownloadTimesheetAction::make()
                        ->visible(fn () => in_array(Filament::getCurrentPanel()->getId(), ['director', 'leader']))
                        ->label('Download'),
                ]),
            ])
            ->bulkActions([
                DownloadTimesheetBulkAction::make(),
                BulkActionCertifyTimesheetAction::make()
                    ->label('Verify'),
            ])
            ->defaultSort(function (Builder $query) {
                $query->orderBy('month', 'desc');

                $query->orderBy('span', 'desc');

                $query->orderBy(Employee::select('name')->whereColumn('employee_id', 'employees.id'));
            })
            ->deferLoading()
            ->recordAction(null)
            ->recordUrl(null);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTimesheets::route('/'),
            'verify' => Pages\VerifyTimesheets::route('verify'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        $query->where(function ($query) {
            $query->certified();

            $query->whereHas('employee');

            $panel = Filament::getCurrentPanel()->getId();

            $query->when($panel === 'director', function ($query) {
                $query->where(function ($query) {
                    $query->whereHas('employee.offices', function (Builder $query) {
                        $query->where('offices.id', Auth::user()->employee?->currentDeployment?->office?->id);

                        $query->where('deployment.current', true);
                    });

                    $query->where(
                        'timesheets.details->signers->director',
                        Employee::select('id')->whereHas('user', fn ($q) => $q->where('id', Auth::id())),
                    );
                });
            });

            $query->when($panel === 'leader', function ($query) {
                $query->where(function ($query) {
                    $query->whereHas('employee.offices', function (Builder $query) {
                        $query->where('offices.id', Auth::user()->employee?->currentDeployment?->office?->id);

                        $query->where('deployment.supervisor_id', Auth::user()->employee?->id);

                        $query->where('deployment.current', true);
                    });

                    $query->where(
                        'timesheets.details->signers->leader',
                        Employee::select('id')->whereHas('user', fn ($q) => $q->where('id', Auth::id())),
                    );
                });
            });

            $query->when(in_array($panel, ['director', 'leader']), function ($query) use ($panel) {
                $query->orWhereHas('export', function (Builder $query) use ($panel) {
                    $query->whereHas('signers', function (Builder $query) use ($panel) {
                        $query
                            ->where('meta', $panel)
                            ->where('signer_id', Auth::id())
                            ->where('signer_type', User::class);
                    });
                });
            });
        });

        return $query;
    }
}
