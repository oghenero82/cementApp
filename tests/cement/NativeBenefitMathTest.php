<?php
use PHPUnit\Framework\TestCase;
use App\Libraries\CementIntegration\BenefitMath;
require_once ROOTPATH.'integrations/metaforge/app/Libraries/CementIntegration/BenefitMath.php';
final class NativeBenefitMathTest extends TestCase
{
    protected function setUp(): void {if(!extension_loaded('bcmath'))self::markTestSkipped('Native accounting requires BCMath');}
    public function testExactPercentPerQuantityAndFixedAwards(): void
    {
        self::assertSame(309,BenefitMath::amount(0,12345,'percent',2.5,0));self::assertSame(333,BenefitMath::amount(10,0,'per_quantity',0.333333,0));self::assertSame(12500,BenefitMath::amount(0,0,'fixed',0,12500));$prior=BenefitMath::amount(1,0,'per_quantity',0.333333,0);$total=BenefitMath::amount(3,0,'per_quantity',0.333333,0);self::assertSame(67,$total-$prior);
    }
    public function testInvalidRatesQuantitiesAndOversizedAwardsFail(): void
    {
        foreach([fn()=>BenefitMath::amount(1,100,'percent',101,0),fn()=>BenefitMath::amount(-1,100,'fixed',0,0),fn()=>BenefitMath::amount(0.0000001,100,'per_quantity',1,0),fn()=>BenefitMath::amount(1000000,0,'per_quantity',1000000000000,0)] as $invalid){try{$invalid();self::fail('Invalid scheme accepted');}catch(InvalidArgumentException){self::assertTrue(true);}}
    }
}
