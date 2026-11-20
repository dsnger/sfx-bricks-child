<?php

declare(strict_types=1);

namespace SFX\ContactInfos\Schema;

/**
 * Structured opening hours of one contact: validation and schema.org output.
 * Pure logic; only __() is used, for the admin-notice text.
 */
final class OpeningHours
{
    public const DAYS = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

    private const TIME = '/^([01]\d|2[0-3]):[0-5]\d$/';

    /**
     * @param array $input    day => ['state' => unset|closed|open, 'ranges' => [[from, to], ...]]
     * @param array $previous stored value, same shape as the returned 'hours'
     * @return array{hours: array, errors: list<string>}
     */
    public static function sanitize(array $input, array $previous): array
    {
        $hours  = [];
        $errors = [];
        foreach (self::DAYS as $day) {
            $kept = $previous[$day] ?? ['state' => 'unset', 'ranges' => []];
            if (!isset($input[$day]) || !is_array($input[$day])) {
                $hours[$day] = $kept;
                continue;
            }
            $result = self::sanitize_day($input[$day]);
            if (is_string($result)) {
                $hours[$day] = $kept;
                /* translators: 1: weekday name, 2: what is wrong with that day's opening hours */
                $errors[] = sprintf(__('%1$s: %2$s', 'sfxtheme'), self::day_label($day), $result);
            } else {
                $hours[$day] = $result;
            }
        }
        return ['hours' => $hours, 'errors' => $errors];
    }

    public static function day_label(string $day): string
    {
        $labels = [
            'Monday'    => __('Monday', 'sfxtheme'),
            'Tuesday'   => __('Tuesday', 'sfxtheme'),
            'Wednesday' => __('Wednesday', 'sfxtheme'),
            'Thursday'  => __('Thursday', 'sfxtheme'),
            'Friday'    => __('Friday', 'sfxtheme'),
            'Saturday'  => __('Saturday', 'sfxtheme'),
            'Sunday'    => __('Sunday', 'sfxtheme'),
        ];
        return $labels[$day] ?? $day;
    }

    /** @return list<array<string,string>> */
    public static function to_schema(array $hours): array
    {
        $out = [];
        foreach (self::DAYS as $day) {
            $state = $hours[$day]['state'] ?? 'unset';
            if ($state === 'closed') {
                $ranges = [['00:00', '00:00']];
            } elseif ($state === 'open') {
                $ranges = $hours[$day]['ranges'] ?? [];
            } else {
                continue;
            }
            foreach ($ranges as $range) {
                $out[] = [
                    '@type'     => 'OpeningHoursSpecification',
                    'dayOfWeek' => 'https://schema.org/' . $day,
                    'opens'     => $range[0],
                    'closes'    => $range[1],
                ];
            }
        }
        return $out;
    }

    /** @return array{state:string,ranges:list<array{0:string,1:string}>}|string error text */
    private static function sanitize_day(array $day)
    {
        $state = $day['state'] ?? 'unset';
        if ($state === 'unset' || $state === 'closed') {
            return ['state' => $state, 'ranges' => []];
        }
        if ($state !== 'open') {
            return __('Unknown state.', 'sfxtheme');
        }

        $ranges = [];
        foreach ((array) ($day['ranges'] ?? []) as $range) {
            $from = is_array($range) ? trim((string) ($range[0] ?? '')) : '';
            $to   = is_array($range) ? trim((string) ($range[1] ?? '')) : '';
            if ($from === '' && $to === '') {
                continue; // an untouched second row
            }
            if (!preg_match(self::TIME, $from) || !preg_match(self::TIME, $to)) {
                return __('Enter times as HH:MM.', 'sfxtheme');
            }
            if ($from === $to) {
                return __('Start and end must differ.', 'sfxtheme');
            }
            $ranges[] = [$from, $to];
        }
        if ($ranges === []) {
            return __('Enter at least one time range.', 'sfxtheme');
        }
        if (count($ranges) > 2) {
            return __('At most two time ranges per day.', 'sfxtheme');
        }
        if (count($ranges) === 2) {
            [$a, $b] = array_map([self::class, 'minutes'], $ranges);
            if ($a[0] < $b[1] && $b[0] < $a[1]) {
                return __('The time ranges overlap.', 'sfxtheme');
            }
        }
        return ['state' => 'open', 'ranges' => $ranges];
    }

    /** @return array{0:int,1:int} start and end in minutes; a range crossing midnight ends at 24:00 */
    private static function minutes(array $range): array
    {
        $start = (int) substr($range[0], 0, 2) * 60 + (int) substr($range[0], 3);
        $end   = (int) substr($range[1], 0, 2) * 60 + (int) substr($range[1], 3);
        return [$start, $end < $start ? 1440 : $end];
    }
}
