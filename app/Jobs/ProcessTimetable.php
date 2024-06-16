<?php

namespace App\Jobs;

use App\Models\Employee;
use App\Models\Schedule;
use App\Models\Suspension;
use App\Models\Timetable;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

class ProcessTimetable implements ShouldBeEncrypted, ShouldBeUnique, ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(
        private readonly Employee $employee,
        private readonly Carbon $date,
    ) {
        $this->queue = 'main';
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $schedule = Schedule::search($this->date, $this->employee);

        $sheet = $this->employee->timesheets()->firstOrCreate(['month' => $this->date->clone()->startOfMonth()]);

        $timetable = Timetable::firstOrCreate(['date' => $this->date, 'timesheet_id' => $sheet->id], ['punch' => []]);

        switch ($schedule?->arrangement) {
            case 'standard-work-hour':
                $this->standard($schedule, $timetable);
                break;
            case 'work-shifting':
                $this->shift($schedule, $timetable);
                break;
            default:
                $this->fallback($timetable);
        }
    }

    /**
     * Get the unique ID for the job.
     */
    public function uniqueId(): string
    {
        return $this->employee->id.'-'.$this->date->format('Y-m-d');
    }

    protected function fallback(Timetable $timetable): void
    {
        $timelogs = $this->employee->timelogs()
            ->whereDate('time', $this->date)
            ->with('scanner')
            ->get();

        $holiday = Suspension::search($this->date);

        if ($timelogs->isEmpty()) {
            $timetable->update([
                'holiday' => $holiday->map->name->join(', ') ?: null,
                'absent' => $absent = $holiday->isEmpty() && $this->date->isWeekday(),
                'regular' => $absent,
            ]);

            return;
        }

        $roster = [];

        foreach (['p1', 'p2', 'p3', 'p4'] as $state) {
            $timelists = $timelogs->reject(fn ($punch) => in_array($punch->id, array_column($roster, 'id')));

            $timelists = match ($state) {
                'p1', 'p3' => $timelists->reject(fn ($timelog) => in_array($timelog->id, array_column($roster, 'id')))->filter->in,
                'p2', 'p4' => $timelists->reject(fn ($timelog) => in_array($timelog->id, array_column($roster, 'id')))->filter->out,
                default => collect(),
            };

            $timelists = match ($state) {
                'p1' => $timelists->filter(fn ($punch) => $punch->time->lt($this->date->clone->setTime(11, 0, 0))),
                'p2' => $timelists->filter(fn ($punch) => $punch->time->lt($this->date->clone->setTime(13, 0, 0))),
                'p3' => $timelists->filter(fn ($punch) => $punch->time->gte($this->date->clone->setTime(11, 0, 0))),
                'p4' => $timelists->filter(fn ($punch) => $punch->time->gte($this->date->clone->setTime(13, 0, 0))),
            };

            $timelists = match ($state) {
                'p1', 'p2' => $timelists->sortBy(fn ($punch) => $punch->time->clone()->subYears((int) $punch->scanner->priority)),
                'p3', 'p4' => $timelists->sortByDesc(fn ($punch) => $punch->time->clone()->addYears((int) $punch->scanner->priority)),
            };

            $punched = $timelists->reject(fn ($timelog) => ($log = end($roster)) ? $timelog->time->gte($log['time']) : $log)->first();

            if (is_null($punched)) {
                continue;
            }

            $roster[$state] = [
                'id' => $punched->id,
                'time' => $punched->time->format('H:i:s'),
                'foreground' => $punched->scanner?->foreground_color,
                'background' => $punched->scanner?->background_color,
            ];
        }

        $timetable->update(['punch' => $roster]);
    }

    protected function standard(Schedule $schedule, Timetable $timetable): void
    {
        $timelogs = $this->employee->timelogs()
            ->whereDate('time', $this->date)
            ->with('scanner')
            ->get();

        $holiday = Suspension::search($this->date);

        if ($timelogs->isEmpty()) {
            $timetable->update([
                'holiday' => $holiday->map->name->join(', ') ?: null,
                'absent' => $absent = $holiday->isEmpty() && $this->date->isWeekday(),
                'regular' => $absent,
            ]);

            return;
        }

        $roster = [];

        foreach ($punches = ['p1', 'p2', 'p3', 'p4'] as $state) {
            $punchTime = $this->date->clone()->setTime(...explode(':', $schedule->timetable[$state]));

            $timelists = $timelogs->reject(fn ($punch) => in_array($punch->id, array_column($roster, 'id')))
                ->filter(fn ($punch) => ((int) $punchTime->diffInMinutes($punch->time)) <= ($schedule->threshold[$state]['max'] ?? INF))
                ->filter(fn ($punch) => ((int) $punch->time->diffInMinutes($punchTime)) <= ($schedule->threshold[$state]['min'] ?? INF));

            $timelists = match ($state) {
                'p1', 'p3' => $timelists->filter(fn ($punch) => $punch->in),
                'p2', 'p4' => $timelists->filter(fn ($punch) => $punch->out),
            };

            $timelists = match ($state) {
                'p1' => $timelists->sortBy(fn ($punch) => $punch->time->clone()->subYears((int) $punch->scanner->priority)),
                'p2' => $timelists->sortBy(fn ($punch) => $punchTime->diffInSeconds($punch->time->clone()->addYears((int) $punch->scanner->priority))),
                'p3' => $timelists->sortBy(fn ($punch) => $punchTime->diffInSeconds($punch->time->clone()->subYears((int) $punch->scanner->priority))),
                'p4' => $timelists->sortByDesc(fn ($punch) => $punch->time->clone()->addYears((int) $punch->scanner->priority)),
            };

            $punched = $timelists->reject(fn ($punch) => (($log = end($roster))) && $punch->time->format('H:i:s') < ($log['time'] ?? '00:00:00'))->first();

            if (is_null($punched)) {
                $roster[$state] = ['missed' => true];

                continue;
            }

            $roster[$state] = [
                'id' => $punched->id,
                'time' => $punched->time->format('H:i:s'),
                'foreground' => $punched->scanner?->foreground_color,
                'background' => $punched->scanner?->background_color,
            ];

            $roster[$state]['undertime'] = match ($state) {
                'p1', 'p3' => ($diff = (int) $punchTime->diffInMinutes($punched->time)) > ($schedule->threshold[$state]['tardy'] ?? 0) ? $diff : 0,
                'p2', 'p4' => ($diff = (int) $punched->time->setSeconds(0)->diffInMinutes($punchTime)) > 0 ? $diff : 0,
                default => null,
            };
        }

        $shift1 = match (isset($roster['p1']['time']) && isset($roster['p2']['time'])) {
            true => $this->date->clone()->setTime(...explode(':', $schedule->timetable['p1']))
                ->diffInMinutes($this->date->clone()->setTime(...explode(':', $schedule->timetable['p2'])), false),
            false => 0,
        };

        $shift2 = match (isset($roster['p3']['time']) && isset($roster['p4']['time'])) {
            true => $this->date->clone()->setTime(...explode(':', $schedule->timetable['p3']))
                ->diffInMinutes($this->date->clone()->setTime(...explode(':', $schedule->timetable['p4']))),
            false => 0,
        };

        $out = $this->date->clone()->setTime(...explode(':', $schedule->timetable['p4']));

        $check = array_intersect_key(array_flip($punches), collect($roster)->reject(fn ($punch) => isset($punch['missed']))->toArray());

        $regular = $this->date->isWeekday() && $holiday->isEmpty();

        $undertime = array_sum(array_column($roster, 'undertime'));

        $excess = isset($roster['p4']['time']) ? (int) $out->diffInMinutes($out->clone()->setTime(...explode(':', $roster['p4']['time']))) : 0;

        $total = ($shift1 ? $shift1 - $roster['p1']['undertime'] - $roster['p2']['undertime'] : 0) +
            ($shift2 ? $shift2 - $roster['p3']['undertime'] - $roster['p4']['undertime'] : 0);

        if ($regular) {
            $overtime = $excess >= ($schedule->threshold['overtime'] ?? 0) ? $excess : 0;
        } else {
            $overtime = $shift1 + $shift2 - $undertime;
        }

        $timetable->update([
            'punch' => $roster,
            'half' => $half = $check == ['p1' => 0, 'p2' => 1] || $check == ['p3' => 2, 'p4' => 3],
            'regular' => $regular,
            'invalid' => ! $half && $check !== array_flip($punches),
            'undertime' => array_sum(array_column($roster, 'undertime')),
            'overtime' => max($overtime, 0),
            'duration' => $total,
            'holiday' => $holiday->map->name->join(', ') ?: null,
            'present' => true,
        ]);
    }

    protected function shift(Schedule $schedule, Timetable $timetable)
    {
        $p1 = $schedule->pivot->timetable['p1']['time'];

        $p2 = $schedule->pivot->timetable['p2']['time'];

        $timelogs = $this->employee->timelogs()
            ->whereBetween('time', [
                $this->date->clone()->setTime(...explode(':', $p1))->subMinutes((int) $schedule->threshold['p1']['min']),
                $this->date->clone()->addDays($p1 > $p2 ? 1 : 0)->setTime(...explode(':', $p2))->addMinutes((int) $schedule->threshold['p2']['max']),
            ])
            ->with('scanner')
            ->get();

        $holiday = Suspension::search($this->date);

        if ($timelogs->isEmpty()) {
            $timetable->update([
                'holiday' => $holiday->map->name->join(', ') ?: null,
                'absent' => $absent = $holiday->isEmpty() && $this->date->isWeekday(),
                'regular' => $absent,
            ]);

            return;
        }

        $roster = [];

        foreach (['p1', 'p2'] as $state) {
            $punchTime = $this->date->clone()->addDays($state === 'p2' && $p1 > $p2 ? 1 : 0)->setTime(...explode(':', $schedule->pivot->timetable[$state]['time']));

            $timelists = $timelogs->reject(fn ($punch) => in_array($punch->id, array_column($roster, 'id')))
                ->filter(fn ($punch) => ((int) $punchTime->diffInMinutes($punch->time)) <= ($schedule->threshold[$state]['max'] ?? INF))
                ->filter(fn ($punch) => ((int) $punch->time->diffInMinutes($punchTime)) <= ($schedule->threshold[$state]['min'] ?? INF));

            $timelists = match ($state) {
                'p1' => $timelists->filter(fn ($punch) => $punch->in)
                    ->sortBy(fn ($punch) => $punch->time->clone()->subYears((int) $punch->scanner->priority)),
                'p2' => $timelists->filter(fn ($punch) => $punch->out)
                    ->sortByDesc(fn ($punch) => $punchTime->diffInSeconds($punch->time->clone()->addYears((int) $punch->scanner->priority))),
            };

            $punched = $timelists->reject(fn ($punch) => ($log = end($roster)) ? $punch->time->format('Y-m-d H:i:s') < ($log['time'] ?? '0000-00-00 00:00:00') : false)->first();

            if (is_null($punched)) {
                $roster[$schedule->pivot->timetable[$state]['alias']] = ['missed' => true];

                continue;
            }

            $roster[$schedule->pivot->timetable[$state]['alias']] = [
                'id' => $punched->id,
                'time' => $punched->time->format('Y-m-d H:i:s'),
                'foreground' => $punched->scanner?->foreground_color,
                'background' => $punched->scanner?->background_color,
            ];

            if ($state === 'p1' && $punched->time->clone()->addDay()->isSameDay($this->date)) {
                $roster[$schedule->pivot->timetable[$state]['alias']]['previous'] = 1;
            }

            if ($state === 'p2' && $punched->time->clone()->subDay()->isSameDay($this->date)) {
                $roster[$schedule->pivot->timetable[$state]['alias']]['next'] = 1;
            }

            $roster[$schedule->pivot->timetable[$state]['alias']]['undertime'] = match ($state) {
                'p1' => ($diff = (int) $punchTime->diffInMinutes($punched->time)) > ($schedule->threshold[$state]['tardy'] ?? 0) ? $diff : 0,
                'p2' => ($diff = (int) $punched->time->diffInMinutes($punchTime)) > 0 ? $diff : 0,
                default => null,
            };
        }

        $roster = collect($roster)->map(function ($punch) {
            if (isset($punch['time'])) {
                $punch['time'] = explode(' ', $punch['time'])[1];
            }

            return $punch;
        })->toArray();

        $check = array_intersect_key(array_flip(array_column($schedule->pivot->timetable, 'alias')), $roster);

        $regular = $this->date->isWeekday() && $holiday->isEmpty();

        $out = $this->date->clone()->setTime(...explode(':', $p2));

        $undertime = array_sum(array_column($roster, 'undertime'));

        $excess = isset($roster['p2']['time']) ? (int) $out->diffInMinutes($out->clone()->setTime(...explode(':', $roster['p2']['time']))) : 0;

        if ($regular) {
            $overtime = $excess > ($schedule->threshold['overtime'] ?? INF) ? $excess : 0;
        } elseif (isset($roster['p1']['time']) && isset($roster['p2']['time'])) {
            $overtime = $this->date->clone()->setTime(...explode(':', $p1))
                ->diffInMinutes($this->date->clone()->setTime(...explode(':', $p2)), false) -
                    $undertime;
        } else {
            $overtime = 0;
        }

        $timetable->update([
            'punch' => $roster,
            'half' => false,
            'invalid' => $check !== array_flip(array_column($schedule->pivot->timetable, 'alias')),
            'undertime' => $undertime,
            'regular' => $regular,
            'overtime' => max($overtime, 0),
            'holiday' => $holiday->map->name->join(', ') ?: null,
            'present' => true,
        ]);
    }
}
