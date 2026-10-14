<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Notifications\NotificationCatalog;
use App\Services\NotificationPreferences;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class NotificationPreferenceController extends Controller
{
    public function index(Request $request, NotificationPreferences $preferences): JsonResponse
    {
        return response()->json([
            'data' => $preferences->forChannel($request->user(), NotificationCatalog::CHANNEL_EMAIL),
            'channel' => NotificationCatalog::CHANNEL_EMAIL,
        ]);
    }

    public function update(Request $request, NotificationPreferences $preferences): JsonResponse
    {
        $validated = $request->validate([
            'channel' => ['required', Rule::in([NotificationCatalog::CHANNEL_EMAIL])],
            'category' => ['required', Rule::in(NotificationCatalog::mailableCategories())],
            'enabled' => ['required', 'boolean'],
        ]);

        $preferences->set(
            $request->user(),
            $validated['channel'],
            $validated['category'],
            (bool) $validated['enabled'],
        );

        return response()->json([
            'message' => $validated['enabled'] ? 'Kategori diaktifkan kembali.' : 'Kategori dimatikan.',
            'data' => $preferences->forChannel($request->user(), NotificationCatalog::CHANNEL_EMAIL),
        ]);
    }

    /**
     * One-click unsubscribe from an email footer.
     *
     * Reachable without a session because the recipient may be reading mail on another device, so
     * the signed URL is the whole authorization. It can only disable the single category named in
     * the signature; the category is re-checked against the catalog, so a signed link for a
     * mandatory category (which the job never mints) is still refused.
     */
    public function unsubscribe(Request $request, User $user, string $category, NotificationPreferences $preferences): JsonResponse|RedirectResponse
    {
        abort_unless(
            in_array($category, NotificationCatalog::mailableCategories(), true),
            422,
            'Kategori notifikasi tidak dapat dihentikan.',
        );

        $preferences->set($user, NotificationCatalog::CHANNEL_EMAIL, $category, false);

        if (! $request->expectsJson()) {
            return redirect('/?notifications_unsubscribed='.urlencode($category));
        }

        return response()->json(['message' => 'Kamu tidak akan menerima email untuk kategori ini lagi.']);
    }
}
