<?php

declare(strict_types=1);

namespace Tests\CrisperCode\Console\Command;

use CrisperCode\Console\Command\AssignRoleCommand;
use CrisperCode\Entity\User;
use CrisperCode\EntityFactory;
use CrisperCode\EntityManager\UserRoleManager;
use CrisperCode\Enum\Roles;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

class AssignRoleCommandTest extends TestCase
{
    private UserRoleManager&MockObject $userRoleManagerMock;
    private EntityFactory&MockObject $entityFactoryMock;
    private CommandTester $commandTester;

    protected function setUp(): void
    {
        $this->userRoleManagerMock = $this->createMock(UserRoleManager::class);
        $this->entityFactoryMock = $this->createMock(EntityFactory::class);

        $command = new AssignRoleCommand($this->userRoleManagerMock, $this->entityFactoryMock);
        $this->commandTester = new CommandTester($command);
    }

    public function testExecuteAssignsRoleToFoundUser(): void
    {
        $email = 'test@example.com';
        $role = 'admin';

        $userMock = $this->createMock(User::class);
        $userMock->id = 123; // Set dynamic property

        // Expectations
        $this->entityFactoryMock->expects($this->once())
            ->method('findOneBy')
            ->with(User::class, ['email' => $email])
            ->willReturn($userMock);

        $this->userRoleManagerMock->expects($this->once())
            ->method('hasRole')
            ->with(123, Roles::ADMIN)
            ->willReturn(false);

        $this->userRoleManagerMock->expects($this->once())
            ->method('assignRole')
            ->with(123, Roles::ADMIN);

        // Execute
        $this->commandTester->execute([
            'email' => $email,
            'role' => $role,
        ]);

        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Role "admin" assigned to user "test@example.com"', $output);
        $this->assertEquals(0, $this->commandTester->getStatusCode());
    }

    public function testExecuteFailsIfRoleInvalid(): void
    {
        $this->commandTester->execute([
            'email' => 'test@example.com',
            'role' => 'super_mega_admin',
        ]);

        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Invalid role "super_mega_admin"', $output);
        $this->assertEquals(1, $this->commandTester->getStatusCode());
    }

    public function testExecuteFailsIfUserNotFound(): void
    {
        $this->entityFactoryMock->expects($this->once())
            ->method('findOneBy')
            ->willReturn(null);

        $this->commandTester->execute([
            'email' => 'missing@example.com',
            'role' => 'admin',
        ]);

        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('User with email "missing@example.com" not found', $output);
        $this->assertEquals(1, $this->commandTester->getStatusCode());
    }

    public function testExecuteSkipsIfUserAlreadyHasRole(): void
    {
        $email = 'test@example.com';
        $userMock = $this->createMock(User::class);
        $userMock->id = 123;

        $this->entityFactoryMock->expects($this->once())
            ->method('findOneBy')
            ->willReturn($userMock);

        $this->userRoleManagerMock->expects($this->once())
            ->method('hasRole')
            ->willReturn(true); // Already has role

        $this->userRoleManagerMock->expects($this->never())
            ->method('assignRole');

        $this->commandTester->execute([
            'email' => $email,
            'role' => 'admin',
        ]);

        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('User "test@example.com" already has role "admin"', $output);
        $this->assertEquals(0, $this->commandTester->getStatusCode());
    }
}
