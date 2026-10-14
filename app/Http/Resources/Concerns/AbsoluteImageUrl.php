<?php

namespace App\Http\Resources\Concerns;

trait AbsoluteImageUrl
{
    /**
     * Turn a stored image path (e.g. "products/x.webp", "categories/y.webp")
     * into a fully resolvable absolute URL by prefixing the configured
     * application URL. Fully-qualified http/https URLs pass through untouched,
     * so remote CDN fallbacks keep working.
     */
    protected function absoluteImageUrl(?string $path): ?string
    {
        if ($path === null || trim($path) === '') {
            return null;
        }

        if (preg_match('#^https?://#i', $path)) {
            return $path;
        }

        return rtrim(config('app.url'), '/').'/storage/'.ltrim($path, '/');
    }
}