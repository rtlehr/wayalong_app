<?php

namespace App\Support;

use App\Models\Document;
use App\Models\Facility;
use App\Models\User;
use App\Notifications\DocumentExpirationNotification;
use Illuminate\Support\Facades\DB;

/** Shared scheduled expiration checker; permission checks are evaluated at delivery time. */
final class DocumentExpirationNotifier
{
    public function send(): int
    {
        $sent = 0;
        $permissions = app(FacilityPermissions::class);

        Document::query()
            ->whereNotNull('expires_on')
            ->whereDate('expires_on', '<=', today()->addDays(30))
            ->whereNotNull('facility_id')
            ->orderBy('id')
            ->chunkById(100, function ($documents) use (&$sent, $permissions): void {
                foreach ($documents as $document) {
                    $window = $document->expires_on->lt(today()) ? 'expired' : '30-days';
                    $facility = Facility::query()->withoutGlobalScopes()->find($document->facility_id);
                    if ($facility === null || (int) $facility->organization_id !== (int) $document->organization_id || $facility->status !== 'active') {
                        continue;
                    }
                    if (! $document->attachments()->where('record_type', DocumentManager::CLIENT_ENROLLMENT)->exists()) {
                        continue;
                    }
                    $users = User::query()->whereIn('id', DB::table('organization_staff_facilities')
                        ->where('organization_id', $document->organization_id)
                        ->where('facility_id', $document->facility_id)->select('user_id'))->get();
                    foreach ($users as $user) {
                        if (! $permissions->allows($user, $facility, FacilityPermissions::MANAGE_CLIENTS)) {
                            continue;
                        }
                        $inserted = DB::table('document_expiration_notices')->insertOrIgnore([
                            'document_id' => $document->id, 'user_id' => $user->id,
                            'window' => $window, 'created_at' => now(),
                        ]);
                        if ($inserted === 0) {
                            continue;
                        }
                        $user->notify(new DocumentExpirationNotification($document->title, $document->expires_on->toDateString(), $window));
                        $sent++;
                    }
                }
            });

        return $sent;
    }
}
