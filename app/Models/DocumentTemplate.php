<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Model;

final class DocumentTemplate extends Model
{
    protected string $table = 'document_templates';

    protected array $fillable = ['slug', 'name', 'description', 'accent_color', 'is_basic', 'is_active', 'is_default', 'sort_order'];

    public function active(): array
    {
        return $this->where(['is_active' => 1], 'sort_order ASC');
    }

    public function allOrdered(): array
    {
        return $this->all('sort_order ASC');
    }

    public function findBySlug(string $slug): ?array
    {
        return $this->findBy('slug', $slug);
    }

    public function defaultSlug(): string
    {
        $row = Database::selectOne('SELECT slug FROM document_templates WHERE is_default = 1 AND is_active = 1 LIMIT 1');

        if ($row !== null) {
            return (string) $row['slug'];
        }

        $row = Database::selectOne('SELECT slug FROM document_templates WHERE is_active = 1 ORDER BY sort_order ASC LIMIT 1');

        return $row === null ? 'modern' : (string) $row['slug'];
    }

    public function makeDefault(int $id): void
    {
        Database::statement('UPDATE document_templates SET is_default = 0');
        $this->updateById($id, ['is_default' => 1, 'is_active' => 1]);
    }

    /**
     * Templates the given plan is allowed to use.
     */
    public function availableFor(bool $allTemplates): array
    {
        if ($allTemplates) {
            return $this->active();
        }

        return $this->where(['is_active' => 1, 'is_basic' => 1], 'sort_order ASC');
    }

    public function isAllowed(string $slug, bool $allTemplates): bool
    {
        $template = $this->findBySlug($slug);

        if ($template === null || (int) $template['is_active'] !== 1) {
            return false;
        }

        return $allTemplates || (int) $template['is_basic'] === 1;
    }
}
