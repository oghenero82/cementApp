<?php
use PHPUnit\Framework\TestCase;
use App\Libraries\CementIntegration\NativeDates;
require_once ROOTPATH.'integrations/metaforge/app/Libraries/CementIntegration/NativeDates.php';
final class NativeDatesTest extends TestCase
{
    public function testOffsetInstantsAreConvertedToCanonicalUtc(): void {self::assertSame('2026-11-17 09:30:00',NativeDates::canonical('2026-11-17T10:30:00+01:00'));self::assertSame('2026-11-17 09:30:00',NativeDates::canonical('2026-11-17T09:30:00.123Z'));self::assertSame('2026-11-17 09:30:00',NativeDates::canonical('2026-11-17 09:30:00'));}
    public function testInvalidCalendarAndRelativeDatesAreRejected(): void {foreach(['tomorrow','2026-02-31T12:00:00Z','2026-02-31 12:00:00'] as $bad){try{NativeDates::canonical($bad);self::fail('Invalid date accepted');}catch(InvalidArgumentException){self::assertTrue(true);}}}
}
