<?php
declare(strict_types=1);

namespace Gared\EtherScan\Tests\Unit\Service;

use Gared\EtherScan\Model\VersionRange;
use Gared\EtherScan\Service\VersionRangeService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(VersionRangeService::class)]
class VersionRangeServiceTest extends TestCase
{
    public function testCalculateVersionReturnsNullWithoutAnyInput(): void
    {
        $service = new VersionRangeService();

        self::assertNull($service->calculateVersion());
    }

    public function testCalculateVersionPrefersPackageVersion(): void
    {
        $service = new VersionRangeService();
        $service->setRevisionVersion('2.1.0');
        $service->setHealthVersion('2.2.0');
        $service->setPackageVersion('2.3.0');
        $service->addVersionRange(new VersionRange('1.0.0', '3.0.0'));

        $result = $service->calculateVersion();

        self::assertInstanceOf(VersionRange::class, $result);
        self::assertSame('2.3.0', $result->getMinVersion());
        self::assertSame('2.3.0', $result->getMaxVersion());
    }

    public function testCalculateVersionPrefersHealthVersionOverRevisionAndRanges(): void
    {
        $service = new VersionRangeService();
        $service->setRevisionVersion('2.1.0');
        $service->setHealthVersion('2.2.0');
        $service->addVersionRange(new VersionRange('1.0.0', '3.0.0'));

        $result = $service->calculateVersion();

        self::assertInstanceOf(VersionRange::class, $result);
        self::assertSame('2.2.0', $result->getMinVersion());
        self::assertSame('2.2.0', $result->getMaxVersion());
    }

    public function testCalculateVersionPrefersRevisionVersionOverRanges(): void
    {
        $service = new VersionRangeService();
        $service->setRevisionVersion('2.1.0');
        $service->addVersionRange(new VersionRange('1.0.0', '3.0.0'));

        $result = $service->calculateVersion();

        self::assertInstanceOf(VersionRange::class, $result);
        self::assertSame('2.1.0', $result->getMinVersion());
        self::assertSame('2.1.0', $result->getMaxVersion());
    }

    public function testCalculateVersionBuildsIntersectionFromRanges(): void
    {
        $service = new VersionRangeService();
        $service->addVersionRange(new VersionRange('1.0.0', '3.0.0'));
        $service->addVersionRange(new VersionRange('2.0.0', '4.0.0'));

        $result = $service->calculateVersion();

        self::assertInstanceOf(VersionRange::class, $result);
        self::assertSame('2.0.0', $result->getMinVersion());
        self::assertSame('3.0.0', $result->getMaxVersion());
    }

    public function testCalculateVersionThrowsExceptionOnNonMatchingRanges(): void
    {
        $service = new VersionRangeService();
        $service->addVersionRange(new VersionRange('1.9.0', '1.9.5'));
        $service->addVersionRange(new VersionRange('2.0.0', null));

        $result = $service->calculateVersion();

        self::assertInstanceOf(VersionRange::class, $result);
        self::assertSame('2.0.0', $result->getMinVersion());
        self::assertSame('3.0.0', $result->getMaxVersion());
    }

    public function testAddVersionRangeIgnoresNull(): void
    {
        $service = new VersionRangeService();
        $service->addVersionRange(null);

        self::assertNull($service->calculateVersion());
    }
}

