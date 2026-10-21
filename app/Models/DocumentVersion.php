<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentVersion extends Model
{
    protected $fillable = [
        'document_id', 'version_number', 'uploaded_by_user_id', 'original_filename',
        'disk', 'storage_path', 'mime_type', 'file_size', 'change_notes',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
