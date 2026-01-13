<?php

declare(strict_types=1);

namespace CrisperCode\Enum;

/**
 * Defines the available user roles.
 *
 * Roles are hierarchical:
 * - ROOT: Superadmin with full system access
 * - ADMIN: Administrative user with elevated privileges
 * - USER: Standard authenticated user
 *
 * @package CrisperCode\Enum
 */
enum Roles: string
{
    /**
     * Root/superadmin role with full system access.
     */
    case ROOT = 'root';

    /**
     * Administrative role with elevated privileges.
     */
    case ADMIN = 'admin';

    /**
     * Standard user role (default for new users).
     */
    case USER = 'user';

    /**
     * Get a human-readable label for the role.
     *
     * @return string The role label.
     */
    public function getLabel(): string
    {
        return match ($this) {
            self::ROOT => 'Root',
            self::ADMIN => 'Administrator',
            self::USER => 'User',
        };
    }

    /**
     * Check if this role has elevated privileges (admin or root).
     *
     * @return bool True if the role has admin-level access.
     */
    public function isElevated(): bool
    {
        return $this === self::ROOT || $this === self::ADMIN;
    }

    /**
     * Get all available role values as strings.
     *
     * @return array<string>
     */
    public static function values(): array
    {
        return array_map(fn(self $role) => $role->value, self::cases());
    }

    /**
     * Try to create a Roles instance from a string.
     *
     * @param string $value The role value.
     * @return self|null The role or null if invalid.
     */
    public static function tryFromString(string $value): ?self
    {
        return self::tryFrom(strtolower(trim($value)));
    }
}
