<?php

namespace ProPhoto\Gallery\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Hash;
use ProPhoto\Access\Models\Gallery;
use ProPhoto\Access\Permissions;
use ProPhoto\Gallery\Http\Resources\ShareResource;
use ProPhoto\Gallery\Models\GalleryShare;
use ProPhoto\Gallery\Models\GalleryAccessLog;

class ShareController extends Controller
{
    /**
     * Get all share links for a gallery.
     */
    public function index(Request $request, Gallery $gallery)
    {
        if (!$request->user()->can('view', $gallery)) {
            abort(403);
        }

        $shares = $gallery->shares()
            ->with(['createdBy'])
            ->latest()
            ->paginate(20);

        return ShareResource::collection($shares);
    }

    /**
     * Create a new share link.
     */
    public function store(Request $request, Gallery $gallery)
    {
        if (!$request->user()->can(Permissions::CREATE_SHARE_LINK)) {
            abort(403);
        }

        $validated = $request->validate([
            'password' => 'nullable|string|min:6',
            'expires_at' => 'nullable|date|after:now',
            'max_views' => 'nullable|integer|min:1',
            'allow_downloads' => 'boolean',
            'allow_comments' => 'boolean',
            'settings' => 'nullable|array',
        ]);

        $share = GalleryShare::create([
            'gallery_id' => $gallery->id,
            'created_by_user_id' => $request->user()->id,
            'password' => isset($validated['password']) ? Hash::make($validated['password']) : null,
            'expires_at' => $validated['expires_at'] ?? null,
            'max_views' => $validated['max_views'] ?? null,
            'allow_downloads' => $validated['allow_downloads'] ?? true,
            'allow_comments' => $validated['allow_comments'] ?? true,
            'settings' => $validated['settings'] ?? null,
        ]);

        return new ShareResource($share);
    }

    /**
     * Access a gallery via share token.
     */
    public function show(Request $request, string $token)
    {
        $share = GalleryShare::where('share_token', $token)
            ->with(['gallery.images'])
            ->firstOrFail();

        // Check if share is valid
        if (!$share->isValid()) {
            abort(403, 'This share link has expired or reached its view limit.');
        }

        // Check password if required
        if ($share->password) {
            $request->validate(['password' => 'required|string']);

            if (!Hash::check($request->password, $share->password)) {
                abort(403, 'Invalid password');
            }
        }

        // Increment view count
        $share->incrementViewCount();

        // Log access
        GalleryAccessLog::create([
            'gallery_id' => $share->gallery_id,
            'share_id' => $share->id,
            'user_id' => $request->user()?->id,
            'action' => GalleryAccessLog::ACTION_VIEW,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return new ShareResource($share);
    }

    /**
     * Revoke a share link.
     */
    public function destroy(Request $request, Gallery $gallery, GalleryShare $share)
    {
        if ($share->gallery_id !== $gallery->id) {
            abort(404);
        }

        if (!$request->user()->can('delete', $share)) {
            abort(403);
        }

        $share->delete();

        return response()->json(['message' => 'Share link revoked successfully']);
    }

    /**
     * Get analytics for a share link.
     */
    public function analytics(Request $request, Gallery $gallery, GalleryShare $share)
    {
        if ($share->gallery_id !== $gallery->id) {
            abort(404);
        }

        if (!$request->user()->can('viewAnalytics', $share)) {
            abort(403);
        }

        $accessLogs = $share->accessLogs()
            ->select('action', \DB::raw('count(*) as count'))
            ->groupBy('action')
            ->get();

        return response()->json([
            'share_token' => $share->share_token,
            'view_count' => $share->view_count,
            'max_views' => $share->max_views,
            'expires_at' => $share->expires_at,
            'is_valid' => $share->isValid(),
            'access_logs' => $accessLogs,
            'unique_ips' => $share->accessLogs()->distinct('ip_address')->count(),
        ]);
    }
}
