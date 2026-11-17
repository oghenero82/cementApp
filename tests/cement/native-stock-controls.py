#!/usr/bin/env python3
"""Isolated native count → adjustment → transfer → quarantine/write-off journey."""
from pathlib import Path
exec(compile(Path(__file__).with_name('native-loading.py').read_text(),str(Path(__file__).with_name('native-loading.py')),'exec'))
call('auth/session');call('auth/login',{'email':os.environ['CEMENT_NATIVE_TEST_EMAIL'],'password':os.environ['CEMENT_NATIVE_TEST_PASSWORD']});direct('bootstrap');b=direct('auth/login',{'email':os.environ['CEMENT_NATIVE_TEST_EMAIL'],'password':os.environ['CEMENT_NATIVE_TEST_PASSWORD']})
variance=native_insert('account',{'code':code+'-VARIANCE','name':'Count and damage variance','accountTypeId':at,'allowPosting':True,'currencyCode':'NGN','status':'active'})
settings=dict(b['institution']['jsonData'].get('settings',{}));settings['cementStockVarianceAccountId']=variance
direct('mutations',{'requestId':str(uuid.uuid4()),'mode':'record_mode','mutations':[{'recordTypeId':b['institution']['recordTypeId'],'recordId':b['institution']['recordId'],'operation':'update','data':{'settings':settings}}]});meta=call('meta/field-registry')
def allrecords():
 p=call('sync?cursor=0');rows=p['records']
 while p['hasMore']:
  p=call('sync?'+urllib.parse.urlencode({'cursor':p['cursor']}));rows+=p['records']
 return rows
def stock(location,condition='good'):
 f=meta['fieldsByRecordType']['inventory_stock'];return next(x['data'] for x in allrecords() if x['recordTypeCode']=='inventory_stock' and x['data'][f['LOCATION_ID']]==location and x['data'][f['PRODUCT_ID']]==masters['product'] and x['data'][f['STOCK_CONDITION']]==condition)
def gl(account):return next(x['jsonData'] for x in direct('records?recordTypeId='+str(types['account_balance']))['records'] if x['jsonData']['accountId']==account)
take=call('mutation',batch([entry('inventory_stocktake',{'CODE':code+'-COUNT','LOCATION_ID':more['warehouse'],'COUNT_DATE':today_text+'T00:00:00Z','INVENTORY_KEEPING_METHOD':'manual_stocktaken','STATUS':'draft'},'take'),entry('inventory_stocktake_line',{'CODE':code+'-COUNT-LINE','STOCKTAKE_ID':{'$ref':'take'},'PRODUCT_ID':masters['product'],'STOCK_CONDITION':'good','COUNTED_QTY':37,'STATUS':'draft'},'line')]))
root_count=next(x for x in take['changes'] if x['recordTypeCode']=='inventory_stocktake');field=meta['fieldsByRecordType']['inventory_stocktake']
post={'requestId':str(uuid.uuid4()),'registryRevision':meta['revision'],'recordTypeCode':'inventory_stocktake','recordTypeId':meta['recordTypeIds']['inventory_stocktake'],'recordId':root_count['recordId'],'version':root_count['version'],'operation':'update','data':{field['STATUS']:'posted'}}
counted=call('mutation',post);assert call('mutation',post)['mutationId']==counted['mutationId'];q=meta['fieldsByRecordType']['inventory_stock']['QUANTITY_ON_HAND'];assert stock(more['warehouse'])[q]==37;assert gl(inventory)['closingDebit']=='5700.00';assert gl(variance)['closingDebit']=='300.00'
transfer=entry('inventory_transfer',{'CODE':code+'-TRANSFER','SOURCE_LOCATION_ID':more['warehouse'],'DESTINATION_LOCATION_ID':more['other'],'PRODUCT_ID':masters['product'],'QUANTITY':7,'UNIT_ID':masters['unit'],'REQUEST_DATE':today_text,'STATUS':'received'})
call('mutation',batch([transfer]));assert stock(more['warehouse'])[q]==30 and stock(more['other'])[q]==27;assert gl(inventory)['closingDebit']=='5700.00'
quarantine=entry('damaged_goods',{'CODE':code+'-DAMAGE','LOCATION_ID':more['warehouse'],'PRODUCT_ID':masters['product'],'QUANTITY':5,'REASON':'Torn bags','CONDITION':'good','DECISION':'quarantine','STATUS':'confirmed'})
call('mutation',batch([quarantine]));assert stock(more['warehouse'])[q]==25 and stock(more['warehouse'],'damaged')[q]==5;assert gl(inventory)['closingDebit']=='5700.00'
writeoff=entry('damaged_goods',{'CODE':code+'-WRITE-OFF','LOCATION_ID':more['warehouse'],'PRODUCT_ID':masters['product'],'QUANTITY':2,'REASON':'Unusable bags','CONDITION':'damaged','DECISION':'write_off','STATUS':'confirmed'})
call('mutation',batch([writeoff]));assert stock(more['warehouse'],'damaged')[q]==3;assert gl(inventory)['closingDebit']=='5500.00';assert gl(variance)['closingDebit']=='500.00'
adjust=entry('stock_adjustment',{'CODE':code+'-ADJUST','LOCATION_ID':more['warehouse'],'PRODUCT_ID':masters['product'],'ADJUSTMENT_DATE':today_text+'T00:00:00Z','REASON_CODE':'approved_surplus','STOCK_CONDITION':'good','QUANTITY_DELTA':4,'UNIT_COST':100,'STATUS':'confirmed'})
req=batch([adjust]);posted=call('mutation',req);assert call('mutation',req)['mutationId']==posted['mutationId'];assert stock(more['warehouse'])[q]==29;assert gl(inventory)['closingDebit']=='5900.00'
adjust['data'][meta['fieldsByRecordType']['stock_adjustment']['CODE']]=code+'-ROLLBACK'
call('mutation',batch([adjust,entry('supplier',{'CODE':code,'NAME':'Duplicate','STATUS':'active'})]),422);assert stock(more['warehouse'])[q]==29 and gl(inventory)['closingDebit']=='5900.00'
print('PASS native stocktake snapshot, approved variance, cost-preserving transfer, quarantine, write-off, replay and late-failure rollback')
call('auth/logout',{});direct('auth/logout',{})
