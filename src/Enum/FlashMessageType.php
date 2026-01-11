<?php

declare(strict_types=1);

namespace CrisperCode\Enum;

/**
 * Defines the available types for flash messages.
 *
 * These types correspond to Bootstrap 5 alert variants.
 *
 * @package CrisperCode\Enum
 */
enum FlashMessageType: string
{
    /**
     * Success message (green alert).
     */
    case SUCCESS = 'success';

    /**
     * Error message (red alert).
     */
    case DANGER = 'danger';

    /**
     * Informational message (blue alert).
     */
    case INFO = 'info';

    /**
     * Warning message (yellow alert).
     */
    case WARNING = 'warning';

    /**
     * Get the Bootstrap icon class for this message type.
     *
     * @return string The Bootstrap icon class name.
     */
    public function getIcon(): string
    {
        return match ($this) {
            self::SUCCESS => 'bi-check-circle-fill',
            self::DANGER => 'bi-exclamation-triangle-fill',
            self::INFO => 'bi-info-circle-fill',
            self::WARNING => 'bi-exclamation-circle-fill',
        };
    }
}
