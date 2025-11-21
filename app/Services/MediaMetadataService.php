<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;

class MediaMetadataService
{
    /**
     * Extract audio metadata (duration) from an uploaded file
     *
     * @param UploadedFile $file
     * @return array metadata array with 'duration' key if available
     */
    public static function extractAudioMetadata(UploadedFile $file): array
    {
        $metadata = [];
        
        try {
            // Suppress deprecated PHP function warnings from getID3
            $errorLevel = error_reporting();
            error_reporting($errorLevel & ~E_DEPRECATED);
            
            try {
                $getID3 = new \getID3();
                $fileInfo = $getID3->analyze($file->getPathname());
                
                if (isset($fileInfo['playtime_seconds'])) {
                    $metadata['duration'] = $fileInfo['playtime_seconds'];
                }
            } finally {
                // Restore error reporting level
                error_reporting($errorLevel);
            }
        } catch (\Throwable $e) {
            \Log::warning('Failed to extract audio metadata', [
                'error' => $e->getMessage(),
                'file' => $file->getClientOriginalName(),
            ]);
        }
        
        return $metadata;
    }

    /**
     * Extract video metadata (duration) from an uploaded file
     *
     * @param UploadedFile $file
     * @return array metadata array with 'duration' key if available
     */
    public static function extractVideoMetadata(UploadedFile $file): array
    {
        $metadata = [];
        
        try {
            $ffprobe = \FFMpeg\FFProbe::create();
            $duration = $ffprobe
                ->format($file->getPathname())
                ->get('duration');
            
            if ($duration) {
                $metadata['duration'] = floatval($duration);
            }
        } catch (\Throwable $e) {
            \Log::warning('Failed to extract video metadata', [
                'error' => $e->getMessage(),
                'file' => $file->getClientOriginalName(),
            ]);
        }
        
        return $metadata;
    }

    /**
     * Extract image metadata (dimensions) from an uploaded file
     *
     * @param UploadedFile $file
     * @return array metadata array with 'width' and 'height' keys if available
     */
    public static function extractImageMetadata(UploadedFile $file): array
    {
        $metadata = [];
        
        try {
            $dimensions = getimagesize($file->getPathname());
            if ($dimensions) {
                $metadata['width'] = $dimensions[0];
                $metadata['height'] = $dimensions[1];
            }
        } catch (\Throwable $e) {
            \Log::warning('Failed to extract image metadata', [
                'error' => $e->getMessage(),
                'file' => $file->getClientOriginalName(),
            ]);
        }
        
        return $metadata;
    }
}
