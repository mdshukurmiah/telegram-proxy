<?php

declare(strict_types=1);

namespace App\Support;

final class TokenMasker
{
    public static function mask(string $message): string
    {
        return (string) preg_replace('/(\d{5,15}):[A-Za-z0-9_-]{30,}/', '$1:****', $message);
    }
}
