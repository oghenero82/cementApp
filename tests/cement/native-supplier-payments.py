#!/usr/bin/env python3
"""Native partial supplier payments, remaining funding and atomic purchase cash counters."""
from pathlib import Path
exec(compile(Path(__file__).with_name('native-loading.py').read_text(),str(Path(__file__).with_name('native-loading.py')),'exec'))
call('auth/session');call('auth/login',{'email':os.environ['CEMENT_NATIVE_TEST_EMAIL'],'password':os.environ['CEMENT_NATIVE_TEST_PASSWORD']});direct('bootstrap');b=direct('auth/login',{'email':os.environ['CEMENT_NATIVE_TEST_EMAIL'],'password':os.environ['CEMENT_NATIVE_TEST_PASSWORD']});meta=call('meta/field-registry')
def po(suffix):
 result=call('mutation',batch([entry('purchase',{'CODE':code+'-'+suffix,'SUPPLIER_ID':masters['supplier'],'PAYMENT_ACCOUNT_ID':bank,'PREPAID_ACCOUNT_ID':prepaid,'PURCHASE_DATE':today_text,'CURRENCY':'NGN','STATUS':'draft'},'purchase'),entry('purchaseItem',{'CODE':code+'-'+suffix+'-ITEM','PURCHASE_ID':{'$ref':'purchase'},'PRODUCT_ID':masters['product'],'UNIT_ID':masters['unit'],'ORIGINAL_QUANTITY':100,'UNIT_PRICE':100,'STATUS':'draft'},'item')]))
 return result['resultMap']
def pay(purchase,amount,suffix):return entry('supplier_purchase_payment',{'CODE':code+'-PAY-'+suffix,'PURCHASE_ID':purchase,'AMOUNT':amount,'PAID_AT':today_text+'T00:00:00Z','PAYMENT_METHOD':'bank','STATUS':'completed'},'payment')
def balance(account):return next(x['jsonData'] for x in direct('records?recordTypeId='+str(types['account_balance']))['records'] if x['jsonData']['accountId']==account)
def update(t,id,data,expected=200):
 raw=next(x for x in direct('records?recordTypeId='+str(types[t]))['records'] if x['recordId']==id)
 return call('mutation',{'requestId':str(uuid.uuid4()),'registryRevision':meta['revision'],'recordTypeCode':t,'recordTypeId':meta['recordTypeIds'][t],'recordId':id,'version':max(1,raw['mutationId']),'operation':'update','data':{meta['fieldsByRecordType'][t][key]:value for key,value in data.items()}},expected)
p1=po('PARTIAL');req=batch([pay(p1['purchase'],4000,'PARTIAL')]);paid=call('mutation',req);assert call('mutation',req)['mutationId']==paid['mutationId'];parent=next(x for x in paid['changes'] if x['recordTypeCode']=='purchase');assert parent['data'][pf['PAID_AMOUNT']]==4000 and parent['data'][pf['STATUS']]=='draft';assert balance(prepaid)['closingDebit']=='8000.00'
update('purchaseItem',p1['item'],{'UNIT_PRICE':90},422)
load=entry('cement_loading',{'CODE':code+'-UNFUNDED-LOAD','PURCHASE_ITEM_ID':p1['item'],'SUPPLIER_DEPOT_ID':more['depot'],'LOADING_DATE':today_text+'T00:00:00Z','UNIT_ID':masters['unit'],'QUANTITY':1,'ALLOCATION_ROWS':[{lf['QUANTITY']:1,cf['WAREHOUSE_ID']:more['warehouse']}],'STATUS':'confirmed'});call('mutation',batch([load]),422)
funded=update('purchase',p1['purchase'],{'STATUS':'paid'});parent=next(x for x in funded['changes'] if x['recordTypeCode']=='purchase');assert parent['data'][pf['PAID_AMOUNT']]==10000
journal=next(x for x in direct('records?recordTypeId='+str(types['journal']))['records'] if x['jsonData']['code']=='CEMENT-FUND-'+str(p1['purchase']));lines=[x['jsonData'] for x in direct('records?recordTypeId='+str(types['journal_line']))['records'] if x['jsonData']['journalId']==journal['recordId']];assert sum(float(x['debit']) for x in lines)==sum(float(x['credit']) for x in lines)==6000;assert balance(prepaid)['closingDebit']=='14000.00'
p2=po('INSTALLMENTS');call('mutation',batch([pay(p2['purchase'],2500,'FIRST')]));last=call('mutation',batch([pay(p2['purchase'],7500,'LAST')]));parent=next(x for x in last['changes'] if x['recordTypeCode']=='purchase');assert parent['data'][pf['PAID_AMOUNT']]==10000 and parent['data'][pf['STATUS']]=='paid';assert not any(x['jsonData']['code']=='CEMENT-FUND-'+str(p2['purchase']) for x in direct('records?recordTypeId='+str(types['journal']))['records']);assert balance(prepaid)['closingDebit']=='24000.00'
call('mutation',batch([pay(p2['purchase'],1,'OVER')]),422)
p3=po('ROLLBACK');call('mutation',batch([pay(p3['purchase'],1000,'ROLLBACK'),entry('supplier',{'CODE':code,'NAME':'Duplicate','STATUS':'active'})]),422)
raw=next(x['jsonData'] for x in direct('records?recordTypeId='+str(types['purchase']))['records'] if x['recordId']==p3['purchase']);assert float(raw[pf['PAID_AMOUNT']])==0 and raw[pf['STATUS']]=='draft';assert balance(prepaid)['closingDebit']=='24000.00'
print('PASS native supplier partial payments, exact remaining funding, automatic installment settlement, immutable funded terms, overpayment/loading guards and late-failure rollback')
call('auth/logout',{});direct('auth/logout',{})
