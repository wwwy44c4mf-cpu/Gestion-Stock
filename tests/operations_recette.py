import json,pathlib,re,sys
mode=sys.argv[1]
if mode=='report':
    data=json.loads(pathlib.Path(sys.argv[2]).read_text())
    if sys.argv[3]=='worker':
        assert data['queues']['notifications']['due']==1,data
    elif sys.argv[3]=='database':
        assert data['status']=='unavailable' and data['code']=='database_or_schema_unavailable',data
    elif sys.argv[3]=='smtp':
        assert data['notifications']['failed']==1,data
        assert data['delivery_last_24h']['smtp_failures']==1,data
        assert 'notification_failures' in data['signals'],data
        assert data['status']=='degraded',data
    assert 'private-marker' not in json.dumps(data)
elif mode=='logs':
    text=pathlib.Path(sys.argv[2]).read_text()
    assert 'private-marker' not in text and 'recovery@example.test' not in text,text
    records=[];all_rows=[]
    for line in text.splitlines():
        # Compose prefixes are stripped without evaluating or printing log content.
        pos=line.find('{')
        if pos>=0:
            try: row=json.loads(line[pos:])
            except json.JSONDecodeError:continue
            all_rows.append(row)
            if 'event' in row:records.append(row)
    assert any(r['event']=='message_failed' for r in records)
    assert any(r['event']=='notification_delivery' and r.get('result_code')=='smtp_delivery_failed' for r in records)
    assert all(re.match(r'^\d{4}-\d\d-\d\dT.*(?:Z|\+00:00)$',r['timestamp']) for r in records)
    assert any(r.get('event')=='http_access' and r.get('status')==403 and r.get('level')=='warning' for r in records)
    assert any(r.get('status')==502 and r.get('logger','').startswith('http.log.access') and 'request' not in r and 'resp_headers' not in r for r in all_rows)
print('Diagnostic et absence de données sensibles vérifiés.')
