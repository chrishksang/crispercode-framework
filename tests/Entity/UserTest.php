<?php

declare(strict_types=1);

namespace Tests\CrisperCode\Entity;

use CrisperCode\Entity\User;
use MeekroDB;
use PHPUnit\Framework\TestCase;

/**
 * Tests for User entity.
 */
class UserTest extends TestCase
{
    private User $user;
    private MeekroDB $dbMock;

    protected function setUp(): void
    {
        $this->dbMock = $this->createMock(MeekroDB::class);
        $this->user = new User($this->dbMock);
    }

    public function testGetTableNameReturnsUsersTable(): void
    {
        $this->assertSame('users', User::getTableName());
    }

    public function testLoadFromValuesHydratesEntity(): void
    {
        $values = [
            'id' => 42,
            'email' => 'test@example.com',
            'password_hash' => 'hashed_password_123',
            'google_id' => null,
            'auth_provider' => 'email',
            'created_at' => '2023-01-01 12:00:00',
            'updated_at' => '2023-01-02 14:30:00',
        ];

        $this->user->loadFromValues($values);

        $this->assertSame(42, $this->user->id);
        $this->assertSame('test@example.com', $this->user->email);
        $this->assertSame('hashed_password_123', $this->user->passwordHash);
        $this->assertNull($this->user->googleId);
        $this->assertSame('email', $this->user->authProvider);
        $this->assertSame('2023-01-01 12:00:00', $this->user->createdAt);
        $this->assertSame('2023-01-02 14:30:00', $this->user->updatedAt);
    }

    public function testDefaultAuthProviderIsEmail(): void
    {
        $this->assertSame('email', $this->user->authProvider);
    }

    public function testSetCreatedAtIfNewOnlySetOnce(): void
    {
        $this->user->setCreatedAtIfNew();
        $firstCreatedAt = $this->user->createdAt;

        // Simulate time passing
        usleep(10000); // 10ms

        $this->user->setCreatedAtIfNew();

        $this->assertSame($firstCreatedAt, $this->user->createdAt);
    }

    public function testTouchUpdatesUpdatedAt(): void
    {
        $this->user->touch();

        $this->assertNotNull($this->user->updatedAt);
        $firstUpdatedAt = $this->user->updatedAt;

        // Simulate time passing
        sleep(1);

        $this->user->touch();

        $this->assertNotSame($firstUpdatedAt, $this->user->updatedAt);
    }

    public function testValuesIncludesEmailAndPasswordHash(): void
    {
        $this->user->email = 'test@example.com';
        $this->user->passwordHash = 'hashed_password';
        $this->user->authProvider = 'email';
        $this->user->createdAt = '2023-01-01 00:00:00';

        $reflection = new \ReflectionClass($this->user);
        $method = $reflection->getMethod('values');
        $method->setAccessible(true);
        $values = $method->invoke($this->user);

        $this->assertArrayHasKey('email', $values);
        $this->assertArrayHasKey('password_hash', $values);
        $this->assertArrayHasKey('auth_provider', $values);
        $this->assertArrayHasKey('created_at', $values);
        $this->assertSame('test@example.com', $values['email']);
        $this->assertSame('hashed_password', $values['password_hash']);
    }

    public function testLoadFromValuesWithGoogleUser(): void
    {
        $values = [
            'id' => 99,
            'email' => 'google@example.com',
            'password_hash' => null,
            'google_id' => 'google_123456789',
            'auth_provider' => 'google',
            'created_at' => '2023-06-15 10:00:00',
            'updated_at' => null,
        ];

        $this->user->loadFromValues($values);

        $this->assertSame('google@example.com', $this->user->email);
        $this->assertNull($this->user->passwordHash);
        $this->assertSame('google_123456789', $this->user->googleId);
        $this->assertSame('google', $this->user->authProvider);
    }
}
