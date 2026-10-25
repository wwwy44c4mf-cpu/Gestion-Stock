// Gestion-Stock: fixed HTTPS origin, never an open proxy.
const PUBLIC_ORIGIN = 'https://gestion-stock.materiel-reseau.workers.dev';
const UPSTREAM_ORIGIN = 'https://gstockbl.anthony-laporte.ovh';
const METHODS = new Set(['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS']);

function failure(status) {
    return new Response('Accès temporairement indisponible. Réessayez plus tard.', {
        status, headers: { 'Content-Type': 'text/plain; charset=utf-8', 'Cache-Control': 'no-store',
            'X-Content-Type-Options': 'nosniff', 'Referrer-Policy': 'no-referrer',
            'Content-Security-Policy': "default-src 'none'; frame-ancestors 'none'" },
    });
}

export default {
    async fetch(request) {
        const url = new URL(request.url);
        if (url.origin !== PUBLIC_ORIGIN || !METHODS.has(request.method)) return failure(404);
        const safe = ['GET', 'HEAD', 'OPTIONS'].includes(request.method);
        const origin = request.headers.get('Origin');
        // Never turn a cross-site write into a trusted same-origin request.
        if (!safe && origin !== null && origin !== PUBLIC_ORIGIN) return failure(403);
        const upstream = new URL(UPSTREAM_ORIGIN);
        upstream.pathname = url.pathname;
        upstream.search = url.search;
        const headers = new Headers(request.headers);
        for (const name of ['Host', 'Forwarded', 'X-Forwarded-Host', 'X-Forwarded-For', 'X-Forwarded-Proto', 'X-Real-IP']) headers.delete(name);
        if (origin === PUBLIC_ORIGIN) headers.set('Origin', UPSTREAM_ORIGIN);
        const referer = headers.get('Referer');
        if (referer) {
            try {
                const ref = new URL(referer);
                if (ref.origin === PUBLIC_ORIGIN) {
                    const mapped = new URL(UPSTREAM_ORIGIN); mapped.pathname = ref.pathname; mapped.search = ref.search;
                    headers.set('Referer', mapped.href);
                } else headers.delete('Referer');
            } catch { headers.delete('Referer'); }
        }
        try {
            const target = new Request(upstream.href, request);
            const outgoing = new Request(target, { headers, redirect: 'manual', cache: 'no-store' });
            // No shared caching of login forms, CSRF tokens, sessions or stock data.
            const response = await fetch(outgoing, { cf: { cacheTtlByStatus: { '100-599': -1 }, cacheEverything: false } });
            if (response.status >= 500) return failure(502);
            const result = new Response(response.body, response);
            const location = result.headers.get('Location');
            if (location) {
                const destination = new URL(location, upstream);
                if (![UPSTREAM_ORIGIN, PUBLIC_ORIGIN].includes(destination.origin)) return failure(502);
                const mapped = new URL(PUBLIC_ORIGIN); mapped.pathname = destination.pathname; mapped.search = destination.search; mapped.hash = destination.hash;
                result.headers.set('Location', mapped.href);
            }
            // Keep all cookies separate; never split Set-Cookie on commas (Expires contains commas).
            if (response.headers.has('Set-Cookie')) {
                const cookies = response.headers.getSetCookie?.() ?? response.headers.getAll?.('Set-Cookie');
                if (!cookies) return failure(502);
                result.headers.delete('Set-Cookie');
                for (const cookie of cookies) {
                    const value = cookie.replace(/;\s*Domain=[^;]*/ig, '');
                    result.headers.append('Set-Cookie', /;\s*Secure(?:;|$)/i.test(value) ? value : value + '; Secure');
                }
            }
            result.headers.set('Cache-Control', 'no-store, private');
            result.headers.set('Cloudflare-CDN-Cache-Control', 'no-store');
            result.headers.set('CDN-Cache-Control', 'no-store');
            result.headers.set('Strict-Transport-Security', 'max-age=31536000');
            result.headers.set('X-Robots-Tag', 'noindex, nofollow');
            result.headers.delete('Refresh');
            // Preserve the app's CSP, Permissions-Policy (camera), HttpOnly and SameSite.
            return result;
        } catch {
            return failure(502); // Never expose the origin URL, request body or credentials in an error.
        }
    },
};
