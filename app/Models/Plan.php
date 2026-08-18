<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Model;

final class Plan extends Model
{
    protected string $table = 'plans';

    protected array $fillable = [
        'slug', 'name', 'description', 'price', 'currency', 'billing_interval', 'document_limit',
        'ai_limit', 'all_templates', 'pdf_enabled', 'email_enabled', 'features', 'is_active', 'sort_order',
    ];

    public function activePlans(): array
    {
        return array_map([$this, 'hydrate'], $this->where(['is_active' => 1], 'sort_order ASC'));
    }

    public function allOrdered(): array
    {
        return array_map([$this, 'hydrate'], $this->all('sort_order ASC'));
    }

    public function findBySlug(string $slug): ?array
    {
        $plan = $this->findBy('slug', $slug);

        return $plan === null ? null : $this->hydrate($plan);
    }

    public function findPlan(int $id): ?array
    {
        $plan = $this->find($id);

        return $plan === null ? null : $this->hydrate($plan);
    }

    public function free(): array
    {
        $plan = $this->findBySlug('free');

        return $plan ?? $this->hydrate([
            'id' => 0,
            'slug' => 'free',
            'name' => 'Free',
            'price' => 0,
            'currency' => 'INR',
            'billing_interval' => 'monthly',
            'document_limit' => 5,
            'ai_limit' => 5,
            'all_templates' => 0,
            'pdf_enabled' => 1,
            'email_enabled' => 0,
            'features' => '[]',
            'is_active' => 1,
        ]);
    }

    public function paidCount(): int
    {
        return (int) Database::scalar('SELECT COUNT(*) FROM plans WHERE price > 0 AND is_active = 1');
    }

    /**
     * Decode JSON features and cast flags/limits.
     */
    public function hydrate(array $plan): array
    {
        $features = json_decode((string) ($plan['features'] ?? '[]'), true);

        $plan['features_list'] = is_array($features) ? $features : [];
        $plan['price'] = (float) ($plan['price'] ?? 0);
        $plan['document_limit'] = (int) ($plan['document_limit'] ?? 0);
        $plan['ai_limit'] = (int) ($plan['ai_limit'] ?? 0);
        $plan['all_templates'] = (bool) ($plan['all_templates'] ?? false);
        $plan['pdf_enabled'] = (bool) ($plan['pdf_enabled'] ?? true);
        $plan['email_enabled'] = (bool) ($plan['email_enabled'] ?? false);
        $plan['is_free'] = $plan['price'] <= 0;

        return $plan;
    }
}
