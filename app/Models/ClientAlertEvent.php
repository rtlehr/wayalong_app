<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientAlertEvent extends Model
{
    use BelongsToOrganization;

    protected $fillable = ['client_alert_id', 'actor_id', 'action', 'reason', 'changes'];

    protected function casts(): array
    {
        return ['reason' => 'encrypted', 'changes' => 'encrypted:array'];
    }

    public function alert(): BelongsTo
    {
        return $this->belongsTo(ClientAlert::class, 'client_alert_id');
    }
    public function actor(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
