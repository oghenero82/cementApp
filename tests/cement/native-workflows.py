#!/usr/bin/env python3
"""Writes uniquely named test records to an isolated native Cement/MetaForge deployment."""
import urllib.request, urllib.error, urllib.parse, http.cookiejar, json, uuid, os
base=os.environ['CEMENT_NATIVE_TEST_URL'].rstrip('/')
parts=urllib.parse.urlsplit(base)
if parts.scheme!='https' and not(parts.scheme=='http' and parts.hostname in ('127.0.0.1','localhost','::1')):raise SystemExit('Use HTTPS or an isolated loopback endpoint')
client=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()));csrf=''
def call(path,body=None,expected=200):
 global csrf
 h={'Content-Type':'application/json','X-Cement-CSRF':csrf}
 try:r=client.open(urllib.request.Request(base+'/api/'+path,headers=h,data=None if body is None else json.dumps(body).encode()),timeout=30)
 except urllib.error.HTTPError as error:r=error
 d=json.load(r);csrf=d.get('csrfToken',csrf)
 if r.status!=expected:raise AssertionError(path+': unexpected HTTP '+str(r.status))
 return d
call('auth/session');call('auth/login',{'email':os.environ['CEMENT_NATIVE_TEST_EMAIL'],'password':os.environ['CEMENT_NATIVE_TEST_PASSWORD']});meta=call('meta/field-registry')
code='PO-'+str(uuid.uuid4())[:8]
def entry(t,d,ref=None):
 e={'recordTypeCode':t,'recordTypeId':meta['recordTypeIds'][t],'operation':'insert','data':{meta['fieldsByRecordType'][t][k]:v for k,v in d.items()}}
 if ref:e['clientRef']=ref
 return e
def batch(items):return {'requestId':str(uuid.uuid4()),'registryRevision':meta['revision'],'batch':items}
masters=call('mutation',batch([entry('supplier',{'CODE':code,'NAME':'Purchase supplier','STATUS':'active'},'supplier'),entry('unit_of_measure',{'CODE':code+'-BAG','NAME':'Bag','SYMBOL':code,'DIMENSION':'count','STATUS':'active'},'unit'),entry('product_category',{'CODE':code+'-CAT','NAME':'Cement','STATUS':'active'},'category'),entry('product',{'CODE':code+'-PROD','NAME':'Cement','CATEGORY_ID':{'$ref':'category'},'BASE_UNIT_ID':{'$ref':'unit'},'TRACKING_MODE':'quantity','INVENTORY_TRACKED':True,'TAXABLE':False,'STATUS':'active'},'product')]))
f=masters['resultMap']
e=batch([entry('purchase',{'CODE':code+'-PUR','SUPPLIER_ID':f['supplier'],'PURCHASE_DATE':'2026-11-17','CURRENCY':'NGN','STATUS':'draft'},'purchase'),entry('purchaseItem',{'CODE':code+'-LINE','PURCHASE_ID':{'$ref':'purchase'},'PRODUCT_ID':f['product'],'UNIT_ID':f['unit'],'ORIGINAL_QUANTITY':100,'UNIT_PRICE':100,'DISCOUNT_TYPE':'percent','DISCOUNT_VALUE':10,'STATUS':'draft'},'line')])
r=call('mutation',e)
root=next(row for row in r['changes'] if row['recordTypeCode']=='purchase');line=next(row for row in r['changes'] if row['recordTypeCode']=='purchaseItem')
pf=meta['fieldsByRecordType']['purchase'];lf=meta['fieldsByRecordType']['purchaseItem']
assert root['data'][pf['NET_AMOUNT']]==9000 and root['data'][pf['GROSS_AMOUNT']]==10000
assert line['data'][lf['NET_UNIT_COST']]==90 and line['data'][lf['QUANTITY_BASE']]==100
assert call('mutation',e)['mutationId']==r['mutationId'];print('PASS native purchase and item drafts: arithmetic, generated snapshots, parent rollup and replay')
# A late constraint failure must roll back even a successful earlier parent insert.
badcode=code+'-BAD'
bad=batch([entry('supplier',{'CODE':badcode,'NAME':'Must roll back','STATUS':'active'},'parent'),entry('supplier',{'CODE':code,'NAME':'Duplicate','STATUS':'active'},'duplicate')])
call('mutation',bad,422)
page=call('sync?cursor=0');rows=page['records']
while page['hasMore']:
 page=call('sync?'+urllib.parse.urlencode({'cursor':page['cursor']}));rows+=page['records']
assert not any(row['recordTypeCode']=='supplier' and row['data'][meta['fieldsByRecordType']['supplier']['CODE']]==badcode for row in rows)
print('PASS native late-failure rollback')
# Funding must remain disabled until a verified posting handler exists.
fund={'requestId':str(uuid.uuid4()),'registryRevision':meta['revision'],'recordTypeCode':'purchase','recordTypeId':meta['recordTypeIds']['purchase'],'recordId':root['recordId'],'version':root['version'],'operation':'update','data':{pf['STATUS']:'paid'}}
call('mutation',fund,422)
print('PASS native financial state cannot be fabricated')
call('auth/logout',{})
