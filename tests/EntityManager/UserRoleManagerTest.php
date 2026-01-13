<?php

declare(strict_types=1);

namespace Tests\CrisperCode\EntityManager;

use CrisperCode\Entity\UserRole;
use CrisperCode\EntityFactory;
use CrisperCode\EntityManager\UserRoleManager;
use CrisperCode\Enum\Roles;
use MeekroDB;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for UserRoleManager.
 */
class UserRoleManagerTest extends TestCase
{
    private MeekroDB&MockObject $dbMock;
    private EntityFactory&MockObject $entityFactoryMock;
    private UserRoleManager $manager;

    protected function setUp(): void
    {
        $this->dbMock = $this->createMock(MeekroDB::class);
        $this->entityFactoryMock = $this->createMock(EntityFactory::class);

        $this->manager = new UserRoleManager($this->dbMock, $this->entityFactoryMock);
    }

    public function testGetRoleValuesReturnsEmptyArrayForNoRoles(): void
    {
        $userId = 1;

        $this->dbMock->expects($this->once())
            ->method('queryFirstColumn')
            ->willReturn([]);

        $result = $this->manager->getRoleValues($userId);

        $this->assertSame([], $result);
    }

    public function testGetRoleValuesReturnsRoleStrings(): void
    {
        $userId = 1;

        $this->dbMock->expects($this->once())
            ->method('queryFirstColumn')
            ->willReturn(['user', 'admin']);

        $result = $this->manager->getRoleValues($userId);

        $this->assertSame(['user', 'admin'], $result);
    }

    public function testGetRolesReturnsEnumInstances(): void
    {
        $userId = 1;

        $this->dbMock->expects($this->once())
            ->method('queryFirstColumn')
            ->willReturn(['user', 'admin']);

        $result = $this->manager->getRoles($userId);

        $this->assertCount(2, $result);
        $this->assertContains(Roles::USER, $result);
        $this->assertContains(Roles::ADMIN, $result);
    }

    public function testGetRolesFiltersInvalidRoleValues(): void
    {
        $userId = 1;

        $this->dbMock->expects($this->once())
            ->method('queryFirstColumn')
            ->willReturn(['user', 'invalid_role', 'admin']);

        $result = $this->manager->getRoles($userId);

        $this->assertCount(2, $result);
        $this->assertContains(Roles::USER, $result);
        $this->assertContains(Roles::ADMIN, $result);
    }

    public function testHasRoleReturnsTrueWhenUserHasRole(): void
    {
        $userId = 1;

        $this->dbMock->expects($this->once())
            ->method('queryFirstField')
            ->with(
                $this->stringContains('SELECT COUNT(*)'),
                $userId,
                'admin'
            )
            ->willReturn(1);

        $this->assertTrue($this->manager->hasRole($userId, Roles::ADMIN));
    }

    public function testHasRoleReturnsFalseWhenUserDoesNotHaveRole(): void
    {
        $userId = 1;

        $this->dbMock->expects($this->once())
            ->method('queryFirstField')
            ->willReturn(0);

        $this->assertFalse($this->manager->hasRole($userId, Roles::ROOT));
    }

    public function testHasAnyRoleReturnsTrueWhenUserHasOneOfTheRoles(): void
    {
        $userId = 1;

        $this->dbMock->expects($this->once())
            ->method('queryFirstField')
            ->with(
                $this->stringContains('role IN'),
                $userId,
                ['admin', 'root']
            )
            ->willReturn(1);

        $this->assertTrue($this->manager->hasAnyRole($userId, [Roles::ADMIN, Roles::ROOT]));
    }

    public function testHasAnyRoleReturnsFalseWhenUserHasNoneOfTheRoles(): void
    {
        $userId = 1;

        $this->dbMock->expects($this->once())
            ->method('queryFirstField')
            ->willReturn(0);

        $this->assertFalse($this->manager->hasAnyRole($userId, [Roles::ADMIN, Roles::ROOT]));
    }

    public function testHasAnyRoleReturnsFalseForEmptyRolesArray(): void
    {
        $userId = 1;

        // Should not query database
        $this->dbMock->expects($this->never())
            ->method('queryFirstField');

        $this->assertFalse($this->manager->hasAnyRole($userId, []));
    }

    public function testAssignRoleCreatesNewRoleAssignment(): void
    {
        $userId = 1;

        // First check if user already has role (returns 0 = no)
        $this->dbMock->expects($this->once())
            ->method('queryFirstField')
            ->willReturn(0);

        $userRoleMock = $this->createMock(UserRole::class);
        $userRoleMock->expects($this->once())->method('save');

        $this->entityFactoryMock->expects($this->once())
            ->method('create')
            ->with(UserRole::class)
            ->willReturn($userRoleMock);

        $result = $this->manager->assignRole($userId, Roles::ADMIN);

        $this->assertTrue($result);
        $this->assertEquals($userId, $userRoleMock->userId);
        $this->assertEquals('admin', $userRoleMock->role);
    }

    public function testAssignRoleReturnsFalseIfRoleAlreadyExists(): void
    {
        $userId = 1;

        // User already has the role
        $this->dbMock->expects($this->once())
            ->method('queryFirstField')
            ->willReturn(1);

        // Should not create new entity
        $this->entityFactoryMock->expects($this->never())
            ->method('create');

        $result = $this->manager->assignRole($userId, Roles::ADMIN);

        $this->assertFalse($result);
    }

    public function testRemoveRoleDeletesRoleAndReturnsTrue(): void
    {
        $userId = 1;

        $this->dbMock->expects($this->once())
            ->method('query')
            ->with(
                $this->stringContains('DELETE FROM'),
                $userId,
                'admin'
            );

        $this->dbMock->expects($this->once())
            ->method('affectedRows')
            ->willReturn(1);

        $result = $this->manager->removeRole($userId, Roles::ADMIN);

        $this->assertTrue($result);
    }

    public function testRemoveRoleReturnsFalseWhenRoleDidNotExist(): void
    {
        $userId = 1;

        $this->dbMock->expects($this->once())
            ->method('query');

        $this->dbMock->expects($this->once())
            ->method('affectedRows')
            ->willReturn(0);

        $result = $this->manager->removeRole($userId, Roles::ADMIN);

        $this->assertFalse($result);
    }

    public function testRemoveAllRolesDeletesAllUserRoles(): void
    {
        $userId = 1;

        $this->dbMock->expects($this->once())
            ->method('query')
            ->with(
                $this->stringContains('DELETE FROM'),
                $userId
            );

        $this->dbMock->expects($this->once())
            ->method('affectedRows')
            ->willReturn(3);

        $result = $this->manager->removeAllRoles($userId);

        $this->assertEquals(3, $result);
    }

    public function testFindUsersByRoleReturnsUserIds(): void
    {
        $this->dbMock->expects($this->once())
            ->method('queryFirstColumn')
            ->with(
                $this->stringContains('SELECT user_id'),
                'admin'
            )
            ->willReturn(['1', '5', '10']);

        $result = $this->manager->findUsersByRole(Roles::ADMIN);

        $this->assertSame([1, 5, 10], $result);
    }

    public function testFindUsersByRoleReturnsEmptyArrayWhenNoUsers(): void
    {
        $this->dbMock->expects($this->once())
            ->method('queryFirstColumn')
            ->willReturn([]);

        $result = $this->manager->findUsersByRole(Roles::ROOT);

        $this->assertSame([], $result);
    }
}
