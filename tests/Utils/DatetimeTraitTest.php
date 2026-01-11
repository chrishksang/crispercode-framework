<?php

namespace Tests\CrisperCode\Utils;

use CrisperCode\Utils\DatetimeTrait;
use PHPUnit\Framework\TestCase;

class DatetimeTraitTest extends TestCase
{
    use DatetimeTrait;

    /**
     * @dataProvider bstDatesProvider
     */
    public function testIsDSTReturnsTrueForBSTDates(string $datetime): void
    {
        $this->assertTrue($this->isDST($datetime), "Expected $datetime to be in BST (DST)");
    }

    /**
     * @dataProvider nonBstDatesProvider
     */
    public function testIsDSTReturnsFalseForNonBSTDates(string $datetime): void
    {
        $this->assertFalse($this->isDST($datetime), "Expected $datetime to NOT be in BST (DST)");
    }

    /**
     * Provides dates that should be within British Summer Time (BST).
     * BST runs from the last Sunday of March to the last Sunday of October.
     */
    public static function bstDatesProvider(): array
    {
        return [
            // 2023 BST: March 26 01:00 UTC to October 29 02:00 BST
            ['2023-04-15 10:00:00'],
            ['2023-07-01 12:00:00'],
            ['2023-10-15 09:00:00'],

            // 2024 BST: March 31 01:00 UTC to October 27 02:00 BST
            ['2024-04-01 08:00:00'],
            ['2024-06-21 15:30:00'],
            ['2024-09-30 14:00:00'],

            // 2025 BST: March 30 01:00 UTC to October 26 02:00 BST
            ['2025-05-01 10:00:00'],
            ['2025-08-15 11:00:00'],

            // Edge case: First day of BST (after clock change)
            ['2023-03-26 02:00:00'],
            ['2024-03-31 02:00:00'],
        ];
    }

    /**
     * Provides dates that should NOT be within British Summer Time (non-BST/GMT).
     */
    public static function nonBstDatesProvider(): array
    {
        return [
            // Winter dates (GMT)
            ['2023-01-15 10:00:00'],
            ['2023-02-28 09:00:00'],
            ['2023-12-25 08:00:00'],

            // 2024 Winter
            ['2024-01-01 00:00:00'],
            ['2024-02-14 12:00:00'],
            ['2024-11-15 14:00:00'],
            ['2024-12-31 23:59:59'],

            // Edge case: Day before BST starts
            ['2023-03-25 12:00:00'],
            ['2024-03-30 12:00:00'],

            // Edge case: After BST ends
            ['2023-10-29 02:00:00'],
            ['2024-10-27 02:00:00'],
        ];
    }

    public function testCompareDateStringsLessThan(): void
    {
        $result = $this->compareDateStrings('2023-01-01 00:00:00', '2023-01-02 00:00:00');
        $this->assertEquals(-1, $result);
    }

    public function testCompareDateStringsGreaterThan(): void
    {
        $result = $this->compareDateStrings('2023-01-02 00:00:00', '2023-01-01 00:00:00');
        $this->assertEquals(1, $result);
    }

    public function testCompareDateStringsEqual(): void
    {
        $result = $this->compareDateStrings('2023-01-01 00:00:00', '2023-01-01 00:00:00');
        $this->assertEquals(0, $result);
    }

    public function testConvertSecToTime(): void
    {
        // 1 day, 2 hours, 30 minutes, 45 seconds = 95445 seconds
        $seconds = (24 * 60 * 60) + (2 * 60 * 60) + (30 * 60) + 45;
        $result = $this->convertSecToTime($seconds);
        $this->assertEquals('1 days, 2 hours, 30 minutes, 45 seconds', $result);
    }

    public function testConvertSecToTimeWithZeroComponents(): void
    {
        // 5 minutes = 300 seconds
        $result = $this->convertSecToTime(300);
        $this->assertEquals('5 minutes', $result);
    }
}
