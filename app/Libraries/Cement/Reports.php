<?php

namespace App\Libraries\Cement;

final class Reports
{
    public function __construct(private Store $s)
    {
    }
    public function generate(array $actor, string $code, array $filters = []): array
    {
        $template = null;
        foreach ($this->s->catalog->json('report_templates') as $r) {
            if ($r['code'] === $code) {
                $template = $r;
            }
        }
        if (!$template) {
            throw new Problem('Unknown report', 404);
        }
        $role = $actor['role'];
        $i = (int)$actor['institution_id'];
        foreach ($template['sourceRecordTypes'] as $type) {
            if (isset($this->s->catalog->types[$type]) && !$this->s->catalog->allowed($role, $type)) {
                throw new Problem('This report contains restricted data', 403);
            }
        }
        if (str_contains($code, 'payroll') && !in_array($role, ['cement_admin','cement_system_admin','cement_accountant'])) {
            throw new Problem('Payroll report is restricted', 403);
        }
        if (in_array($code, ['cement.bank_position','cement.trial_balance','cement.profit_loss','cement.balance_sheet']) && !in_array($role, ['cement_admin','cement_system_admin','cement_accountant'])) {
            throw new Problem('Financial report is restricted', 403);
        }
        $records = function (string $type) use ($i, $filters): array {
            $rows = [];
            foreach ($this->s->all($i, $type) as $r) {
                $d = $this->s->catalog->decode($type, $r['data']);
                $date = $this->recordDate($i, $type, $d);
                foreach (['SALE_DATE','PURCHASE_DATE','LOADING_DATE','PAYMENT_DATE','EXPENSE_DATE','BENEFIT_DATE','PAID_DATE','FUEL_DATE','SERVICE_DATE','COUNT_DATE','PERIOD_START','MOVEMENT_DATE','RECEIPT_DATE','RECEIVED_DATE','CLEARANCE_DATE','PAID_AT','TRANSACTION_DATE'] as $alias) {
                    if (isset($d[$alias])) {
                        $date = substr($d[$alias], 0, 10);
                        break;
                    }
                }
                if ($date && ((!empty($filters['from']) && $date < $filters['from']) || (!empty($filters['to']) && $date > $filters['to']))) {
                    continue;
                }
                if (isset($filters['entityType'],$filters['entityId'])) {
                    $ok = ($type === $filters['entityType'] && $r['id'] == (int)$filters['entityId']) || $this->references($i, $type, $d, $filters['entityType'], (int)$filters['entityId']);
                    if (!$ok) {
                        continue;
                    }
                }
                $d['_ID'] = $r['id'];
                $rows[] = $d;
            }return $rows;
        };
        $rows = [];
        switch ($code) {
            case 'cement.sales_daily':
                $groups = [];
                foreach ($records('customer_sales') as $d) {
                    if (in_array($d['STATUS'], ['invoiced','partially_invoiced'])) {
                        foreach ($this->s->run('SELECT * FROM sale_recognitions WHERE institution_id=? AND sale_id=?', [$i,$d['_ID']])->fetchAll(\PDO::FETCH_ASSOC) as $recognition) {
                            $day = substr($recognition['created_at'], 0, 10);
                            if ((!empty($filters['from']) && $day < $filters['from']) || (!empty($filters['to']) && $day > $filters['to'])) {
                                continue;
                            }$groups[$day] = ($groups[$day] ?? 0) + $recognition['amount_minor'] / 100;
                        }
                    }
                }
                foreach ($groups as $day => $value) {
                    $rows[] = ['Date' => $day,'Sales' => $value];
                }break;
            case 'cement.customer_balances':
                foreach ($records('customer_sales') as $d) {
                    if (in_array($d['STATUS'], ['invoiced','partially_invoiced'])) {
                        $rows[] = ['Customer' => $d['CUSTOMER_ID'],'Invoice' => $d['CODE'],'Date' => $d['SALE_DATE'],'Due' => $d['BALANCE_DUE'],'Age days' => (int)floor((time() - strtotime($d['SALE_DATE'])) / 86400),'Terms days' => $d['PAYMENT_TERMS_DAYS'] ?? 0];
                    }
                }break;
            case 'cement.sales_margin':
                foreach ($records('customer_sale_item') as $d) {
                    $sale = $this->s->semantic($i, 'customer_sales', $d['SALE_ID']);
                    if (!in_array($sale['STATUS'], ['invoiced','partially_invoiced'])) {
                        continue;
                    }
                    $recognizedQty = $d['QUANTITY'];
                    if ($sale['STATUS'] === 'partially_invoiced') {
                        $accepted = (float)$this->s->run('SELECT COALESCE(SUM(quantity),0) FROM sale_recognitions WHERE institution_id=? AND sale_id=?', [$i,$d['SALE_ID']])->fetchColumn();
                        foreach ($this->s->related($i, 'customer_sale_item', 'SALE_ID', $d['SALE_ID']) as $prior) {
                            if ($prior['id'] === $d['_ID']) {
                                break;
                            }$accepted -= $this->s->catalog->decode('customer_sale_item', $prior['data'])['QUANTITY'];
                        }$recognizedQty = min($d['QUANTITY'], max(0, $accepted));
                    }
                    $ratio = $recognizedQty / $d['QUANTITY'];
                    $cost = 0;
                    if (!empty($d['SOURCE_PURCHASE_ITEM_ID'])) {
                        $cost = $this->s->semantic($i, 'purchaseItem', $d['SOURCE_PURCHASE_ITEM_ID'])['NET_UNIT_COST'] * $recognizedQty;
                    } else {
                        foreach ($this->s->related($i, 'inventory_movement', 'SALE_ITEM_ID', $d['_ID']) as $m) {
                            $movement = $this->s->catalog->decode('inventory_movement', $m['data']);
                            $cost += (empty($movement['SOURCE_LOCATION_ID']) ? -1 : 1) * ($movement['TOTAL_COST'] ?? 0);
                        }
                    }
                    $revenue = round(($d['LINE_AMOUNT'] - $d['TAX_AMOUNT']) * $ratio, 2);
                    $rows[] = ['Product' => $d['PRODUCT_ID'],'Customer' => $sale['CUSTOMER_ID'],'Revenue' => $revenue,'Cost' => $cost,'Gross margin' => round($revenue - $cost, 2)];
                } break;
            case 'cement.purchase_balance':case 'cement.purchase_vs_loading':
                foreach ($records('purchaseItem') as $d) {
                    $rows[] = ['Purchase' => $d['PURCHASE_ID'],'Item' => $d['CODE'],'Ordered' => $d['ORIGINAL_QUANTITY'],'Loaded' => $d['LOADED_QTY'],'Cleared' => $d['CLEARED_QTY'],'Delivered' => $d['DELIVERED_QTY'],'Unloaded' => $d['ORIGINAL_QUANTITY'] - $d['LOADED_QTY']];
                }break;
            case 'cement.supplier_pins':
                foreach ($records('purchase') as $d) {
                    $rows[] = ['Purchase' => $d['CODE'],'Supplier' => $d['SUPPLIER_ID'],'PIN' => $d['SUPPLIER_PIN'] ?? '','BIN' => $d['SUPPLIER_BIN'] ?? '','Depot' => $d['SUPPLIER_DEPOT_ID'] ?? ''];
                }break;
            case 'cement.loading_register':
                foreach ($records('cement_loading') as $d) {
                    if ($d['STATUS'] === 'confirmed') {
                        $rows[] = ['Loading' => $d['CODE'],'Depot' => $d['SUPPLIER_DEPOT_ID'],'Quantity' => $d['QUANTITY'],'Customer quantity' => $d['CUSTOMER_QUANTITY'],'Warehouse quantity' => $d['WAREHOUSE_QUANTITY'],'Cleared' => $d['CLEARED_QUANTITY']];
                    }
                }break;
            case 'cement.inventory_status':case 'cement.inventory_valuation':
                foreach ($records('inventory_stock') as $d) {
                    $rows[] = ['Location' => $d['LOCATION_ID'],'Product' => $d['PRODUCT_ID'],'Condition' => $d['STOCK_CONDITION'],'On hand' => $d['QUANTITY_ON_HAND'],'Reserved' => $d['QUANTITY_RESERVED'],'Available' => $d['QUANTITY_AVAILABLE'],'Unit cost' => $d['AVERAGE_UNIT_COST'],'Value' => $d['TOTAL_VALUE']];
                }break;
            case 'cement.inventory_bad_goods':
                foreach ($records('damaged_goods') as $d) {
                    $rows[] = ['Location' => $d['LOCATION_ID'],'Product' => $d['PRODUCT_ID'],'Quantity' => $d['QUANTITY'],'Reason' => $d['REASON'],'Decision' => $d['DECISION'] ?? 'pending','State' => $d['STATUS']];
                }break;
            case 'cement.stock_variances':
                foreach ($records('inventory_stocktake_line') as $d) {
                    $rows[] = ['Count' => $d['STOCKTAKE_ID'],'Product' => $d['PRODUCT_ID'],'System' => $d['SYSTEM_QTY'],'Counted' => $d['COUNTED_QTY'],'Variance' => $d['VARIANCE_QTY'],'Value' => $d['VARIANCE_VALUE']];
                }break;
            case 'cement.trip_profit':
                foreach ($records('trip') as $d) {
                    $stops = $this->s->related($i, 'trip_destination', 'TRIP_ID', $d['_ID']);
                    $totalQty = array_sum(array_map(fn ($r) => $this->s->catalog->decode('trip_destination', $r['data'])['QUANTITY'], $stops));
                    foreach ($stops as $r) {
                        $stop = $this->s->catalog->decode('trip_destination', $r['data']);
                        $sale = !empty($stop['SALE_ID']) ? $this->s->semantic($i, 'customer_sales', $stop['SALE_ID']) : [];
                        $allocated = $totalQty > 0 ? ($d['TOTAL_TRIP_COST'] ?? 0) * $stop['QUANTITY'] / $totalQty : 0;
                        $saleQty = 0;
                        $revenue = 0;
                        $goods = 0;
                        if ($sale && in_array($sale['STATUS'], ['invoiced','partially_invoiced'])) {
                            foreach ($this->s->related($i, 'customer_sale_item', 'SALE_ID', $stop['SALE_ID']) as $lineRow) {
                                $line = $this->s->catalog->decode('customer_sale_item', $lineRow['data']);
                                if (!empty($stop['PRODUCT_ID']) && $line['PRODUCT_ID'] !== $stop['PRODUCT_ID']) {
                                    continue;
                                }$saleQty += $line['QUANTITY'];
                                $revenue += $line['LINE_AMOUNT'] - ($line['TAX_AMOUNT'] ?? 0);
                                if (!empty($line['SOURCE_PURCHASE_ITEM_ID'])) {
                                    $goods += $this->s->semantic($i, 'purchaseItem', $line['SOURCE_PURCHASE_ITEM_ID'])['NET_UNIT_COST'] * $line['QUANTITY'];
                                } else {
                                    foreach ($this->s->related($i, 'inventory_movement', 'SALE_ITEM_ID', $lineRow['id']) as $movement) {
                                        $m = $this->s->catalog->decode('inventory_movement', $movement['data']);
                                        $goods += (empty($m['SOURCE_LOCATION_ID']) ? -1 : 1) * ($m['TOTAL_COST'] ?? 0);
                                    }
                                }
                            }
                        }
                        $ratio = $saleQty > 0 ? $stop['QUANTITY'] / $saleQty : 0;
                        $revenue = round($revenue * $ratio, 2);
                        $goods = round($goods * $ratio, 2);
                        $rows[] = ['Trip' => $d['CODE'],'Stop' => $stop['DESTINATION_ORDER'],'Customer' => $stop['CUSTOMER_ID'] ?? '','Net sales' => $revenue,'Goods cost' => $goods,'Allocated transport cost' => round($allocated, 2),'Contribution after transport' => round($revenue - $goods - $allocated, 2)];
                    }
                }break;
            case 'cement.fleet_cost':
                foreach (['fuel_transaction' => 'TOTAL_AMOUNT','vehicle_maintenance' => 'TOTAL_COST','trip_expense' => 'AMOUNT'] as $type => $alias) {
                    foreach ($records($type) as $d) {
                        if ($d['STATUS'] === 'posted') {
                            $rows[] = ['Kind' => $type,'Vehicle' => $d['VEHICLE_ID'] ?? '','Trip' => $d['TRIP_ID'] ?? '','Cost' => $d[$alias]];
                        }
                    }
                }break;
            case 'cement.supplier_incentives':
                foreach ($records('supplier_incentive') as $d) {
                    $rows[] = ['Supplier' => $d['SUPPLIER_ID'],'Benefit' => $d['CODE'],'Earned' => $d['ACTUAL_AMOUNT'],'Received' => $d['AMOUNT_RECEIVED'],'Outstanding' => $d['ACTUAL_AMOUNT'] - $d['AMOUNT_RECEIVED']];
                }break;
            case 'cement.payroll_summary':
                foreach ($records('payroll') as $d) {
                    $rows[] = ['Period start' => $d['PERIOD_START'],'Period end' => $d['PERIOD_END'],'Gross' => $d['TOTAL_GROSS'] ?? 0,'Deductions' => $d['TOTAL_DEDUCTIONS'] ?? 0,'Net' => $d['TOTAL_NET'] ?? 0,'State' => $d['STATUS']];
                }break;
            case 'cement.expense_analysis':
                foreach (['expense','trip_expense'] as $type) {
                    foreach ($records($type) as $d) {
                        if ($d['STATUS'] === 'posted') {
                            $rows[] = ['Kind' => $d['EXPENSE_TYPE'],'Date' => $d['EXPENSE_DATE'],'Amount' => $d['AMOUNT']];
                        }
                    }
                }break;
            default:
                $accounts = [];
                $bankCodes = ['bank'];
                foreach ($records('bank_account') as $bank) {
                    $bankCodes[] = $this->s->semantic($i, 'account', $bank['ACCOUNT_ID'])['CODE'];
                }
                foreach ($this->s->run('SELECT * FROM journals WHERE institution_id=?', [$i])->fetchAll(\PDO::FETCH_ASSOC) as $j) {
                    $date = substr($j['created_at'], 0, 10);
                    if ((!empty($filters['from']) && $date < $filters['from']) || (!empty($filters['to']) && $date > $filters['to'])) {
                        continue;
                    }
                    foreach (['debit_account' => 1,'credit_account' => -1] as $key => $sign) {
                        $accounts[$j[$key]] = ($accounts[$j[$key]] ?? 0) + $sign * $j['amount_minor'];
                    }
                }
                $categories = [];
                foreach ($this->s->all($i, 'account') as $r) {
                    $a = $this->s->catalog->decode('account', $r['data']);
                    $categories[$a['CODE']] = $this->s->semantic($i, 'account_type', $a['ACCOUNT_TYPE_ID'])['CATEGORY'];
                }
                $earnings = 0;
                foreach ($accounts as $account => $value) {
                    if (in_array($categories[$account] ?? '', ['income','expense'])) {
                        $earnings += $value;
                    }
                }
                foreach ($accounts as $account => $value) {
                    if ($code === 'cement.bank_position' && !in_array($account, $bankCodes)) {
                        continue;
                    }
                    if ($code === 'cement.profit_loss' && !in_array($categories[$account] ?? '', ['income','expense'])) {
                        continue;
                    }
                    if ($code === 'cement.balance_sheet' && in_array($categories[$account] ?? '', ['income','expense'])) {
                        continue;
                    }
                    $rows[] = ['Account' => $account,'Debit' => max(0, $value) / 100,'Credit' => max(0, -$value) / 100,'Balance' => $value / 100];
                }
                if ($code === 'cement.balance_sheet' && $earnings) {
                    $rows[] = ['Account' => 'retained_earnings','Debit' => max(0, $earnings) / 100,'Credit' => max(0, -$earnings) / 100,'Balance' => $earnings / 100];
                }
        }
        return ['code' => $code,'title' => $template['name'],'generatedAt' => gmdate('c'),'filters' => $filters,'rows' => $rows,'rowCount' => count($rows),'columns' => array_keys($rows[0] ?? [])];
    }
    private function references(int $i, string $type, array $data, string $target, int $id, int $depth = 0): bool
    {
        foreach ($this->s->catalog->descriptors($type) as $field) {
            $parent = $field['target'];
            $value = $data[$field['alias']] ?? null;
            if (!$parent || !$value) {
                continue;
            }if ($parent === $target && (int)$value === $id) {
                return true;
            }if ($depth >= 2) {
                continue;
            }try {
                if ($this->references($i, $parent, $this->s->semantic($i, $parent, (int)$value), $target, $id, $depth + 1)) {
                    return true;
                }
            } catch (Problem) {
            }
        }
        return false;
    }
    private function recordDate(int $i, string $type, array $data, int $depth = 0): ?string
    {
        foreach (['SALE_DATE','PURCHASE_DATE','LOADING_DATE','PAYMENT_DATE','EXPENSE_DATE','BENEFIT_DATE','PAID_DATE','FUEL_DATE','SERVICE_DATE','COUNT_DATE','PERIOD_START','MOVEMENT_DATE','RECEIPT_DATE','RECEIVED_DATE','CLEARANCE_DATE','PAID_AT','TRANSACTION_DATE'] as $alias) {
            if (!empty($data[$alias])) {
                return substr($data[$alias], 0, 10);
            }
        }
        if ($depth >= 2) {
            return null;
        }foreach ($this->s->catalog->descriptors($type) as $field) {
            $parent = $field['target'];
            $value = $data[$field['alias']] ?? null;
            if (!$parent || !$value) {
                continue;
            }try {
                $date = $this->recordDate($i, $parent, $this->s->semantic($i, $parent, (int)$value), $depth + 1);
                if ($date) {
                    return $date;
                }
            } catch (Problem) {
            }
        }return null;
    }
    public static function csv(array $result): string
    {
        $f = fopen('php://temp', 'r+');
        fputcsv($f, $result['columns'], ',', '"', '');
        foreach ($result['rows'] as $row) {
            fputcsv($f, array_map(static fn ($v) => is_string($v) && preg_match('/^[=+@\-\t\r]/', $v) ? "'".$v : $v, array_values($row)), ',', '"', '');
        }
        rewind($f);
        return stream_get_contents($f);
    }
    public static function xlsx(array $result): string
    {
        $file = tempnam(sys_get_temp_dir(), 'cement-xlsx-');
        $zip = new \ZipArchive();
        $zip->open($file, \ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Report" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
        $xml = '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
        foreach (array_merge([$result['columns']], array_map('array_values', $result['rows'])) as $row) {
            $xml .= '<row>';
            foreach ($row as $value) {
                $xml .= is_int($value) || is_float($value) ? '<c><v>'.$value.'</v></c>' : '<c t="inlineStr"><is><t xml:space="preserve">'.htmlspecialchars((string)$value, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</t></is></c>';
            }$xml .= '</row>';
        }
        $zip->addFromString('xl/worksheets/sheet1.xml', $xml.'</sheetData></worksheet>');
        $zip->close();
        $content = file_get_contents($file);
        unlink($file);
        return $content;
    }

}
