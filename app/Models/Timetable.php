<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;

class Timetable extends Model
{
    use HasFactory, HasUlids;

    protected $fillable = [
        'date',
        'punch',
        'undertime',
        'overtime',
        'duration',
        'half',
        'absent',
        'present',
        'invalid',
        'holiday',
        'regular',
        'rectified',
        'timesheet_id',
    ];

    protected $casts = [
        'half' => 'bool',
        'absent' => 'bool',
        'present' => 'bool',
        'invalid' => 'bool',
        'regular' => 'bool',
        'date' => 'date:Y-m-d',
        'punch' => 'json',
    ];

    public function undertime(): Attribute
    {
        return Attribute::make(
            fn ($undertime) => $undertime > 0 ? $undertime : null,
        );
    }

    public function overtime(): Attribute
    {
        return Attribute::make(
            fn ($overtime) => $overtime > 0 ? $overtime : null,
        );
    }

    public function duration(): Attribute
    {
        return Attribute::make(
            fn ($duration) => $duration > 0 ? $duration : null,
        );
    }

    public function holiday(): Attribute
    {
        return Attribute::make(
            set: fn ($holiday) => $holiday ?: null,
        );
    }

    public function timesheet(): BelongsTo
    {
        return $this->belongsTo(Timesheet::class);
    }

    public function employee(): HasOneThrough
    {
        return $this->hasOneThrough(Employee::class, Timesheet::class, 'timetables.id', 'id', secondLocalKey: 'employee_id')
            ->join('timetables', 'timesheets.id', 'timetables.timesheet_id');
    }

    public function scopeOvertimeWork(Builder $query): void
    {
        $query->where(function (Builder $query) {
            $query->where('present', true)->where('regular', false);
        });

        $query->orWhere(function (Builder $query) {
            $query->where('overtime', '>', 0)->where('regular', true);
        });
    }

    public function scopeRegularDays(Builder $query): void
    {
        $query->where('regular', 1);
    }

    public function scopeFirstHalf(Builder $query): void
    {
        $query->whereDay('date', '<=', 15);
    }

    public function scopeSecondHalf(Builder $query): void
    {
        $query->whereDay('date', '>=', 16);
    }
}