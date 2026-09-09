<?php

namespace App\Queries;

use App\Enums\DatePrecision;
use App\Models\Photo;
use App\Models\User;
use App\People\UncertainDate;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

class DateMemoryQuery
{
    public function __construct(private readonly PhotoQuery $photos) {}

    /** @return list<array<string, mixed>> */
    public function forDate(User $viewer, CarbonImmutable $today, int $limit = 12): array
    {
        return $this->photos->visibleTo($viewer)
            ->where('do_not_resurface', false)
            ->whereNotNull('historical_date')
            ->whereNotNull('historical_date_precision')
            ->where('historical_date_precision', '!=', DatePrecision::Unknown->value)
            ->where(function (Builder $interval): void {
                $interval->whereNull('historical_date_window_end')
                    ->orWhereColumn('historical_date_window_end', '>=', 'historical_date');
            })
            ->where(function (Builder $eligible) use ($today): void {
                $eligible->where(function (Builder $anchored) use ($today): void {
                    $anchored->whereIn('historical_date_precision', [
                        DatePrecision::Exact->value,
                        DatePrecision::Approximate->value,
                    ])->whereMonth('historical_date', $today->month)
                        ->whereDay('historical_date', $today->day);
                })->orWhere(function (Builder $month) use ($today): void {
                    $month->where('historical_date_precision', DatePrecision::Month->value)
                        ->whereMonth('historical_date', $today->month);
                })->orWhereIn('historical_date_precision', [
                    DatePrecision::Year->value,
                    DatePrecision::Decade->value,
                ]);
            })
            ->orderByRaw("CASE historical_date_precision WHEN 'exact' THEN 0 WHEN 'month' THEN 1 WHEN 'approximate' THEN 2 WHEN 'year' THEN 3 WHEN 'decade' THEN 4 ELSE 5 END")
            ->orderByDesc('historical_date')
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->map(fn (Photo $photo): array => $this->payload($photo))
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    private function payload(Photo $photo): array
    {
        $date = $photo->historical_date;
        $precision = $photo->historical_date_precision;
        if ($date === null || $precision === null) {
            throw new \LogicException('A date memory must have a historical date and precision.');
        }

        return [
            'photo_id' => $photo->id,
            'media_upload_id' => $photo->media_upload_id,
            'label' => $photo->caption ?? $photo->mediaUpload->client_filename,
            'reason' => $this->reason($date, $precision),
            'historical_date' => UncertainDate::fromStorage(
                $precision,
                $date->format('Y-m-d'),
            )->toPayload(),
            'added_at' => $photo->created_at?->toAtomString(),
        ];
    }

    private function reason(CarbonImmutable $date, DatePrecision $precision): string
    {
        return match ($precision) {
            DatePrecision::Exact => "On this day in {$date->year}",
            DatePrecision::Month => 'Sometime in '.$date->format('F Y'),
            DatePrecision::Year => "In {$date->year}",
            DatePrecision::Decade => "From the {$date->year}s",
            DatePrecision::Approximate => "Around {$date->year}",
            DatePrecision::Unknown => throw new \LogicException('Unknown dates cannot become date memories.'),
        };
    }
}
