<?php

namespace ProPhoto\Gallery\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use ProPhoto\Access\Models\Gallery;
use ProPhoto\Access\Models\Image;
use ProPhoto\Access\Permissions;
use ProPhoto\Gallery\Http\Resources\ImageResource;
use ProPhoto\Gallery\Models\GalleryAccessLog;

class ImageController extends Controller
{
    /**
     * Get all images for a gallery.
     */
    public function index(Request $request, Gallery $gallery)
    {
        if (!$request->user()->can('view', $gallery)) {
            abort(403);
        }

        $images = $gallery->images()
            ->with(['tags', 'comments'])
            ->paginate(50);

        // Log access
        GalleryAccessLog::create([
            'gallery_id' => $gallery->id,
            'user_id' => $request->user()->id,
            'action' => GalleryAccessLog::ACTION_VIEW,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return ImageResource::collection($images);
    }

    /**
     * Get a specific image.
     */
    public function show(Request $request, Gallery $gallery, Image $image)
    {
        if ($image->gallery_id !== $gallery->id) {
            abort(404, 'Image not found in this gallery');
        }

        if (!$request->user()->can('view', $gallery)) {
            abort(403);
        }

        $image->load(['tags', 'comments', 'interactions']);

        return new ImageResource($image);
    }

    /**
     * Upload images to a gallery.
     */
    public function store(Request $request, Gallery $gallery)
    {
        if (!$request->user()->can(Permissions::UPLOAD_IMAGES)) {
            abort(403, 'You do not have permission to upload images.');
        }

        if (!$request->user()->can('update', $gallery)) {
            abort(403, 'You cannot upload to this gallery.');
        }

        $validated = $request->validate([
            'images' => 'required|array',
            'images.*' => 'required|image|max:51200', // 50MB max
        ]);

        $uploadedImages = [];

        foreach ($request->file('images') as $file) {
            $path = $file->store('galleries/' . $gallery->id, 'public');

            $image = Image::create([
                'gallery_id' => $gallery->id,
                'filename' => $file->getClientOriginalName(),
                'file_path' => $path,
                'file_size' => $file->getSize(),
                'mime_type' => $file->getMimeType(),
                'uploaded_by_user_id' => $request->user()->id,
            ]);

            $uploadedImages[] = $image;
        }

        return ImageResource::collection($uploadedImages);
    }

    /**
     * Download an image.
     */
    public function download(Request $request, Gallery $gallery, Image $image)
    {
        if ($image->gallery_id !== $gallery->id) {
            abort(404);
        }

        if (!$request->user()->can(Permissions::DOWNLOAD_IMAGES)) {
            abort(403, 'You do not have permission to download images.');
        }

        // Log download
        GalleryAccessLog::create([
            'gallery_id' => $gallery->id,
            'user_id' => $request->user()->id,
            'action' => GalleryAccessLog::ACTION_DOWNLOAD,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'metadata' => ['image_id' => $image->id],
        ]);

        return response()->download(storage_path('app/public/' . $image->file_path));
    }

    /**
     * Rate an image.
     */
    public function rate(Request $request, Gallery $gallery, Image $image)
    {
        if ($image->gallery_id !== $gallery->id) {
            abort(404);
        }

        if (!$request->user()->can(Permissions::RATE_IMAGES)) {
            abort(403);
        }

        $validated = $request->validate([
            'rating' => 'required|integer|min:1|max:5',
        ]);

        // Create or update rating interaction
        $image->interactions()->updateOrCreate(
            [
                'user_id' => $request->user()->id,
                'interaction_type' => 'rating',
            ],
            [
                'metadata' => ['rating' => $validated['rating']],
            ]
        );

        return response()->json(['message' => 'Image rated successfully']);
    }

    /**
     * Approve image for marketing.
     */
    public function approve(Request $request, Gallery $gallery, Image $image)
    {
        if ($image->gallery_id !== $gallery->id) {
            abort(404);
        }

        if (!$request->user()->can(Permissions::APPROVE_IMAGES)) {
            abort(403);
        }

        $image->update(['is_marketing_approved' => true]);

        return response()->json(['message' => 'Image approved for marketing']);
    }
}
