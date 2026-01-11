<?php

declare(strict_types=1);

namespace CrisperCode\Service;

use CrisperCode\Enum\FlashMessageType;

/**
 * Service for managing flash messages across requests using session storage.
 *
 * Flash messages are temporary messages that persist only for the next request,
 * typically used to display feedback after form submissions or actions.
 *
 * @package CrisperCode\Service
 */
class FlashMessageService
{
    private const SESSION_KEY = 'flash_messages';

    /**
     * FlashMessageService constructor.
     *
     * Ensures the session is started.
     */
    public function __construct()
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    /**
     * Adds a flash message to the session.
     *
     * @param FlashMessageType $type The type/severity of the message.
     * @param string $message The message text to display.
     * @param bool $dismissible Whether the message can be dismissed by the user.
     *
     * @example
     * $flashService->add(FlashMessageType::SUCCESS, 'Strategy saved successfully!');
     * $flashService->add(FlashMessageType::DANGER, 'Failed to delete ticker.', false);
     */
    public function add(FlashMessageType $type, string $message, bool $dismissible = true): void
    {
        if (!isset($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = [];
        }

        $_SESSION[self::SESSION_KEY][] = [
            'type' => $type->value,
            'message' => $message,
            'dismissible' => $dismissible,
            'icon' => $type->getIcon(),
        ];
    }

    /**
     * Adds a success message.
     *
     * @param string $message The success message text.
     * @param bool $dismissible Whether the message can be dismissed.
     *
     * @example
     * $flashService->success('Backtest completed successfully!');
     */
    public function success(string $message, bool $dismissible = true): void
    {
        $this->add(FlashMessageType::SUCCESS, $message, $dismissible);
    }

    /**
     * Adds an error/danger message.
     *
     * @param string $message The error message text.
     * @param bool $dismissible Whether the message can be dismissed.
     *
     * @example
     * $flashService->error('API rate limit exceeded. Please try again later.');
     */
    public function error(string $message, bool $dismissible = true): void
    {
        $this->add(FlashMessageType::DANGER, $message, $dismissible);
    }

    /**
     * Adds an informational message.
     *
     * @param string $message The info message text.
     * @param bool $dismissible Whether the message can be dismissed.
     *
     * @example
     * $flashService->info('Your file is being processed in the background.');
     */
    public function info(string $message, bool $dismissible = true): void
    {
        $this->add(FlashMessageType::INFO, $message, $dismissible);
    }

    /**
     * Adds a warning message.
     *
     * @param string $message The warning message text.
     * @param bool $dismissible Whether the message can be dismissed.
     *
     * @example
     * $flashService->warning('Some price data is missing for this period.');
     */
    public function warning(string $message, bool $dismissible = true): void
    {
        $this->add(FlashMessageType::WARNING, $message, $dismissible);
    }

    /**
     * Retrieves all flash messages and clears them from the session.
     *
     * This method should be called once per request to display messages,
     * typically in a base template or layout.
     *
     * @return array<int, array{type: string, message: string, dismissible: bool, icon: string}>
     *               Array of flash message data.
     *
     * @example
     * $messages = $flashService->getAndClear();
     * // Returns: [
     * //   ['type' => 'success', 'message' => 'Saved!', 'dismissible' => true, 'icon' => 'bi-check-circle-fill'],
     * //   ['type' => 'warning', 'message' => 'Warning!', 'dismissible' => true,
     * //    'icon' => 'bi-exclamation-circle-fill']
     * // ]
     */
    public function getAndClear(): array
    {
        $messages = $_SESSION[self::SESSION_KEY] ?? [];
        unset($_SESSION[self::SESSION_KEY]);
        return $messages;
    }

    /**
     * Checks if there are any flash messages without removing them.
     *
     * @return bool True if there are pending flash messages.
     */
    public function has(): bool
    {
        return isset($_SESSION[self::SESSION_KEY]) && $_SESSION[self::SESSION_KEY] !== [];
    }

    /**
     * Clears all flash messages without retrieving them.
     */
    public function clear(): void
    {
        unset($_SESSION[self::SESSION_KEY]);
    }
}
