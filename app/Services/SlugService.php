<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Model;

class SlugService
{
    /**
     * Convert text to URL-friendly slug format
     * - Converts to lowercase
     * - Replaces spaces and underscores with hyphens
     * - Removes special characters
     * - Collapses multiple hyphens to single hyphen
     * - Removes leading/trailing hyphens
     *
     * @param string $text
     * @return string
     */
    public static function generateSlug(string $text): string
    {
        $text = trim((string)$text);
        
        // Convert to lowercase
        $text = mb_strtolower($text, 'UTF-8');
        
        // Replace spaces and underscores with hyphens
        $text = preg_replace('/[\s_]+/', '-', $text);
        
        // Remove special characters, keep only alphanumeric and hyphens
        $text = preg_replace('/[^a-z0-9-]/', '', $text);
        
        // Replace multiple consecutive hyphens with single hyphen
        $text = preg_replace('/-+/', '-', $text);
        
        // Remove leading and trailing hyphens
        $text = trim($text, '-');
        
        return $text;
    }

    /**
     * Generate a unique slug for a model
     * Handles duplicate slugs by appending a counter
     *
     * @param string $text The text to slugify
     * @param string $modelClass The fully qualified model class name (e.g., 'App\Models\Quiz')
     * @param int|null $excludeId Model ID to exclude from uniqueness check (for updates)
     * @return string
     */
    public static function makeUniqueSlug(string $text, string $modelClass, ?int $excludeId = null): string
    {
        $baseSlug = self::generateSlug($text);
        
        if (empty($baseSlug)) {
            $baseSlug = 'item';
        }

        // Check if slug already exists
        $query = $modelClass::query()->where('slug', $baseSlug);
        
        if ($excludeId !== null) {
            $query->where('id', '!=', $excludeId);
        }

        if (!$query->exists()) {
            return $baseSlug;
        }

        // Slug exists, append counter
        $counter = 1;
        $maxAttempts = 1000;
        
        while ($counter < $maxAttempts) {
            $candidateSlug = "{$baseSlug}-{$counter}";
            
            $query = $modelClass::query()->where('slug', $candidateSlug);
            if ($excludeId !== null) {
                $query->where('id', '!=', $excludeId);
            }
            
            if (!$query->exists()) {
                return $candidateSlug;
            }
            
            $counter++;
        }

        // Fallback: use timestamp
        return "{$baseSlug}-" . time();
    }
}
