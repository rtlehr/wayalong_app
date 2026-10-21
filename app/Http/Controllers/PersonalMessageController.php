<?php

namespace App\Http\Controllers;

use App\Models\OrganizationInvitation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PersonalMessageController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('personal/Messages', [
            'messages' => $request->user()->notifications()->latest()->limit(100)->get()->map(fn ($notification) => [
                'id' => $notification->id,
                'title' => $notification->data['title'] ?? 'WayAlong notification',
                'message' => $notification->data['message'] ?? '',
                'url' => $notification->data['url'] ?? null,
                'read' => $notification->read_at !== null,
                'createdAt' => $notification->created_at?->toIso8601String(),
                'invitationToken' => $this->availableInvitationToken($request, $notification->data['url'] ?? null),
            ]),
        ]);
    }

    public function read(Request $request, string $notification): RedirectResponse
    {
        $message = $request->user()->notifications()->whereKey($notification)->firstOrFail();
        $message->markAsRead();

        return back();
    }

    private function availableInvitationToken(Request $request, ?string $url): ?string
    {
        if (! is_string($url) || ! preg_match('~^/invitations/([A-Za-z0-9]{64})$~', $url, $matches)) {
            return null;
        }
        $invitation = OrganizationInvitation::where('token_hash', hash('sha256', $matches[1]))->first();
        return $invitation && $invitation->isValid() && strcasecmp($invitation->email, $request->user()->email) === 0
            ? $matches[1] : null;
    }

}
