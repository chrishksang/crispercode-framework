<?php

declare(strict_types=1);

namespace CrisperCode\Entity;

use CrisperCode\Attribute\Column;
use CrisperCode\Attribute\Index;
use CrisperCode\Attribute\Table;
use MeekroDB;

/**
 * Queue job entity for database-backed queue system.
 *
 * @package CrisperCode\Entity
 */
#[Table(name: 'queue_jobs')]
#[Index(columns: ['queue', 'status', 'available_at', 'priority'], unique: false)]
#[Index(columns: ['status', 'created_at'], unique: false)]
class QueueJob extends EntityBase
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    public const TABLE_NAME = 'queue_jobs';

    #[Column(type: 'varchar', length: 50)]
    public string $queue;

    #[Column(type: 'varchar', length: 255)]
    public string $handler;

    #[Column(type: 'text')]
    public string $payload;

    #[Column(type: "enum('pending','processing','completed','failed')")]
    public string $status = self::STATUS_PENDING;

    #[Column(type: 'int', default: 0)]
    public int $attempts = 0;

    #[Column(type: 'int', default: 3)]
    public int $maxAttempts = 3;

    #[Column(type: 'int', default: 0)]
    public int $priority = 0;

    #[Column(type: 'datetime')]
    public string $availableAt;

    #[Column(type: 'datetime', nullable: true)]
    public ?string $reservedAt = null;

    #[Column(type: 'datetime', nullable: true)]
    public ?string $completedAt = null;

    #[Column(type: 'datetime', nullable: true)]
    public ?string $failedAt = null;

    #[Column(type: 'text', nullable: true)]
    public ?string $error = null;

    #[Column(type: 'datetime')]
    public string $createdAt;

    public function __construct(MeekroDB $db)
    {
        parent::__construct($db);

        $this->createdAt = date('Y-m-d H:i:s');
        $this->availableAt = date('Y-m-d H:i:s');
    }

    public static function getTableName(): string
    {
        return 'queue_jobs';
    }
}
