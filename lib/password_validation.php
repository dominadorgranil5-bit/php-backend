<?php
/**
 * lib/password_validation.php — password rules per role.
 * - Staff accounts: length only (password_error / require_valid_password).
 * - Customers (register + reset), admins and riders: length plus an
 *   uppercase letter, a lowercase letter and a number
 *   (strong_password_error / require_strong_password).
 * Keep PASSWORD_MIN_LENGTH in sync with MIN_PASSWORD_LENGTH in
 * src/lib/password-validation.ts.
 */

const PASSWORD_MIN_LENGTH = 8;

/** Length-only rule (staff). Returns an error message, or null when acceptable. */
function password_error(string $password): ?string
{
    // mb_strlen counts characters, not bytes (strlen would count "é" as 2).
    $length = function_exists('mb_strlen') ? mb_strlen($password, 'UTF-8') : strlen($password);
    if ($length < PASSWORD_MIN_LENGTH) {
        return 'Password must be at least ' . PASSWORD_MIN_LENGTH . ' characters long.';
    }
    return null;
}

/**
 * Full rule (customers, admins, riders): the length check plus an uppercase
 * letter, a lowercase letter and a number. Returns an error, or null.
 */
function strong_password_error(string $password): ?string
{
    $error = password_error($password);
    if ($error !== null) {
        return $error;
    }
    if (!preg_match('/[A-Z]/', $password) || !preg_match('/[a-z]/', $password) || !preg_match('/[0-9]/', $password)) {
        return 'Password must include an uppercase letter, a lowercase letter, and a number.';
    }
    return null;
}

/** Sends a 400 JSON error and stops if the password is too short (staff rule). */
function require_valid_password(string $password): void
{
    $error = password_error($password);
    if ($error !== null) {
        json_error($error, 400);
    }
}

/** Sends a 400 JSON error and stops unless the full rule is met (customers, admins, riders). */
function require_strong_password(string $password): void
{
    $error = strong_password_error($password);
    if ($error !== null) {
        json_error($error, 400);
    }
}
