<?php

declare(strict_types=1);

namespace Tests\CrisperCode\Console\EntityProvider;

use CrisperCode\Console\EntityProvider\EntityProviderInterface;
use CrisperCode\Console\EntityProvider\FrameworkEntityProvider;
use CrisperCode\Entity\EmailVerificationToken;
use CrisperCode\Entity\EntityBase;
use CrisperCode\Entity\KeyValue;
use CrisperCode\Entity\LoginAttempt;
use CrisperCode\Entity\RememberToken;
use CrisperCode\Entity\User;
use PHPUnit\Framework\TestCase;

class FrameworkEntityProviderTest extends TestCase
{
    private FrameworkEntityProvider $provider;

    protected function setUp(): void
    {
        $this->provider = new FrameworkEntityProvider();
    }

    public function testImplementsEntityProviderInterface(): void
    {
        $this->assertInstanceOf(EntityProviderInterface::class, $this->provider);
    }

    public function testReturnsArrayOfEntityClasses(): void
    {
        $entities = $this->provider->getEntities();

        $this->assertIsArray($entities);
        $this->assertNotEmpty($entities);

        foreach ($entities as $entity) {
            $this->assertIsString($entity);
            $this->assertTrue(class_exists($entity), "Class $entity does not exist");
        }
    }

    public function testAllEntitiesExtendEntityBase(): void
    {
        $entities = $this->provider->getEntities();

        foreach ($entities as $entity) {
            $this->assertTrue(
                is_subclass_of($entity, EntityBase::class),
                "Entity $entity does not extend EntityBase"
            );
        }
    }

    public function testContainsExpectedEntities(): void
    {
        $entities = $this->provider->getEntities();

        $expectedEntities = [
            User::class,
            LoginAttempt::class,
            RememberToken::class,
            EmailVerificationToken::class,
            KeyValue::class,
        ];

        foreach ($expectedEntities as $expected) {
            $this->assertContains(
                $expected,
                $entities,
                "Expected entity $expected not found in provider"
            );
        }

        $this->assertCount(count($expectedEntities), $entities);
    }

    public function testEntitiesHaveTableNameConstant(): void
    {
        $entities = $this->provider->getEntities();

        foreach ($entities as $entity) {
            $constantName = $entity . '::TABLE_NAME';
            $this->assertTrue(
                defined($constantName),
                "Entity $entity does not define TABLE_NAME constant"
            );

            $tableName = constant($constantName);
            $this->assertIsString($tableName);
            $this->assertNotEmpty($tableName);
        }
    }
}
