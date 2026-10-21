<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentAttachment extends Model
{
    protected $fillable = ['document_id', 'record_type', 'record_id'];

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
