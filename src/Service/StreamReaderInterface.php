<?php

declare(strict_types=1);

namespace CrisperCode\Service;

interface StreamReaderInterface
{
    public function readFile(string $filePath, array $options = []): iterable;
}
