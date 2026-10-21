"""Real HTTPS checks using a disposable Caddy CA; never install this CA on the host."""
import http.cookiejar,json,ssl,urllib.request,urllib.error,unittest
ROOT='https://stock.example.test:8443'
class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self,*args): return None
class ProductionTest(unittest.TestCase):
    def setUp(self):
        self.jar=http.cookiejar.CookieJar()
        self.context=ssl.create_default_context(cafile='/tls/caddy/pki/authorities/local/root.crt')
        self.opener=urllib.request.build_opener(urllib.request.ProxyHandler({}),urllib.request.HTTPSHandler(context=self.context),urllib.request.HTTPCookieProcessor(self.jar),NoRedirect())
    def request(self,path,method='GET',body=None,headers=None,root=ROOT):
        h=headers or {}
        if body is not None: body=json.dumps(body).encode();h={'Content-Type':'application/json',**h}
        try: r=self.opener.open(urllib.request.Request(root+path,data=body,headers=h,method=method),timeout=15)
        except urllib.error.HTTPError as e:r=e
        return r.status,r.headers,r.read()
    def csrf(self):
        status,headers,body=self.request('/api/v1/auth/csrf');self.assertEqual(200,status,body.decode(errors='replace')[:500])
        return json.loads(body)['token'],headers
    def test_https_certificate_redirect_and_browser_headers(self):
        status,h,b=self.request('/connexion',headers={'X-Request-Id':'b'*32});self.assertEqual(200,status,b.decode(errors='replace')[:500])
        for key,value in [('X-Content-Type-Options','nosniff'),('X-Frame-Options','DENY'),('Referrer-Policy','same-origin')]:self.assertEqual(value,h[key])
        self.assertIn("script-src 'self'",h['Content-Security-Policy']);self.assertIn('max-age=31536000',h['Strict-Transport-Security'])
        self.assertNotIn('Server',h);self.assertNotIn('X-Powered-By',h)
        self.assertEqual(1,len(h.get_all('X-Request-Id')));self.assertRegex(h['X-Request-Id'],r'^[a-f0-9]{32}$');self.assertNotEqual('b'*32,h['X-Request-Id'])
        status,h,_=self.request('/connexion?retour=stock',root='http://stock.example.test:8080');self.assertEqual(308,status);self.assertEqual('https://stock.example.test/connexion?retour=stock',h['Location'])
    def test_secure_cookie_and_real_production_login(self):
        token,h=self.csrf();cookies=h.get_all('Set-Cookie');self.assertTrue(cookies)
        self.assertIn('secure',';'.join(cookies).lower());self.assertIn('httponly',';'.join(cookies).lower());self.assertIn('samesite=lax',';'.join(cookies).lower())
        s,_,_=self.request('/api/v1/auth/login','POST',{'email':'recovery@example.test','password':'Recovery-fixture-2026!'}, {'X-CSRF-Token':token});self.assertEqual(200,s)
        s,_,b=self.request('/api/v1/stocks');self.assertEqual(200,s);self.assertEqual(8,json.loads(b)['data'][0]['quantity'])
    def test_csrf_and_development_account_are_refused_in_production(self):
        self.csrf();s,_,_=self.request('/api/v1/auth/login','POST',{'email':'recovery@example.test','password':'Recovery-fixture-2026!'});self.assertEqual(403,s)
        token,_=self.csrf();s,_,_=self.request('/api/v1/auth/login','POST',{'email':'admin.dev@example.test','password':'Recovery-fixture-2026!'},{'X-CSRF-Token':token});self.assertEqual(401,s)
    def test_direct_backend_cannot_spoof_https_or_trusted_host(self):
        s,h,_=self.request('/api/v1/auth/csrf',headers={'Host':'stock.example.test','X-Forwarded-Proto':'https','X-Forwarded-For':'127.0.0.1'},root='http://web:8080');self.assertEqual(400,s);self.assertFalse(h.get_all('Set-Cookie'))
        s,_,_=self.request('/api/v1/auth/csrf',headers={'Host':'attacker.test','X-Forwarded-Proto':'https'},root='http://web:8080');self.assertEqual(400,s)
    def test_private_files_and_assets(self):
        for path in ['/.env','/.secrets/app_secret','/composer.json','/docker/production/check.php']:
            s,_,b=self.request(path);self.assertIn(s,[302,403,404]);self.assertNotIn(b'APP_SECRET',b)
        s,h,_=self.request('/assets/app.js');self.assertEqual(200,s);self.assertEqual('nosniff',h['X-Content-Type-Options']);self.assertRegex(h['X-Request-Id'],r'^[a-f0-9]{32}$')
if __name__=='__main__':unittest.main(verbosity=2)
