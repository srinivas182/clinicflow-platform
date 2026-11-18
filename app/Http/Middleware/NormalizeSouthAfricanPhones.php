<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cell and phone numbers may be typed as +27 82 123 4567, 27821234567 or 082-123-4567; they are
 * stored in the local format 0821234567 (practice tab row 1). Only cell-number fields (cell,
 * guardian_cell, owner_phone…) are touched, and only when they hold a South African number; display
 * numbers on websites (e.g. "011 555 0100") keep the way they were typed.
 */
class NormalizeSouthAfricanPhones
{
    private const FIELDS = '/(^|_)cell$|^owner_phone$/';

    public function handle(Request $request, Closure $next): Response
    {
        $changed = [];
        foreach ($request->all() as $key => $value) {
            if (is_string($key) && is_string($value) && preg_match(self::FIELDS, $key) === 1) {
                $normal = self::normalize($value);
                if ($normal !== $value) {
                    $changed[$key] = $normal;
                }
            }
        }
        if ($changed !== []) {
            $request->merge($changed);
        }

        return $next($request);
    }

    public static function normalize(string $value): string
    {
        $digits = preg_replace('/[\s\-().]/', '', trim($value)) ?? '';
        if (preg_match('/^(?:\+27|0027|27|0)(\d{9})$/', $digits, $m) === 1) {
            return '0'.$m[1];
        }

        return $value;
    }
}
