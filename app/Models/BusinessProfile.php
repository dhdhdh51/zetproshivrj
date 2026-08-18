<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

final class BusinessProfile extends Model
{
    protected string $table = 'business_profiles';

    protected array $fillable = [
        'user_id', 'business_name', 'logo_path', 'email', 'phone', 'website', 'address',
        'city', 'state', 'country', 'postal_code', 'gstin', 'tax_number', 'bank_name',
        'account_name', 'account_number', 'ifsc', 'default_terms', 'default_notes',
        'default_currency', 'default_template', 'signature_name',
    ];

    public function forUser(int $userId): ?array
    {
        return $this->findBy('user_id', $userId);
    }

    /**
     * Always return an array so views never have to null-check.
     */
    public function forUserOrEmpty(int $userId): array
    {
        return $this->forUser($userId) ?? [
            'id' => null,
            'user_id' => $userId,
            'business_name' => '',
            'logo_path' => null,
            'email' => '',
            'phone' => '',
            'website' => '',
            'address' => '',
            'city' => '',
            'state' => '',
            'country' => '',
            'postal_code' => '',
            'gstin' => '',
            'tax_number' => '',
            'bank_name' => '',
            'account_name' => '',
            'account_number' => '',
            'ifsc' => '',
            'default_terms' => '',
            'default_notes' => '',
            'default_currency' => 'INR',
            'default_template' => 'modern',
            'signature_name' => '',
        ];
    }

    public function saveForUser(int $userId, array $data): int
    {
        $existing = $this->forUser($userId);

        if ($existing === null) {
            $data['user_id'] = $userId;

            return $this->create($data);
        }

        $this->updateById((int) $existing['id'], $data);

        return (int) $existing['id'];
    }

    public function isComplete(?array $profile): bool
    {
        return $profile !== null && trim((string) ($profile['business_name'] ?? '')) !== '';
    }
}
