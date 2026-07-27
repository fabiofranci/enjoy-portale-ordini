<?php

declare(strict_types=1);

namespace App\Services\Imports\Catalog;

use Illuminate\Support\Str;

final class CatalogImportNormalizer
{
    public function text(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim(preg_replace('/\s+/u', ' ', (string) $value) ?? '');

        return $normalized !== '' ? $normalized : null;
    }

    public function code(mixed $value): ?string
    {
        $code = $this->text($value);

        if ($code !== null && str_starts_with($code, "'") && strlen($code) > 1) {
            $code = substr($code, 1);
        }

        return $code;
    }

    public function unit(mixed $value): ?string
    {
        $unit = $this->text($value);

        return $unit !== null ? Str::upper($unit) : null;
    }

    public function decimal(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value) || is_float($value)) {
            return is_finite((float) $value) ? (float) $value : null;
        }

        $source = trim((string) $value);
        if ($source === '') {
            return null;
        }

        $number = str_replace(["\xc2\xa0", ' ', '%'], '', $source);
        $number = preg_replace('/[^\d,.\-+]/', '', $number) ?? '';

        if ($number === '' || $number === '-' || $number === '+') {
            return null;
        }

        $lastComma = strrpos($number, ',');
        $lastDot = strrpos($number, '.');

        if ($lastComma !== false && $lastDot !== false) {
            if ($lastComma > $lastDot) {
                $number = str_replace('.', '', $number);
                $number = str_replace(',', '.', $number);
            } else {
                $number = str_replace(',', '', $number);
            }
        } elseif ($lastComma !== false) {
            $number = str_replace('.', '', $number);
            $number = str_replace(',', '.', $number);
        } else {
            $number = str_replace(',', '', $number);
        }

        return is_numeric($number) && is_finite((float) $number) ? (float) $number : null;
    }

    /**
     * @param  array<int, mixed>  $parts
     */
    public function sequentialDiscount(array $parts): ?float
    {
        $factor = 1.0;
        $hasValue = false;

        foreach ($parts as $part) {
            if ($part === null || trim((string) $part) === '') {
                continue;
            }

            $discount = $this->decimal($part);
            if ($discount === null || $discount < 0 || $discount > 100) {
                return null;
            }

            $hasValue = true;
            $factor *= 1 - ($discount / 100);
        }

        return $hasValue ? round((1 - $factor) * 100, 5) : 0.0;
    }

    public function stableHash(mixed $value): string
    {
        return hash('sha256', json_encode(
            $this->canonicalize($value),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
        ));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }

        ksort($value);

        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }
}
