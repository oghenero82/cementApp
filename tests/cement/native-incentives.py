#!/usr/bin/env python3
"""Actual supplier scheme accrual, earned receivables, partial receipts and replay/rollback guards."""
from pathlib import Path
exec(compile(Path(__file__).with_name('native-loading.py').read_text(),str(Path(__file__).with_name('native-loading.py')),'exec'))
call('auth/session');call('auth/login',{'email':os.environ['CEMENT_NATIVE_TEST_EMAIL'],'password':os.environ['CEMENT_NATIVE_TEST_PASSWORD']});direct('bootstrap');b=direct('auth/login',{'email':os.environ['CEMENT_NATIVE_TEST_EMAIL'],'password':os.environ['CEMENT_NATIVE_TEST_PASSWORD']})
# Reject native record_mode bypass while preserving the authorised Cement entry path.
raw=entry('supplier',{'CODE':code+'-ENTRY-GUARD','NAME':'Entry guard supplier','STATUS':'active'})
direct('mutations',{'requestId':str(uuid.uuid4()),'mode':'record_mode','mutations':[{'recordTypeId':raw['recordTypeId'],'operation':'insert','data':raw['data']}]},403)
call('mutation',batch([raw]))
income_type=native_insert('account_type',{'code':code+'-BENEFIT-TYPE','name':'Supplier benefit income','category':'income','normalBalance':'credit','status':'active'})
receivable=native_insert('account',{'code':code+'-BENEFIT-AR','name':'Supplier benefit receivable','accountTypeId':at,'allowPosting':True,'currencyCode':'NGN','status':'active'})
income=native_insert('account',{'code':code+'-BENEFIT-INCOME','name':'Supplier benefit income','accountTypeId':income_type,'allowPosting':True,'currencyCode':'NGN','status':'active'})
settings=dict(b['institution']['jsonData'].get('settings',{}));settings.update(enableIncentiveSchemes=True,cementIncentiveReceivableAccountId=receivable,cementIncentiveIncomeAccountId=income,cementIncentiveBankAccountId=bank)
direct('mutations',{'requestId':str(uuid.uuid4()),'mode':'record_mode','mutations':[{'recordTypeId':b['institution']['recordTypeId'],'recordId':b['institution']['recordId'],'operation':'update','data':{'settings':settings}}]});meta=call('meta/field-registry')
scheme=call('mutation',batch([entry('supplier_incentive_scheme',{'CODE':code+'-SCHEME','SUPPLIER_ID':masters['supplier'],'NAME':'Loaded bag rebate','BASIS':'loading_quantity','CALCULATION_TYPE':'per_quantity','RATE':2,'THRESHOLD_QTY':50,'VALID_FROM':today_text,'PRODUCT_IDS':[masters['product']],'DEPOT_IDS':[more['depot']],'STATUS':'active'},'scheme')]))['resultMap']['scheme']
def benefit(suffix):return entry('supplier_incentive',{'CODE':code+'-BENEFIT-'+suffix,'SUPPLIER_ID':masters['supplier'],'SCHEME_ID':scheme,'PURCHASE_ID':root['recordId'],'BENEFIT_DATE':today_text,'BENEFIT_TYPE':'rebate','BASIS_QUANTITY':999999,'BASIS_AMOUNT':999999,'ACTUAL_AMOUNT':999999,'STATUS':'verified'},'benefit')
request=batch([benefit('FIRST')]);earned=call('mutation',request);assert call('mutation',request)['mutationId']==earned['mutationId'];benefit_id=earned['resultMap']['benefit'];bf=meta['fieldsByRecordType']['supplier_incentive'];row=next(x for x in earned['changes'] if x['recordTypeCode']=='supplier_incentive');assert row['data'][bf['ACTUAL_AMOUNT']]==120 and row['data'][bf['BASIS_QUANTITY']]==60 and row['data'][bf['BASIS_AMOUNT']]==6000
def balance(account):return next(x['jsonData'] for x in direct('records?recordTypeId='+str(types['account_balance']))['records'] if x['jsonData']['accountId']==account)
assert balance(receivable)['closingDebit']=='120.00' and balance(income)['closingCredit']=='120.00'
def receipt(id,amount,suffix):return entry('supplier_incentive_receipt',{'CODE':code+'-BENEFIT-RECEIPT-'+suffix,'INCENTIVE_ID':id,'AMOUNT':amount,'RECEIVED_DATE':today_text,'STATUS':'completed'})
received=call('mutation',batch([receipt(benefit_id,50,'PART')]));assert next(x for x in received['changes'] if x['recordTypeCode']=='supplier_incentive')['data'][bf['AMOUNT_RECEIVED']]==50
call('mutation',batch([receipt(benefit_id,71,'OVER')]),422)
call('mutation',batch([benefit('DUPLICATE')]),422)
raw=next(x for x in direct('records?recordTypeId='+str(types['supplier_incentive_scheme']))['records'] if x['recordId']==scheme)
call('mutation',{'requestId':str(uuid.uuid4()),'registryRevision':meta['revision'],'recordTypeCode':'supplier_incentive_scheme','recordTypeId':meta['recordTypeIds']['supplier_incentive_scheme'],'recordId':scheme,'version':max(1,raw['mutationId']),'operation':'update','data':{meta['fieldsByRecordType']['supplier_incentive_scheme']['RATE']:3}},422)
# Only the newly qualified quantity earns another award; previous awards cannot be repeated.
extra=entry('cement_loading',{'CODE':code+'-BENEFIT-EXTRA-LOAD','PURCHASE_ITEM_ID':item_id,'SUPPLIER_DEPOT_ID':more['depot'],'LOADING_DATE':today_text+'T00:00:00Z','UNIT_ID':masters['unit'],'QUANTITY':10,'ALLOCATION_ROWS':[{lf['QUANTITY']:10,cf['WAREHOUSE_ID']:more['other']}],'STATUS':'confirmed'})
call('mutation',batch([extra]));next_earned=call('mutation',batch([benefit('NEXT')]));next_id=next_earned['resultMap']['benefit'];assert next(x for x in next_earned['changes'] if x['recordTypeCode']=='supplier_incentive')['data'][bf['ACTUAL_AMOUNT']]==20
assert balance(income)['closingCredit']=='140.00' and balance(receivable)['closingDebit']=='90.00'
call('mutation',batch([receipt(benefit_id,70,'ROLLBACK'),entry('supplier',{'CODE':code,'NAME':'Duplicate','STATUS':'active'})]),422);assert balance(receivable)['closingDebit']=='90.00'
call('mutation',batch([receipt(benefit_id,70,'FINAL')]));call('mutation',batch([receipt(next_id,20,'NEXT')]));assert balance(receivable)['closingDebit']=='0.00' and balance(income)['closingCredit']=='140.00'
print('PASS native cumulative scheme accrual, authoritative basis, duplicate-award and rate-edit guards, partial receipts, exact replay, entry-service enforcement and late-failure rollback')
call('auth/logout',{});direct('auth/logout',{})
