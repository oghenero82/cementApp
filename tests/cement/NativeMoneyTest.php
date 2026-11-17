<?php

use PHPUnit\Framework\TestCase;
use App\Libraries\CementIntegration\NativeLedger;

require_once ROOTPATH.'integrations/metaforge/app/Libraries/CementIntegration/NativeLedger.php';
final class NativeMoneyTest extends TestCase
{
    public function testMoneyIsParsedWithoutFloatingPointRounding(): void
    {
        self::assertSame(1001, NativeLedger::minor('10.01'));
        self::assertSame(10, NativeLedger::minor('0.1'));
        self::assertSame(100000000000000, NativeLedger::minor('1000000000000.00'));
        self::assertSame('10.01', NativeLedger::decimal(1001));
    }
    public function testMoneyRejectsRoundingOverflowAndNegativePosting(): void
    {
        foreach (['1.001','-1','1e3','NaN','1000000000000.01',1.23] as $value) {
            try {
                NativeLedger::minor($value);
                self::fail('Invalid money accepted');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }
}
