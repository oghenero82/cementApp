<?php
use PHPUnit\Framework\TestCase;
use App\Libraries\CementIntegration\NativePayments;
require_once ROOTPATH.'integrations/metaforge/app/Libraries/CementIntegration/NativePayments.php';
final class NativeDepositApplicationTest extends TestCase
{
    public function testMixedReceiptsApplyOnlyTheRecognizedReceivable(): void
    {
        self::assertSame(50000,NativePayments::depositApplication(50000,80000,0,0));self::assertSame(50000,NativePayments::depositApplication(120000,80000,30000,0));self::assertSame(70000,NativePayments::depositApplication(120000,100000,30000,0));self::assertSame(120000,NativePayments::depositApplication(120000,200000,30000,0));
    }
    public function testReturnCreditsAndRefundsCannotCreateAnotherDepositApplication(): void
    {
        self::assertSame(50000,NativePayments::depositApplication(170000,80000,0,30000));self::assertSame(70000,NativePayments::depositApplication(170000,100000,0,30000));self::assertSame(0,NativePayments::depositApplication(50000,0,0,0));
    }
    public function testInvalidFinancialSnapshotsAreRejected(): void {$this->expectException(InvalidArgumentException::class);NativePayments::depositApplication(-1,100,0,0);}
}
