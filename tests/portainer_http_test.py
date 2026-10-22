"""Exercise the Cloudflared boundary from trusted and untrusted containers."""
import json
import sys
import urllib.error
import urllib.request

trusted = sys.argv[1] == 'trusted'


def request(path, headers=None):
    headers = {'Host': 'stock.example.test', **(headers or {})}
    client = urllib.request.build_opener(urllib.request.ProxyHandler({}))
    try:
        result = client.open(urllib.request.Request('http://gstockbl-origin:8080' + path, headers=headers), timeout=15)
    except urllib.error.HTTPError as error:
        result = error
    return result.status, result.headers, result.read()


status, headers, body = request('/api/v1/auth/csrf', {'X-Forwarded-Proto': 'https'})
assert status == (200 if trusted else 400), (status, body[:100])
if trusted:
    assert json.loads(body)['token']
    cookie = ';'.join(headers.get_all('Set-Cookie', [])).lower()
    assert 'secure' in cookie and 'httponly' in cookie and 'samesite=lax' in cookie
    assert request('/connexion', {'X-Forwarded-Proto': 'https'})[0] == 200
    assert request('/api/v1/auth/csrf', {'X-Forwarded-Proto': 'https', 'Host': 'attacker.example.test'})[0] == 400
    assert request('/api/v1/auth/csrf', {'X-Forwarded-Proto': 'https', 'X-Forwarded-Host': 'attacker.example.test'})[0] == 200
status, headers, _ = request('/api/v1/auth/csrf')
assert status == 400 and not headers.get_all('Set-Cookie')
for path in ('/.env', '/.secrets/app_secret', '/docker/portainer/setup.py'):
    status, _, body = request(path, {'X-Forwarded-Proto': 'https'})
    assert status in (302, 400, 403, 404)
    assert b'APP_SECRET=' not in body
print('Frontière Cloudflared vérifiée : ' + ('proxy autorisé' if trusted else 'autre conteneur refusé'))
