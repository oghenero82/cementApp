<?php

use PHPUnit\Framework\TestCase;
use App\Libraries\Cement\{Store,Auth,Mutation,Problem,Reports,Catalog};

final class DomainTest extends TestCase
{
    private Store $s;
    private array $actor;
    private string $path;
    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'cement-test-');
        $this->s = new Store($this->path);
        $this->s->migrate();
        $this->actor = (new Auth($this->s))->setup(['institution' => 'Test cement','email' => 'test@example.test','password' => 'test-only-long-password']);
    }
    protected function tearDown(): void
    {
        unset($this->s);
        @unlink($this->path);
    }
    private function insert(string $type, array $data): int
    {
        $data += ['CODE' => 'TEST-'.bin2hex(random_bytes(4)),'STATUS' => 'draft'];
        $e = ['requestId' => 'test-'.bin2hex(random_bytes(8)),'registryRevision' => Catalog::REVISION,'recordTypeCode' => $type,'operation' => 'insert','data' => $this->s->catalog->encode($type, $data)];
        return (new Mutation($this->s))->execute($this->actor, $e)['results'][0]['recordId'];
    }
    private function update(string $type, int $id, array $data): void
    {
        $row = $this->s->get(1, $type, $id);
        (new Mutation($this->s))->execute($this->actor, ['requestId' => 'test-'.bin2hex(random_bytes(8)),'registryRevision' => Catalog::REVISION,'recordTypeCode' => $type,'recordId' => $id,'version' => $row['version'],'operation' => 'update','data' => $this->s->catalog->encode($type, $data)]);
    }
    private function fixture(): array
    {
        $supplier = $this->insert('supplier', ['NAME' => 'Supplier','STATUS' => 'active']);
        $depot = $this->insert('supplier_depot', ['SUPPLIER_ID' => $supplier,'NAME' => 'Depot','STATUS' => 'active']);
        $unit = $this->insert('unit_of_measure', ['NAME' => 'Bag','SYMBOL' => 'bag-'.bin2hex(random_bytes(3)),'DIMENSION' => 'count','STATUS' => 'active']);
        $category = $this->insert('product_category', ['NAME' => 'Cement','STATUS' => 'active']);
        $product = $this->insert('product', ['NAME' => 'Cement 42.5','CATEGORY_ID' => $category,'BASE_UNIT_ID' => $unit,'TRACKING_MODE' => 'quantity','INVENTORY_TRACKED' => true,'TAXABLE' => false,'STATUS' => 'active']);
        $customer = $this->insert('customer', ['COMPANY_NAME' => 'Builder','CUSTOMER_TYPE' => 'company','CREDIT_LIMIT' => 1000000,'STATUS' => 'active']);
        $warehouse = $this->insert('inventory_location', ['NAME' => 'Main warehouse','LOCATION_TYPE' => 'warehouse','STATUS' => 'active']);
        $price = $this->insert('price_list', ['NAME' => 'Retail','PRICE_TYPE' => 'retail','CURRENCY' => 'NGN','VALID_FROM' => '2026-01-01','STATUS' => 'active']);
        $this->insert('price_list_item', ['PRICE_LIST_ID' => $price,'PRODUCT_ID' => $product,'UNIT_ID' => $unit,'MIN_QUANTITY' => 1,'UNIT_PRICE' => 150,'STATUS' => 'active']);
        $purchase = $this->insert('purchase', ['SUPPLIER_ID' => $supplier,'SUPPLIER_DEPOT_ID' => $depot,'PURCHASE_DATE' => '2026-11-01','CURRENCY' => 'NGN']);
        $item = $this->insert('purchaseItem', ['PURCHASE_ID' => $purchase,'PRODUCT_ID' => $product,'UNIT_ID' => $unit,'ORIGINAL_QUANTITY' => 100,'UNIT_PRICE' => 100]);
        return compact('supplier', 'depot', 'unit', 'category', 'product', 'customer', 'warehouse', 'purchase', 'item');
    }
    private function loading(array $f, float $quantity = 60): int
    {
        $c = $this->s->catalog;
        return $this->insert('cement_loading', ['PURCHASE_ITEM_ID' => $f['item'],'SUPPLIER_DEPOT_ID' => $f['depot'],'LOADING_DATE' => '2026-11-02T10:00:00Z','UNIT_ID' => $f['unit'],'QUANTITY' => $quantity,'ALLOCATION_ROWS' => [
            [$c->fields['cement_loading']['QUANTITY'] => $quantity * 2 / 3,$c->fields['customer_sales']['CUSTOMER_ID'] => $f['customer']],
            [$c->fields['cement_loading']['QUANTITY'] => $quantity / 3,$c->fields['customer_sales']['WAREHOUSE_ID'] => $f['warehouse']],
        ],'STATUS' => 'confirmed']);
    }
    public function testPurchaseLoadingClearanceSalesReceiptPersistence(): void
    {
        $f = $this->fixture();
        $this->update('purchase', $f['purchase'], ['STATUS' => 'paid']);
        $load = $this->loading($f);
        self::assertEquals(60, $this->s->semantic(1, 'purchaseItem', $f['item'])['LOADED_QTY']);
        $stock = $this->s->all(1, 'inventory_stock');
        self::assertCount(1, $stock);
        self::assertEquals(20, $this->s->catalog->decode('inventory_stock', $stock[0]['data'])['QUANTITY_ON_HAND']);
        $this->insert('loading_clearance', ['PURCHASE_ITEM_ID' => $f['item'],'LOADING_ID' => $load,'CLEARANCE_DATE' => '2026-11-03','QUANTITY' => 10,'STATUS' => 'verified']);
        self::assertEquals(10, $this->s->semantic(1, 'purchaseItem', $f['item'])['CLEARED_QTY']);
        $sale = $this->s->all(1, 'customer_sales')[0];
        $d = $this->s->catalog->decode('customer_sales', $sale['data']);
        self::assertEquals(6000, $d['NET_AMOUNT']);
        self::assertSame('invoiced', $d['STATUS']);
        $this->insert('customer_payment', ['CUSTOMER_ID' => $f['customer'],'SALE_ID' => $sale['id'],'AMOUNT' => 1000,'PAYMENT_DATE' => '2026-11-04T10:00:00Z','PAYMENT_METHOD' => 'bank','STATUS' => 'completed']);
        $reopened = new Store($this->path);
        self::assertEquals(5000, $reopened->semantic(1, 'customer_sales', $sale['id'])['BALANCE_DUE']);
        $journals = $this->s->run('SELECT * FROM journals')->fetchAll(PDO::FETCH_ASSOC);
        self::assertNotEmpty($journals);
        foreach ($journals as $j) {
            self::assertGreaterThan(0, (int)$j['amount_minor']);
        }
    }
    public function testIdempotencyRejectsDifferentPayloadAndDoesNotDoublePost(): void
    {
        $f = $this->fixture();
        $r = $this->s->get(1, 'purchase', $f['purchase']);
        $e = ['requestId' => 'stable-request-123','registryRevision' => Catalog::REVISION,'recordTypeCode' => 'purchase','recordId' => $f['purchase'],'version' => $r['version'],'operation' => 'update','data' => $this->s->catalog->encode('purchase', ['STATUS' => 'paid'])];
        $m = new Mutation($this->s);
        $first = $m->execute($this->actor, $e);
        self::assertSame($first, $m->execute($this->actor, $e));
        self::assertEquals(1, $this->s->run('SELECT count(*) FROM journals')->fetchColumn());
        $e['data'] = $this->s->catalog->encode('purchase', ['STATUS' => 'draft']);
        $this->expectException(Problem::class);
        $m->execute($this->actor, $e);
    }
    public function testOverloadingRollsBackEverything(): void
    {
        $f = $this->fixture();
        $this->update('purchase', $f['purchase'], ['STATUS' => 'paid']);
        try {
            $this->loading($f, 101);
            self::fail('Overload accepted');
        } catch (Problem $e) {
            self::assertStringContainsString('entitlement', $e->getMessage());
        }
        self::assertCount(0, $this->s->all(1, 'cement_loading'));
        self::assertCount(0, $this->s->all(1, 'inventory_stock'));
        self::assertEquals(0, $this->s->semantic(1, 'purchaseItem', $f['item'])['LOADED_QTY']);
    }
    public function testLoadedPurchaseTermsAreImmutable(): void
    {
        $f = $this->fixture();
        $this->update('purchase', $f['purchase'], ['STATUS' => 'paid']);
        $this->loading($f);
        $this->expectException(Problem::class);
        $this->update('purchaseItem', $f['item'], ['UNIT_PRICE' => 110]);
    }
    public function testClearingCannotExceedLoading(): void
    {
        $f = $this->fixture();
        $this->update('purchase', $f['purchase'], ['STATUS' => 'paid']);
        $load = $this->loading($f);
        $this->expectException(Problem::class);
        $this->insert('loading_clearance', ['PURCHASE_ITEM_ID' => $f['item'],'LOADING_ID' => $load,'CLEARANCE_DATE' => '2026-11-03','QUANTITY' => 61,'STATUS' => 'verified']);
    }
    public function testCrossInstitutionReferenceIsRejected(): void
    {
        $f = $this->fixture();
        $this->s->run('INSERT INTO institutions(title,settings) VALUES (?,?)', ['Other','{}']);
        $this->actor['institution_id'] = 2;
        $this->expectException(Problem::class);
        $this->insert('supplier_depot', ['SUPPLIER_ID' => $f['supplier'],'NAME' => 'Wrong tenant']);
    }
    public function testDriverCannotReadPayrollOrCreatePurchase(): void
    {
        self::assertFalse($this->s->catalog->allowed('cement_driver', 'payroll'));
        $this->actor['role'] = 'cement_driver';
        $this->expectException(Problem::class);
        $this->insert('purchase', ['SUPPLIER_ID' => 1]);
    }
    public function testAtomicBatchRollsBackOnInvalidSecondRecord(): void
    {
        $e = ['requestId' => 'batch-invalid-request','registryRevision' => Catalog::REVISION,'batch' => [
            ['recordTypeCode' => 'supplier','operation' => 'insert','data' => $this->s->catalog->encode('supplier', ['CODE' => 'A','NAME' => 'A','STATUS' => 'active'])],
            ['recordTypeCode' => 'supplier','operation' => 'insert','data' => ['unknown' => 'no']],
        ]];
        try {
            (new Mutation($this->s))->execute($this->actor, $e);
            self::fail('Invalid batch accepted');
        } catch (Problem) {
        }
        self::assertCount(0, $this->s->all(1, 'supplier'));
    }
    public function testComputedStockCannotBeEditedDirectly(): void
    {
        $this->expectException(Problem::class);
        $this->insert('inventory_stock', []);
    }
    public function testTwentyReportsExecuteAndCsvNeutralizesSpreadsheetFormulas(): void
    {
        $this->fixture();
        $reports = new Reports($this->s);
        foreach ($this->s->catalog->json('report_templates') as $r) {
            $result = $reports->generate($this->actor, $r['code']);
            self::assertIsArray($result['rows']);
        }
        self::assertStringContainsString("'=HYPERLINK", $reports->csv(['columns' => ['Value'],'rows' => [['Value' => '=HYPERLINK("x")']]]));
    }
    public function testWeightedAverageAndFifoHaveDifferentIssueCosts(): void
    {
        foreach (['weighted_average' => 150,'fifo' => 100] as $method => $expected) {
            $f = $this->fixture();
            $settings = $this->s->settings(1);
            $settings['inventoryValuationMethod'] = $method;
            $this->s->run('UPDATE institutions SET settings=? WHERE id=1', [json_encode($settings)]);
            foreach ([100,200] as $cost) {
                $this->insert('inventory_movement', ['MOVEMENT_DATE' => '2026-11-01T10:00:00Z','MOVEMENT_TYPE' => 'opening','PRODUCT_ID' => $f['product'],'DESTINATION_LOCATION_ID' => $f['warehouse'],'STOCK_CONDITION' => 'good','QTY_BASE' => 10,'COST_POLICY' => $method,'UNIT_COST' => $cost,'STATUS' => 'confirmed']);
            }
            $issue = $this->insert('inventory_movement', ['MOVEMENT_DATE' => '2026-11-02T10:00:00Z','MOVEMENT_TYPE' => 'issue','PRODUCT_ID' => $f['product'],'SOURCE_LOCATION_ID' => $f['warehouse'],'STOCK_CONDITION' => 'good','QTY_BASE' => 5,'COST_POLICY' => $method,'STATUS' => 'confirmed']);
            self::assertEquals($expected, $this->s->semantic(1, 'inventory_movement', $issue)['UNIT_COST']);
        }
    }
    public function testDeliveryOwnershipRecognizesOnlyAcceptedQuantityAndAllowsPartialReceipt(): void
    {
        $settings = $this->s->settings(1);
        $settings['ownershipTransferPolicy'] = 'delivery-transfer';
        $this->s->run('UPDATE institutions SET settings=? WHERE id=1', [json_encode($settings)]);
        $f = $this->fixture();
        $this->update('purchase', $f['purchase'], ['STATUS' => 'paid']);
        $this->loading($f);
        $sale = $this->s->all(1, 'customer_sales')[0];
        self::assertSame('draft', $this->s->semantic(1, 'customer_sales', $sale['id'])['STATUS']);
        $proof = ['SALE_ID' => $sale['id'],'DELIVERED_DATE' => '2026-11-03T10:00:00Z','ACCEPTED_QUANTITY' => 10,'REJECTED_QUANTITY' => 0,'RECEIVER_NAME' => 'Receiver','STATUS' => 'confirmed'];
        $this->insert('delivery_proof', $proof);
        $d = $this->s->semantic(1, 'customer_sales', $sale['id']);
        self::assertSame('partially_invoiced', $d['STATUS']);
        self::assertEquals(1500, $d['BALANCE_DUE']);
        $this->insert('customer_payment', ['CUSTOMER_ID' => $f['customer'],'SALE_ID' => $sale['id'],'AMOUNT' => 500,'PAYMENT_DATE' => '2026-11-04T10:00:00Z','PAYMENT_METHOD' => 'bank','STATUS' => 'completed']);
        $this->insert('delivery_proof', array_replace($proof, ['ACCEPTED_QUANTITY' => 30]));
        $d = $this->s->semantic(1, 'customer_sales', $sale['id']);
        self::assertSame('invoiced', $d['STATUS']);
        self::assertEquals(5500, $d['BALANCE_DUE']);
        self::assertEquals(60, $this->s->semantic(1, 'purchaseItem', $f['item'])['DELIVERED_QTY']);
    }
    public function testStocktakePostsApprovedVarianceAndRejectsStaleCounts(): void
    {
        $f = $this->fixture();
        $this->insert('inventory_movement', ['MOVEMENT_DATE' => '2026-11-01T10:00:00Z','MOVEMENT_TYPE' => 'opening','PRODUCT_ID' => $f['product'],'DESTINATION_LOCATION_ID' => $f['warehouse'],'STOCK_CONDITION' => 'good','QTY_BASE' => 20,'COST_POLICY' => 'weighted_average','UNIT_COST' => 100,'STATUS' => 'confirmed']);
        $take = $this->insert('inventory_stocktake', ['LOCATION_ID' => $f['warehouse'],'COUNT_DATE' => '2026-11-02T10:00:00Z','INVENTORY_KEEPING_METHOD' => 'monthly']);
        $this->insert('inventory_stocktake_line', ['STOCKTAKE_ID' => $take,'PRODUCT_ID' => $f['product'],'STOCK_CONDITION' => 'good','COUNTED_QTY' => 18]);
        $this->update('inventory_stocktake', $take, ['STATUS' => 'posted']);
        $stock = $this->s->all(1, 'inventory_stock')[0];
        self::assertEquals(18, $this->s->catalog->decode('inventory_stock', $stock['data'])['QUANTITY_ON_HAND']);
    }
    public function testPayrollApprovalAndPartialPaymentArePersistentAndBounded(): void
    {
        $person = $this->insert('person', ['FIRST_NAME' => 'Ada','LAST_NAME' => 'Test','STATUS' => 'active']);
        $employment = $this->insert('staff_employment', ['PERSON_ID' => $person,'EMPLOYEE_NUMBER' => 'E1','HIRE_DATE' => '2026-01-01','EMPLOYMENT_TYPE' => 'full_time','BASIC_SALARY' => 1000,'STATUS' => 'active']);
        $payroll = $this->insert('payroll', ['PERIOD_START' => '2026-11-01','PERIOD_END' => '2026-11-30','PAYMENT_DATE' => '2026-11-30','CURRENCY' => 'NGN']);
        $item = $this->insert('payroll_item', ['PAYROLL_ID' => $payroll,'EMPLOYMENT_ID' => $employment,'BASIC_SALARY' => 1000,'TAX_AMOUNT' => 100,'ALLOWANCES' => [50],'DEDUCTIONS' => [25]]);
        $this->update('payroll', $payroll, ['STATUS' => 'posted']);
        self::assertEquals(925, $this->s->semantic(1, 'payroll', $payroll)['TOTAL_NET']);
        $payment = ['PAYROLL_ITEM_ID' => $item,'EMPLOYMENT_ID' => $employment,'PAID_DATE' => '2026-11-30','PAYMENT_METHOD' => 'bank','AMOUNT' => 500,'STATUS' => 'completed'];
        $this->insert('payroll_payment', $payment);
        $this->expectException(Problem::class);
        $this->insert('payroll_payment', $payment);
    }

    public function testBalancedManualJournalAndBankReconciliation(): void
    {
        $type = $this->insert('account_type', ['NAME' => 'Asset','CATEGORY' => 'asset','STATUS' => 'active']);
        $bank = $this->insert('account', ['CODE' => 'bank','NAME' => 'Bank','ACCOUNT_TYPE_ID' => $type,'CURRENCY' => 'NGN','STATUS' => 'active']);
        $equity = $this->insert('account', ['CODE' => 'capital','NAME' => 'Capital','ACCOUNT_TYPE_ID' => $type,'CURRENCY' => 'NGN','STATUS' => 'active']);
        $journal = $this->insert('journal', ['POSTED_AT' => gmdate('c'),'SOURCE' => 'opening']);
        $this->insert('journal_line', ['JOURNAL_ID' => $journal,'ACCOUNT_ID' => $bank,'DEBIT' => 1000,'CREDIT' => 0]);
        $this->insert('journal_line', ['JOURNAL_ID' => $journal,'ACCOUNT_ID' => $equity,'DEBIT' => 0,'CREDIT' => 1000]);
        $this->update('journal', $journal, ['STATUS' => 'posted']);
        $bankAccount = $this->insert('bank_account', ['NAME' => 'Business bank','ACCOUNT_ID' => $bank,'BANK_NAME' => 'Test bank','ACCOUNT_NUMBER' => '123456','CURRENCY' => 'NGN','STATUS' => 'active']);
        $statement = $this->insert('bank_statement', ['BANK_ACCOUNT_ID' => $bankAccount,'PERIOD_START' => gmdate('Y-m-01'),'PERIOD_END' => gmdate('Y-m-t'),'OPENING_BALANCE' => 0,'CLOSING_BALANCE' => 1000]);
        $this->insert('bank_statement_line', ['STATEMENT_ID' => $statement,'TRANSACTION_DATE' => gmdate('Y-m-d'),'DESCRIPTION' => 'Opening transfer','AMOUNT' => 1000,'JOURNAL_ID' => $journal]);
        $reconciliation = $this->insert('bank_reconciliation', ['STATEMENT_ID' => $statement,'STATUS' => 'confirmed']);
        self::assertEquals(0, $this->s->semantic(1, 'bank_reconciliation', $reconciliation)['DIFFERENCE']);
        self::assertSame('reconciled', $this->s->semantic(1, 'bank_statement', $statement)['STATUS']);
    }
    public function testCooperativeAccountsAreReusedByPayrollDeductions(): void
    {
        $person = $this->insert('person', ['FIRST_NAME' => 'Staff','LAST_NAME' => 'Member','STATUS' => 'active']);
        $member = $this->insert('member', ['PERSON_ID' => $person,'MEMBER_NUMBER' => 'M1','STATUS' => 'active']);
        $savings = $this->insert('member_account', ['MEMBER_ID' => $member,'ACCOUNT_TYPE' => 'savings','STATUS' => 'active']);
        $loan = $this->insert('member_account', ['MEMBER_ID' => $member,'ACCOUNT_TYPE' => 'loan','BALANCE_LIMIT' => 500,'STATUS' => 'active']);
        $this->insert('cooperative_transaction', ['MEMBER_ACCOUNT_ID' => $loan,'POSTING_DATE' => '2026-11-01','AMOUNT' => 400,'TRANSACTION_TYPE' => 'loan_disbursement','STATUS' => 'posted']);
        $employment = $this->insert('staff_employment', ['PERSON_ID' => $person,'EMPLOYEE_NUMBER' => 'COOP-E1','HIRE_DATE' => '2026-01-01','EMPLOYMENT_TYPE' => 'full_time','BASIC_SALARY' => 1000,'COOPERATIVE_MEMBER_ID' => $member,'DEFAULT_SAVINGS_ACCOUNT_ID' => $savings,'DEFAULT_LOAN_ACCOUNT_ID' => $loan,'STATUS' => 'active']);
        $payroll = $this->insert('payroll', ['PERIOD_START' => '2026-11-01','PERIOD_END' => '2026-11-30','PAYMENT_DATE' => '2026-11-30','CURRENCY' => 'NGN']);
        $this->insert('payroll_item', ['PAYROLL_ID' => $payroll,'EMPLOYMENT_ID' => $employment,'BASIC_SALARY' => 1000,'SAVINGS_DEDUCTION' => 50,'LOAN_DEDUCTION' => 100]);
        $this->update('payroll', $payroll, ['STATUS' => 'posted']);
        self::assertEquals(850, $this->s->semantic(1, 'payroll', $payroll)['TOTAL_NET']);
        self::assertEquals(50, $this->s->semantic(1, 'member_account', $savings)['BALANCE']);
        self::assertEquals(300, $this->s->semantic(1, 'member_account', $loan)['BALANCE']);
        self::assertCount(3, $this->s->all(1, 'cooperative_transaction'));
    }
    public function testSchedulerGeneratesOncePerPeriodWithoutFabricatingEmailDelivery(): void
    {
        $report = $this->s->all(1, 'report')[0];
        $this->insert('report_schedule', ['REPORT_ID' => $report['id'],'CADENCE' => 'daily','TIMEZONE' => 'Africa/Lagos','RECIPIENTS' => ['recipient@example.test'],'OUTPUT_FORMAT' => 'csv','ENABLED' => true,'STATUS' => 'active']);
        $scheduler = new \App\Libraries\Cement\Scheduler($this->s);
        $now = new DateTimeImmutable('2026-11-16T12:00:00Z');
        $scheduler->run($now);
        $scheduler->run($now);
        self::assertCount(1, $this->s->all(1, 'report_result'));
        $deliveries = $this->s->all(1, 'report_delivery');
        self::assertCount(1, $deliveries);
        self::assertSame('pending', $this->s->catalog->decode('report_delivery', $deliveries[0]['data'])['DELIVERY_STATUS']);
    }
    public function testXlsxIsAValidWorkbookAndDoesNotExecuteCellFormulas(): void
    {
        $content = (new Reports($this->s))->xlsx(['columns' => ['Column'],'rows' => [['Column' => '=2+2']]]);
        $file = tempnam(sys_get_temp_dir(), 'xlsx-test');
        file_put_contents($file, $content);
        $zip = new ZipArchive();
        self::assertTrue($zip->open($file));
        self::assertNotFalse($zip->locateName('xl/workbook.xml'));
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        self::assertStringContainsString('inlineStr', $sheet);
        self::assertStringNotContainsString('<f>', $sheet);
        $zip->close();
        unlink($file);
    }
    public function testPaidInvoiceReturnProducesActualRefundWithoutNegativeReceivable(): void
    {
        $f = $this->fixture();
        $this->update('purchase', $f['purchase'], ['STATUS' => 'paid']);
        $this->loading($f);
        $sale = $this->s->all(1, 'customer_sales')[0];
        $line = $this->s->all(1, 'customer_sale_item')[0];
        $this->insert('customer_payment', ['CUSTOMER_ID' => $f['customer'],'SALE_ID' => $sale['id'],'AMOUNT' => 6000,'PAYMENT_DATE' => '2026-11-04T10:00:00Z','PAYMENT_METHOD' => 'bank','STATUS' => 'completed']);
        $return = $this->insert('customer_sale_return', ['SALE_ID' => $sale['id'],'SALE_ITEM_ID' => $line['id'],'QUANTITY' => 10,'UNIT_ID' => $f['unit'],'RETURN_REASON' => 'Customer return','DISPOSITION' => 'write_off','STATUS' => 'confirmed']);
        self::assertEquals(1500, $this->s->semantic(1, 'customer_sale_return', $return)['REFUND_AMOUNT']);
        self::assertEquals(0, $this->s->semantic(1, 'customer_sales', $sale['id'])['BALANCE_DUE']);
    }
    public function testDamageQuarantineMovesConditionWithoutChangingTotalStock(): void
    {
        $f = $this->fixture();
        $this->insert('inventory_movement', ['MOVEMENT_DATE' => '2026-11-01T10:00:00Z','MOVEMENT_TYPE' => 'opening','PRODUCT_ID' => $f['product'],'DESTINATION_LOCATION_ID' => $f['warehouse'],'STOCK_CONDITION' => 'good','QTY_BASE' => 20,'COST_POLICY' => 'weighted_average','UNIT_COST' => 100,'STATUS' => 'confirmed']);
        $this->insert('damaged_goods', ['LOCATION_ID' => $f['warehouse'],'PRODUCT_ID' => $f['product'],'QUANTITY' => 5,'REASON' => 'Torn bags','CONDITION' => 'good','DECISION' => 'quarantine','STATUS' => 'confirmed']);
        $balances = [];
        foreach ($this->s->all(1, 'inventory_stock') as $r) {
            $d = $this->s->catalog->decode('inventory_stock', $r['data']);
            $balances[$d['STOCK_CONDITION']] = $d['QUANTITY_ON_HAND'];
        }self::assertEquals(['good' => 15,'damaged' => 5], $balances);
    }
    public function testMetadataValidatorRejectsMissingAliasesAndFieldCollisions(): void
    {
        $fields = $this->s->catalog->fields['purchase'];
        $meta = ['revision' => 'mf:2','fieldsByRecordType' => ['purchase' => $fields],'recordTypeIds' => ['purchase' => 55]];
        $meta['fieldsByRecordType']['purchase']['SUPPLIER_PIN'] = 'supplierAccessRef';
        self::assertTrue($this->s->catalog->validateMetadata($meta)['authoritative']);
        unset($meta['fieldsByRecordType']['purchase']['CODE']);
        $this->expectException(Problem::class);
        $this->s->catalog->validateMetadata($meta);
    }

    public function testQuantityAdjustmentPreservesOriginalSnapshotAndExtendsEntitlement(): void
    {
        $f = $this->fixture();
        $this->update('purchase', $f['purchase'], ['STATUS' => 'paid']);
        $this->insert('purchase_adjustment', ['PURCHASE_ID' => $f['purchase'],'PURCHASE_ITEM_ID' => $f['item'],'ADJUSTMENT_TYPE' => 'quantity','QUANTITY' => 20,'REASON' => 'Supplier approved additional entitlement','STATUS' => 'posted']);
        self::assertEquals(100, $this->s->semantic(1, 'purchaseItem', $f['item'])['ORIGINAL_QUANTITY']);
        self::assertEquals(12000, $this->s->semantic(1, 'purchase', $f['purchase'])['NET_AMOUNT']);
        $this->loading($f, 120);
        self::assertEquals(120, $this->s->semantic(1, 'purchaseItem', $f['item'])['LOADED_QTY']);
    }
    public function testDataEntryCannotApproveMoneyMovement(): void
    {
        $f = $this->fixture();
        $this->actor['role'] = 'cement_data_entry';
        $this->expectException(Problem::class);
        $this->update('purchase', $f['purchase'], ['STATUS' => 'paid']);
    }
    public function testFundedItemCannotBeDeleted(): void
    {
        $f = $this->fixture();
        $this->update('purchase', $f['purchase'], ['STATUS' => 'paid']);
        $r = $this->s->get(1, 'purchaseItem', $f['item']);
        $this->expectException(Problem::class);
        (new Mutation($this->s))->execute($this->actor, ['requestId' => 'delete-funded-item','registryRevision' => Catalog::REVISION,'recordTypeCode' => 'purchaseItem','operation' => 'delete','recordId' => $f['item'],'version' => $r['version'],'data' => []]);
    }
    public function testStaleRecordVersionIsRejectedAndDeletionEmitsTombstone(): void
    {
        $supplier = $this->insert('supplier', ['NAME' => 'One']);
        $old = $this->s->get(1, 'supplier', $supplier);
        $this->update('supplier', $supplier, ['NAME' => 'Two']);
        try {
            (new Mutation($this->s))->execute($this->actor, ['requestId' => 'stale-version-test','registryRevision' => Catalog::REVISION,'recordTypeCode' => 'supplier','operation' => 'update','recordId' => $supplier,'version' => $old['version'],'data' => $this->s->catalog->encode('supplier', ['NAME' => 'Three'])]);
            self::fail('Stale edit accepted');
        } catch (Problem $e) {
            self::assertSame(409, $e->httpStatus);
        }
        $row = $this->s->get(1, 'supplier', $supplier);
        (new Mutation($this->s))->execute($this->actor, ['requestId' => 'tombstone-delete-test','registryRevision' => Catalog::REVISION,'recordTypeCode' => 'supplier','operation' => 'delete','recordId' => $supplier,'version' => $row['version'],'data' => []]);
        self::assertCount(0, $this->s->all(1, 'supplier'));
        $deleted = $this->s->run('SELECT deleted,mutation_id FROM records WHERE id=?', [$supplier])->fetch(PDO::FETCH_ASSOC);
        self::assertEquals(1, $deleted['deleted']);
        self::assertGreaterThan($row['mutation_id'], $deleted['mutation_id']);
    }
    public function testPayrollSnapshotsAreScopedSeparatelyFromTheirGenericRecordType(): void
    {
        $report = null;
        foreach ($this->s->all(1, 'report') as $r) {
            if ($this->s->catalog->decode('report', $r['data'])['CODE'] === 'cement.payroll_summary') {
                $report = $r['id'];
            }
        }
        $data = ['REPORT_ID' => $report,'GENERATED_AT' => gmdate('c'),'ROWS' => [['Employee' => 'Private','Net' => 1000]],'STATUS' => 'completed'];
        $scope = new \App\Libraries\Cement\Scope($this->s);
        self::assertTrue($scope->allows($this->actor, 'report_result', $data));
        $sales = $this->actor;
        $sales['role'] = 'cement_sales_manager';
        self::assertFalse($scope->allows($sales, 'report_result', $data));
    }
    public function testDriverCanAccessOnlyAssignedTrips(): void
    {
        $one = $this->insert('person', ['FIRST_NAME' => 'One','LAST_NAME' => 'Driver']);
        $two = $this->insert('person', ['FIRST_NAME' => 'Two','LAST_NAME' => 'Driver']);
        $vehicle = $this->insert('vehicle', ['REGISTRATION_NUMBER' => 'TEST-001','VEHICLE_TYPE' => 'truck']);
        $first = $this->insert('trip', ['DRIVER_ID' => $one,'VEHICLE_ID' => $vehicle]);
        $second = $this->insert('trip', ['DRIVER_ID' => $two,'VEHICLE_ID' => $vehicle]);
        $driver = $this->actor;
        $driver['role'] = 'cement_driver';
        $driver['person_id'] = $one;
        $scope = new \App\Libraries\Cement\Scope($this->s);
        self::assertTrue($scope->allows($driver, 'trip', $this->s->semantic(1, 'trip', $first)));
        self::assertFalse($scope->allows($driver, 'trip', $this->s->semantic(1, 'trip', $second)));
    }
    public function testFiscalCloseClearsIncomeAndBlocksBackdatedPosting(): void
    {
        $asset = $this->insert('account_type', ['NAME' => 'Asset','CATEGORY' => 'asset']);
        $income = $this->insert('account_type', ['NAME' => 'Income','CATEGORY' => 'income']);
        $bank = $this->insert('account', ['CODE' => 'bank','NAME' => 'Bank','ACCOUNT_TYPE_ID' => $asset,'CURRENCY' => 'NGN','STATUS' => 'active']);
        $revenue = $this->insert('account', ['CODE' => 'service_income','NAME' => 'Income','ACCOUNT_TYPE_ID' => $income,'CURRENCY' => 'NGN','STATUS' => 'active']);
        $make = function () use ($bank, $revenue) {
            $j = $this->insert('journal', ['POSTED_AT' => '2025-06-01T10:00:00Z','SOURCE' => 'Manual opening']);
            $this->insert('journal_line', ['JOURNAL_ID' => $j,'ACCOUNT_ID' => $bank,'DEBIT' => 200,'CREDIT' => 0]);
            $this->insert('journal_line', ['JOURNAL_ID' => $j,'ACCOUNT_ID' => $revenue,'DEBIT' => 0,'CREDIT' => 200]);
            return $j;
        };
        $j = $make();
        $this->update('journal', $j, ['STATUS' => 'posted']);
        $year = $this->insert('fiscal_year', ['NAME' => '2025','START_DATE' => '2025-01-01','END_DATE' => '2025-12-31','STATUS' => 'active']);
        $this->update('fiscal_year', $year, ['STATUS' => 'closed']);
        foreach ($this->s->all(1, 'account_balance') as $r) {
            $d = $this->s->catalog->decode('account_balance', $r['data']);
            if ($d['ACCOUNT_ID'] === $revenue) {
                self::assertEquals(0, $d['BALANCE']);
            }
        }
        $another = $make();
        $this->expectException(Problem::class);
        $this->update('journal', $another, ['STATUS' => 'posted']);
    }
    public function testStocktakeRejectsAStalePhysicalCount(): void
    {
        $f = $this->fixture();
        $movement = ['MOVEMENT_DATE' => '2026-11-01T10:00:00Z','MOVEMENT_TYPE' => 'opening','PRODUCT_ID' => $f['product'],'DESTINATION_LOCATION_ID' => $f['warehouse'],'STOCK_CONDITION' => 'good','QTY_BASE' => 20,'COST_POLICY' => 'weighted_average','UNIT_COST' => 100,'STATUS' => 'confirmed'];
        $this->insert('inventory_movement', $movement);
        $take = $this->insert('inventory_stocktake', ['LOCATION_ID' => $f['warehouse'],'COUNT_DATE' => '2026-11-02T10:00:00Z','INVENTORY_KEEPING_METHOD' => 'monthly']);
        $this->insert('inventory_stocktake_line', ['STOCKTAKE_ID' => $take,'PRODUCT_ID' => $f['product'],'STOCK_CONDITION' => 'good','COUNTED_QTY' => 18]);
        $this->insert('inventory_movement', $movement);
        $this->expectException(Problem::class);
        $this->update('inventory_stocktake', $take, ['STATUS' => 'posted']);
    }

    public function testCustomerDepositFundsLoadingWhenCreditSalesAreDisabled(): void
    {
        $settings = $this->s->settings(1);
        $settings['allowCreditSales'] = false;
        $this->s->run('UPDATE institutions SET settings=? WHERE id=1', [json_encode($settings)]);
        $f = $this->fixture();
        $this->insert('customer_payment', ['CUSTOMER_ID' => $f['customer'],'AMOUNT' => 6000,'PAYMENT_DATE' => '2026-11-01T10:00:00Z','PAYMENT_METHOD' => 'bank','STATUS' => 'completed']);
        $this->update('purchase', $f['purchase'], ['STATUS' => 'paid']);
        $this->loading($f);
        $sale = $this->s->all(1, 'customer_sales')[0];
        $d = $this->s->catalog->decode('customer_sales', $sale['data']);
        self::assertEquals(6000, $d['AMOUNT_PAID']);
        self::assertEquals(0, $d['BALANCE_DUE']);
        self::assertEquals(600000, $this->s->run('SELECT SUM(amount_minor) FROM payment_allocations')->fetchColumn());
        $statement = (new \App\Libraries\Cement\CustomerStatement($this->s))->generate($this->actor, ['customerId' => $f['customer']]);
        self::assertEquals(0, $statement['closingReceivable']);
        self::assertEquals(0, $statement['closingDeposit']);
        foreach ($statement['rows'] as $event) {
            self::assertGreaterThanOrEqual(0, $event['Deposit balance']);
        }
    }
    public function testXlsxImportReadsValuesAndRejectsFormulas(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'cement-import-');
        file_put_contents($file, Reports::xlsx(['columns' => ['CODE','NAME','STATUS'],'rows' => [['CODE' => 'S1','NAME' => 'Supplier','STATUS' => 'active']]]));
        $reader = new \App\Libraries\Cement\ImportPreview($this->s->catalog);
        $rows = $reader->xlsx($file, 'supplier');
        self::assertSame(['S1','Supplier','active'], $rows[1]);
        $zip = new ZipArchive();
        $zip->open($file);
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->addFromString('xl/worksheets/sheet1.xml', str_replace('<c t="inlineStr">', '<c><f>1+1</f>', $sheet));
        $zip->close();
        try {
            $reader->xlsx($file, 'supplier');
            self::fail('Formula accepted');
        } catch (Problem $e) {
            self::assertStringContainsString('Formula', $e->getMessage());
        } finally {
            unlink($file);
        }
    }
    public function testTripCompletesOnlyAfterProofAndIncludesActualFuelCost(): void
    {
        $f = $this->fixture();
        $this->update('purchase', $f['purchase'], ['STATUS' => 'paid']);
        $load = $this->loading($f);
        $sale = $this->s->all(1, 'customer_sales')[0];
        $person = $this->insert('person', ['FIRST_NAME' => 'Trip','LAST_NAME' => 'Driver']);
        $vehicle = $this->insert('vehicle', ['REGISTRATION_NUMBER' => 'TRIP-001','VEHICLE_TYPE' => 'truck']);
        $trip = $this->insert('trip', ['VEHICLE_ID' => $vehicle,'DRIVER_ID' => $person,'LOADING_ID' => $load]);
        $stop = $this->insert('trip_destination', ['TRIP_ID' => $trip,'DESTINATION_ORDER' => 1,'CUSTOMER_ID' => $f['customer'],'SALE_ID' => $sale['id'],'PRODUCT_ID' => $f['product'],'QUANTITY' => 40,'UNIT_ID' => $f['unit']]);
        $this->insert('fuel_transaction', ['VEHICLE_ID' => $vehicle,'TRIP_ID' => $trip,'FUEL_DATE' => '2026-11-04T10:00:00Z','LITRES' => 5,'PRICE_PER_LITRE' => 1000,'STATUS' => 'posted']);
        $this->insert('delivery_proof', ['SALE_ID' => $sale['id'],'TRIP_DESTINATION_ID' => $stop,'DELIVERED_DATE' => '2026-11-04T12:00:00Z','ACCEPTED_QUANTITY' => 40,'STATUS' => 'confirmed']);
        $this->update('trip', $trip, ['STATUS' => 'completed']);
        self::assertEquals(5000, $this->s->semantic(1, 'trip', $trip)['TOTAL_TRIP_COST']);
    }

    public function testImportsBindTypeAndRetryOnlyFailedRows(): void
    {
        $importer = new \App\Libraries\Cement\Importer($this->s);
        $input = ['recordTypeCode' => 'supplier','registryRevision' => Catalog::REVISION,'period' => '2026-11','filename' => 'source.csv','sourceFileDigest' => str_repeat('a', 64),'rows' => [$this->s->catalog->encode('supplier', ['CODE' => 'IMPORT-1','NAME' => 'Import','STATUS' => 'active'])]];
        self::assertSame('confirmed', $importer->run($this->actor, $input)['outcomes'][0]['state']);
        $input['recordTypeCode'] = 'product_category';
        self::assertSame('confirmed', $importer->run($this->actor, $input)['outcomes'][0]['state']);
        self::assertCount(1, $this->s->all(1, 'supplier'));
        self::assertCount(1, $this->s->all(1, 'product_category'));
        $next = (int)$this->s->run('SELECT MAX(id)+1 FROM records')->fetchColumn();
        $pending = ['recordTypeCode' => 'supplier_depot','registryRevision' => Catalog::REVISION,'period' => '2026-11','rows' => [$this->s->catalog->encode('supplier_depot', ['CODE' => 'DEPOT-IMPORT','NAME' => 'Depot','SUPPLIER_ID' => $next,'STATUS' => 'active'])]];
        self::assertSame('failed', $importer->run($this->actor, $pending)['outcomes'][0]['state']);
        $this->insert('supplier', ['NAME' => 'Missing supplier']);
        $confirmed = $importer->run($this->actor, $pending);
        self::assertSame('confirmed', $confirmed['outcomes'][0]['state']);
        self::assertTrue($importer->run($this->actor, $pending)['replayed']);
        self::assertCount(1, $this->s->all(1, 'supplier_depot'));
    }
    public function testActiveDeductionSchedulesAreSnapshottedIntoPayroll(): void
    {
        $person = $this->insert('person', ['FIRST_NAME' => 'Scheduled','LAST_NAME' => 'Staff']);
        $employee = $this->insert('staff_employment', ['PERSON_ID' => $person,'EMPLOYEE_NUMBER' => 'SCHEDULED','HIRE_DATE' => '2026-01-01','EMPLOYMENT_TYPE' => 'full_time','BASIC_SALARY' => 1000]);
        $this->insert('staff_deduction', ['EMPLOYMENT_ID' => $employee,'DEDUCTION_TYPE' => 'tax','FIXED_AMOUNT' => 100,'START_DATE' => '2026-01-01','STATUS' => 'active']);
        $payroll = $this->insert('payroll', ['PERIOD_START' => '2026-11-01','PERIOD_END' => '2026-11-30','PAYMENT_DATE' => '2026-11-30','CURRENCY' => 'NGN']);
        $item = $this->insert('payroll_item', ['PAYROLL_ID' => $payroll,'EMPLOYMENT_ID' => $employee,'BASIC_SALARY' => 1000]);
        self::assertEquals(900, $this->s->semantic(1, 'payroll_item', $item)['NET_PAY']);
    }

    public function testEachStopOfASplitSaleCompletesIndependently(): void
    {
        $f = $this->fixture();
        $this->update('purchase', $f['purchase'], ['STATUS' => 'paid']);
        $load = $this->loading($f);
        $sale = $this->s->all(1, 'customer_sales')[0];
        $person = $this->insert('person', ['FIRST_NAME' => 'Split','LAST_NAME' => 'Driver']);
        $vehicle = $this->insert('vehicle', ['REGISTRATION_NUMBER' => 'SPLIT-001','VEHICLE_TYPE' => 'truck']);
        $trip = $this->insert('trip', ['VEHICLE_ID' => $vehicle,'DRIVER_ID' => $person,'LOADING_ID' => $load]);
        foreach ([1,2] as $order) {
            $stop = $this->insert('trip_destination', ['TRIP_ID' => $trip,'DESTINATION_ORDER' => $order,'CUSTOMER_ID' => $f['customer'],'SALE_ID' => $sale['id'],'PRODUCT_ID' => $f['product'],'QUANTITY' => 20,'UNIT_ID' => $f['unit']]);
            $this->insert('delivery_proof', ['SALE_ID' => $sale['id'],'TRIP_DESTINATION_ID' => $stop,'DELIVERED_DATE' => '2026-11-04T12:00:00Z','ACCEPTED_QUANTITY' => 20,'STATUS' => 'confirmed']);
            self::assertSame('delivered', $this->s->semantic(1, 'trip_destination', $stop)['DELIVERY_STATUS']);
        }
        $this->update('trip', $trip, ['STATUS' => 'completed']);
        $report = (new Reports($this->s))->generate($this->actor, 'cement.trip_profit');
        self::assertEquals(6000, array_sum(array_column($report['rows'], 'Net sales')));
        self::assertEquals(2000, array_sum(array_column($report['rows'], 'Contribution after transport')));
    }
    public function testDirectDeliveryReturnCanUseAnAuthorizedDraftWarehouseReceipt(): void
    {
        $f = $this->fixture();
        $this->update('purchase', $f['purchase'], ['STATUS' => 'paid']);
        $this->loading($f);
        $sale = $this->s->all(1, 'customer_sales')[0];
        $line = $this->s->all(1, 'customer_sale_item')[0];
        $receipt = $this->insert('inventory_movement', ['MOVEMENT_DATE' => '2026-11-05T10:00:00Z','MOVEMENT_TYPE' => 'return','PRODUCT_ID' => $f['product'],'DESTINATION_LOCATION_ID' => $f['warehouse'],'STOCK_CONDITION' => 'good','QTY_BASE' => 10,'COST_POLICY' => 'weighted_average']);
        $this->insert('customer_sale_return', ['SALE_ID' => $sale['id'],'SALE_ITEM_ID' => $line['id'],'QUANTITY' => 10,'UNIT_ID' => $f['unit'],'RETURN_REASON' => 'Customer return','DISPOSITION' => 'restock','STOCK_MOVEMENT_ID' => $receipt,'STATUS' => 'confirmed']);
        self::assertSame('confirmed', $this->s->semantic(1, 'inventory_movement', $receipt)['STATUS']);
        $stock = $this->s->all(1, 'inventory_stock')[0];
        self::assertEquals(30, $this->s->catalog->decode('inventory_stock', $stock['data'])['QUANTITY_ON_HAND']);
    }

    public function testReservationCompetitionReleaseAndConsumption(): void
    {
        $f = $this->fixture();
        $this->update('purchase', $f['purchase'], ['STATUS' => 'paid']);
        $this->loading($f);
        $sale = $this->insert('customer_sales', ['CUSTOMER_ID' => $f['customer'],'SALE_DATE' => '2026-11-03','WAREHOUSE_ID' => $f['warehouse']]);
        $this->insert('customer_sale_item', ['SALE_ID' => $sale,'PRODUCT_ID' => $f['product'],'UNIT_ID' => $f['unit'],'QUANTITY' => 15,'AGREED_UNIT_PRICE' => 150]);
        $reservation = $this->insert('inventory_reservation', ['SALE_ID' => $sale,'LOCATION_ID' => $f['warehouse'],'PRODUCT_ID' => $f['product'],'QUANTITY_BASE' => 15,'STATUS' => 'active']);
        $stock = $this->s->all(1, 'inventory_stock')[0];
        self::assertEquals(5, $this->s->semantic(1, 'inventory_stock', $stock['id'])['QUANTITY_AVAILABLE']);
        $journals = $this->s->run('SELECT count(*) FROM journals')->fetchColumn();
        try {
            $this->insert('inventory_reservation', ['SALE_ID' => $sale,'LOCATION_ID' => $f['warehouse'],'PRODUCT_ID' => $f['product'],'QUANTITY_BASE' => 6,'STATUS' => 'active']);
            self::fail('Overbooking accepted');
        } catch (Problem $e) {
            self::assertStringContainsString('available stock', $e->getMessage());
        }
        self::assertEquals($journals, $this->s->run('SELECT count(*) FROM journals')->fetchColumn());
        $this->update('customer_sales', $sale, ['STATUS' => 'invoiced']);
        self::assertSame('consumed', $this->s->semantic(1, 'inventory_reservation', $reservation)['STATUS']);
        $stock = $this->s->semantic(1, 'inventory_stock', $stock['id']);
        self::assertEquals(5, $stock['QUANTITY_ON_HAND']);
        self::assertEquals(0, $stock['QUANTITY_RESERVED']);
        self::assertEquals(5, $stock['QUANTITY_AVAILABLE']);
        $other = $this->insert('customer_sales', ['CUSTOMER_ID' => $f['customer'],'SALE_DATE' => '2026-11-03','WAREHOUSE_ID' => $f['warehouse']]);
        $this->insert('customer_sale_item', ['SALE_ID' => $other,'PRODUCT_ID' => $f['product'],'UNIT_ID' => $f['unit'],'QUANTITY' => 5,'AGREED_UNIT_PRICE' => 150]);
        $hold = $this->insert('inventory_reservation', ['SALE_ID' => $other,'LOCATION_ID' => $f['warehouse'],'PRODUCT_ID' => $f['product'],'QUANTITY_BASE' => 5,'STATUS' => 'active']);
        $this->update('inventory_reservation', $hold, ['STATUS' => 'released']);
        self::assertEquals(0, $this->s->semantic(1, 'inventory_reservation', $hold)['REMAINING_QTY']);
        self::assertEquals(5, $this->s->catalog->decode('inventory_stock', $this->s->all(1, 'inventory_stock')[0]['data'])['QUANTITY_AVAILABLE']);
    }
    public function testPermissionChangesAreRevisionedAndCannotExposePayroll(): void
    {
        $permissions = new \App\Libraries\Cement\Permissions($this->s);
        $this->s->loadPermissions(1);
        $before = $permissions->read($this->actor);
        $updated = $permissions->update($this->actor, ['revision' => $before['revision'],'role' => 'cement_data_entry','permissions' => ['supplier' => ['read' => true,'write' => false]]]);
        self::assertNotSame($before['revision'], $updated['revision']);
        self::assertTrue($this->s->catalog->allowed('cement_data_entry', 'supplier'));
        self::assertFalse($this->s->catalog->allowed('cement_data_entry', 'supplier', true));
        try {
            $permissions->update($this->actor, ['revision' => $before['revision'],'role' => 'cement_data_entry','permissions' => []]);
            self::fail('Stale permissions accepted');
        } catch (Problem $e) {
            self::assertSame(409, $e->httpStatus);
        }
        try {
            $permissions->update($this->actor, ['revision' => $updated['revision'],'role' => 'cement_driver','permissions' => ['payroll' => ['read' => true,'write' => false]]]);
            self::fail('Confidential access accepted');
        } catch (Problem $e) {
            self::assertStringContainsString('confidential', $e->getMessage());
        }
        $this->s->loadPermissions(2);
        self::assertEmpty($this->s->catalog->permissionOverrides);
    }
    public function testStatementReconcilesInvoicesReceiptsAndPeriodOpening(): void
    {
        $f = $this->fixture();
        $this->update('purchase', $f['purchase'], ['STATUS' => 'paid']);
        $this->loading($f);
        $sale = $this->s->all(1, 'customer_sales')[0];
        $this->insert('customer_payment', ['CUSTOMER_ID' => $f['customer'],'SALE_ID' => $sale['id'],'AMOUNT' => 1000,'PAYMENT_DATE' => '2026-11-04T10:00:00Z','PAYMENT_METHOD' => 'bank','STATUS' => 'completed']);
        $service = new \App\Libraries\Cement\CustomerStatement($this->s);
        $statement = $service->generate($this->actor, ['customerId' => $f['customer']]);
        self::assertEquals(5000, $statement['closingReceivable']);
        self::assertEquals(0, $statement['closingDeposit']);
        self::assertCount(2, $statement['rows']);
        $future = $service->generate($this->actor, ['customerId' => $f['customer'],'from' => gmdate('Y-m-d', time() + 86400)]);
        self::assertEquals(5000, $future['openingReceivable']);
        self::assertSame([],$future['rows']);
        $this->expectException(Problem::class);
        $service->generate($this->actor,['customerId' => $f['customer'],'from' => '2026-02-31']);
    }

}
