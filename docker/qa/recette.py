"""Real HTTP paths and five independent sessions against disposable synthetic data."""
import concurrent.futures
import datetime as dt
import http.cookiejar
import json
import math
import os
from pathlib import Path
import platform
import threading
import time
import urllib.error
import urllib.parse
import urllib.request
import uuid

OUTPUT = Path('/qa-output')
TARGET = os.environ.get('QA_TARGET', '')
PASSWORD = 'QA-fixture-only-2026!'


def percentile(values, fraction=.95):
    if not values:
        raise ValueError('missing_latency_samples')
    return sorted(values)[math.ceil(len(values) * fraction) - 1]


def measures(samples, limit_ms):
    if len(samples) < 100 or any(not math.isfinite(x) or x < 0 for x in samples):
        raise ValueError('insufficient_or_invalid_latency_samples')
    p95 = percentile(samples)
    return {'samples': len(samples), 'p50_ms': round(percentile(samples, .5), 3),
            'p95_ms': round(p95, 3), 'max_ms': round(max(samples), 3),
            'limit_ms': limit_ms, 'passed': p95 < limit_ms}


class Client:
    def __init__(self, user=None):
        self.user = user
        self.csrf = None
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))

    def request(self, method, path, body=None, key=None):
        headers = {'Accept': 'application/json'}
        if body is not None:
            headers['Content-Type'] = 'application/json'
        if method not in ('GET', 'HEAD'):
            headers['X-CSRF-Token'] = self.csrf or ''
            headers['Idempotency-Key'] = key or 'qa-' + uuid.uuid4().hex
        request = urllib.request.Request(TARGET + path, data=None if body is None else json.dumps(body).encode(), headers=headers, method=method)
        start = time.perf_counter()
        try:
            response = self.opener.open(request, timeout=15)
        except urllib.error.HTTPError as error:
            response = error
        with response:
            raw = response.read()
            status = response.status
        elapsed = (time.perf_counter() - start) * 1000
        try:
            payload = json.loads(raw)
        except (ValueError, UnicodeDecodeError):
            raise RuntimeError('non_json_api_response') from None
        return status, payload, elapsed

    def expect(self, method, path, body=None, expected=(200,), key=None):
        status, payload, elapsed = self.request(method, path, body, key)
        if status not in expected:
            route = '/'.join('id' if len(part) == 36 else part for part in path.split('?')[0].split('/'))
            raise RuntimeError(method + '_' + route + '_status_' + str(status))
        return payload, elapsed

    def login(self):
        self.csrf = self.expect('GET', '/api/v1/auth/csrf')[0]['token']
        self.expect('POST', '/api/v1/auth/login', {'email': self.user['email'], 'password': PASSWORD})
        self.csrf = self.expect('GET', '/api/v1/auth/csrf')[0]['token']


def stock(client, store, article):
    data, _ = client.expect('GET', '/api/v1/stocks?' + urllib.parse.urlencode({'store_id': store, 'article_id': article}))
    rows = data['data']
    if len(rows) != 1:
        raise RuntimeError('expected_single_stock_row')
    return rows[0]['quantity']


