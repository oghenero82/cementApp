#!/usr/bin/env python3
"""Credits/refunds restore original stock cost and remain atomic with native shared journals."""
from pathlib import Path
exec(compile(Path(__file__).with_name('native-sales.py').read_text(),str(Path(__file__).with_name('native-sales.py')),'exec'))
call('auth/session');call('auth/login',{'email':os.environ['CEMENT_NATIVE_TEST_EMAIL'],'password':os.environ['CEMENT_NATIVE_TEST_PASSWORD']});direct('bootstrap');b=direct('auth/login',{'email':os.environ['CEMENT_NATIVE_TEST_EMAIL'],'password':os.environ['CEMENT_NATIVE_TEST_PASSWORD']});meta=call('meta/field-registry')
# The tax invoice has 645 gross, 500 paid from an advance, 145 receivable and 300 original cost.
taxid=taxroot['recordId'];taxline=taxdraft['resultMap']['line'];rf=meta['fieldsByRecordType']['customer_sale_return']
before_cost=float(bal(accounts['CostOfSales'])['closingDebit']);before_inventory=float(bal(inventory)['closingDebit'])
def return_entry(qty,suffix,disposition='restock'):
 return entry('customer_sale_return',{'CODE':code+'-RETURN-'+suffix,'SALE_ID':taxid,'SALE_ITEM_ID':taxline,'QUANTITY':qty,'UNIT_ID':masters['unit'],'RETURN_REASON':'Test original-cost return','DISPOSITION':disposition,'STATUS':'confirmed'},'return')
first_request=batch([return_entry(1,'FIRST')]);first_return=call('mutation',first_request);assert call('mutation',first_request)['mutationId']==first_return['mutationId'];first=next(x for x in first_return['changes'] if x['recordTypeCode']=='customer_sale_return');assert first['data'][rf['REFUND_AMOUNT']]==70
assert float(bal(accounts['CostOfSales'])['closingDebit'])==before_cost-100;assert float(bal(inventory)['closingDebit'])==before_inventory+100;assert bal(accounts['TaxPayable'])['closingCredit']=='30.00'
statement=call('customers/statement?'+urllib.parse.urlencode({'customerId':advance_customer}));assert statement['closingReceivable']==0 and sum(x['Cash refunded'] for x in statement['rows'])==70
call('mutation',batch([return_entry(2.01,'OVER')]),422)
# A later failure rolls back the refund, return receipt, tax reversal and original-cost COGS.
call('mutation',batch([return_entry(1,'ROLLBACK','damaged'),entry('supplier',{'CODE':code,'NAME':'Duplicate','STATUS':'active'})]),422)
assert float(bal(inventory)['closingDebit'])==before_inventory+100 and bal(accounts['TaxPayable'])['closingCredit']=='30.00'
last=call('mutation',batch([return_entry(2,'LAST','damaged')]));final_return=next(x for x in last['changes'] if x['recordTypeCode']=='customer_sale_return');assert final_return['data'][rf['REFUND_AMOUNT']]==430
assert float(bal(accounts['CostOfSales'])['closingDebit'])==before_cost-300;assert bal(accounts['TaxPayable'])['closingCredit']=='0.00'
statement=call('customers/statement?'+urllib.parse.urlencode({'customerId':advance_customer}));assert statement['closingReceivable']==0 and sum(x['Cash refunded'] for x in statement['rows'])==500
call('mutation',batch([entry('customer_payment',{'CODE':code+'-RETURN-OVERPAY','CUSTOMER_ID':advance_customer,'SALE_ID':taxid,'AMOUNT':1,'PAYMENT_DATE':today_text+'T00:00:00Z','PAYMENT_METHOD':'bank','STATUS':'completed'})]),422)
print('PASS native partial returns, original-cost restocking/damaged receipts, tax reversal, receivable credits, cash refunds, over-return guard and late-batch rollback')
call('auth/logout',{});direct('auth/logout',{})
