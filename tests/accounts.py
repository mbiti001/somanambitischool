"""Integration checks against a disposable development server; never uses real email."""
from trusted_fixture import trusted_fixture
import json, urllib.request, urllib.error, http.cookiejar, pathlib, re, sqlite3, time
BASE='http://127.0.0.1:8787'
ROOT=pathlib.Path(__file__).resolve().parents[1]
PASS='Sample-test-password-742!'
COURSE=json.loads((ROOT/'private/legacy-investing-course.json').read_text())
def quiz_answers(i):return [q['correct'] for q in COURSE['lessons'][i]['questions']]
def finish_chapter(device,i):
    result=None
    for unit in COURSE['lessons'][i]['units']:
        version=device.get()[1]['account']['version']
        result=device.post({'action':'section','version':version,'lesson':i,'unit':unit['id'],'answers':[q['correct'] for q in unit['questions']]})
    return result
count=0
class Device:
    def __init__(self):
        self.jar=http.cookiejar.CookieJar();self.opener=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar));self.csrf='';self.get()
    def call(self,path='api.php',body=None,headers=None):
        h={'Origin':BASE}
        if body is not None:h.update({'Content-Type':'application/json','X-CSRF-Token':self.csrf})
        if headers:h.update(headers)
        if body and body.get('action')=='login':trusted_fixture(self.opener,ROOT,BASE,body.get('email'))
        req=urllib.request.Request(BASE+'/'+path,data=json.dumps(body).encode() if body is not None else None,headers=h)
        try:r=self.opener.open(req)
        except urllib.error.HTTPError as e:r=e
        data=json.loads(r.read());self.csrf=data.get('csrf',self.csrf);return r.status,data
    def get(self):return self.call()
    def post(self,body,status=200):
        code,data=self.call(body=body);assert code==status,(code,data);return data

def passed(name):
    global count
    count+=1;print('PASS:',name)
def mail_token(email,purpose):
    messages=[json.loads(s) for s in (ROOT/'.local/mail-outbox.jsonl').read_text().splitlines()]
    m=next(m for m in reversed(messages) if m['to']==email and ('Verify' if purpose=='verify' else 'Reset') in m['subject'])
    return re.search(r'(?:token=|reset=)([a-f0-9]{64})',m['body']).group(1)
def verify(device,email):device.opener.open(BASE+'/verify.php?token='+mail_token(email,'verify')).read()

