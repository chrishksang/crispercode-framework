<?php

declare(strict_types=1);

namespace CrisperCode\Service;

use JsonMachine\Exception\InvalidArgumentException;
use JsonMachine\Items;

class JsonStreamReader implements StreamReaderInterface
{
    /**
     * @throws InvalidArgumentException
     */
    public function readFile(string $filePath, array $options = []): iterable
    {
        return Items::fromFile($filePath, $options);
    }
}
