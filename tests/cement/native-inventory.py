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
b=direct('bootstrap');b=direct('auth/login',{'email':os.environ['CEMENT_NATIVE_TEST_EMAIL'],'password':os.environ['CEMENT_NATIVE_TEST_PASSWORD']});types={r['recordCode']:r['recordId'] for r in b['recordTypes']};code='INV-'+str(uuid.uuid4())[:8]
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

inventory=native_insert('account',{'code':code+'-STOCK','name':'Test stock GL','accountTypeId':at,'allowPosting':True,'currencyCode':'NGN','status':'active'})
expense_type=native_insert('account_type',{'code':code+'-EXP-TYPE','name':'Test variance','category':'expense','normalBalance':'debit','status':'active'})
variance=native_insert('account',{'code':code+'-VAR','name':'Test stock variance','accountTypeId':expense_type,'allowPosting':True,'currencyCode':'NGN','status':'active'})
policy=os.environ.get('CEMENT_NATIVE_TEST_POLICY','fifo');assert policy in ('fifo','weighted_average')
remaining_value=1000 if policy=='fifo' else 750
settings=dict(b['institution']['jsonData'].get('settings',{}));settings.update(inventoryValuationMethod=policy,cementInventoryAccountId=inventory,cementStockVarianceAccountId=variance)
direct('mutations',{'requestId':str(uuid.uuid4()),'mode':'record_mode','mutations':[{'recordTypeId':b['institution']['recordTypeId'],'recordId':b['institution']['recordId'],'operation':'update','data':{'settings':settings}}]})
meta=call('meta/field-registry')
master=call('mutation',batch([entry('unit_of_measure',{'CODE':code+'-UNIT','NAME':'Bag','SYMBOL':code,'DIMENSION':'count','STATUS':'active'},'unit'),entry('product_category',{'CODE':code+'-CAT','NAME':'Cement','STATUS':'active'},'category'),entry('product',{'CODE':code+'-PROD','NAME':'Cement','CATEGORY_ID':{'$ref':'category'},'BASE_UNIT_ID':{'$ref':'unit'},'TRACKING_MODE':'quantity','INVENTORY_TRACKED':True,'TAXABLE':False,'STATUS':'active'},'product'),entry('inventory_location',{'CODE':code+'-W1','NAME':'Test warehouse 1','LOCATION_TYPE':'warehouse','STATUS':'active'},'warehouse'),entry('inventory_location',{'CODE':code+'-W2','NAME':'Test warehouse 2','LOCATION_TYPE':'warehouse','STATUS':'active'},'other')]))['resultMap']
def movement(suffix,qty,cost=0,source=None,destination=None,status='confirmed'):
 d={'CODE':code+'-'+suffix,'PRODUCT_ID':master['product'],'MOVEMENT_DATE':today_text+'T00:00:00Z','MOVEMENT_TYPE':'manual_test','STOCK_CONDITION':'good','QTY_BASE':qty,'COST_POLICY':policy,'UNIT_COST':cost,'STATUS':status}
 if source:d['SOURCE_LOCATION_ID']=source
 if destination:d['DESTINATION_LOCATION_ID']=destination
 return entry('inventory_movement',d)
call('mutation',batch([movement('R1',10,100,destination=master['warehouse']),movement('R2',10,200,destination=master['warehouse'])]))
issue=call('mutation',batch([movement('ISSUE',15,source=master['warehouse'])]));stock_type=meta['recordTypeIds']['inventory_stock'];sf=meta['fieldsByRecordType']['inventory_stock'];mf=meta['fieldsByRecordType']['inventory_movement']
stock=next(x for x in issue['changes'] if x['recordTypeCode']=='inventory_stock' and x['data'][sf['PRODUCT_ID']]==master['product']);assert stock['data'][sf['QUANTITY_ON_HAND']]==5 and stock['data'][sf['TOTAL_VALUE']]==remaining_value
out=next(x for x in issue['changes'] if x['recordTypeCode']=='inventory_movement');assert out['data'][mf['TOTAL_COST']]==3000-remaining_value
call('mutation',batch([movement('OVER',6,source=master['warehouse'])]),422)
transfer=call('mutation',batch([movement('TRANSFER',2,source=master['warehouse'],destination=master['other'])]));stocks=[x['data'] for x in transfer['changes'] if x['recordTypeCode']=='inventory_stock'];assert sum(x[sf['QUANTITY_ON_HAND']] for x in stocks)==5 and sum(x[sf['TOTAL_VALUE']] for x in stocks)==remaining_value
balances=direct('records?recordTypeId='+str(types['account_balance']))['records'];assert next(x['jsonData']['closingDebit'] for x in balances if x['jsonData']['accountId']==inventory)==format(remaining_value,'.2f')
# A late duplicate must roll back movement, stock, layer and shared GL projections.
call('mutation',batch([movement('ROLLBACK',1,50,destination=master['warehouse']),entry('product_category',{'CODE':code+'-CAT','NAME':'Duplicate','STATUS':'active'})]),422)
balances=direct('records?recordTypeId='+str(types['account_balance']))['records'];assert next(x['jsonData']['closingDebit'] for x in balances if x['jsonData']['accountId']==inventory)==format(remaining_value,'.2f')
call('mutation',batch([movement('CLEAN1',3,source=master['warehouse']),movement('CLEAN2',2,source=master['other'])]))
balances=direct('records?recordTypeId='+str(types['account_balance']))['records'];assert next(x['jsonData']['closingDebit'] for x in balances if x['jsonData']['accountId']==inventory)=='0.00'
print('PASS persisted native '+policy+' receipts, issue, transfer, cost layers, shared inventory GL and complete late-failure rollback')
call('auth/logout',{});direct('auth/logout',{})
