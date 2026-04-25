<?php

declare(strict_types=1);

namespace App\Models\Core;

use stdClass;

/**
 * Session-based authentication helper.
 *
 * Provides a simple API for managing the currently authenticated user
 * via PHP sessions. The user is stored as a stdClass object under the
 * 'user' session key.
 *
 * Usage:
 *
 *   // Login
 *   Passport::login($userObject);
 *
 *   // Check authentication
 *   if (Passport::isAuthenticated()) {
 *       $user = Passport::getUser();
 *   }
 *
 *   // Logout
 *   Passport::logout();
 */
final class Passport
{
    private const SESSION_KEY = 'user';

    /**
     * Check if a user is currently authenticated.
     */
    public static function isAuthenticated(): bool
    {
        return session(self::SESSION_KEY) !== null;
    }

    /**
     * Retrieve the authenticated user, or null if not authenticated.
     *
     * Returns a clone to prevent accidental mutation of session state.
     */
    public static function getUser(): ?stdClass
    {
        $user = session(self::SESSION_KEY);
        if ($user === null) {
            return null;
        }

        return clone $user;
    }

    /**
     * Retrieve the authenticated user's ID, or null if not authenticated.
     */
    public static function getUserId(): ?int
    {
        $user = self::getUser();
        return $user?->id ?? null;
    }

    /**
     * Register a user into the session (login).
     */
    public static function login(stdClass $user): void
    {
        session([self::SESSION_KEY => $user]);
    }

    /**
     * Remove the authenticated user from the session (logout).
     */
    public static function logout(): void
    {
        session([self::SESSION_KEY => null]);
    }
}
