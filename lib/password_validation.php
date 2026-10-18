<?php
/**
 * lib/password_validation.php — one password rule for every role
 * (customer, staff, admin, rider). Keep PASSWORD_MIN_LENGTH in sync with
 * MIN_PASSWORD_LENGTH in src/lib/password-validation.ts.
 */

const PASSWORD_MIN_LENGTH = 8;

/** Returns an error message, or null when the password is acceptable. */
function password_error(string $password): ?string
{
    // mb_strlen counts characters, not bytes (strlen would count "é" as 2).
    $length = function_exists('mb_strlen') ? mb_strlen($password, 'UTF-8') : strlen($password);
    if ($length < PASSWORD_MIN_LENGTH) {
        return 'Password must be at least ' . PASSWORD_MIN_LENGTH . ' characters long.';
    }
    return null;
}

/** Sends a 400 JSON error and stops if the password is too short. */
function require_valid_password(string $password): void
{
    $error = password_error($password);
    if ($error !== null) {
        json_error($error, 400);
    }
}
