<?php

namespace CrisperCode\Utils;

class PerformanceMonitor
{
    private int $startMemory;

    private float $startTime;

    private int $endMemory;

    private float $endTime;

    public function start(): void
    {
        $this->startMemory = memory_get_usage();
        $this->startTime = microtime(true);
    }

    public function end(): void
    {
        $this->endMemory = memory_get_usage();
        $this->endTime = microtime(true);
    }

    public function getMemoryUsage(): string
    {
        $memoryUsage = $this->endMemory - $this->startMemory;
        return number_format($memoryUsage / 1024, 2) . " KB";
    }

    public function getRenderTime(): string
    {
        $renderTime = $this->endTime - $this->startTime;
        return number_format($renderTime, 4) . " seconds";
    }
}
