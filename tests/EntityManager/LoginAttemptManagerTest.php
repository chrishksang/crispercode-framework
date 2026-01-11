<?php

declare(strict_types=1);

namespace Tests\CrisperCode\EntityManager;

use CrisperCode\Entity\LoginAttempt;
use CrisperCode\EntityFactory;
use CrisperCode\EntityManager\LoginAttemptManager;
use MeekroDB;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for LoginAttemptManager.
 */
class LoginAttemptManagerTest extends TestCase
{
    private MeekroDB&MockObject $dbMock;
    private EntityFactory&MockObject $entityFactoryMock;
    private LoginAttemptManager $manager;

    protected function setUp(): void
    {
        $this->dbMock = $this->createMock(MeekroDB::class);
        $this->entityFactoryMock = $this->createMock(EntityFactory::class);

        $this->manager = new LoginAttemptManager($this->dbMock, $this->entityFactoryMock);
    }

    public function testRecordAttemptCreatesLoginAttempt(): void
    {
        $email = 'test@example.com';
        $ip = '192.168.1.1';
        $successful = false;

        $attemptMock = $this->createMock(LoginAttempt::class);
        $attemptMock->expects($this->once())->method('setAttemptedAtNow');
        $attemptMock->expects($this->once())->method('save');

        $this->entityFactoryMock->expects($this->once())
            ->method('create')
            ->with(LoginAttempt::class)
            ->willReturn($attemptMock);

        $result = $this->manager->recordAttempt($email, $ip, $successful);

        $this->assertSame($attemptMock, $result);
    }

    public function testGetRecentFailedCountQueriesDatabase(): void
    {
        $email = 'test@example.com';
        $ip = '192.168.1.1';

        $this->dbMock->expects($this->once())
            ->method('queryFirstField')
            ->willReturn(5);

        $count = $this->manager->getRecentFailedCount($email, $ip, 60);

        $this->assertEquals(5, $count);
    }

    public function testIsLoginAllowedReturnsTrueWhenNoLockout(): void
    {
        $email = 'test@example.com';
        $ip = '192.168.1.1';

        $this->dbMock->expects($this->atLeastOnce())
            ->method('queryFirstField')
            ->willReturn(0);

        $this->assertTrue($this->manager->isLoginAllowed($email, $ip));
    }

    public function testIsLoginAllowedReturnsFalseWhenLockedOut(): void
    {
        $email = 'test@example.com';
        $ip = '192.168.1.1';

        // Return high failure count
        $this->dbMock->expects($this->atLeastOnce())
            ->method('queryFirstField')
            ->willReturnOnConsecutiveCalls(
                25, // First call: failed count check (>20 triggers 30 min lockout)
                date('Y-m-d H:i:s', time() - 60) // Second call: last attempt time (1 min ago)
            );

        $this->assertFalse($this->manager->isLoginAllowed($email, $ip));
    }

    public function testClearFailedAttemptsDeletesRecords(): void
    {
        $email = 'test@example.com';
        $ip = '192.168.1.1';

        $this->dbMock->expects($this->once())
            ->method('query')
            ->with(
                $this->stringContains('DELETE FROM login_attempts'),
                $email,
                $ip
            );

        $this->manager->clearFailedAttempts($email, $ip);
    }

    public function testCleanupOldAttemptsReturnsAffectedRows(): void
    {
        $this->dbMock->expects($this->once())
            ->method('query')
            ->with(
                $this->stringContains('DELETE FROM login_attempts'),
                $this->anything()
            );

        $this->dbMock->expects($this->once())
            ->method('affectedRows')
            ->willReturn(10);

        $deleted = $this->manager->cleanupOldAttempts(24);

        $this->assertEquals(10, $deleted);
    }

    public function testGetLockoutSecondsReturnsZeroWhenNotLocked(): void
    {
        $email = 'test@example.com';
        $ip = '192.168.1.1';

        // Return low failure count (under threshold)
        $this->dbMock->expects($this->atLeastOnce())
            ->method('queryFirstField')
            ->willReturn(2);

        $seconds = $this->manager->getLockoutSecondsRemaining($email, $ip);

        $this->assertEquals(0, $seconds);
    }

    public function testEmailIsNormalizedToLowercase(): void
    {
        $email = 'TEST@Example.COM';
        $ip = '192.168.1.1';

        $attemptMock = $this->createMock(LoginAttempt::class);
        $attemptMock->expects($this->once())->method('save');

        $this->entityFactoryMock->expects($this->once())
            ->method('create')
            ->willReturn($attemptMock);

        $this->manager->recordAttempt($email, $ip, false);

        // Verify email was normalized (lowercase)
        $this->assertEquals('test@example.com', $attemptMock->email);
    }
}