def smoke(fixture, clients):
    admin, manager_a, manager_b, technician_a, technician_b = clients
    a, b = fixture['stores']['A'], fixture['stores']['B']
    article = fixture['articles'][19]
    cases = []

    def passed(code):
        cases.append({'case': code, 'passed': True})

    Client().expect('GET', '/api/v1/stocks', expected=(401,))
    passed('REC-01')
    before = stock(admin, b, article)
    technician_a.expect('POST', '/api/v1/movements/consumptions', {'store_id': b, 'article_id': article, 'quantity': 1}, expected=(403, 404))
    assert stock(admin, b, article) == before
    passed('REC-02')
    assert stock(admin, a, article) == 5
    entry, _ = admin.expect('POST', '/api/v1/movements/entries', {'store_id': a, 'article_id': article, 'quantity': 10, 'reason': 'Réception QA fictive'}, expected=(201,))
    assert stock(admin, a, article) == 15
    passed('REC-03')
    consumption, _ = technician_a.expect('POST', '/api/v1/movements/consumptions', {'store_id': a, 'article_id': article, 'quantity': 4}, expected=(201,))
    assert stock(admin, a, article) == 11
    assert consumption['data']['consumer_id'] == technician_a.user['id']
    passed('REC-04')
    technician_a.expect('POST', '/api/v1/movements/consumptions', {'store_id': a, 'article_id': article, 'quantity': 20}, expected=(409,))
    assert stock(admin, a, article) == 11
    passed('REC-05')
    admin.expect('POST', '/api/v1/movements/consumptions', {'store_id': a, 'article_id': article, 'quantity': 10}, expected=(201,))
    barrier = threading.Barrier(2)

    def last_unit(client):
        barrier.wait(timeout=10)
        return client.request('POST', '/api/v1/movements/consumptions', {'store_id': a, 'article_id': article, 'quantity': 1})[0]

    with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
        results = list(pool.map(last_unit, (manager_a, technician_a)))
    assert sorted(results) == [201, 409]
    assert stock(admin, a, article) == 0
    passed('REC-06')
    admin.expect('POST', '/api/v1/movements/entries', {'store_id': a, 'article_id': article, 'quantity': 5, 'reason': 'Préparation QA transfert'}, expected=(201,))
    before_a, before_b = stock(admin, a, article), stock(admin, b, article)
    admin.expect('POST', '/api/v1/movements/transfers', {'source_store_id': a, 'destination_store_id': b, 'article_id': article, 'quantity': 3}, expected=(201,))
    assert stock(admin, a, article) == before_a - 3
    assert stock(admin, b, article) == before_b + 3
    passed('REC-07')
    body = {'store_id': a, 'article_id': article, 'quantity': 1}
    first, _ = technician_a.expect('POST', '/api/v1/movements/consumptions', body, expected=(201,), key='qa-http-idempotence')
    second, _ = technician_a.expect('POST', '/api/v1/movements/consumptions', body, expected=(201,), key='qa-http-idempotence')
    assert first['data']['id'] == second['data']['id']
    assert stock(admin, a, article) == before_a - 4
    passed('REC-14')
    admin.expect('POST', '/api/v1/movements/entries', {'store_id': a, 'article_id': article, 'quantity': 9, 'reason': 'Préparation QA inventaire'}, expected=(201,))
    assert stock(admin, a, article) == 10
    campaign, _ = manager_a.expect('POST', '/api/v1/inventories', {'store_id': a}, expected=(201,))
    inventory = campaign['data']['id']
    manager_a.expect('POST', '/api/v1/inventories/' + inventory + '/start')
    technician_a.expect('POST', '/api/v1/movements/consumptions', body, expected=(409,))
    lines = manager_a.expect('GET', '/api/v1/inventories/' + inventory + '/lines?limit=100')[0]['data']
    assert len(lines) == 20
    counts = [{'article_id': line['article_id'], 'counted_qty': 8 if line['article_id'] == article else line['theoretical_qty'], 'expected_version': line['version']} for line in lines]
    manager_a.expect('POST', '/api/v1/inventories/' + inventory + '/lines', {'lines': counts})
    manager_a.expect('POST', '/api/v1/inventories/' + inventory + '/submit')
    admin.expect('POST', '/api/v1/inventories/' + inventory + '/validate')
    assert stock(admin, a, article) == 8
    passed('REC-10')
    passed('EXTRA-inventory-freeze')
    for path in ('/api/v1/stocks', '/api/v1/consumptions', '/api/v1/statistics/consumptions', '/api/v1/dashboard'):
        manager_a.expect('GET', path + '?store_id=' + b, expected=(403, 404))
    technician_a.expect('GET', '/api/v1/dashboard?store_id=' + a, expected=(403,))
    passed('REC-12')
    before = stock(admin, a, article)
    technician_a.expect('POST', '/api/v1/movements/returns', {'consumption_id': consumption['data']['id'], 'quantity': 1}, expected=(201,))
    technician_a.expect('POST', '/api/v1/movements/returns', {'consumption_id': consumption['data']['id'], 'quantity': 4}, expected=(409,))
    assert stock(admin, a, article) == before + 1
    passed('EXTRA-return-cap')
    correction, _ = admin.expect('POST', '/api/v1/movements/' + entry['data']['id'] + '/correct', {'quantity': 8, 'reason': 'Correction QA fictive'}, expected=(201,))
    assert correction['data']['replacement']['quantity'] == 8
    assert stock(admin, a, article) == before - 1
    passed('EXTRA-entry-correction')
    original = admin.expect('GET', '/api/v1/articles/' + article)[0]['data']
    proposal, _ = manager_a.expect('POST', '/api/v1/article-proposals', {'operation': 'update', 'article_id': article, 'base_version': original['version'], 'payload': {'description': 'Proposition QA fictive'}}, expected=(201,))
    assert admin.expect('GET', '/api/v1/articles/' + article)[0]['data']['version'] == original['version']
    admin.expect('POST', '/api/v1/article-proposals/' + proposal['data']['id'] + '/review', {'decision': 'approved'})
    assert admin.expect('GET', '/api/v1/articles/' + article)[0]['data']['description'] == 'Proposition QA fictive'
    passed('EXTRA-catalog-validation')
    admin.expect('PATCH', '/api/v1/users/' + technician_a.user['id'], {'active': False})
    technician_a.expect('GET', '/api/v1/stocks', expected=(401,))
    admin.expect('PATCH', '/api/v1/users/' + technician_a.user['id'], {'active': True})
    technician_a.login()
    passed('REC-18')
    return {'status': 'passed', 'checks': cases, 'real_http': True}


