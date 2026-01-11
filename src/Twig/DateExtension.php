<?php

namespace CrisperCode\Twig;

use DateTime;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * Extension for date formatting in Twig templates.
 *
 * Provides the `time_diff` filter to display human-readable time differences
 * (e.g., "5 minutes ago", "in 2 days"). Based on the original Twig Date Extension
 * but updated for compatibility with PHP 8.4.
 *
 * @package CrisperCode\Twig
 */
final class DateExtension extends AbstractExtension
{
    /**
     * Map of time units to their names.
     *
     * @var array<string, string>
     */
    public static $units = [
        'y' => 'year',
        'm' => 'month',
        'd' => 'day',
        'h' => 'hour',
        'i' => 'minute',
        's' => 'second',
    ];

    /**
     * Optional translator interface for localized output.
     */
    private ?TranslatorInterface $translator;

    /**
     * DateExtension constructor.
     *
     * @param TranslatorInterface|null $translator Optional translator.
     */
    public function __construct(
        ?TranslatorInterface $translator = null
    ) {
        $this->translator = $translator;
    }

    /**
     * Registers the `time_diff` filter.
     *
     * @return TwigFilter[] List of filters.
     */
    public function getFilters(): array
    {
        return [
            new TwigFilter('time_diff', [$this, 'diff'], ['needs_environment' => true]),
        ];
    }

    /**
     * Filters for converting dates to a "time ago" string.
     *
     * Similar to Facebook and Twitter timestamp formats.
     *
     * @param Environment $env Twig environment instance.
     * @param string|DateTime $date The target date to compare.
     * @param string|DateTime|null $now The reference date (defaults to current time).
     *
     * @return string The human-readable time difference.
     *
     * @example
     * {{ post.created_at|time_diff }} -> "2 hours ago"
     * {{ event.starts_at|time_diff }} -> "in 3 days"
     */
    public function diff(Environment $env, $date, $now = null): string
    {
        // Convert both dates to DateTime instances.
        $date = twig_date_converter($env, $date);
        $now = twig_date_converter($env, $now);

        // Get the difference between the two DateTime objects.
        $diff = $date->diff($now);

        // Check for each interval if it appears in the $diff object.
        foreach (self::$units as $attribute => $unit) {
            $count = $diff->$attribute;

            if (0 !== $count) {
                return $this->getPluralizedInterval($count, (bool) $diff->invert, $unit);
            }
        }

        return $this->getPluralizedInterval(0, (bool) $diff->invert, 'second');
    }

    /**
     * Helper to generate the pluralized string for a time unit.
     *
     * @param int $count The quantity.
     * @param bool $invert Whether the difference is in the future (invert=true) or past.
     * @param string $unit The time unit name (e.g., 'minute').
     *
     * @return string Formatted string (e.g., "5 minutes ago").
     */
    private function getPluralizedInterval(int $count, bool $invert, $unit): string
    {
        if ($this->translator instanceof TranslatorInterface) {
            if ($count === 0) {
                return $this->translator->trans('diff.empty', [], 'date');
            }
            $id = sprintf('diff.%s.%s', $invert ? 'in' : 'ago', $unit);

            return $this->translator->trans($id, ['%count%' => $count], 'date');
        }

        if ($count === 0) {
            return 'just now';
        }

        if (1 !== $count) {
            $unit .= 's';
        }

        return $invert ? "in $count $unit" : "$count $unit ago";
    }
}
