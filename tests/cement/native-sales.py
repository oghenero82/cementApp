#!/usr/bin/env python3
"""Native purchase → split warehouse loading → warehouse/direct sale → receipt → delivery."""
from pathlib import Path
import base64
# Reuse the independently asserted funding/loading scenario against this isolated deployment.
exec(compile(Path(__file__).with_name('native-loading.py').read_text(),str(Path(__file__).with_name('native-loading.py')),'exec'))
call('auth/session');call('auth/login',{'email':os.environ['CEMENT_NATIVE_TEST_EMAIL'],'password':os.environ['CEMENT_NATIVE_TEST_PASSWORD']})
direct('bootstrap');b=direct('auth/login',{'email':os.environ['CEMENT_NATIVE_TEST_EMAIL'],'password':os.environ['CEMENT_NATIVE_TEST_PASSWORD']})
accounts={}
for key in ['Receivables','SalesRevenue','CostOfSales','CustomerDeposits','TaxPayable']:
 accounts[key]=native_insert('account',{'code':code+'-'+key,'name':'Test '+key,'accountTypeId':at,'allowPosting':True,'currencyCode':'NGN','status':'active'})
settings=dict(b['institution']['jsonData'].get('settings',{}));settings.update({'cement'+key+'AccountId':value for key,value in accounts.items()});settings['cementReceiptBankAccountId']=bank;settings['allowSalesPriceOverride']=False
# Explicit known policy for this isolated journey; existing purchases retain their snapshots.
settings['ownershipTransferPolicy']='loading-transfer';settings['allowCreditSales']=True
direct('mutations',{'requestId':str(uuid.uuid4()),'mode':'record_mode','mutations':[{'recordTypeId':b['institution']['recordTypeId'],'recordId':b['institution']['recordId'],'operation':'update','data':{'settings':settings}}]});meta=call('meta/field-registry')
ids=call('mutation',batch([entry('customer',{'CODE':code+'-CUSTOMER','COMPANY_NAME':'Native customer','CUSTOMER_TYPE':'company','CREDIT_LIMIT':100000,'STATUS':'active'},'customer'),entry('price_list',{'CODE':code+'-PRICES','NAME':'Cement prices','PRICE_TYPE':'retail','CURRENCY':'NGN','VALID_FROM':today_text,'STATUS':'active'},'prices'),entry('price_list_item',{'CODE':code+'-TIER','PRICE_LIST_ID':{'$ref':'prices'},'PRODUCT_ID':masters['product'],'UNIT_ID':masters['unit'],'MIN_QUANTITY':1,'UNIT_PRICE':200,'STATUS':'active'},'tier')]))['resultMap']
sale_batch=call('mutation',batch([entry('customer_sales',{'CODE':code+'-SALE','CUSTOMER_ID':ids['customer'],'SALE_DATE':today_text,'WAREHOUSE_ID':more['warehouse'],'CURRENCY':'NGN','STATUS':'draft'},'sale'),entry('customer_sale_item',{'CODE':code+'-SALE-LINE','SALE_ID':{'$ref':'sale'},'PRODUCT_ID':masters['product'],'UNIT_ID':masters['unit'],'QUANTITY':10,'AGREED_UNIT_PRICE':200,'STATUS':'draft'},'line')]))
sf=meta['fieldsByRecordType']['customer_sales'];sale=next(x for x in sale_batch['changes'] if x['recordTypeCode']=='customer_sales');saleid=sale['recordId'];assert sale['data'][sf['NET_AMOUNT']]==2000
invoice={'requestId':str(uuid.uuid4()),'registryRevision':meta['revision'],'recordTypeCode':'customer_sales','recordTypeId':meta['recordTypeIds']['customer_sales'],'recordId':saleid,'version':sale['version'],'operation':'update','data':{sf['STATUS']:'invoiced'}}
posted=call('mutation',invoice);assert call('mutation',invoice)['mutationId']==posted['mutationId']
def bal(account):
 rows=direct('records?recordTypeId='+str(types['account_balance']))['records'];return next(x['jsonData'] for x in rows if x['jsonData']['accountId']==account)
