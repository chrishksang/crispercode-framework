<?php

namespace CrisperCode\Utils;

/**
 * Trait for datetime operations.
 * @phpstan-ignore trait.unused
 */
trait DatetimeTrait
{
    public function convertSecToTime(int $sec): string
    {
        $date1 = new \DateTime("@0"); //starting seconds
        $date2 = new \DateTime("@$sec"); // ending seconds
        $interval = date_diff($date1, $date2); //the time difference
        $str = $interval->format('%y years,%m months,%d days,%h hours,%i minutes,%s seconds');
        $arr = explode(',', $str);
        return implode(', ', array_filter($arr, function ($item) {
            return !str_starts_with($item, '0');
        }));
    }

    public function compareDateStrings(string $datetime1, string $datetime2): int
    {
        $timestamp1 = strtotime($datetime1);
        $timestamp2 = strtotime($datetime2);
        if ($timestamp1 < $timestamp2) {
            return -1;
        } elseif ($timestamp1 > $timestamp2) {
            return 1;
        }
        return 0;
    }

    public function isDST(string $datetime, string $timezone = 'Europe/London'): bool
    {
        $date = new \DateTime($datetime, new \DateTimeZone($timezone));
        return (bool) $date->format('I');
    }
}