def benchmark(fixture, clients):
    # Warm-up is excluded from timings. Sessions and CSRF tokens remain independent.
    today = dt.datetime.now(dt.timezone.utc).date()
    dates = '&date_from=' + (today - dt.timedelta(days=29)).isoformat() + '&date_to=' + today.isoformat()
    barrier = threading.Barrier(5)

    def paths_for(client):
        store = client.user['store_id']
        paths = {'stock_list': '/api/v1/stocks?store_id=' + store + '&limit=25',
                 'consumption_history': '/api/v1/consumptions?store_id=' + store + '&limit=25',
                 'statistics': '/api/v1/statistics/consumptions?store_id=' + store + dates}
        if client.user['role'] in (1, 2):
            paths['dashboard'] = '/api/v1/dashboard?store_id=' + store + dates
        return paths

    for client in clients:
        for path in paths_for(client).values():
            client.expect('GET', path)

    def actor_run(client):
        store = client.user['store_id']
        paths = paths_for(client)
        samples = {name: [] for name in (*paths, 'consumption_write')}
        barrier.wait(timeout=30)
        for _ in range(40):
            for name, path in paths.items():
                _, elapsed = client.expect('GET', path)
                samples[name].append(elapsed)
            _, elapsed = client.expect('POST', '/api/v1/movements/consumptions', {'store_id': store, 'article_id': fixture['articles'][0], 'quantity': 1}, expected=(201,))
            samples['consumption_write'].append(elapsed)
        return samples

    start = time.perf_counter()
    with concurrent.futures.ThreadPoolExecutor(max_workers=5) as pool:
        results = list(pool.map(actor_run, clients))
    merged = {name: [sample for result in results for sample in result.get(name, [])] for name in results[0]}
    timings = {name: measures(values, 3000 if name == 'consumption_write' else 2000) for name, values in merged.items()}
    return {'status': 'passed' if all(row['passed'] for row in timings.values()) else 'failed',
            'timestamp_utc': dt.datetime.now(dt.timezone.utc).isoformat(),
            'concurrent_sessions': 5, 'cycles_per_session': 40, 'measured_requests': sum(len(values) for values in merged.values()),
            'warmup_reads': sum(len(paths_for(client)) for client in clients), 'duration_seconds': round(time.perf_counter() - start, 3),
            'history_days': 360, 'historical_movements_per_day': 10, 'historical_movements': 3600,
            'initial_entries': 40, 'stores': 2, 'articles': 20, 'architecture': platform.machine(),
            'transport': 'HTTP interne Docker, PHP prod, debug désactivé', 'runtime': fixture['runtime'],
            'docker_host': json.loads((OUTPUT / 'host.json').read_text()), 'endpoints': timings}


def main():
    if os.environ.get('QA_FIXTURE') != '1' or TARGET != 'http://web:8080':
        raise RuntimeError('isolated_qa_target_required')
    fixture = json.loads((OUTPUT / 'fixture.json').read_text())
    clients = [Client(user) for user in fixture['users']]
    for client in clients:
        client.login()
    smoke_report = smoke(fixture, clients)
    (OUTPUT / 'http-recette.json').write_text(json.dumps(smoke_report, indent=2) + '\n')
    print('HTTP business checks passed: ' + ', '.join(row['case'] for row in smoke_report['checks']))
    report = benchmark(fixture, clients)
    (OUTPUT / 'performance.json').write_text(json.dumps(report, indent=2, ensure_ascii=False) + '\n')
    print(json.dumps(report, ensure_ascii=False))
    if report['status'] != 'passed':
        raise RuntimeError('performance_threshold_exceeded')


if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        (OUTPUT / 'failure.json').write_text(json.dumps({'status': 'failed', 'error_class': type(error).__name__, 'code': str(error) if isinstance(error, (AssertionError, RuntimeError)) else 'qa_probe_failed'}) + '\n')
        raise SystemExit('QA failed; sanitized diagnostic written to failure.json') from None