stamp=str(time.time_ns());email='alice'+stamp+'@example.test';other='bob'+stamp+'@example.test'
a=Device();b=Device()
assert a.get()[1]['user'] is None
passed('Guest bootstrap and independent device sessions')
code,_=a.call(body={'action':'register'},headers={'X-CSRF-Token':'wrong'});assert code==403
code,_=a.call(body={'action':'register'},headers={'Origin':'https://evil.test'});assert code==403
passed('CSRF and cross-origin requests rejected')
a.post({'action':'register','email':email,'name':'Alice','password':'short'},400)
x=a.post({'action':'register','email':email,'name':'Alice','password':PASS},201)
assert not x['user']['verified'] and x['account'] is None
code,msg=a.call(body={'action':'trade','version':1,'symbol':'SCOM','q':1,'side':'buy'});assert code==403 and msg['unverified']
passed('Sign-up hashes passwords and requires email verification before account saving')
verify(a,email);x=a.get()[1];assert x['user']['verified'] and x['account']['state']['cash']==100000
passed('Email verification activates account')
legacy={'cash':99750,'holdings':{'SCOM':10},'completed':[0],'history':[{'side':'buy','q':10,'s':'SCOM','total':250,'date':'2026-11-12T00:00:00Z'}]}
bad={**legacy,'cash':100000};a.post({'action':'import','state':bad,'version':1},400)
x=a.post({'action':'import','state':legacy,'version':1});assert x['account']['state']['cash']==99750
assert x['account']['state']['completed']==[0]
a.post({'action':'import','state':legacy,'version':2},400)
passed('Browser progress imported once; inconsistent data and overwrite rejected')
b.post({'action':'login','email':email,'password':'incorrect-password'},401)
x=b.post({'action':'login','email':email,'password':PASS});assert x['account']['state']['holdings']['SCOM']==10
passed('Second device signs in and sees the same portfolio and lessons')
b.post({'action':'trade','version':2,'symbol':'SCOM','q':11,'side':'sell'},400)
for q in [0,-1,1.5,True,1000000]:b.post({'action':'trade','version':2,'symbol':'SCOM','q':q,'side':'buy'},400)
b.post({'action':'trade','version':2,'symbol':'SCOM','q':4000,'side':'buy'},400)
x=b.post({'action':'trade','version':2,'symbol':'SCOM','q':4,'side':'sell','price':999999});assert x['account']['state']['cash']==99850 and x['account']['state']['holdings']['SCOM']==6
passed('Trade validation, overspending, overselling, and server-owned prices')
x=a.post({'action':'lesson','version':2,'lesson':1,'answers':quiz_answers(1)},409);assert x['conflict']
a.get();a.post({'action':'lesson','version':3,'lesson':1,'answer':0},400)
x=finish_chapter(a,1);assert x['account']['state']['completed']==[0,1]
a.post({'action':'lesson','version':3,'lesson':1,'answers':quiz_answers(1)},409)
assert b.get()[1]['account']['state']['completed']==[0,1]
passed('Stale devices and duplicate submissions cannot overwrite newer progress')
x=a.post({'action':'section','version':6,'lesson':1,'unit':'review','answers':quiz_answers(1)});assert x['account']['state']['completed']==[0,1]
passed('Repeated lesson completion is not counted twice')
c=Device();c.post({'action':'register','email':other,'name':'Bob','password':PASS},201);verify(c,other);assert c.get()[1]['account']['state']['cash']==100000
x=c.post({'action':'trade','version':1,'symbol':'SCOM','q':2,'side':'buy','user_id':'alice'});assert x['account']['state']['cash']==99950
assert a.get()[1]['account']['state']['cash']==99850
passed('Accounts are isolated and client-supplied user IDs are ignored')
a.post({'action':'forgot','email':email});token=mail_token(email,'reset');newpass='New-sample-password-583!'
x=c.post({'action':'reset_password','token':token,'password':newpass});c.post({'action':'reset_password','token':token,'password':newpass},400)
assert a.get()[1]['user'] is None and b.get()[1]['user'] is None
b.post({'action':'login','email':email,'password':PASS},401)
x=b.post({'action':'login','email':email,'password':newpass});assert x['account']['state']['cash']==99850
passed('Password reset is single use, retains progress, and revokes all previous sessions')
x=b.post({'action':'reset','version':7});assert x['account']['state']['cash']==100000 and x['account']['state']['completed']==[0,1] and not x['account']['state']['history']
b.post({'action':'section','version':8,'lesson':11,'unit':'understand','answers':[q['correct'] for q in COURSE['lessons'][11]['units'][0]['questions']]},400)
x=finish_chapter(b,3);assert x['account']['state']['completed']==[0,1,3]
b.post({'action':'lesson','version':11,'lesson':18,'answer':0},400)
assert a.post({'action':'login','email':email,'password':newpass})['account']['state']['completed']==[0,1,3]
passed('Ordered sections save across devices; locked chapters and unknown IDs are rejected')
b.post({'action':'logout'});assert b.get()[1]['user'] is None
b.post({'action':'trade','version':6,'symbol':'SCOM','q':1,'side':'buy'},401)
passed('Portfolio reset preserves lessons; logout blocks account writes')
# Database-level evidence: only hashes are stored for passwords and recovery links.
con=sqlite3.connect(ROOT/'.local/test.sqlite');stored=con.execute('select password_hash from users where email=?',(email,)).fetchone()[0]
assert stored!=newpass and stored.startswith('$2y$')
assert con.execute('select count(*) from auth_tokens where token_hash=?',(token,)).fetchone()[0]==0
passed('Passwords and recovery tokens are not stored as plaintext')
print(f'All {count} integration checks passed.')
