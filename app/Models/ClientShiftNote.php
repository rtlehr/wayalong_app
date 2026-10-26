<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ClientShiftNote extends Model
{
    use BelongsToOrganization;

    protected $fillable = ['client_enrollment_id', 'author_id', 'shift_date', 'shift', 'summary', 'observations', 'significant_events', 'interventions', 'handoff'];

    protected function casts(): array
    {
        return [
            'shift_date' => 'date',
            'summary' => 'encrypted',
            'observations' => 'encrypted',
            'significant_events' => 'encrypted',
            'interventions' => 'encrypted',
            'handoff' => 'encrypted',
        ];
    }

    public function acknowledgments(): HasMany
    {
        return $this->hasMany(ClientShiftNoteAcknowledgment::class, 'client_shift_note_id');
    }
    public function author(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
