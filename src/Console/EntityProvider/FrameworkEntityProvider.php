<?php

declare(strict_types=1);

namespace CrisperCode\Console\EntityProvider;

use CrisperCode\Entity\EmailVerificationToken;
use CrisperCode\Entity\KeyValue;
use CrisperCode\Entity\LoginAttempt;
use CrisperCode\Entity\RememberToken;
use CrisperCode\Entity\User;

/**
 * Provides the list of framework-level entities for schema synchronization.
 *
 * @package CrisperCode\Console\EntityProvider
 */
class FrameworkEntityProvider implements EntityProviderInterface
{
    /**
     * Returns framework entity classes in dependency order.
     *
     * @return array<class-string<\CrisperCode\Entity\EntityBase>>
     */
    public function getEntities(): array
    {
        return [
            User::class,
            LoginAttempt::class,
            RememberToken::class,
            EmailVerificationToken::class,
            KeyValue::class,
        ];
    }
}
