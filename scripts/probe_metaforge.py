#!/usr/bin/env python3
"""Read-only probe of the uploaded native MetaForge API, independent of the Cement proxy.
Credentials are supplied through environment settings; no secrets or record data are printed.
"""
import argparse
import http.cookiejar
import json
import os
import urllib.error
import urllib.parse
import urllib.request

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--url', required=True)
parser.add_argument('--local-http', action='store_true', help='Permit HTTP only for an isolated loopback test installation')
args = parser.parse_args()
url = urllib.parse.urlsplit(args.url)
if url.scheme != 'https' and not (args.local_http and url.scheme == 'http' and url.hostname in ('127.0.0.1', '::1', 'localhost')):
    parser.error('Use HTTPS; --local-http is restricted to loopback test installations')
if url.username or url.password or url.query or url.fragment:
    parser.error('Use a base URL without credentials, query or fragment')

class NoRedirects(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *unused):
        return None

client = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()), NoRedirects())
csrf = {}
def call(path, body=None, expected=200):
    global csrf
    headers = {'Accept': 'application/json'}
    if body is not None:
        headers.update({'Content-Type': 'application/json', csrf['header']: csrf['hash']})
    request = urllib.request.Request(args.url.rstrip('/')+'/api/'+path,
        data=None if body is None else json.dumps(body).encode(), headers=headers)
    try:
        response = client.open(request, timeout=20)
    except urllib.error.HTTPError as error:
        response = error
    if response.status != expected:
        raise RuntimeError(path+': unexpected HTTP status '+str(response.status))
    payload = json.load(response)
    assert isinstance(payload, dict), 'Expected a JSON object'
    csrf = payload.get('csrf', csrf)
    assert isinstance(csrf.get('hash'), str) and isinstance(csrf.get('header'), str), 'Missing native CSRF contract'
    print('PASS', path, 'HTTP', response.status)
    return payload

call('bootstrap')
email, password = os.environ.get('MF_PROBE_EMAIL'), os.environ.get('MF_PROBE_PASSWORD')
if not email or not password:
    raise SystemExit('Bootstrap verified. Configure MF_PROBE_EMAIL and MF_PROBE_PASSWORD securely for authenticated checks.')
session = call('auth/login', {'email': email, 'password': password})
if not session.get('authenticated'):
    raise SystemExit('Authentication requires '+str(session.get('next'))+'; complete that flow in MetaForge before probing again.')
metadata = call('bootstrap')
assert isinstance(metadata.get('recordTypes'), list), 'Missing authorized metadata'
print('Authorized RecordTypes:', len(metadata['recordTypes']))
sync = call('sync')
assert isinstance(sync.get('records'), dict) and 'cursor' in sync and 'complete' in sync, 'Unsupported sync envelope'
# Deliberately invalid, empty request: exercises authenticated validation without writing records.
rejected = call('mutations', {}, expected=422)
assert rejected.get('success') is False and rejected.get('errors'), 'Invalid mutation was not rejected'
call('auth/logout', {})
print('Native session / CSRF / authorized metadata / sync / mutation rejection verified.')
print('This probe does not certify Cement business services, schema mapping or deployed email delivery.')
