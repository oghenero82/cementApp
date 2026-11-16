import { InventoryReservationPresenter } from './InventoryReservationPresenter.js';
import { CementAuditEventPresenter } from './CementAuditEventPresenter.js';
import { CementBranchPresenter } from './CementBranchPresenter.js';
import { CementLoadingPresenter } from './CementLoadingPresenter.js';
import { CustomerPaymentPresenter } from './CustomerPaymentPresenter.js';
import { CustomerSaleItemPresenter } from './CustomerSaleItemPresenter.js';
import { CustomerSaleReturnPresenter } from './CustomerSaleReturnPresenter.js';
import { CustomerSalesPresenter } from './CustomerSalesPresenter.js';
import { CustomerPresenter } from './CustomerPresenter.js';
import { DamagedGoodsPresenter } from './DamagedGoodsPresenter.js';
import { DeliveryProofPresenter } from './DeliveryProofPresenter.js';
import { ExpensePresenter } from './ExpensePresenter.js';
import { FuelTransactionPresenter } from './FuelTransactionPresenter.js';
import { InventoryCostLayerPresenter } from './InventoryCostLayerPresenter.js';
import { InventoryLocationPresenter } from './InventoryLocationPresenter.js';
import { InventoryMovementPresenter } from './InventoryMovementPresenter.js';
import { InventoryStockPresenter } from './InventoryStockPresenter.js';
import { InventoryStocktakeLinePresenter } from './InventoryStocktakeLinePresenter.js';
import { InventoryStocktakePresenter } from './InventoryStocktakePresenter.js';
import { InventoryTransferPresenter } from './InventoryTransferPresenter.js';
import { LoadingClearancePresenter } from './LoadingClearancePresenter.js';
import { PayrollItemPresenter } from './PayrollItemPresenter.js';
import { PayrollPaymentPresenter } from './PayrollPaymentPresenter.js';
import { PayrollPresenter } from './PayrollPresenter.js';
import { PersonPresenter } from './PersonPresenter.js';
import { PriceListItemPresenter } from './PriceListItemPresenter.js';
import { PriceListPresenter } from './PriceListPresenter.js';
import { ProductCategoryPresenter } from './ProductCategoryPresenter.js';
import { ProductPresenter } from './ProductPresenter.js';
import { PurchaseAdjustmentPresenter } from './PurchaseAdjustmentPresenter.js';
import { PurchaseItemPresenter } from './PurchaseItemPresenter.js';
import { PurchasePresenter } from './PurchasePresenter.js';
import { ReportDeliveryPresenter } from './ReportDeliveryPresenter.js';
import { ReportResultPresenter } from './ReportResultPresenter.js';
import { ReportSchedulePresenter } from './ReportSchedulePresenter.js';
import { ReportPresenter } from './ReportPresenter.js';
import { StaffDeductionPresenter } from './StaffDeductionPresenter.js';
import { StaffEmploymentPresenter } from './StaffEmploymentPresenter.js';
import { StockAdjustmentPresenter } from './StockAdjustmentPresenter.js';
import { SupplierDepotPresenter } from './SupplierDepotPresenter.js';
import { SupplierIncentiveReceiptPresenter } from './SupplierIncentiveReceiptPresenter.js';
import { SupplierIncentiveSchemePresenter } from './SupplierIncentiveSchemePresenter.js';
import { SupplierIncentivePresenter } from './SupplierIncentivePresenter.js';
import { SupplierProductPresenter } from './SupplierProductPresenter.js';
import { SupplierPurchasePaymentPresenter } from './SupplierPurchasePaymentPresenter.js';
import { SupplierPresenter } from './SupplierPresenter.js';
import { TripDestinationPresenter } from './TripDestinationPresenter.js';
import { TripExpensePresenter } from './TripExpensePresenter.js';
import { TripPresenter } from './TripPresenter.js';
import { UnitOfMeasurePresenter } from './UnitOfMeasurePresenter.js';
import { VehicleMaintenancePresenter } from './VehicleMaintenancePresenter.js';
import { VehiclePresenter } from './VehiclePresenter.js';
import { AccountTypePresenter } from './AccountTypePresenter.js';
import { AccountPresenter } from './AccountPresenter.js';
import { FiscalYearPresenter } from './FiscalYearPresenter.js';
import { JournalPresenter } from './JournalPresenter.js';
import { JournalLinePresenter } from './JournalLinePresenter.js';
import { AccountBalancePresenter } from './AccountBalancePresenter.js';
import { BankAccountPresenter } from './BankAccountPresenter.js';
import { BankStatementPresenter } from './BankStatementPresenter.js';
import { BankStatementLinePresenter } from './BankStatementLinePresenter.js';
import { BankReconciliationPresenter } from './BankReconciliationPresenter.js';
import { MemberPresenter } from './MemberPresenter.js';
import { MemberAccountPresenter } from './MemberAccountPresenter.js';
import { CooperativeTransactionPresenter } from './CooperativeTransactionPresenter.js';
export const presenters = {
  inventory_reservation: InventoryReservationPresenter,
  cement_audit_event: CementAuditEventPresenter,
  cement_branch: CementBranchPresenter,
  cement_loading: CementLoadingPresenter,
  customer_payment: CustomerPaymentPresenter,
  customer_sale_item: CustomerSaleItemPresenter,
  customer_sale_return: CustomerSaleReturnPresenter,
  customer_sales: CustomerSalesPresenter,
  customer: CustomerPresenter,
  damaged_goods: DamagedGoodsPresenter,
  delivery_proof: DeliveryProofPresenter,
  expense: ExpensePresenter,
  fuel_transaction: FuelTransactionPresenter,
  inventory_cost_layer: InventoryCostLayerPresenter,
  inventory_location: InventoryLocationPresenter,
  inventory_movement: InventoryMovementPresenter,
  inventory_stock: InventoryStockPresenter,
  inventory_stocktake_line: InventoryStocktakeLinePresenter,
  inventory_stocktake: InventoryStocktakePresenter,
  inventory_transfer: InventoryTransferPresenter,
  loading_clearance: LoadingClearancePresenter,
  payroll_item: PayrollItemPresenter,
  payroll_payment: PayrollPaymentPresenter,
  payroll: PayrollPresenter,
  person: PersonPresenter,
  price_list_item: PriceListItemPresenter,
  price_list: PriceListPresenter,
  product_category: ProductCategoryPresenter,
  product: ProductPresenter,
  purchase_adjustment: PurchaseAdjustmentPresenter,
  purchaseItem: PurchaseItemPresenter,
  purchase: PurchasePresenter,
  report_delivery: ReportDeliveryPresenter,
  report_result: ReportResultPresenter,
  report_schedule: ReportSchedulePresenter,
  report: ReportPresenter,
  staff_deduction: StaffDeductionPresenter,
  staff_employment: StaffEmploymentPresenter,
  stock_adjustment: StockAdjustmentPresenter,
  supplier_depot: SupplierDepotPresenter,
  supplier_incentive_receipt: SupplierIncentiveReceiptPresenter,
  supplier_incentive_scheme: SupplierIncentiveSchemePresenter,
  supplier_incentive: SupplierIncentivePresenter,
  supplier_product: SupplierProductPresenter,
  supplier_purchase_payment: SupplierPurchasePaymentPresenter,
  supplier: SupplierPresenter,
  trip_destination: TripDestinationPresenter,
  trip_expense: TripExpensePresenter,
  trip: TripPresenter,
  unit_of_measure: UnitOfMeasurePresenter,
  vehicle_maintenance: VehicleMaintenancePresenter,
  vehicle: VehiclePresenter,
  account_type: AccountTypePresenter,
  account: AccountPresenter,
  fiscal_year: FiscalYearPresenter,
  journal: JournalPresenter,
  journal_line: JournalLinePresenter,
  account_balance: AccountBalancePresenter,
  bank_account: BankAccountPresenter,
  bank_statement: BankStatementPresenter,
  bank_statement_line: BankStatementLinePresenter,
  bank_reconciliation: BankReconciliationPresenter,
  member: MemberPresenter,
  member_account: MemberAccountPresenter,
  cooperative_transaction: CooperativeTransactionPresenter,
};