assert bal(accounts['Receivables'])['closingDebit']=='2000.00';assert bal(accounts['SalesRevenue'])['closingCredit']=='2000.00';assert bal(accounts['CostOfSales'])['closingDebit']=='1000.00'
bank_record=native_insert('bank_account',{'code':code+'-BANK-ACCOUNT','name':'Receipt bank','bankName':'Test bank','accountNumber':code,'currencyCode':'NGN','glAccountId':bank,'status':'active'})
lookup=call('lookups?'+urllib.parse.urlencode({'recordTypeCode':'customer_payment','alias':'BANK_ACCOUNT_ID'}));assert any(x['id']==bank_record for x in lookup['items'])
pay=entry('customer_payment',{'CODE':code+'-RECEIPT','BANK_ACCOUNT_ID':bank_record,'CUSTOMER_ID':ids['customer'],'SALE_ID':saleid,'AMOUNT':500,'PAYMENT_DATE':today_text+'T00:00:00Z','PAYMENT_METHOD':'bank','STATUS':'completed'},'payment');request=batch([pay]);receipt=call('mutation',request);assert call('mutation',request)['mutationId']==receipt['mutationId'];assert next(x['data'][sf['BALANCE_DUE']] for x in receipt['changes'] if x['recordTypeCode']=='customer_sales')==1500
assert bal(accounts['Receivables'])['closingDebit']=='1500.00'
call('mutation',batch([entry('customer_payment',{'CODE':code+'-OVERPAY','CUSTOMER_ID':ids['customer'],'SALE_ID':saleid,'AMOUNT':1501,'PAYMENT_DATE':today_text+'T00:00:00Z','PAYMENT_METHOD':'bank','STATUS':'completed'})]),422)
# Upload a real signature image through the Cement endpoint; browsers never contact MetaForge.
png=base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aP1sAAAAASUVORK5CYII=')
boundary='Cement'+uuid.uuid4().hex
multipart=('--'+boundary+'\r\nContent-Disposition: form-data; name="recordTypeCode"\r\n\r\ndelivery_proof\r\n--'+boundary+'\r\nContent-Disposition: form-data; name="attachment"; filename="signature.png"\r\nContent-Type: image/png\r\n\r\n').encode()+png+('\r\n--'+boundary+'--\r\n').encode()
request=urllib.request.Request(base+'/api/files/upload',data=multipart,headers={'X-Cement-CSRF':csrf,'Content-Type':'multipart/form-data; boundary='+boundary})
signature=json.load(client.open(request))['reference'];assert client.open(base+'/api/files/'+signature['attachmentId']).read()==png
proof=entry('delivery_proof',{'CODE':code+'-POD','SALE_ID':saleid,'DELIVERED_DATE':today_text+'T00:00:00Z','ACCEPTED_QUANTITY':4,'RECEIVER_NAME':'Test receiver','SIGNATURE':signature,'STATUS':'confirmed'})
pod=call('mutation',batch([proof]));assert next(x['data'][sf['DELIVERY_STATUS']] for x in pod['changes'] if x['recordTypeCode']=='customer_sales')=='partial'
rejected=call('mutation',batch([entry('delivery_proof',{'CODE':code+'-REJECTED-POD','SALE_ID':saleid,'DELIVERED_DATE':today_text+'T00:00:00Z','ACCEPTED_QUANTITY':0,'REJECTED_QUANTITY':6,'RECEIVER_NAME':'Test receiver','SIGNATURE':signature,'STATUS':'confirmed'})]));assert next(x['data'][sf['DELIVERY_STATUS']] for x in rejected['changes'] if x['recordTypeCode']=='customer_sales')=='completed_with_rejection'
# A later failure in the same outer mutation rolls back all sales, COGS and warehouse effects.
failed=entry('cement_loading',{'CODE':code+'-FAIL-LOAD','PURCHASE_ITEM_ID':item_id,'SUPPLIER_DEPOT_ID':more['depot'],'LOADING_DATE':today_text+'T00:00:00Z','UNIT_ID':masters['unit'],'QUANTITY':20,'ALLOCATION_ROWS':[{lf['QUANTITY']:10,cf['CUSTOMER_ID']:ids['customer']},{lf['QUANTITY']:10,cf['WAREHOUSE_ID']:more['other']}],'STATUS':'confirmed'},'loading')
call('mutation',batch([failed,entry('supplier',{'CODE':code,'NAME':'Duplicate','STATUS':'active'})]),422)
assert bal(accounts['Receivables'])['closingDebit']=='1500.00';assert bal(prepaid)['closingDebit']=='4000.00'
failed['data'][lf['CODE']]=code+'-DIRECT';direct_load=call('mutation',batch([failed]));call('mutation',batch([entry('supplier',{'CODE':code,'NAME':'Duplicate','STATUS':'active'})]),422)
assert bal(prepaid)['closingDebit']=='2000.00';assert bal(accounts['Receivables'])['closingDebit']=='3500.00';assert bal(accounts['CostOfSales'])['closingDebit']=='2000.00'
assert next(x['data'][ip['LOADED_QTY']] for x in direct_load['changes'] if x['recordTypeCode']=='purchaseItem')==80
print('PASS native warehouse/direct sales, dated pricing, shared revenue/COGS/receivables, partial receipt/POD, exact replay and late-batch rollback')

