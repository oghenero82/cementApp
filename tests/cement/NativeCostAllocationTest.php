<?php
use PHPUnit\Framework\TestCase;
use App\Libraries\CementIntegration\CostAllocation;
require_once ROOTPATH.'integrations/metaforge/app/Libraries/CementIntegration/CostAllocation.php';
final class NativeCostAllocationTest extends TestCase
{
    public function testFractionalDestinationsConserveEveryMinorUnit(): void
    {
        if(!extension_loaded('bcmath'))self::markTestSkipped('Native accounting requires BCMath');
        self::assertSame([1,1,0,0],CostAllocation::split(2,[0.25,0.25,0.25,0.25]));self::assertSame([2501,2500,2500,2500],CostAllocation::split(10001,[1,1,1,1]));self::assertSame([3000,7000],CostAllocation::split(10000,[30,70]));
    }
    public function testInvalidDestinationWeightsAreRejected(): void
    {
        foreach([[],[0],[1,-1],[0.0000001]] as $weights){try{CostAllocation::split(100,$weights);self::fail('Invalid allocation accepted');}catch(InvalidArgumentException){self::assertTrue(true);}}
    }
}
