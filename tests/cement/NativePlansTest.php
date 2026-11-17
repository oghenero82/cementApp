<?php

use PHPUnit\Framework\TestCase;
use App\Libraries\CementIntegration\TrustedPlans;

require_once ROOTPATH.'integrations/metaforge/app/Libraries/CementIntegration/TrustedPlans.php';
final class NativePlansTest extends TestCase
{
    public function testBindingsUseAcknowledgedNativeIdentitiesAndReturnResultMap(): void
    {
        $response = ['mutationPlans' => [
            ['recordTypeId' => 901,'operation' => 'insert','data' => ['name' => 'Supplier'],'_cementClientRef' => 'parent'],
            ['recordTypeId' => 902,'operation' => 'insert','data' => ['name' => 'Depot'],'_cementReferences' => ['supplierAccountRef' => 'parent'],'_cementClientRef' => 'child'],
        ],'results' => []];
        $received = [];
        TrustedPlans::apply($response, function ($plan) use (&$received) {
            $received[] = $plan;
            return ['recordTypeId' => $plan['recordTypeId'],'recordId' => count($received) + 100];
        });
        self::assertSame(101, $received[1]['data']['supplierAccountRef']);
        self::assertArrayNotHasKey('_cementReferences', $received[1]);
        self::assertArrayNotHasKey('_cementClientRef', $received[0]);
        self::assertSame(['parent' => 101,'child' => 102], $response['results']['resultMap']);
    }
    public function testDuplicateBindingsAreRejectedBeforeExecution(): void
    {
        $response = ['mutationPlans' => [['_cementClientRef' => 'same'],['_cementClientRef' => 'same']],'results' => []];
        $calls = 0;
        try {
            TrustedPlans::apply($response, function () use (&$calls) {
                $calls++;
            });
            self::fail('Duplicate accepted');
        } catch (InvalidArgumentException) {
            self::assertSame(0, $calls);
        }
    }
    public function testUnknownReferencesFailInsteadOfGuessingAnIdentity(): void
    {
        $response = ['mutationPlans' => [['data' => [],'_cementReferences' => ['supplierAccountRef' => 'missing']]],'results' => []];
        $this->expectException(InvalidArgumentException::class);
        TrustedPlans::apply($response, fn () => self::fail('Unresolved plan was executed'));
    }
    public function testNativeFailureDoesNotPublishAResultMap(): void
    {
        $response = ['mutationPlans' => [['_cementClientRef' => 'parent'],['_cementClientRef' => 'child']],'results' => []];
        $calls = 0;
        try {
            TrustedPlans::apply($response, function () use (&$calls) {
                if (++$calls === 2) {
                    throw new RuntimeException('Native constraint rejected the child');
                }return ['recordId' => 91,'recordTypeId' => 901];
            });
            self::fail('Failure was swallowed');
        } catch (RuntimeException) {
            self::assertSame([], $response['results']);
        }
    }
}