# Delivery-transfer leaves both stock and financial recognition pending until accepted POD.
settings['ownershipTransferPolicy']='delivery-transfer'
direct('mutations',{'requestId':str(uuid.uuid4()),'mode':'record_mode','mutations':[{'recordTypeId':b['institution']['recordTypeId'],'recordId':b['institution']['recordId'],'operation':'update','data':{'settings':settings}}]});meta=call('meta/field-registry')
draft=call('mutation',batch([entry('customer_sales',{'CODE':code+'-DELIVERY-SALE','CUSTOMER_ID':ids['customer'],'SALE_DATE':today_text,'WAREHOUSE_ID':more['warehouse'],'CURRENCY':'NGN','STATUS':'draft'},'sale'),entry('customer_sale_item',{'CODE':code+'-DELIVERY-LINE','SALE_ID':{'$ref':'sale'},'PRODUCT_ID':masters['product'],'UNIT_ID':masters['unit'],'QUANTITY':10,'AGREED_UNIT_PRICE':200,'STATUS':'draft'},'line')]))
ds=next(x for x in draft['changes'] if x['recordTypeCode']=='customer_sales');dsid=ds['recordId'];before_revenue=bal(accounts['SalesRevenue'])['closingCredit'];before_cogs=bal(accounts['CostOfSales'])['closingDebit']
prepay=call('mutation',batch([entry('customer_payment',{'CODE':code+'-PREPAY','CUSTOMER_ID':ids['customer'],'SALE_ID':dsid,'AMOUNT':500,'PAYMENT_DATE':today_text+'T00:00:00Z','PAYMENT_METHOD':'bank','STATUS':'completed'})]))
assert bal(accounts['CustomerDeposits'])['closingCredit']=='500.00';ds=next(x for x in prepay['changes'] if x['recordTypeCode']=='customer_sales')
inv={'requestId':str(uuid.uuid4()),'registryRevision':meta['revision'],'recordTypeCode':'customer_sales','recordTypeId':meta['recordTypeIds']['customer_sales'],'recordId':dsid,'version':ds['version'],'operation':'update','data':{sf['STATUS']:'invoiced'}}
call('mutation',inv);assert bal(accounts['SalesRevenue'])['closingCredit']==before_revenue and bal(accounts['CostOfSales'])['closingDebit']==before_cogs
# Prohibit forged inline signatures and over-delivery before any effect can be posted.
badproof=entry('delivery_proof',{'CODE':code+'-FORGED','SALE_ID':dsid,'DELIVERED_DATE':today_text+'T00:00:00Z','ACCEPTED_QUANTITY':4,'RECEIVER_NAME':'Receiver','SIGNATURE':{'attachmentId':'0'*32},'STATUS':'confirmed'})
call('mutation',batch([badproof]),422)
def deliver(qty,suffix):
 return call('mutation',batch([entry('delivery_proof',{'CODE':code+'-DELIVERY-'+suffix,'SALE_ID':dsid,'DELIVERED_DATE':today_text+'T00:00:00Z','ACCEPTED_QUANTITY':qty,'RECEIVER_NAME':'Receiver','SIGNATURE':signature,'STATUS':'confirmed'})]))
first=deliver(4,'PART');assert bal(accounts['CustomerDeposits'])['closingCredit']=='0.00';assert float(bal(accounts['SalesRevenue'])['closingCredit'])==float(before_revenue)+800;assert float(bal(accounts['CostOfSales'])['closingDebit'])==float(before_cogs)+400
call('mutation',batch([entry('customer_payment',{'CODE':code+'-PREPAY-2','CUSTOMER_ID':ids['customer'],'SALE_ID':dsid,'AMOUNT':1000,'PAYMENT_DATE':today_text+'T00:00:00Z','PAYMENT_METHOD':'bank','STATUS':'completed'})]));assert bal(accounts['CustomerDeposits'])['closingCredit']=='700.00'
middle=deliver(1,'MIDDLE');assert bal(accounts['CustomerDeposits'])['closingCredit']=='500.00';assert float(bal(accounts['CostOfSales'])['closingDebit'])==float(before_cogs)+500
def update_guard(t,record_id,alias,value,expected=200):
 raw=next(x for x in direct('records?recordTypeId='+str(types[t]))['records'] if x['recordId']==record_id)
 request={'requestId':str(uuid.uuid4()),'registryRevision':meta['revision'],'recordTypeCode':t,'recordTypeId':meta['recordTypeIds'][t],'recordId':record_id,'version':max(1,raw['mutationId']),'operation':'update','data':{meta['fieldsByRecordType'][t][alias]:value}}
 return call('mutation',request,expected)
