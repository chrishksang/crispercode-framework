<?php

declare(strict_types=1);

namespace CrisperCode\EntityManager;

use CrisperCode\Attribute\EntityManagerAttribute;
use CrisperCode\Entity\UserRole;
use CrisperCode\Enum\Roles;

/**
 * Manager for user role assignments.
 *
 * Provides methods to query, assign, and remove roles for users.
 * Users can have multiple roles simultaneously.
 *
 * @extends EntityManagerBase<UserRole>
 * @package CrisperCode\EntityManager
 */
#[EntityManagerAttribute(entityClass: UserRole::class)]
class UserRoleManager extends EntityManagerBase implements EntityManagerInterface
{
    /**
     * Loads all roles for a user.
     *
     * @param int $userId The user ID.
     * @return array<UserRole> Array of UserRole entities.
     */
    public function loadByUserId(int $userId): array
    {
        $tableName = $this->getTableName();
        $rows = $this->db->query("SELECT * FROM {$tableName} WHERE user_id = %i", $userId);

        return array_map(fn($row) => $this->loadFromValues($row), $rows);
    }

    /**
     * Gets all role values for a user as strings.
     *
     * @param int $userId The user ID.
     * @return array<string> Array of role value strings.
     */
    public function getRoleValues(int $userId): array
    {
        $tableName = $this->getTableName();
        $roles = $this->db->queryFirstColumn(
            "SELECT role FROM {$tableName} WHERE user_id = %i",
            $userId
        );

        return $roles ?: [];
    }

    /**
     * Gets all roles for a user as Roles enum instances.
     *
     * @param int $userId The user ID.
     * @return array<Roles> Array of Roles enum values.
     */
    public function getRoles(int $userId): array
    {
        $roleValues = $this->getRoleValues($userId);

        return array_filter(
            array_map(fn($value) => Roles::tryFrom($value), $roleValues),
            fn($role) => $role !== null
        );
    }

    /**
     * Checks if a user has a specific role.
     *
     * @param int $userId The user ID.
     * @param Roles $role The role to check.
     * @return bool True if the user has the role.
     */
    public function hasRole(int $userId, Roles $role): bool
    {
        $tableName = $this->getTableName();
        $count = (int) $this->db->queryFirstField(
            "SELECT COUNT(*) FROM {$tableName} WHERE user_id = %i AND role = %s",
            $userId,
            $role->value
        );

        return $count > 0;
    }

    /**
     * Checks if a user has any of the specified roles.
     *
     * @param int $userId The user ID.
     * @param array<Roles> $roles Array of roles to check.
     * @return bool True if the user has at least one of the roles.
     */
    public function hasAnyRole(int $userId, array $roles): bool
    {
        if (empty($roles)) {
            return false;
        }

        $roleValues = array_map(fn(Roles $role) => $role->value, $roles);
        $tableName = $this->getTableName();

        $count = (int) $this->db->queryFirstField(
            "SELECT COUNT(*) FROM {$tableName} WHERE user_id = %i AND role IN %ls",
            $userId,
            $roleValues
        );

        return $count > 0;
    }

    /**
     * Assigns a role to a user.
     *
     * This operation is idempotent - assigning an existing role is a no-op.
     *
     * @param int $userId The user ID.
     * @param Roles $role The role to assign.
     * @return bool True if the role was newly assigned, false if already existed.
     */
    public function assignRole(int $userId, Roles $role): bool
    {
        // Check if already has the role
        if ($this->hasRole($userId, $role)) {
            return false;
        }

        /** @var UserRole $userRole */
        $userRole = $this->entityFactory->create(UserRole::class);
        $userRole->userId = $userId;
        $userRole->role = $role->value;
        $userRole->save();

        return true;
    }

    /**
     * Removes a role from a user.
     *
     * @param int $userId The user ID.
     * @param Roles $role The role to remove.
     * @return bool True if the role was removed, false if user didn't have it.
     */
    public function removeRole(int $userId, Roles $role): bool
    {
        $tableName = $this->getTableName();

        $this->db->query(
            "DELETE FROM {$tableName} WHERE user_id = %i AND role = %s",
            $userId,
            $role->value
        );

        return $this->db->affectedRows() > 0;
    }

    /**
     * Removes all roles from a user.
     *
     * @param int $userId The user ID.
     * @return int Number of roles removed.
     */
    public function removeAllRoles(int $userId): int
    {
        $tableName = $this->getTableName();

        $this->db->query(
            "DELETE FROM {$tableName} WHERE user_id = %i",
            $userId
        );

        return $this->db->affectedRows();
    }

    /**
     * Finds all user IDs that have a specific role.
     *
     * @param Roles $role The role to search for.
     * @return array<int> Array of user IDs.
     */
    public function findUsersByRole(Roles $role): array
    {
        $tableName = $this->getTableName();
        $userIds = $this->db->queryFirstColumn(
            "SELECT user_id FROM {$tableName} WHERE role = %s",
            $role->value
        );

        return array_map('intval', $userIds ?: []);
    }
}
