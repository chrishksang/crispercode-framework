<?php

declare(strict_types=1);

namespace Tests\CrisperCode\Fixtures;

use CrisperCode\Attribute\Column;
use CrisperCode\Attribute\Index;
use CrisperCode\Entity\EntityBase;

/**
 * Test entity for integration tests.
 */
#[Index(columns: ['email'], unique: true)]
class TestUser extends EntityBase
{
    public const TABLE_NAME = 'test_users';

    #[Column(type: 'VARCHAR', length: 255)]
    public string $email;

    #[Column(type: 'VARCHAR', length: 100)]
    public string $name;

    #[Column(type: 'INT', nullable: true)]
    public ?int $age = null;

    #[Column(type: 'DATETIME')]
    public string $createdAt;
}
