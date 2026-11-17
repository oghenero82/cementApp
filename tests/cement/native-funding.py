import ssl, datetime
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
# Separate native admin client used only to configure the isolated shared Accounting records.
native=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()),urllib.request.HTTPSHandler(context=ssl.create_default_context(cafile=os.environ.get('CEMENT_NATIVE_TEST_CA'))));nc={}
def direct(path,body=None,expected=200):
 global nc
 h={'Content-Type':'application/json'}
 if body is not None:h[nc['header']]=nc['hash']
 try:q=native.open(urllib.request.Request(os.environ['CEMENT_NATIVE_DIRECT_URL'].rstrip('/')+'/api/'+path,headers=h,data=None if body is None else json.dumps(body).encode()))
 except urllib.error.HTTPError as e:q=e
 d=json.load(q);nc=d.get('csrf',nc)
 if q.status!=expected:print(path,q.status);raise RuntimeError('Native test setup failed')
 return d
b=direct('bootstrap');b=direct('auth/login',{'email':os.environ['CEMENT_NATIVE_TEST_EMAIL'],'password':os.environ['CEMENT_NATIVE_TEST_PASSWORD']});types={r['recordCode']:r['recordId'] for r in b['recordTypes']};code='FUND-'+str(uuid.uuid4())[:8]
def native_insert(t,data):
 d=direct('mutations',{'requestId':str(uuid.uuid4()),'mode':'record_mode','mutations':[{'recordTypeId':types[t],'operation':'insert','data':data}]});return d['records'][0]['record']['recordId']
at=native_insert('account_type',{'code':code+'-AT','name':'Test assets','category':'asset','normalBalance':'debit','status':'active'})
prepaid=native_insert('account',{'code':code+'-PREPAID','name':'Test prepaid','accountTypeId':at,'allowPosting':True,'currencyCode':'NGN','status':'active'})
bank=native_insert('account',{'code':code+'-BANK','name':'Test bank','accountTypeId':at,'allowPosting':True,'currencyCode':'NGN','status':'active'})
today=datetime.datetime.now(datetime.timezone.utc).date();today_text=today.isoformat();current_year=today.year
years=[r for r in direct('records?recordTypeId='+str(types['fiscal_year']))['records'] if r['jsonData']['startDate']<=today_text<=r['jsonData']['endDate']]
if not years:native_insert('fiscal_year',{'code':'NATIVE-'+str(current_year),'name':str(current_year),'startDate':str(current_year)+'-01-01','endDate':str(current_year)+'-12-31','status':'OPEN'})
def entry(t,data,ref=None):
 e={'recordTypeCode':t,'recordTypeId':meta['recordTypeIds'][t],'operation':'insert','data':{meta['fieldsByRecordType'][t][k]:v for k,v in data.items()}}
 if ref:e['clientRef']=ref
 return e
