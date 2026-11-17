<?php
use PHPUnit\Framework\TestCase;
use App\Libraries\CementIntegration\InventoryMath;
require_once ROOTPATH.'integrations/metaforge/app/Libraries/CementIntegration/InventoryMath.php';
final class NativeInventoryMathTest extends TestCase
{
    private function receipt(int $id,int $qty,int $cost,string $policy='fifo'): array {return ['_ID'=>$id,'STATUS'=>'confirmed','PRODUCT_ID'=>1,'STOCK_CONDITION'=>'good','COST_POLICY'=>$policy,'MOVEMENT_DATE'=>'2026-11-17 10:00:00','DESTINATION_LOCATION_ID'=>1,'QTY_BASE'=>$qty,'UNIT_COST'=>$cost];}
    private function issue(int $id,int $qty,string $policy='fifo'): array {$m=$this->receipt($id,$qty,0,$policy);unset($m['DESTINATION_LOCATION_ID']);$m['SOURCE_LOCATION_ID']=1;return $m;}
    public function testFifoAndWeightedAverageAllocateDifferentCorrectCosts(): void
    {
        $fifo=InventoryMath::project([$this->receipt(1,10,100),$this->receipt(2,10,200),$this->issue(3,15)]);self::assertSame(200000,$fifo['effects'][3]['minor']);self::assertSame(100000,$fifo['stocks']['1:1:good']['minor']);self::assertSame(5.0,$fifo['stocks']['1:1:good']['qty']);
        $wac=InventoryMath::project([$this->receipt(1,10,100,'weighted_average'),$this->receipt(2,10,200,'weighted_average'),$this->issue(3,15,'weighted_average')]);self::assertSame(225000,$wac['effects'][3]['minor']);self::assertSame(75000,$wac['stocks']['1:1:good']['minor']);
    }
    public function testTransferPreservesQuantityAndCostAcrossLocations(): void
    {
        $move=$this->issue(2,4);$move['DESTINATION_LOCATION_ID']=2;$p=InventoryMath::project([$this->receipt(1,10,123),$move]);self::assertSame(123000,array_sum(array_column($p['stocks'],'minor')));self::assertSame(10.0,array_sum(array_column($p['stocks'],'qty')));self::assertSame(49200,$p['stocks']['2:1:good']['minor']);
    }
    public function testPendingMovementsHaveNoEffectAndOverIssueFails(): void
    {
        $pending=$this->receipt(2,100,100);$pending['STATUS']='draft';$p=InventoryMath::project([$this->receipt(1,10,100),$pending]);self::assertSame(10.0,$p['stocks']['1:1:good']['qty']);$this->expectException(InvalidArgumentException::class);InventoryMath::project([$this->receipt(1,10,100),$this->issue(3,11)]);
    }
    public function testBackdatingAndChangingPolicyWhileStockExistsAreRejected(): void
    {
        $back=$this->issue(2,1);$back['MOVEMENT_DATE']='2026-11-16 10:00:00';foreach([$back,$this->issue(3,1,'weighted_average')] as $bad){try{InventoryMath::project([$this->receipt(1,10,100),$bad]);self::fail('Unsafe valuation change accepted');}catch(InvalidArgumentException){self::assertTrue(true);}}
    }
}
