<?php
use PHPUnit\Framework\TestCase;
use App\Libraries\CementIntegration\{NativeLedger,SalesMath};
require_once ROOTPATH.'integrations/metaforge/app/Libraries/CementIntegration/NativeLedger.php';
require_once ROOTPATH.'integrations/metaforge/app/Libraries/CementIntegration/SalesMath.php';
final class NativeSalesMathTest extends TestCase
{
    public function testPartialRecognitionConservesResidualCents(): void
    {
        self::assertSame(3333,SalesMath::portion(10001,1,3));self::assertSame(6667,SalesMath::portion(10001,2,3));self::assertSame(10001,SalesMath::portion(10001,3,3));self::assertSame(0,SalesMath::portion(1,0.000001,1));
    }
    public function testLineSnapshotsPriceDiscountTaxAndUnitFactor(): void
    {
        $d=SalesMath::line(['QUANTITY'=>3,'AGREED_UNIT_PRICE'=>'120.00','DISCOUNT_TYPE'=>'percent','DISCOUNT_VALUE'=>10,'TAX_RATE'=>7.5],50);self::assertSame('108.00',$d['NET_UNIT_PRICE']);self::assertSame('24.30',$d['TAX_AMOUNT']);self::assertSame('348.30',$d['LINE_AMOUNT']);self::assertEquals(150,$d['QUANTITY_BASE']);
    }
    public function testOverDeliveryAndInvalidPricingFail(): void
    {
        foreach([fn()=>SalesMath::portion(100,2,1),fn()=>SalesMath::portion(-1,1,1),fn()=>SalesMath::line(['QUANTITY'=>1,'AGREED_UNIT_PRICE'=>10,'DISCOUNT_VALUE'=>11],1),fn()=>SalesMath::line(['QUANTITY'=>0,'AGREED_UNIT_PRICE'=>10],1)] as $fn){try{$fn();self::fail('Invalid sale accepted');}catch(InvalidArgumentException){self::assertTrue(true);}}
    }
}
