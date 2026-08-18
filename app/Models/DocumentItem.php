<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Model;

final class DocumentItem extends Model
{
    protected string $table = 'document_items';

    protected array $fillable = [
        'document_id', 'position', 'description', 'quantity', 'unit', 'rate',
        'tax_percent', 'line_subtotal', 'line_tax', 'line_total',
    ];

    public function deleteForDocument(int $documentId): void
    {
        Database::delete($this->table, 'document_id = :id', ['id' => $documentId]);
    }

    /**
     * Replace all items of a document with the given (already calculated) rows.
     *
     * @param array<int, array<string, mixed>> $items
     */
    public function replaceForDocument(int $documentId, array $items): void
    {
        $this->deleteForDocument($documentId);

        $position = 0;
        foreach ($items as $item) {
            $item['document_id'] = $documentId;
            $item['position'] = $position++;
            $this->create($item);
        }
    }
}
