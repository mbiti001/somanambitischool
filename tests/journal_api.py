"""Local disposable account only; exercise journal persistence and conflicts."""
import runpy
ctx=runpy.run_path('tests/discipline.py');a,b=ctx['a'],ctx['b']
account=a.call()['account'];version=account['version']
r=a.call({'action':'trade','version':version,'symbol':'SCOM','q':10,'side':'buy'})['account'];trade=r['state']['history'][-1]
r=a.call({'action':'journal_save','version':r['version'],'tradeId':trade['id'],'reason':'Practice decision','risk':'Price may fall','outcome':'Observing','learned':'','reviewed':False})['account'];entry=r['state']['journal'][0]
assert b.call()['account']['state']['journal'][0]['reason']=='Practice decision'
print('PASS: journal appears in another authenticated session')
a.call({'action':'journal_save','version':r['version'],'entryId':'not-my-entry','reason':'Invalid'},400)
a.call({'action':'journal_save','version':r['version'],'tradeId':'not-my-trade','reason':'Invalid'},400)
a.call({'action':'journal_save','version':r['version'],'entryId':entry['id'],'reason':'','reviewed':False},400)
print('PASS: missing/foreign records and blank reasoning rejected')
next_a=a.call({'action':'journal_save','version':r['version'],'entryId':entry['id'],'reason':'Revised decision','learned':'Check assumptions','reviewed':True})['account']
b.call({'action':'journal_save','version':r['version'],'entryId':entry['id'],'reason':'Stale overwrite'},409)
assert b.call()['account']['state']['journal'][0]['reason']=='Revised decision'
print('PASS: edits sync; stale device cannot overwrite reflection')
r=a.call({'action':'reset','version':next_a['version']})['account'];assert r['state']['journal'][0]['reviewed'] and r['state']['cash']==100000 and not r['state']['history']
print('PASS: account portfolio reset preserves journal')
r=a.call({'action':'journal_delete','version':r['version'],'entryId':entry['id']})['account'];assert not b.call()['account']['state']['journal']
print('PASS: journal deletion syncs without altering lessons or cash')
