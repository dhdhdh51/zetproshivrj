<?php

declare(strict_types=1);

namespace App\Validators;

/**
 * Validation rule sets for authentication and account management.
 */
final class AuthRules
{
    /**
     * @return array<string, string>
     */
    public static function register(): array
    {
        return [
            'name' => 'required|min:2|max:120',
            'email' => 'required|email|max:190|unique:users,email',
            'password' => 'required|password|confirmed',
            'terms' => 'required',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function registerMessages(): array
    {
        return [
            'terms.required' => 'Please accept the Terms of Service and Privacy Policy.',
            'email.unique' => 'An account with this email address already exists.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function login(): array
    {
        return [
            'email' => 'required|email',
            'password' => 'required',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function newPassword(): array
    {
        return ['password' => 'required|password|confirmed'];
    }

    /**
     * @return array<string, string>
     */
    public static function account(int $ignoreUserId): array
    {
        return [
            'name' => 'required|min:2|max:120',
            'email' => 'required|email|max:190|unique:users,email,' . $ignoreUserId,
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function businessProfile(): array
    {
        return [
            'business_name' => 'required|min:2|max:160',
            'email' => 'nullable|email|max:190',
            'phone' => 'nullable|max:40',
            'website' => 'nullable|max:190',
            'address' => 'nullable|max:255',
            'city' => 'nullable|max:80',
            'state' => 'nullable|max:80',
            'country' => 'nullable|max:80',
            'postal_code' => 'nullable|max:20',
            'gstin' => 'nullable|max:20',
            'tax_number' => 'nullable|max:40',
            'bank_name' => 'nullable|max:120',
            'account_name' => 'nullable|max:120',
            'account_number' => 'nullable|max:40',
            'ifsc' => 'nullable|max:20',
            'signature_name' => 'nullable|max:120',
            'default_terms' => 'nullable|max:8000',
            'default_notes' => 'nullable|max:3000',
            'default_currency' => 'nullable|max:3',
            'default_template' => 'nullable|max:30',
        ];
    }
}
