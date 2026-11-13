"""Local preparation/security checks. Does not call an AI provider or production."""
from trusted_fixture import trusted_fixture
import json,urllib.request,urllib.error,http.cookiejar,pathlib
root=pathlib.Path(__file__).resolve().parents[1];base='http://localhost:8791'
client=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()));csrf=''
def call(path='tutor.php',data=None,status=200,origin=base,token=None,raw=None):
 global csrf
 if data and isinstance(data,dict) and data.get('action')=='login':trusted_fixture(client,root,base,data.get('email'))
 req=urllib.request.Request(base+'/'+path,data=raw if raw is not None else json.dumps(data).encode() if data is not None else None,headers={'Origin':origin,'Content-Type':'application/json','X-CSRF-Token':csrf if token is None else token})
 try:r=client.open(req)
 except urllib.error.HTTPError as e:r=e
 body=json.loads(r.read());assert r.status==status,(r.status,body);csrf=body.get('csrf',csrf);return body
s=call();assert s['enabled'] is False and s['eligible'] is False and 'api_key' not in s
q={'lesson':0,'unit':0,'question':'Explain ownership simply','conversation':'a'*32}
call(data=q,status=403,token='wrong');call(data=q,status=403,origin='https://untrusted.example');call(data=q,status=401)
call(data={**q,'unit':99},status=400);call(data=q,raw=b'{broken',status=400)
course=json.loads((root/'public/course.json').read_text());guard=call(data={**q,'question':course['lessons'][0]['units'][0]['questions'][0]['question']});assert guard['mode']=='study' and '2%' not in guard['text']
fixture=json.loads((root/'.local/full-study-fixture.json').read_text());call('api.php');before=call('api.php',{'action':'login',**fixture})['account']
s=call();assert s['enabled'] is False and s['eligible'] is True
call(data=q,status=503);assert call(data={'action':'clear'})['cleared']
after=call('api.php')['account'];assert before==after
call('api.php',{'action':'logout'})
print('PASS: HTTP same-origin/CSRF/auth, invalid JSON/context, assessment hints, honest disabled mode, clear conversation, account progress unchanged. No real AI call made.')
