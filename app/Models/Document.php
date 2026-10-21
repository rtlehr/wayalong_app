<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Shared document metadata. Never query this model from a user-facing route
 * without applying the module's record-level authorization and scope.
 */
class Document extends Model
{
    protected $fillable = [
        'organization_id', 'facility_id', 'uploaded_by_user_id', 'title',
        'category', 'original_filename', 'disk', 'storage_path',
        'mime_type', 'file_size', 'expires_on', 'current_version', 'review_status', 'reviewed_by_user_id', 'reviewed_at', 'review_notes',
    ];

    protected function casts(): array
    {
        return ['expires_on' => 'date', 'reviewed_at' => 'datetime'];
    }

    public function versions(): HasMany
    {
        return $this->hasMany(DocumentVersion::class)->orderByDesc('version_number');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(DocumentAttachment::class);
    }
}