def batch(rows):return {'requestId':str(uuid.uuid4()),'registryRevision':meta['revision'],'batch':rows}
masters=call('mutation',batch([entry('supplier',{'CODE':code,'NAME':'Funding supplier','STATUS':'active'},'supplier'),entry('unit_of_measure',{'CODE':code+'-U','NAME':'Bag','SYMBOL':code,'DIMENSION':'count','STATUS':'active'},'unit'),entry('product_category',{'CODE':code+'-C','NAME':'Cement','STATUS':'active'},'category'),entry('product',{'CODE':code+'-P','NAME':'Cement','CATEGORY_ID':{'$ref':'category'},'BASE_UNIT_ID':{'$ref':'unit'},'TRACKING_MODE':'quantity','INVENTORY_TRACKED':True,'TAXABLE':False,'STATUS':'active'},'product')]))['resultMap']
r=call('mutation',batch([entry('purchase',{'CODE':code+'-PO','SUPPLIER_ID':masters['supplier'],'PAYMENT_ACCOUNT_ID':bank,'PREPAID_ACCOUNT_ID':prepaid,'PURCHASE_DATE':today_text,'CURRENCY':'NGN','STATUS':'draft'},'purchase'),entry('purchaseItem',{'CODE':code+'-LINE','PURCHASE_ID':{'$ref':'purchase'},'PRODUCT_ID':masters['product'],'UNIT_ID':masters['unit'],'ORIGINAL_QUANTITY':100,'UNIT_PRICE':100,'STATUS':'draft'},'line')]))
root=next(row for row in r['changes'] if row['recordTypeCode']=='purchase');pf=meta['fieldsByRecordType']['purchase'];e={'requestId':str(uuid.uuid4()),'registryRevision':meta['revision'],'recordTypeCode':'purchase','recordTypeId':meta['recordTypeIds']['purchase'],'recordId':root['recordId'],'version':root['version'],'operation':'update','data':{pf['STATUS']:'paid'}}
r=call('mutation',e);assert next(row for row in r['changes'] if row['recordTypeCode']=='purchase')['data'][pf['PAID_AMOUNT']]==10000
assert call('mutation',e)['mutationId']==r['mutationId']
journals=direct('records?recordTypeId='+str(types['journal']))['records'];journal=next(j for j in journals if j['jsonData']['code']=='CEMENT-FUND-'+str(root['recordId']));assert journal['jsonData']['status']=='POSTED'
lines=[x['jsonData'] for x in direct('records?recordTypeId='+str(types['journal_line']))['records'] if x['jsonData']['journalId']==journal['recordId']];assert sum(float(x['debit']) for x in lines)==sum(float(x['credit']) for x in lines)==10000
balances=[x['jsonData'] for x in direct('records?recordTypeId='+str(types['account_balance']))['records']];assert any(x['accountId']==prepaid and float(x['closingDebit'])==10000 for x in balances);assert any(x['accountId']==bank and float(x['closingCredit'])==10000 for x in balances)
print('PASS native funding posts one balanced shared journal and shared GL balances; exact replay does not duplicate')
# Posted lines are immutable, including for the developer role.
line=next(x for x in direct('records?recordTypeId='+str(types['journal_line']))['records'] if x['jsonData']['journalId']==journal['recordId'])
direct('mutations',{'requestId':str(uuid.uuid4()),'mode':'record_mode','mutations':[{'recordTypeId':types['journal_line'],'recordId':line['recordId'],'operation':'update','data':{'debit':'999.00'}}]},422)
# A failure after funding must roll back the purchase, journal, lines and balance projections.
bad=call('mutation',batch([entry('purchase',{'CODE':code+'-ROLLBACK','SUPPLIER_ID':masters['supplier'],'PAYMENT_ACCOUNT_ID':bank,'PREPAID_ACCOUNT_ID':prepaid,'PURCHASE_DATE':today_text,'CURRENCY':'NGN','STATUS':'draft'},'purchase'),entry('purchaseItem',{'CODE':code+'-ROLLBACK-LINE','PURCHASE_ID':{'$ref':'purchase'},'PRODUCT_ID':masters['product'],'UNIT_ID':masters['unit'],'ORIGINAL_QUANTITY':100,'UNIT_PRICE':100,'STATUS':'draft'},'line')]))
badroot=next(x for x in bad['changes'] if x['recordTypeCode']=='purchase');fundrow={'recordTypeCode':'purchase','recordTypeId':meta['recordTypeIds']['purchase'],'recordId':badroot['recordId'],'version':badroot['version'],'operation':'update','data':{pf['STATUS']:'paid'}}
call('mutation',batch([fundrow,entry('supplier',{'CODE':code,'NAME':'Duplicate supplier','STATUS':'active'})]),422)
alljournals=direct('records?recordTypeId='+str(types['journal']))['records'];assert not any(x['jsonData']['code']=='CEMENT-FUND-'+str(badroot['recordId']) for x in alljournals)
allbalances=direct('records?recordTypeId='+str(types['account_balance']))['records'];assert next(x['jsonData'] for x in allbalances if x['jsonData']['accountId']==bank)['closingCredit']=='10000.00'
# An unbalanced shared journal must never advance to the posting queue.
year=next(x['jsonData']['fiscalYearId'] for x in alljournals if x['recordId']==journal['recordId'])
unbalanced=native_insert('journal',{'code':code+'-UNBALANCED','transactionDate':today_text,'fiscalYearId':year,'description':'Unbalanced test draft','status':'DRAFT'})
native_insert('journal_line',{'journalId':unbalanced,'lineNumber':1,'accountId':prepaid,'debit':'10.00','credit':'0.00'})
direct('mutations',{'requestId':str(uuid.uuid4()),'mode':'record_mode','mutations':[{'recordTypeId':types['journal'],'recordId':unbalanced,'operation':'update','data':{'status':'WAIT_POSTING'}}]},422)
assert next(x['jsonData']['status'] for x in direct('records?recordTypeId='+str(types['journal']))['records'] if x['recordId']==unbalanced)=='DRAFT'
print('PASS native posting immutability, unbalanced-journal rejection and late-failure rollback of shared ledger and balances')
call('auth/logout',{});direct('auth/logout',{})
