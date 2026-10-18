<?php
/**
 * lib/input_validation.php — name and mobile-number rules for account creation.
 * Keep in sync with src/lib/staff-input-validation.ts.
 * Never trust the app's masking: these functions are the real enforcement.
 */

const MOBILE_DIGITS = 11;

/** "juan" -> "Juan", "dela cruz" -> "Dela Cruz", "anne-marie" -> "Anne-Marie". */
function title_case_name(string $value): string
{
    $value = trim(preg_replace('/\s+/u', ' ', $value));
    $lower = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    return preg_replace_callback(
        '/(^|[\s\'-])(\p{L})/u',
        fn($m) => $m[1] . (function_exists('mb_strtoupper') ? mb_strtoupper($m[2], 'UTF-8') : strtoupper($m[2])),
        $lower
    );
}

/** Validates one name part and returns it in Title Case. Sends a 400 JSON error and stops if invalid. */
function require_valid_name_part(string $value, string $label): string
{
    $value = trim($value);
    if ($value === '') {
        json_error("$label is required.", 400);
    }
    if (!preg_match('/^[A-Za-zÀ-ÖØ-öø-ÿ][A-Za-zÀ-ÖØ-öø-ÿ\s\'.-]*$/u', $value)) {
        json_error("$label can only contain letters, spaces, apostrophes, periods and hyphens.", 400);
    }
    return title_case_name($value);
}

/** Exactly 11 digits, numbers only. Sends a 400 JSON error and stops if invalid. */
function require_valid_mobile(string $phone): string
{
    $phone = trim($phone);
    if ($phone === '') {
        json_error('Mobile number is required.', 400);
    }
    // D modifier: "$" must not match before a trailing newline.
    if (!preg_match('/^[0-9]{' . MOBILE_DIGITS . '}$/D', $phone)) {
        json_error('Mobile number must be exactly 11 digits and contain numbers only.', 400);
    }
    return $phone;
}
