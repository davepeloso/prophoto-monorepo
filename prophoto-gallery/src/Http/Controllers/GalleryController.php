<?php

namespace ProPhoto\Gallery\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use ProPhoto\Access\Models\Gallery;
use ProPhoto\Access\Permissions;
use ProPhoto\Gallery\Http\Resources\GalleryResource;
use ProPhoto\Gallery\Models\GalleryCollection;

class GalleryController extends Controller
{
    /**
     * Get all galleries accessible to the user.
     */
    public function index(Request $request)
    {
        $user = $request->user();

        $query = Gallery::with(['images', 'organization', 'user']);

        // Studio users see everything
        if (!$user->hasRole('studio_user')) {
            $query->where(function ($q) use ($user) {
                // User's own galleries
                $q->where('user_id', $user->id)
                    // Or organization galleries
                    ->orWhere('organization_id', $user->organization_id)
                    // Or galleries with contextual permission
                    ->orWhereHas('contextualPermissions', function ($q) use ($user) {
                        $q->where('user_id', $user->id);
                    });
            });
        }

        $galleries = $query->paginate(20);

        return GalleryResource::collection($galleries);
    }

    /**
     * Get a specific gallery by ID or slug.
     */
    public function show(Request $request, $identifier)
    {
        $gallery = is_numeric($identifier)
            ? Gallery::findOrFail($identifier)
            : Gallery::where('slug', $identifier)->firstOrFail();

        // Check permission
        if (!$request->user()->can('view', $gallery)) {
            abort(403, 'You do not have permission to view this gallery.');
        }

        $gallery->load(['images', 'organization', 'user', 'collections']);

        return new GalleryResource($gallery);
    }

    /**
     * Create a new gallery.
     */
    public function store(Request $request)
    {
        if (!$request->user()->can(Permissions::CREATE_GALLERY)) {
            abort(403, 'You do not have permission to create galleries.');
        }

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'organization_id' => 'nullable|exists:organizations,id',
            'settings' => 'nullable|array',
            'client_message' => 'nullable|string',
        ]);

        $gallery = Gallery::create([
            ...$validated,
            'user_id' => $request->user()->id,
        ]);

        return new GalleryResource($gallery);
    }

    /**
     * Update a gallery.
     */
    public function update(Request $request, Gallery $gallery)
    {
        if (!$request->user()->can('update', $gallery)) {
            abort(403, 'You do not have permission to update this gallery.');
        }

        $validated = $request->validate([
            'title' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'settings' => 'nullable|array',
            'client_message' => 'nullable|string',
        ]);

        $gallery->update($validated);

        return new GalleryResource($gallery);
    }

    /**
     * Delete a gallery.
     */
    public function destroy(Request $request, Gallery $gallery)
    {
        if (!$request->user()->can('delete', $gallery)) {
            abort(403, 'You do not have permission to delete this gallery.');
        }

        $gallery->delete();

        return response()->json(['message' => 'Gallery deleted successfully']);
    }

    /**
     * Get gallery statistics.
     */
    public function stats(Request $request, Gallery $gallery)
    {
        if (!$request->user()->can('view', $gallery)) {
            abort(403);
        }

        return response()->json([
            'total_images' => $gallery->images()->count(),
            'approved_images' => $gallery->images()->where('is_marketing_approved', true)->count(),
            'view_count' => $gallery->view_count ?? 0,
            'download_count' => $gallery->download_count ?? 0,
            'collections_count' => $gallery->collections()->count(),
        ]);
    }
}
