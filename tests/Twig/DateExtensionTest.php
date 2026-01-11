<?php

namespace Tests\CrisperCode\Twig;

use CrisperCode\Twig\DateExtension;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\TwigFilter;
use Symfony\Contracts\Translation\TranslatorInterface;

class DateExtensionTest extends TestCase
{
    private DateExtension $extension;
    private Environment $env;

    protected function setUp(): void
    {
        $this->extension = new DateExtension();
        $this->env = new Environment(new ArrayLoader());
    }

    public function testGetFiltersReturnsTimeDiff(): void
    {
        $filters = $this->extension->getFilters();

        $this->assertCount(1, $filters);
        $this->assertInstanceOf(TwigFilter::class, $filters[0]);
        $this->assertEquals('time_diff', $filters[0]->getName());
    }

    public function testDiffReturnsSecondsAgo(): void
    {
        $now = new \DateTime('2023-01-01 12:00:10');
        $past = new \DateTime('2023-01-01 12:00:00');

        $result = $this->extension->diff($this->env, $past, $now);
        $this->assertEquals('10 seconds ago', $result);
    }

    public function testDiffReturnsMinutesAgo(): void
    {
        $now = new \DateTime('2023-01-01 12:10:00');
        $past = new \DateTime('2023-01-01 12:00:00');

        $result = $this->extension->diff($this->env, $past, $now);
        $this->assertEquals('10 minutes ago', $result);
    }

    public function testDiffReturnsInSeconds(): void
    {
        $now = new \DateTime('2023-01-01 12:00:00');
        $future = new \DateTime('2023-01-01 12:00:05');

        $result = $this->extension->diff($this->env, $future, $now);
        $this->assertEquals('in 5 seconds', $result);
    }

    public function testDiffReturnsJustNowForZeroDifference(): void
    {
        $now = new \DateTime('2023-01-01 12:00:00');
        $past = new \DateTime('2023-01-01 12:00:00');

        // Based on the review, we should probably handle this better than returning empty string.
        // I'll expect "just now" for now and update the code to match.
        // Or "0 seconds ago". "0 seconds ago" is consistent with pluralization logic if I allow 0 count.
        // But the loop skips 0 count.

        $result = $this->extension->diff($this->env, $past, $now);
        $this->assertEquals('just now', $result);
    }

    public function testDiffWithTranslator(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects($this->once())
            ->method('trans')
            ->with('diff.ago.minute', ['%count%' => 5], 'date')
            ->willReturn('il y a 5 minutes');

        $extension = new DateExtension($translator);

        $now = new \DateTime('2023-01-01 12:05:00');
        $past = new \DateTime('2023-01-01 12:00:00');

        $result = $extension->diff($this->env, $past, $now);
        $this->assertEquals('il y a 5 minutes', $result);
    }

    public function testDiffWithStrings(): void
    {
        $now = '2023-01-01 12:00:10';
        $past = '2023-01-01 12:00:00';

        $result = $this->extension->diff($this->env, $past, $now);
        $this->assertEquals('10 seconds ago', $result);
    }
}
