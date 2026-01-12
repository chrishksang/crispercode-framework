<?php

declare(strict_types=1);

namespace CrisperCode\Entity;

use CrisperCode\Attribute\Column;
use CrisperCode\Attribute\Index;

/**
 * UserRole entity for storing user-role assignments.
 *
 * Users can have multiple roles. Each row represents one role assignment.
 *
 * @package CrisperCode\Entity
 */
#[Index(columns: ['user_id', 'role'], name: 'idx_user_role', unique: true)]
#[Index(columns: ['user_id'], name: 'idx_user_id')]
class UserRole extends EntityBase
{
    public const TABLE_NAME = 'user_roles';

    /**
     * The ID of the user this role is assigned to.
     */
    #[Column(type: 'INT')]
    public int $userId;

    /**
     * The role value (should match a Roles enum value).
     *
     * Validated at the application layer, not enforced as a DB enum.
     */
    #[Column(type: 'VARCHAR', length: 50)]
    public string $role;

    /**
     * Timestamp when the role was assigned.
     */
    #[Column(type: 'DATETIME')]
    public string $createdAt;

    /**
     * Sets the creation timestamp if not already set.
     */
    public function setCreatedAtIfNew(): void
    {
        if (!isset($this->createdAt)) {
            $this->createdAt = date('Y-m-d H:i:s');
        }
    }

    /**
     * Persists the entity, automatically managing timestamps.
     */
    public function save(): int
    {
        $this->setCreatedAtIfNew();
        return parent::save();
    }
}
