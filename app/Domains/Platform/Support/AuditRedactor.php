<?php

declare(strict_types=1);

namespace App\Domains\Platform\Support;

use Illuminate\Http\UploadedFile;

/**
 * Makes request input and setting values safe to keep in the activity log: secrets (keys,
 * passwords, tokens) are masked, long text is shortened and uploaded files are named only.
 */
final class AuditRedactor
{
    private const SECRET = '/(secret|password|passwd|token|api_?key|private|credential|signature|(^|[_.])(pin|otp|key)([_.]|$))/i';

    public static function isSecret(string $name): bool
    {
        return preg_match(self::SECRET, $name) === 1;
    }

    public static function value(string $name, mixed $value): mixed
    {
        if (self::isSecret($name)) {
            return $value === null || $value === '' ? $value : '••••';
        }
        if ($value instanceof UploadedFile) {
            return 'file: '.$value->getClientOriginalName();
        }
        if (is_string($value)) {
            return mb_strlen($value) > 300 ? mb_substr($value, 0, 300).'…' : $value;
        }
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $out[$k] = self::value(is_string($k) ? $k : $name, $v);
            }

            return $out;
        }

        return $value;
    }

    /**
     * @param  array<array-key, mixed>  $input
     * @return array<array-key, mixed>
     */
    public static function input(array $input): array
    {
        unset($input['_token'], $input['_method'], $input['password_confirmation']);
        $out = [];
        foreach ($input as $k => $v) {
            $out[$k] = self::value((string) $k, $v);
        }

        return $out;
    }
}
