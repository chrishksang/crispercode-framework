<?php

namespace Tests\CrisperCode\Utils;

use CrisperCode\Utils\PerformanceMonitor;
use PHPUnit\Framework\TestCase;

class PerformanceMonitorTest extends TestCase
{
    public function testStart()
    {
        $performanceMonitor = new PerformanceMonitor();
        $performanceMonitor->start();

        $this->assertIsInt($this->getPrivateProperty($performanceMonitor, 'startMemory'));
        $this->assertIsFloat($this->getPrivateProperty($performanceMonitor, 'startTime'));
    }

    public function testEnd()
    {
        $performanceMonitor = new PerformanceMonitor();
        $performanceMonitor->start();
        $performanceMonitor->end();

        $this->assertIsInt($this->getPrivateProperty($performanceMonitor, 'endMemory'));
        $this->assertIsFloat($this->getPrivateProperty($performanceMonitor, 'endTime'));
    }

    public function testGetMemoryUsage()
    {
        $performanceMonitor = new PerformanceMonitor();
        $performanceMonitor->start();
        $performanceMonitor->end();

        $memoryUsage = $performanceMonitor->getMemoryUsage();
        $this->assertMatchesRegularExpression('/^\d+\.\d{2} KB$/', $memoryUsage);
    }

    public function testGetRenderTime()
    {
        $performanceMonitor = new PerformanceMonitor();
        $performanceMonitor->start();
        $performanceMonitor->end();

        $renderTime = $performanceMonitor->getRenderTime();
        $this->assertMatchesRegularExpression('/^\d+\.\d{4} seconds$/', $renderTime);
    }

    private function getPrivateProperty($object, $property)
    {
        $reflection = new \ReflectionClass($object);
        $property = $reflection->getProperty($property);
        $property->setAccessible(true);
        return $property->getValue($object);
    }
}
