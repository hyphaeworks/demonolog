<?php

declare(strict_types=1);

namespace Hyphaeworks\Demonolog\Format\Line;

use JsonException;

/**
 * @internal
 */
final readonly class ContextSplitter
{
    private const CANDIDATES = '/^[\[{]|(?<= )[\[{]/';

    /**
     * @return array{message: string, context: array<array-key, mixed>, extra: array<array-key, mixed>, undecodable: bool}
     */
    public function split(string $rest): array
    {
        preg_match_all(self::CANDIDATES, $rest, $matches, PREG_OFFSET_CAPTURE);
        $candidates = array_reverse(array_column($matches[0], 1));
        $scanner = new JsonValueScanner($rest);

        foreach ([true, false] as $withExtra) {
            foreach ($candidates as $start) {
                $split = $this->splitAt($rest, $scanner, $start, $withExtra);
                if ($split !== null) {
                    return $split;
                }
            }
        }

        $message = rtrim($rest);

        return [
            'message' => $message,
            'context' => [],
            'extra' => [],
            'undecodable' => str_ends_with($message, '}') || str_ends_with($message, ']'),
        ];
    }

    /**
     * @return array{message: string, context: array<array-key, mixed>, extra: array<array-key, mixed>, undecodable: false}|null
     */
    private function splitAt(string $rest, JsonValueScanner $scanner, int $start, bool $withExtra): ?array
    {
        $context = $scanner->valueAt($start);
        if ($context === null) {
            return null;
        }
        [$end, $contextJson] = $context;

        $extraJson = '[]';
        if ($withExtra) {
            $extra = ($rest[$end] ?? '') === ' ' ? $scanner->valueAt($end + 1) : null;
            if ($extra === null) {
                return null;
            }
            [$end, $extraJson] = $extra;
        }

        if ($end < $scanner->contentEnd) {
            return null;
        }
        $scanner->charge(strlen($rest));

        $decodedContext = $this->decode($contextJson);
        $decodedExtra = $this->decode($extraJson);
        if ($decodedContext === null || $decodedExtra === null) {
            return null;
        }
        // Monolog never prints a lone empty value.
        if (!$withExtra && $decodedContext === []) {
            return null;
        }

        return [
            'message' => rtrim(substr($rest, 0, $start)),
            'context' => $decodedContext,
            'extra' => $decodedExtra,
            'undecodable' => false,
        ];
    }

    /**
     * @return array<array-key, mixed>|null
     */
    private function decode(string $json): ?array
    {
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING | JSON_INVALID_UTF8_SUBSTITUTE);
        } catch (JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }
}
