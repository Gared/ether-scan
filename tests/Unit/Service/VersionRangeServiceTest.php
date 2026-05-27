<?php
declare(strict_types=1);

namespace Gared\EtherScan\Tests\Unit\Service;

use Gared\EtherScan\Model\VersionRange;
use Gared\EtherScan\Service\VersionRangeService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function testCalculateVersionUsesHigherVersionOnCollision(): void
    {
        $service = new VersionRangeService();
        $service->addVersionRange(new VersionRange('1.9.0', '1.9.5'));
        $service->addVersionRange(new VersionRange('2.0.0', null));
        $service->addVersionRange(new VersionRange('1.6.0', '1.7.0'));

        $result = $service->calculateVersion();

        self::assertInstanceOf(VersionRange::class, $result);
        self::assertSame('2.0.0', $result->getMinVersion());
        self::assertSame(null, $result->getMaxVersion());
    }

    public function testAddVersionRangeIgnoresNull(): void
    {
        $service = new VersionRangeService();
        $service->addVersionRange(null);

        self::assertNull($service->calculateVersion());
    }

    #[DataProvider('getVersionRangesAndExpectedRanges')]
    public function testCalculateVersionRanges(array $versionRanges, ?string $expectedMinVersion, ?string $expectedMaxVersion): void
    {
        $service = new VersionRangeService();
        foreach ($versionRanges as $versionRange) {
            $service->addVersionRange($versionRange);
        }

        $result = $service->calculateVersion();

        self::assertInstanceOf(VersionRange::class, $result);
        self::assertSame($expectedMinVersion, $result->getMinVersion());
        self::assertSame($expectedMaxVersion, $result->getMaxVersion());
    }

    public static function getVersionRangesAndExpectedRanges(): iterable
    {
        yield 'one version range' => [
            [
                new VersionRange('1.0.0', '3.0.0'),
            ],
            '1.0.0',
            '3.0.0',
        ];

        yield 'multiple ranges' => [
            [
                new VersionRange('1.0.0', '3.0.0'),
                new VersionRange('2.0.0', '4.5.0'),
                new VersionRange('1.2.5', '2.2.1'),
            ],
            '2.0.0',
            '2.2.1',
        ];

        yield 'multiple ranges with null' => [
            [
                new VersionRange('1.0.0', null),
                new VersionRange('2.0.0', '4.5.0'),
                new VersionRange(null, '2.2.1'),
            ],
            '2.0.0',
            '2.2.1',
        ];

        yield 'version ranges with collision will result in higher version' => [
            [
                new VersionRange('1.0.0', '1.9.5'),
                new VersionRange('2.0.0', '2.1.0'),
            ],
            '2.0.0',
            '2.0.0',
        ];
    }
}

