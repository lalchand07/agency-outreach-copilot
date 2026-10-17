<?php

declare(strict_types=1);

namespace App\Leads;

/**
 * Normalises the keys used for de-duplication and suppression.
 */
final class Normalizer
{
    /** Country name or ISO code → international dialling code. */
    private const DIAL_CODES = [
        'GB' => '44', 'UK' => '44', 'UNITED KINGDOM' => '44', 'ENGLAND' => '44', 'SCOTLAND' => '44', 'WALES' => '44',
        'PK' => '92', 'PAKISTAN' => '92',
        'AE' => '971', 'UAE' => '971', 'UNITED ARAB EMIRATES' => '971',
        'SA' => '966', 'SAUDI ARABIA' => '966', 'KSA' => '966',
        'QA' => '974', 'QATAR' => '974',
        'KW' => '965', 'KUWAIT' => '965',
        'BH' => '973', 'BAHRAIN' => '973',
        'OM' => '968', 'OMAN' => '968',
        'IE' => '353', 'IRELAND' => '353',
        'US' => '1', 'USA' => '1', 'UNITED STATES' => '1', 'CA' => '1', 'CANADA' => '1',
        'IN' => '91', 'INDIA' => '91', 'BD' => '880', 'BANGLADESH' => '880',
    ];

    /**
     * "https://www.Example.co.uk/about" → "example.co.uk". Returns null when no host can be found.
     */
    public static function domain(?string $urlOrDomain): ?string
    {
        $value = trim((string) $urlOrDomain);
        if ($value === '') {
            return null;
        }
        if (str_contains($value, '@') && !str_contains($value, '/')) {
            $value = substr($value, (int) strrpos($value, '@') + 1);
        }
        if (!preg_match('#^[a-z][a-z0-9+.-]*://#i', $value)) {
            $value = 'http://' . $value;
        }
        $host = parse_url($value, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return null;
        }
        $host = rtrim(strtolower($host), '.');
        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }
        if (!preg_match('/^[a-z0-9-]+(\.[a-z0-9-]+)+$/', $host) && !preg_match('/^xn--/', $host)) {
            return null;
        }

        return $host;
    }

    /**
     * Phone number to digits with country code, e.g. "020 7946 0958" in the UK → "442079460958".
     * Returns null for anything shorter than 7 digits.
     */
    public static function phone(?string $phone, ?string $country = null): ?string
    {
        $raw = trim((string) $phone);
        if ($raw === '') {
            return null;
        }
        $plus = str_starts_with($raw, '+');
        $digits = (string) preg_replace('/\D+/', '', $raw);
        if (strlen($digits) < 7) {
            return null;
        }
        if ($plus) {
            return $digits;
        }
        if (str_starts_with($digits, '00')) {
            return substr($digits, 2);
        }
        $code = self::dialCode($country);
        if ($code !== null) {
            if (str_starts_with($digits, '0')) {
                return $code . ltrim($digits, '0');
            }
            if (str_starts_with($digits, $code) && strlen($digits) > 10) {
                return $digits;
            }

            return $code . $digits;
        }

        return ltrim($digits, '0');
    }

    public static function dialCode(?string $country): ?string
    {
        $key = strtoupper(trim((string) $country));

        return self::DIAL_CODES[$key] ?? null;
    }

    public static function email(?string $email): ?string
    {
        $email = strtolower(trim((string) $email));

        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : null;
    }
}
