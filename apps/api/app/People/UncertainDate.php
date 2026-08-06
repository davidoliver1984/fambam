<?php

namespace App\People;

use App\Enums\DatePrecision;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

final readonly class UncertainDate
{
    private function __construct(
        public DatePrecision $precision,
        public ?CarbonImmutable $date,
    ) {}

    /** @param array{precision: string, value?: string|null} $input */
    public static function fromInput(array $input): self
    {
        $precision = DatePrecision::tryFrom($input['precision'])
            ?? throw new InvalidArgumentException('The date precision is invalid.');
        $value = $input['value'] ?? null;

        if ($precision === DatePrecision::Unknown) {
            if ($value !== null && $value !== '') {
                throw new InvalidArgumentException('An unknown date cannot have a value.');
            }

            return new self($precision, null);
        }

        if (! is_string($value) || $value === '') {
            throw new InvalidArgumentException('A value is required for this date precision.');
        }

        $date = match ($precision) {
            DatePrecision::Exact, DatePrecision::Approximate => self::parse('!Y-m-d', $value),
            DatePrecision::Month => self::parse('!Y-m', $value),
            DatePrecision::Year => self::parse('!Y', $value),
            DatePrecision::Decade => self::parseDecade($value),
        };

        return new self($precision, $date);
    }

    public static function fromStorage(DatePrecision $precision, ?string $date): self
    {
        return new self(
            $precision,
            $date === null ? null : CarbonImmutable::parse($date)->startOfDay(),
        );
    }

    /** @return array{precision: string, value: ?string} */
    public function toPayload(): array
    {
        $value = match ($this->precision) {
            DatePrecision::Exact, DatePrecision::Approximate => $this->date?->format('Y-m-d'),
            DatePrecision::Month => $this->date?->format('Y-m'),
            DatePrecision::Year => $this->date?->format('Y'),
            DatePrecision::Decade => $this->date?->format('Y').'s',
            DatePrecision::Unknown => null,
        };

        return ['precision' => $this->precision->value, 'value' => $value];
    }

    public function storageDate(): ?string
    {
        return $this->date?->format('Y-m-d');
    }

    private static function parse(string $format, string $value): CarbonImmutable
    {
        $date = CarbonImmutable::createFromFormat($format, $value);
        $errors = CarbonImmutable::getLastErrors();

        if (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
            throw new InvalidArgumentException('The date value does not match its precision.');
        }

        if ((int) $date->format('Y') < 1000 || (int) $date->format('Y') > 9999) {
            throw new InvalidArgumentException('The date year must contain four digits.');
        }

        return $date->startOfDay();
    }

    private static function parseDecade(string $value): CarbonImmutable
    {
        if (preg_match('/^(\d{3}0)s$/', $value, $matches) !== 1) {
            throw new InvalidArgumentException('A decade must use a value such as 1980s.');
        }

        return self::parse('!Y', $matches[1]);
    }
}
