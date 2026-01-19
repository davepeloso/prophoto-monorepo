<?php

namespace ProPhoto\Ingest\Services;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ProPhoto\Ingest\Models\ProxyImage;
use ProPhoto\Ingest\Models\Image;
use ProPhoto\Ingest\Models\Tag;
use Carbon\Carbon;

class IngestProcessor
{
    public function __construct(
        protected array $storageConfig,
        protected array $schemaConfig
    ) {}

    /**
     * Process a single proxy image into final storage
     *
     * Uses normalized metadata from ExifTool when available, falling back
     * to parsing raw EXIF values for backwards compatibility.
     */
    public function process(ProxyImage $proxy, int $sequence, ?array $association = null): Image
    {
        try {
            // Build the final path and filename
            $finalPath = $this->buildPath($proxy, $sequence);
            $finalFilename = $this->buildFilename($proxy, $sequence);

            // Get source and destination disk
            $tempDisk = $this->storageConfig['temp_disk'] ?? 'local';
            $finalDisk = $this->storageConfig['final_disk'] ?? 'local';

            // Log processing start
            \Log::info('Processing image ingest', [
                'proxy_uuid' => $proxy->uuid,
                'sequence' => $sequence,
                'temp_disk' => $tempDisk,
                'final_disk' => $finalDisk,
                'temp_path' => $proxy->temp_path,
                'final_path' => $finalPath,
                'final_filename' => $finalFilename,
                'extraction_method' => $proxy->extraction_method ?? 'unknown',
            ]);

            // Verify source file exists
            if (!Storage::disk($tempDisk)->exists($proxy->temp_path)) {
                \Log::error('Source temp file does not exist', [
                    'proxy_uuid' => $proxy->uuid,
                    'temp_disk' => $tempDisk,
                    'temp_path' => $proxy->temp_path,
                ]);
                throw new \Exception("Source file not found: {$proxy->temp_path}");
            }

            // Read file content from temp disk
            $fileContent = Storage::disk($tempDisk)->get($proxy->temp_path);

            \Log::info('Retrieved file content', [
                'proxy_uuid' => $proxy->uuid,
                'file_size' => strlen($fileContent),
            ]);

            // Ensure final path directory exists
            Storage::disk($finalDisk)->makeDirectory($finalPath, 0755, true);

            // Move to final location using put() with file content
            $fullFinalPath = $finalPath . '/' . $finalFilename;
            Storage::disk($finalDisk)->put($fullFinalPath, $fileContent);

            // Verify file was written successfully
            if (!Storage::disk($finalDisk)->exists($fullFinalPath)) {
                \Log::error('Failed to verify written file', [
                    'proxy_uuid' => $proxy->uuid,
                    'final_disk' => $finalDisk,
                    'final_path' => $fullFinalPath,
                ]);
                throw new \Exception("File write failed: {$fullFinalPath}");
            }

            \Log::info('File moved successfully', [
                'proxy_uuid' => $proxy->uuid,
                'final_disk' => $finalDisk,
                'final_path' => $fullFinalPath,
                'file_size' => Storage::disk($finalDisk)->size($fullFinalPath),
            ]);

            // Parse metadata with logging
            $metadata = $proxy->metadata ?? [];
            \Log::info('Parsing metadata', [
                'proxy_uuid' => $proxy->uuid,
                'metadata_keys' => array_keys($metadata),
                'total_keys' => count($metadata),
                'has_normalized_fields' => isset($metadata['date_taken']) || isset($metadata['f_stop']),
            ]);

            // Get GPS coordinates - prefer normalized fields from ExifTool
            $gpsData = $this->extractGpsData($metadata);

            // Create permanent image record
            // Use normalized fields from ExifTool when available, with fallback parsing
            $image = Image::create([
                'file_name' => $finalFilename,
                'file_path' => $fullFinalPath,
                'disk' => $finalDisk,
                'size' => $metadata['file_size'] ?? $metadata['FileSize'] ?? strlen($fileContent),
                'date_taken' => $this->extractDateTaken($metadata),
                'camera_make' => $metadata['camera_make'] ?? $metadata['Make'] ?? null,
                'camera_model' => $metadata['camera_model'] ?? $metadata['Model'] ?? null,
                'lens' => $metadata['lens'] ?? $metadata['LensModel'] ?? null,
                'f_stop' => $this->extractFStop($metadata),
                'iso' => $this->extractISO($metadata),
                'shutter_speed' => $this->extractShutterSpeed($metadata),
                'focal_length' => $this->extractFocalLength($metadata),
                'gps_lat' => $gpsData['lat'],
                'gps_lng' => $gpsData['lng'],
                'raw_metadata' => $proxy->metadata_raw ?? $metadata,
                'imageable_type' => $association['type'] ?? null,
                'imageable_id' => $association['id'] ?? null,
            ]);

            \Log::info('Image record created', [
                'image_id' => $image->id,
                'proxy_uuid' => $proxy->uuid,
                'file_path' => $image->file_path,
                'camera' => $image->camera_make . ' ' . $image->camera_model,
                'date_taken' => $image->date_taken,
            ]);

            // Sync tags from both tags_json (legacy) and tags relationship
            $tagIds = [];
            
            // Get tags from relationship (preferred method)
            if ($proxy->tags()->exists()) {
                $tagIds = $proxy->tags()->pluck('id')->toArray();
            }
            
            // Also include tags from tags_json for backwards compatibility
            if (!empty($proxy->tags_json)) {
                foreach ($proxy->tags_json as $tagName) {
                    $tag = Tag::findOrCreateByName($tagName);
                    if (!in_array($tag->id, $tagIds)) {
                        $tagIds[] = $tag->id;
                    }
                }
            }
            
            if (!empty($tagIds)) {
                $image->tags()->sync($tagIds);

                \Log::info('Tags synced', [
                    'image_id' => $image->id,
                    'tag_count' => count($tagIds),
                ]);
            }

            // Cleanup temp files
            $this->cleanup($proxy);

            \Log::info('Image ingest completed successfully', [
                'image_id' => $image->id,
                'proxy_uuid' => $proxy->uuid,
            ]);

            return $image;
        } catch (\Exception $e) {
            \Log::error('Image ingest failed', [
                'proxy_uuid' => $proxy->uuid,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    /**
     * Build the final storage path based on schema config
     */
    protected function buildPath(ProxyImage $proxy, int $sequence): string
    {
        $pattern = $this->schemaConfig['path'] ?? 'images/{date:Y}/{date:m}';

        return $this->replacePlaceholders($pattern, $proxy, $sequence);
    }

    /**
     * Build the final filename based on schema config
     */
    protected function buildFilename(ProxyImage $proxy, int $sequence): string
    {
        $pattern = $this->schemaConfig['filename'] ?? '{sequence}-{original}';
        $filename = $this->replacePlaceholders($pattern, $proxy, $sequence);

        // Ensure we keep the original extension
        $originalExt = pathinfo($proxy->filename, PATHINFO_EXTENSION);
        $newExt = pathinfo($filename, PATHINFO_EXTENSION);

        if (empty($newExt)) {
            $filename .= '.' . $originalExt;
        }

        return $filename;
    }

    /**
     * Replace placeholders in path/filename patterns
     */
    protected function replacePlaceholders(string $pattern, ProxyImage $proxy, int $sequence): string
    {
        $dateTaken = $this->parseDateTime($proxy->metadata) ?? now();

        $replacements = [
            '{original}' => pathinfo($proxy->filename, PATHINFO_FILENAME),
            '{uuid}' => $proxy->uuid,
            '{camera}' => Str::slug($proxy->metadata['Make'] ?? 'unknown'),
            '{model}' => Str::slug($proxy->metadata['Model'] ?? 'unknown'),
        ];

        // Handle special tags
        $projectTag = $proxy->getProjectTag();
        $filenameTag = $proxy->getFilenameTag();
        
        $replacements['{project}'] = $projectTag ? Str::slug($projectTag->name) : '';
        $replacements['{filename}'] = $filenameTag ? Str::slug($filenameTag->name) : '';

        // Handle sequence with padding
        $padding = $this->schemaConfig['sequence_padding'] ?? 3;
        $replacements['{sequence}'] = str_pad($sequence, $padding, '0', STR_PAD_LEFT);

        // Handle date patterns {date:FORMAT}
        $pattern = preg_replace_callback('/\{date:([^}]+)\}/', function ($matches) use ($dateTaken) {
            return $dateTaken->format($matches[1]);
        }, $pattern);

        return str_replace(array_keys($replacements), array_values($replacements), $pattern);
    }

    /**
     * Parse date/time from metadata
     */
    protected function parseDateTime(array $metadata): ?Carbon
    {
        $dateKeys = ['DateTimeOriginal', 'DateTimeDigitized', 'DateTime'];

        foreach ($dateKeys as $key) {
            if (!empty($metadata[$key])) {
                try {
                    return Carbon::parse($metadata[$key]);
                } catch (\Exception $e) {
                    continue;
                }
            }
        }

        return null;
    }

    /**
     * Parse aperture value
     */
    protected function parseAperture($value): ?float
    {
        if (is_numeric($value)) {
            return round((float) $value, 2);
        }

        if (is_string($value) && str_contains($value, '/')) {
            [$num, $den] = explode('/', $value);
            return $den > 0 ? round($num / $den, 2) : null;
        }

        return null;
    }

    /**
     * Parse shutter speed value
     */
    protected function parseShutterSpeed($value): ?float
    {
        if (is_numeric($value)) {
            return (float) $value;
        }

        if (is_string($value) && str_contains($value, '/')) {
            [$num, $den] = explode('/', $value);
            return $den > 0 ? $num / $den : null;
        }

        return null;
    }

    /**
     * Parse focal length value
     */
    protected function parseFocalLength($value): ?int
    {
        if (is_numeric($value)) {
            return (int) $value;
        }

        if (is_string($value)) {
            preg_match('/(\d+)/', $value, $matches);
            return isset($matches[1]) ? (int) $matches[1] : null;
        }

        return null;
    }

    /**
     * Parse ISO value
     */
    protected function parseISO($value): ?int
    {
        if (is_numeric($value)) {
            return (int) $value;
        }

        if (is_string($value)) {
            preg_match('/(\d+)/', $value, $matches);
            return isset($matches[1]) ? (int) $matches[1] : null;
        }

        return null;
    }

    /**
     * Extract date taken - prefers normalized field, falls back to parsing
     */
    protected function extractDateTaken(array $metadata): ?Carbon
    {
        // First check for pre-normalized date_taken from ExifTool
        if (!empty($metadata['date_taken'])) {
            try {
                return Carbon::parse($metadata['date_taken']);
            } catch (\Exception $e) {
                // Fall through to legacy parsing
            }
        }

        // Fall back to legacy parsing
        return $this->parseDateTime($metadata);
    }

    /**
     * Extract f-stop - prefers normalized field, falls back to parsing
     */
    protected function extractFStop(array $metadata): ?float
    {
        // First check for pre-normalized f_stop from ExifTool
        if (isset($metadata['f_stop']) && is_numeric($metadata['f_stop'])) {
            return round((float) $metadata['f_stop'], 2);
        }

        // Fall back to legacy parsing
        return $this->parseAperture($metadata['FNumber'] ?? null);
    }

    /**
     * Extract ISO - prefers normalized field, falls back to parsing
     */
    protected function extractISO(array $metadata): ?int
    {
        // First check for pre-normalized iso from ExifTool
        if (isset($metadata['iso']) && is_numeric($metadata['iso'])) {
            return (int) $metadata['iso'];
        }

        // Fall back to legacy parsing
        return $this->parseISO($metadata['ISOSpeedRatings'] ?? $metadata['ISO'] ?? null);
    }

    /**
     * Extract shutter speed - prefers normalized field, falls back to parsing
     */
    protected function extractShutterSpeed(array $metadata): ?float
    {
        // First check for pre-normalized shutter_speed from ExifTool
        if (isset($metadata['shutter_speed']) && is_numeric($metadata['shutter_speed'])) {
            return (float) $metadata['shutter_speed'];
        }

        // Fall back to legacy parsing
        return $this->parseShutterSpeed($metadata['ExposureTime'] ?? null);
    }

    /**
     * Extract focal length - prefers normalized field, falls back to parsing
     */
    protected function extractFocalLength(array $metadata): ?int
    {
        // First check for pre-normalized focal_length from ExifTool
        if (isset($metadata['focal_length']) && is_numeric($metadata['focal_length'])) {
            return (int) $metadata['focal_length'];
        }

        // Fall back to legacy parsing
        return $this->parseFocalLength($metadata['FocalLength'] ?? null);
    }

    /**
     * Extract GPS data - prefers normalized fields, falls back to parsing
     */
    protected function extractGpsData(array $metadata): array
    {
        $result = ['lat' => null, 'lng' => null];

        // First check for pre-normalized GPS from ExifTool
        if (isset($metadata['gps_lat']) && isset($metadata['gps_lng'])) {
            $result['lat'] = (float) $metadata['gps_lat'];
            $result['lng'] = (float) $metadata['gps_lng'];
            return $result;
        }

        // Fall back to legacy parsing
        return $this->parseGpsCoordinates($metadata);
    }

    /**
     * Parse GPS coordinates from EXIF (legacy fallback)
     */
    protected function parseGpsCoordinates(array $metadata): array
    {
        $result = ['lat' => null, 'lng' => null];

        if (empty($metadata['GPSLatitude']) || empty($metadata['GPSLongitude'])) {
            return $result;
        }

        try {
            $lat = $this->gpsToDecimal(
                $metadata['GPSLatitude'],
                $metadata['GPSLatitudeRef'] ?? 'N'
            );

            $lng = $this->gpsToDecimal(
                $metadata['GPSLongitude'],
                $metadata['GPSLongitudeRef'] ?? 'E'
            );

            if ($lat !== null && $lng !== null) {
                $result['lat'] = $lat;
                $result['lng'] = $lng;
            }
        } catch (\Exception $e) {
            \Log::debug('GPS parsing failed', ['error' => $e->getMessage()]);
        }

        return $result;
    }

    /**
     * Convert GPS EXIF format to decimal degrees
     */
    protected function gpsToDecimal($coordinate, string $hemisphere): ?float
    {
        if (is_string($coordinate)) {
            $coordinate = explode(',', $coordinate);
        }

        if (!is_array($coordinate) || count($coordinate) < 3) {
            return null;
        }

        $degrees = $this->evalFraction($coordinate[0]);
        $minutes = $this->evalFraction($coordinate[1]);
        $seconds = $this->evalFraction($coordinate[2]);

        $decimal = $degrees + ($minutes / 60) + ($seconds / 3600);

        if (in_array($hemisphere, ['S', 'W'])) {
            $decimal *= -1;
        }

        return round($decimal, 8);
    }

    /**
     * Evaluate EXIF fraction string (e.g., "1/250")
     */
    protected function evalFraction($value): float
    {
        if (is_numeric($value)) {
            return (float) $value;
        }

        if (is_string($value) && str_contains($value, '/')) {
            [$numerator, $denominator] = explode('/', $value);
            return $denominator > 0 ? (float) $numerator / (float) $denominator : 0;
        }

        return 0;
    }

    /**
     * Cleanup temporary files and proxy record
     */
    protected function cleanup(ProxyImage $proxy): void
    {
        try {
            $tempDisk = $this->storageConfig['temp_disk'] ?? 'local';

            \Log::info('Cleaning up proxy files', [
                'proxy_uuid' => $proxy->uuid,
                'temp_path' => $proxy->temp_path,
                'thumbnail_path' => $proxy->thumbnail_path,
                'preview_path' => $proxy->preview_path,
            ]);

            // Delete temp file
            if (Storage::disk($tempDisk)->exists($proxy->temp_path)) {
                Storage::disk($tempDisk)->delete($proxy->temp_path);
            }

            // Delete thumbnail
            if ($proxy->thumbnail_path && Storage::disk($tempDisk)->exists($proxy->thumbnail_path)) {
                Storage::disk($tempDisk)->delete($proxy->thumbnail_path);
            }

            // Delete preview
            if ($proxy->preview_path && Storage::disk($tempDisk)->exists($proxy->preview_path)) {
                Storage::disk($tempDisk)->delete($proxy->preview_path);
            }

            // Delete proxy record
            $proxy->delete();

            \Log::info('Proxy cleanup completed', [
                'proxy_uuid' => $proxy->uuid,
            ]);
        } catch (\Exception $e) {
            \Log::error('Proxy cleanup failed', [
                'proxy_uuid' => $proxy->uuid,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
