<?php
declare(strict_types=1);

namespace Erp\Modules\Calendar;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/** Recurrence expansion shared by Calendar display and Appointment availability. */
final class CalendarRecurrence
{
    private const MAX_YEARS = 15;
    private const MAX_OCCURRENCES = 5480;

    /** @return list<int> ISO weekdays, Monday=1 through Sunday=7. */
    public static function weekdays(mixed $raw, int $anchorIsoWeekday, bool $weekly): array
    {
        if (!$weekly) return [];
        if ($raw === null || $raw === '') $raw = [$anchorIsoWeekday];
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) $raw = $decoded;
        }
        if (!is_array($raw) || count($raw) > 7) throw new InvalidArgumentException('Choose one or more valid repeat weekdays.');
        $days = [];
        foreach ($raw as $value) {
            $day = filter_var($value, FILTER_VALIDATE_INT);
            if ($day === false || $day < 1 || $day > 7) throw new InvalidArgumentException('Repeat weekdays must be ISO weekdays from 1 to 7.');
            $days[] = (int)$day;
        }
        $days = array_values(array_unique($days));
        sort($days);
        if (!$days) throw new InvalidArgumentException('Choose at least one weekday for a weekly event.');
        return $days;
    }

    /** Return the inclusive final occurrence date, with Odoo's 15-year safety horizon. */
    public static function untilDate(string $startDate, array $rule): string
    {
        $anchor = self::validDate($startDate, 'Recurrence start date');
        $max = $anchor->modify('+' . self::MAX_YEARS . ' years');
        $endType = (string)($rule['recurrence_end_type'] ?? '');
        if ($endType === 'date') {
            $last = self::validDate((string)($rule['recurrence_until_date'] ?? ''), 'Repeat until date');
            if ($last < $anchor) throw new InvalidArgumentException('Repeat until date must be on or after the event start date.');
            if ($last > $max) throw new InvalidArgumentException('Recurring Calendar events are limited to 15 years.');
            if (count(self::dateSequence($anchor, $rule, $last)) > self::MAX_OCCURRENCES) throw new InvalidArgumentException('A Calendar recurrence cannot exceed 5,480 occurrences.');
            return $last->format('Y-m-d');
        }
        if ($endType === 'forever') return $max->format('Y-m-d');
        if ($endType !== 'count') throw new InvalidArgumentException('Choose a recurrence end rule.');
        $count = filter_var($rule['recurrence_count'] ?? null, FILTER_VALIDATE_INT);
        if ($count === false || $count < 1 || $count > self::MAX_OCCURRENCES) throw new InvalidArgumentException('Repeat count must be between 1 and 5,480.');
        $sequence = self::dateSequence($anchor, $rule, $max);
        if (count($sequence) < $count) throw new InvalidArgumentException('Repeat count exceeds the 15-year recurrence horizon.');
        return $sequence[$count - 1]->format('Y-m-d');
    }

    /** Expand only occurrences that overlap the requested UTC half-open range. */
    public static function occurrences(array $event, string $rangeStartUtc, string $rangeEndUtc, array $exceptions = []): array
    {
        if (empty($event['recurrence_frequency'])) return [];
        $utc = new DateTimeZone('UTC');
        $timezone = self::timezone((string)($event['timezone'] ?? ''));
        $baseStartUtc = new DateTimeImmutable((string)$event['starts_at_utc'], $utc);
        $baseEndUtc = new DateTimeImmutable((string)$event['ends_at_utc'], $utc);
        $baseStartLocal = $baseStartUtc->setTimezone($timezone);
        $baseDate = new DateTimeImmutable($baseStartLocal->format('Y-m-d'), $timezone);
        $rule = self::ruleFromEvent($event);
        $lastDate = self::validDate((string)($event['recurrence_until_date'] ?? ''), 'Recurrence end date');
        $dates = self::dateSequence($baseDate, $rule, $lastDate);
        if (($rule['recurrence_end_type'] ?? '') === 'count') $dates = array_slice($dates, 0, (int)$rule['recurrence_count']);
        $from = new DateTimeImmutable($rangeStartUtc, $utc);
        $until = new DateTimeImmutable($rangeEndUtc, $utc);
        if ($until <= $from) return [];
        $allDay = filter_var($event['all_day'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $durationSeconds = $baseEndUtc->getTimestamp() - $baseStartUtc->getTimestamp();
        $baseEndLocal = $baseEndUtc->setTimezone($timezone);
        $baseEndDate = new DateTimeImmutable($baseEndLocal->format('Y-m-d'), $timezone);
        $allDayLength = max(1, (int)$baseDate->diff($baseEndDate)->format('%a'));
        $time = $baseStartLocal->format('H:i');
        $results = [];
        foreach ($dates as $index => $occurrenceDate) {
            if ($allDay) {
                $startLocal = new DateTimeImmutable($occurrenceDate->format('Y-m-d') . ' 00:00:00', $timezone);
                $endLocal = $startLocal->modify('+' . $allDayLength . ' days');
                $startUtc = $startLocal->setTimezone($utc);
                $endUtc = $endLocal->setTimezone($utc);
            } else {
                $localValue = $occurrenceDate->format('Y-m-d') . ' ' . $time;
                $startLocal = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $localValue, $timezone);
                if (!$startLocal || $startLocal->format('Y-m-d H:i') !== $localValue) continue;
                $startUtc = $startLocal->setTimezone($utc);
                $endUtc = $startUtc->modify('+' . $durationSeconds . ' seconds');
            }
            if ($startUtc >= $until || $endUtc <= $from) continue;
            $results[] = [
                'starts_at_utc' => $startUtc->format('Y-m-d H:i:s'),
                'ends_at_utc' => $endUtc->format('Y-m-d H:i:s'),
                'recurrence_index' => $index + 1,
                'occurrence_start_utc' => $startUtc->format('Y-m-d H:i:s'),
            ];
        }
        $byAnchor = [];
        foreach ($results as $result) $byAnchor[$result['occurrence_start_utc']] = $result;
        $parentStatus = (string)($event['status'] ?? 'scheduled');
        foreach ($byAnchor as &$occurrence) $occurrence['status'] = $parentStatus;
        unset($occurrence);
        foreach ($exceptions as $exception) {
            $anchor = (string)($exception['occurrence_start_utc'] ?? '');
            if ($anchor === '') continue;
            if (!isset($byAnchor[$anchor])) {
                $anchorInstant = new DateTimeImmutable($anchor, $utc);
                $single = self::occurrences($event, $anchorInstant->format('Y-m-d H:i:s'), $anchorInstant->modify('+1 second')->format('Y-m-d H:i:s'));
                foreach ($single as $candidate) {
                    if ($candidate['occurrence_start_utc'] === $anchor) {
                        $byAnchor[$anchor] = $candidate;
                        break;
                    }
                }
            }
            if (!isset($byAnchor[$anchor])) continue;
            $byAnchor[$anchor]['status'] = $parentStatus === 'scheduled' ? (string)($exception['status'] ?? 'scheduled') : $parentStatus;
            $byAnchor[$anchor]['is_occurrence_exception'] = true;
            if (filter_var($exception['has_overrides'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                foreach (['title','description','starts_at_utc','ends_at_utc','timezone','location','organizer_user_id','organizer_name','contact_id','contact_name'] as $field) {
                    $byAnchor[$anchor][$field] = $exception[$field] ?? null;
                }
                $byAnchor[$anchor]['all_day'] = filter_var($exception['all_day'] ?? false, FILTER_VALIDATE_BOOLEAN);
                $byAnchor[$anchor]['resource_ids'] = array_map('intval', $exception['resource_ids'] ?? []);
                $byAnchor[$anchor]['resource_names'] = (string)($exception['resource_names'] ?? '');
            }
            if (filter_var($exception['has_overrides'] ?? false, FILTER_VALIDATE_BOOLEAN) || filter_var($exception['has_attendee_overrides'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                $byAnchor[$anchor]['attendee_user_ids'] = array_map('intval', $exception['attendee_user_ids'] ?? []);
                $byAnchor[$anchor]['attendee_contact_ids'] = array_map('intval', $exception['attendee_contact_ids'] ?? []);
                $byAnchor[$anchor]['attendee_user_responses'] = $exception['attendee_user_responses'] ?? [];
                $byAnchor[$anchor]['user_attendee_count'] = count($byAnchor[$anchor]['attendee_user_ids']);
                $byAnchor[$anchor]['contact_attendee_count'] = count($byAnchor[$anchor]['attendee_contact_ids']);
                $byAnchor[$anchor]['my_response_status'] = $exception['my_response_status'] ?? null;
            }
        }
        foreach ($byAnchor as $key => $result) {
            $start = new DateTimeImmutable($result['starts_at_utc'], $utc);
            $end = new DateTimeImmutable($result['ends_at_utc'], $utc);
            if ($start >= $until || $end <= $from) unset($byAnchor[$key]);
        }
        $results = array_values($byAnchor);
        usort($results, static fn(array $a, array $b): int => [$a['starts_at_utc'], $a['occurrence_start_utc']] <=> [$b['starts_at_utc'], $b['occurrence_start_utc']]);
        return $results;
    }

    /** Return whether a UTC instant is the canonical start of a generated occurrence. */
    public static function isOccurrence(array $event, string $occurrenceStartUtc): bool
    {
        $utc = new DateTimeZone('UTC');
        try {
            $start = new DateTimeImmutable($occurrenceStartUtc, $utc);
            if ($start->format('Y-m-d H:i:s') !== $occurrenceStartUtc) return false;
            $end = $start->modify('+1 second');
            foreach (self::occurrences($event, $start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s')) as $occurrence) {
                if ($occurrence['occurrence_start_utc'] === $occurrenceStartUtc) return true;
            }
        } catch (\Throwable) {
            return false;
        }
        return false;
    }

    private static function ruleFromEvent(array $event): array
    {
        return [
            'recurrence_frequency' => $event['recurrence_frequency'] ?? null,
            'recurrence_interval' => (int)($event['recurrence_interval'] ?? 1),
            'recurrence_weekdays' => $event['recurrence_weekdays'] ?? $event['recurrence_weekdays_json'] ?? [],
            'recurrence_month_by' => $event['recurrence_month_by'] ?? null,
            'recurrence_day' => isset($event['recurrence_day']) ? (int)$event['recurrence_day'] : null,
            'recurrence_weekday' => isset($event['recurrence_weekday']) ? (int)$event['recurrence_weekday'] : null,
            'recurrence_week_ordinal' => isset($event['recurrence_week_ordinal']) ? (int)$event['recurrence_week_ordinal'] : null,
            'recurrence_end_type' => $event['recurrence_end_type'] ?? null,
            'recurrence_count' => isset($event['recurrence_count']) ? (int)$event['recurrence_count'] : null,
            'recurrence_until_date' => $event['recurrence_until_date'] ?? null,
        ];
    }

    /** @return list<DateTimeImmutable> */
    private static function dateSequence(DateTimeImmutable $anchor, array $rule, DateTimeImmutable $last): array
    {
        $frequency = (string)($rule['recurrence_frequency'] ?? '');
        $interval = filter_var($rule['recurrence_interval'] ?? 1, FILTER_VALIDATE_INT);
        if (!in_array($frequency, ['daily', 'weekly', 'monthly', 'yearly'], true)) throw new InvalidArgumentException('Choose a valid Calendar repeat period.');
        if ($interval === false || $interval < 1 || $interval > 99) throw new InvalidArgumentException('Repeat interval must be between 1 and 99.');
        $anchor = $anchor->setTime(0, 0);
        $last = $last->setTimezone($anchor->getTimezone())->setTime(0, 0);
        if ($last < $anchor) return [];
        $sequence = [$anchor];
        if ($frequency === 'daily') {
            for ($index = 1; $index < self::MAX_OCCURRENCES; $index++) {
                $candidate = $anchor->modify('+' . ($index * $interval) . ' days');
                if ($candidate > $last) break;
                $sequence[] = $candidate;
            }
            return $sequence;
        }
        if ($frequency === 'weekly') {
            $days = self::weekdays($rule['recurrence_weekdays'] ?? null, (int)$anchor->format('N'), true);
            $anchorMonday = $anchor->modify('-' . ((int)$anchor->format('N') - 1) . ' days');
            for ($candidate = $anchor->modify('+1 day'); $candidate <= $last; $candidate = $candidate->modify('+1 day')) {
                $monday = $candidate->modify('-' . ((int)$candidate->format('N') - 1) . ' days');
                $weeks = intdiv((int)$anchorMonday->diff($monday)->format('%a'), 7);
                if ($weeks % $interval === 0 && in_array((int)$candidate->format('N'), $days, true)) {
                    $sequence[] = $candidate;
                    if (count($sequence) >= self::MAX_OCCURRENCES) break;
                }
            }
            return $sequence;
        }
        if ($frequency === 'monthly') {
            $monthBy = (string)($rule['recurrence_month_by'] ?? '');
            if ($monthBy === 'day') {
                $day = filter_var($rule['recurrence_day'] ?? null, FILTER_VALIDATE_INT);
                if ($day === false || $day < 1 || $day > 31) throw new InvalidArgumentException('Day of month must be from 1 to 31.');
            } elseif ($monthBy === 'weekday') {
                $weekday = filter_var($rule['recurrence_weekday'] ?? null, FILTER_VALIDATE_INT);
                $ordinal = filter_var($rule['recurrence_week_ordinal'] ?? null, FILTER_VALIDATE_INT);
                if ($weekday === false || $weekday < 1 || $weekday > 7 || $ordinal === false || !in_array($ordinal, [-1, 1, 2, 3, 4], true)) throw new InvalidArgumentException('Choose a valid weekday position for the monthly repeat.');
            } else {
                throw new InvalidArgumentException('Choose a monthly recurrence pattern.');
            }
            for ($monthIndex = 1; $monthIndex < self::MAX_OCCURRENCES; $monthIndex++) {
                $first = $anchor->modify('first day of this month')->modify('+' . ($monthIndex * $interval) . ' months');
                $candidate = $monthBy === 'weekday'
                    ? self::weekdayOfMonth($first, (int)$rule['recurrence_weekday'], (int)$rule['recurrence_week_ordinal'])
                    : self::dayOfMonth($first, (int)$rule['recurrence_day']);
                if ($candidate > $last) break;
                $sequence[] = $candidate;
                if (count($sequence) >= self::MAX_OCCURRENCES) break;
            }
            return $sequence;
        }
        for ($yearIndex = 1; $yearIndex <= self::MAX_YEARS; $yearIndex++) {
            $year = (int)$anchor->format('Y') + ($yearIndex * $interval);
            $candidate = self::dayOfMonth(new DateTimeImmutable(sprintf('%04d-%02d-01', $year, (int)$anchor->format('m')), $anchor->getTimezone()), (int)$anchor->format('j'));
            if ($candidate > $last) break;
            $sequence[] = $candidate;
        }
        return $sequence;
    }

    private static function dayOfMonth(DateTimeImmutable $month, int $day): DateTimeImmutable
    {
        if ($day < 1 || $day > 31) throw new InvalidArgumentException('Day of month must be from 1 to 31.');
        return $month->setDate((int)$month->format('Y'), (int)$month->format('m'), min($day, (int)$month->format('t')));
    }

    private static function weekdayOfMonth(DateTimeImmutable $month, int $weekday, int $ordinal): DateTimeImmutable
    {
        if ($weekday < 1 || $weekday > 7 || !in_array($ordinal, [-1, 1, 2, 3, 4], true)) throw new InvalidArgumentException('Choose a valid weekday position for the monthly repeat.');
        $first = $month->modify('first day of this month');
        if ($ordinal === -1) {
            $last = $month->modify('last day of this month');
            return $last->modify('-' . (((int)$last->format('N') - $weekday + 7) % 7) . ' days');
        }
        return $first->modify('+' . ((($weekday - (int)$first->format('N') + 7) % 7) + (($ordinal - 1) * 7)) . ' days');
    }

    private static function validDate(string $value, string $label): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();
        if (!$date || ($errors && ($errors['warning_count'] || $errors['error_count'])) || $date->format('Y-m-d') !== $value) throw new InvalidArgumentException($label . ' must be a valid YYYY-MM-DD date.');
        return $date;
    }

    private static function timezone(string $name): DateTimeZone
    {
        try { return new DateTimeZone($name); } catch (\Throwable) { throw new InvalidArgumentException('Choose a valid IANA time zone for recurrence.'); }
    }
}
