<?php

declare(strict_types=1);

namespace App\Api\Terminal;

use App\Fleet\Jobs\LogMasker;

/**
 * Turns a raw pane capture into what the API may return: terminal escapes and control characters removed, registered
 * secrets masked, trailing blank lines dropped, and at most MAX_BYTES (the end of the capture is kept, valid UTF-8).
 */
final readonly class TerminalText
{
    public const int MAX_BYTES = 65536;
    public const int MAX_LINES = 200;
    public const int DEFAULT_LINES = 40;

    public function __construct(private LogMasker $masker)
    {
    }

    /**
     * @return array{text: string, truncated: bool}
     */
    public function clean(string $raw): array
    {
        $text = preg_replace('/\e\[[0-?]*[ -\/]*[@-~]|\e\][^\a\e]*(?:\a|\e\\\\)|\e[PX^_][^\e]*(?:\e\\\\)?|\e[@-Z\\\\-_]/', '', $raw) ?? '';
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', '', $text) ?? '';
        $text = (string) preg_replace('/[^\S\n]+$/m', '', $text);
        $text = rtrim($text, "\n");
        $text = $this->masker->mask($text);

        $truncated = false;
        if (\strlen($text) > self::MAX_BYTES) {
            $truncated = true;
            $text = substr($text, -self::MAX_BYTES);
            // Do not start in the middle of a multi-byte character.
            while ('' !== $text && (\ord($text[0]) & 0xC0) === 0x80) {
                $text = substr($text, 1);
            }
        }

        return ['text' => $text, 'truncated' => $truncated];
    }
}
