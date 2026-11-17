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
    public function testLaterPlansCanUpdateAnEarlierNativeIdentity(): void
    {
        $response = ['mutationPlans' => [['recordTypeId' => 1,'operation' => 'insert','data' => [],'_cementClientRef' => 'journal'],['recordTypeId' => 1,'operation' => 'update','data' => ['status' => 'WAIT_POSTING'],'_cementRecordRef' => 'journal']],'results' => []];
        $seen = [];
        TrustedPlans::apply($response, function ($plan) use (&$seen) {
            $seen[] = $plan;
            return ['recordId' => 100,'recordTypeId' => 1];
        });
        self::assertSame(100, $seen[1]['recordId']);
        self::assertArrayNotHasKey('_cementRecordRef', $seen[1]);
    }

    public function testEntryAuthorityIsInheritedAndClearedOnFailure(): void
    {
        self::assertFalse(TrustedPlans::inside());
        $outer = ['mutationPlans' => [['_cementEntry' => true, '_cementGeneratedBy' => 'cement.test']], 'results' => []];
        try {
            TrustedPlans::apply($outer, function ($plan) {
                self::assertTrue(TrustedPlans::inside());
                self::assertArrayNotHasKey('_cementEntry', $plan);
                $nested = ['mutationPlans' => [[]], 'results' => []];
                TrustedPlans::apply($nested, function () {
                    self::assertTrue(TrustedPlans::inside());
                    self::assertSame('cement.test', TrustedPlans::origin());
                    throw new RuntimeException('Rollback');
                });
            });
            self::fail('Failure was swallowed');
        } catch (RuntimeException $e) {
            self::assertSame('Rollback', $e->getMessage());
        }
        self::assertFalse(TrustedPlans::inside());
        self::assertNull(TrustedPlans::origin());
    }

}
