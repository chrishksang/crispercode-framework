<?php

declare(strict_types=1);

namespace CrisperCode\Service;

use CrisperCode\Config\FrameworkConfig;

/**
 * Service for generating blind index hashes for secure searching.
 *
 * Use HMAC-SHA256 to create deterministic hashes of search terms
 * without revealing the terms themselves.
 *
 * @package CrisperCode\Service
 */
class BlindIndexService
{
    private string $systemMasterKey;

    public function __construct(FrameworkConfig $config)
    {
        $key = $config->getSystemMasterKey();

        if ($key === '') {
            throw new \RuntimeException('SYSTEM_MASTER_KEY not configured in FrameworkConfig.');
        }

        $this->systemMasterKey = $key;
    }

    /**
     * Generates a blind index hash for a word.
     *
     * @param string $word The word to hash.
     * @param string $userContext User-specific context (e.g., user ID) to prevent frequency analysis across users.
     * @return string The hashed term (hex encoded).
     */
    public function generateHash(string $word, string $userContext): string
    {
        $normalizedWord = $this->normalize($word);

        // Derive a user-specific key using the system master key
        // derivedKey = HMAC(userContext, masterKey)
        $derivedKey = hash_hmac('sha256', $userContext, $this->systemMasterKey, true);

        // Generate the term hash
        // hash = HMAC(word, derivedKey)
        return hash_hmac('sha256', $normalizedWord, $derivedKey);
    }

    /**
     * Normalizes a word for consistent hashing.
     */
    private function normalize(string $word): string
    {
        // Lowercase and remove non-alphanumeric characters
        $word = mb_strtolower(trim($word));
        return preg_replace('/[^a-z0-9]/', '', $word);
    }

    /**
     * Tokenizes a text into hashable words.
     *
     * @return array<string> Unique tokens
     */
    public function tokenize(string $text): array
    {
        $text = mb_strtolower($text);
        // Split by non-word characters
        $words = preg_split('/[\s\.,;!?:\"\'\(\)\[\]\{\}]+/', $text, -1, PREG_SPLIT_NO_EMPTY);

        $tokens = [];
        foreach ($words as $word) {
            $normalized = $this->normalize($word);
            if ($normalized !== '') {
                $tokens[] = $normalized;
            }
        }

        return array_unique($tokens);
    }
}
