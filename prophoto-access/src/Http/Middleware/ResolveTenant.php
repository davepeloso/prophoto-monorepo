<?php

declare(strict_types=1);

namespace ProPhoto\Access\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use ProPhoto\Access\Models\Studio;
use ProPhoto\Access\Support\TenantContext;
use Symfony\Component\HttpFoundation\Response;

final class ResolveTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        // Important for tests/queues; avoids leaking context between requests
        TenantContext::clear();

        $host = strtolower((string) $request->getHost());
        $base = strtolower((string) config('prophoto.base_domain', env('APP_BASE_DOMAIN', 'prophoto.com')));

        // Non-tenant hosts (marketing or central app)
        if ($host === $base || $host === "www.$base") {
            return $next($request);
        }

        // Subdomain tenancy: {studio}.prophoto.com
        if (str_ends_with($host, ".$base")) {
            $sub = substr($host, 0, -1 * (strlen($base) + 1)); // strip ".base"

            if ($sub === '' || in_array($sub, (array) config('prophoto.reserved_subdomains', []), true)) {
                return $next($request);
            }

            $studio = Studio::query()->where('slug', $sub)->first();

            if (! $studio) {
                abort(404, 'Studio not found.');
            }

            TenantContext::setStudio($studio);
            return $next($request);
        }

        // Unknown host (future: custom domains table lookup here)
        abort(404);
    }
}
