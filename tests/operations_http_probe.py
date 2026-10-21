import ssl,urllib.request,urllib.error,sys
context=ssl.create_default_context(cafile='/tls/caddy/pki/authorities/local/root.crt')
opener=urllib.request.build_opener(urllib.request.ProxyHandler({}),urllib.request.HTTPSHandler(context=context))
checks=[('http://web:8080/.env?token=private-marker-query',403),('https://stock.example.test:8443/connexion?token=private-marker-query',200)] if sys.argv[1]=='normal' else [('https://stock.example.test:8443/connexion?token=private-marker-query',502)]
for url,expected in checks:
    request=urllib.request.Request(url,headers={'Host':'stock.example.test','Authorization':'Bearer private-marker-auth','Cookie':'sample=private-marker-cookie'})
    try:response=opener.open(request,timeout=15)
    except urllib.error.HTTPError as e:response=e
    assert response.status==expected,(response.status,expected)
    response.read()
print('Sondes HTTP confidentielles vérifiées sans afficher leur contenu.')