update_guard('customer',ids['customer'],'CREDIT_LIMIT',1000)
blocked=entry('delivery_proof',{'CODE':code+'-CREDIT-BLOCK','SALE_ID':dsid,'DELIVERED_DATE':today_text+'T00:00:00Z','ACCEPTED_QUANTITY':5,'RECEIVER_NAME':'Receiver','SIGNATURE':signature,'STATUS':'confirmed'})
call('mutation',batch([blocked]),422);assert float(bal(accounts['CostOfSales'])['closingDebit'])==float(before_cogs)+500
update_guard('customer',ids['customer'],'CREDIT_LIMIT',100000)
last=deliver(5,'FINAL');assert bal(accounts['CustomerDeposits'])['closingCredit']=='0.00';assert float(bal(accounts['SalesRevenue'])['closingCredit'])==float(before_revenue)+2000;assert float(bal(accounts['CostOfSales'])['closingDebit'])==float(before_cogs)+1000;assert next(x['data'][sf['DELIVERY_STATUS']] for x in last['changes'] if x['recordTypeCode']=='customer_sales')=='delivered'
statement=call('customers/statement?'+urllib.parse.urlencode({'customerId':ids['customer']}));assert statement['closingReceivable']==4000 and statement['closingDeposit']==0
future=(today+datetime.timedelta(days=1)).isoformat();period=call('customers/statement?'+urllib.parse.urlencode({'customerId':ids['customer'],'from':future}));assert period['openingReceivable']==4000 and period['rows']==[]
call('customers/statement?'+urllib.parse.urlencode({'customerId':ids['customer'],'from':'2026-02-31'}),expected=422)
newunit=call('mutation',batch([entry('unit_of_measure',{'CODE':code+'-OTHER-U','NAME':'Other unit','SYMBOL':code+'-OTHER','DIMENSION':'count','STATUS':'active'},'unit')]))['resultMap']['unit']
update_guard('product',masters['product'],'BASE_UNIT_ID',newunit,422)
update_guard('customer',ids['customer'],'CREDIT_LIMIT',-1,422)
# Advances exist independently of a sale and keep historical allocations when later invoices arrive.
settings['ownershipTransferPolicy']='loading-transfer'
direct('mutations',{'requestId':str(uuid.uuid4()),'mode':'record_mode','mutations':[{'recordTypeId':b['institution']['recordTypeId'],'recordId':b['institution']['recordId'],'operation':'update','data':{'settings':settings}}]});meta=call('meta/field-registry')
advance_customer=call('mutation',batch([entry('customer',{'CODE':code+'-ADV-CUSTOMER','COMPANY_NAME':'Advance customer','CUSTOMER_TYPE':'company','STATUS':'active'},'customer')]))['resultMap']['customer']
advance=entry('customer_payment',{'CODE':code+'-ADVANCE','CUSTOMER_ID':advance_customer,'AMOUNT':1000,'PAYMENT_DATE':today_text+'T00:00:00Z','PAYMENT_METHOD':'bank','STATUS':'completed'},'advance');advance_request=batch([advance]);paid_advance=call('mutation',advance_request);assert call('mutation',advance_request)['mutationId']==paid_advance['mutationId'];advance_id=paid_advance['resultMap']['advance']
assert bal(accounts['CustomerDeposits'])['closingCredit']=='1000.00'
future_sale=call('mutation',batch([entry('customer_sales',{'CODE':code+'-ADV-SALE','CUSTOMER_ID':advance_customer,'SALE_DATE':today_text,'WAREHOUSE_ID':more['warehouse'],'CURRENCY':'NGN','STATUS':'draft'},'sale'),entry('customer_sale_item',{'CODE':code+'-ADV-LINE','SALE_ID':{'$ref':'sale'},'PRODUCT_ID':masters['product'],'UNIT_ID':masters['unit'],'QUANTITY':10,'AGREED_UNIT_PRICE':200,'STATUS':'draft'},'line')]))
future_root=next(x for x in future_sale['changes'] if x['recordTypeCode']=='customer_sales');future_invoice={'recordTypeCode':'customer_sales','recordTypeId':meta['recordTypeIds']['customer_sales'],'recordId':future_root['recordId'],'version':future_root['version'],'operation':'update','data':{sf['STATUS']:'invoiced'}}
call('mutation',batch([future_invoice,entry('supplier',{'CODE':code,'NAME':'Duplicate','STATUS':'active'})]),422)
assert bal(accounts['CustomerDeposits'])['closingCredit']=='1000.00'
raw=next(x for x in direct('records?recordTypeId='+str(types['customer_payment']))['records'] if x['recordId']==advance_id);assert raw['jsonData']['settings']['cement']['unallocatedMinor']==100000
applied=call('mutation',batch([future_invoice]));assert next(x['data'][sf['AMOUNT_PAID']] for x in applied['changes'] if x['recordTypeCode']=='customer_sales')==1000;assert bal(accounts['CustomerDeposits'])['closingCredit']=='0.00'
statement=call('customers/statement?'+urllib.parse.urlencode({'customerId':advance_customer}));assert statement['closingReceivable']==1000 and statement['closingDeposit']==0
# A receipt without a selected sale allocates to existing invoices before retaining an excess advance.
unassigned=entry('customer_payment',{'CODE':code+'-FIFO-RECEIPT','CUSTOMER_ID':advance_customer,'AMOUNT':1500,'PAYMENT_DATE':today_text+'T00:00:00Z','PAYMENT_METHOD':'bank','STATUS':'completed'})
remaining=call('mutation',batch([unassigned]));assert next(x['data'][sf['AMOUNT_PAID']] for x in remaining['changes'] if x['recordTypeCode']=='customer_sales')==2000
statement=call('customers/statement?'+urllib.parse.urlencode({'customerId':advance_customer}));assert statement['closingReceivable']==0 and statement['closingDeposit']==500
# Tax recognition posts a separate payable while applying only the remaining advance.
historical=next(x['jsonData']['settings']['cement']['allocations'] for x in direct('records?recordTypeId='+str(types['customer_payment']))['records'] if x['recordId']==advance_id)
revenue_before=float(bal(accounts['SalesRevenue'])['closingCredit'])
taxdraft=call('mutation',batch([entry('customer_sales',{'CODE':code+'-TAX-SALE','CUSTOMER_ID':advance_customer,'SALE_DATE':today_text,'WAREHOUSE_ID':more['warehouse'],'CURRENCY':'NGN','STATUS':'draft'},'sale'),entry('customer_sale_item',{'CODE':code+'-TAX-LINE','SALE_ID':{'$ref':'sale'},'PRODUCT_ID':masters['product'],'UNIT_ID':masters['unit'],'QUANTITY':3,'AGREED_UNIT_PRICE':200,'TAX_RATE':7.5,'STATUS':'draft'},'line')]))
taxroot=next(x for x in taxdraft['changes'] if x['recordTypeCode']=='customer_sales');assert taxroot['data'][sf['NET_AMOUNT']]==645
posted_tax=call('mutation',{'requestId':str(uuid.uuid4()),'registryRevision':meta['revision'],'recordTypeCode':'customer_sales','recordTypeId':meta['recordTypeIds']['customer_sales'],'recordId':taxroot['recordId'],'version':taxroot['version'],'operation':'update','data':{sf['STATUS']:'invoiced'}})
assert bal(accounts['TaxPayable'])['closingCredit']=='45.00';assert float(bal(accounts['SalesRevenue'])['closingCredit'])==revenue_before+600
statement=call('customers/statement?'+urllib.parse.urlencode({'customerId':advance_customer}));assert statement['closingReceivable']==145 and statement['closingDeposit']==0
assert next(x['jsonData']['settings']['cement']['allocations'] for x in direct('records?recordTypeId='+str(types['customer_payment']))['records'] if x['recordId']==advance_id)==historical
update_guard('customer',ids['customer'],'STATUS','inactive')
inactive_receipt=call('mutation',batch([entry('customer_payment',{'CODE':code+'-INACTIVE-DEBT','CUSTOMER_ID':ids['customer'],'SALE_ID':saleid,'AMOUNT':100,'PAYMENT_DATE':today_text+'T00:00:00Z','PAYMENT_METHOD':'bank','STATUS':'completed'})]));assert next(x['data'][sf['BALANCE_DUE']] for x in inactive_receipt['changes'] if x['recordTypeCode']=='customer_sales')==1400
update_guard('customer',ids['customer'],'STATUS','active')
print('PASS native unallocated advance, FIFO invoice allocation, residual deposits and rollback of allocations with a failed invoice batch')
print('PASS native real signature upload/download, invalid-reference rejection, deferred ownership recognition, partial delivery, deposit application and authoritative period customer statements')
call('auth/logout',{});direct('auth/logout',{})
