<?php

declare(strict_types=1);

namespace App\Tests\Unit\Fleet\Instances;

use App\Fleet\Instances\InstanceNaming;
use App\Fleet\Instances\Labels;
use App\Fleet\Projects\ValidationFailed;
use PHPUnit\Framework\TestCase;

final class InstanceNamingTest extends TestCase
{
    public function testBareLabelWhenFree(): void
    {
        self::assertSame('develop', InstanceNaming::allocateFree(['acme--main'], 'acme', 'develop'));
    }

    public function testSuffixesWhenTaken(): void
    {
        self::assertSame('develop-1', InstanceNaming::allocateFree(['acme--develop'], 'acme', 'develop'));
        self::assertSame('develop-2', InstanceNaming::allocateFree(['acme--develop', 'acme--develop-1'], 'acme', 'develop'));
        self::assertSame('develop-1', InstanceNaming::allocateFree(['acme--develop', 'acme--develop-2'], 'acme', 'develop'), 'the first free number wins');
    }

    public function testAnotherProjectsIdDoesNotCount(): void
    {
        self::assertSame('develop', InstanceNaming::allocateFree(['blog--develop'], 'acme', 'develop'));
    }

    public function testBatchNeverUsesTheBareLabel(): void
    {
        self::assertSame(['develop-1', 'develop-2', 'develop-3'], InstanceNaming::allocateBatch([], 'acme', 'develop', 3));
        self::assertSame(['develop-1'], InstanceNaming::allocateBatch(['acme--develop'], 'acme', 'develop', 1));
    }

    public function testBatchSkipsTakenNumbers(): void
    {
        self::assertSame(
            ['develop-2', 'develop-4', 'develop-5'],
            InstanceNaming::allocateBatch(['acme--develop-1', 'acme--develop-3'], 'acme', 'develop', 3),
        );
    }

    public function testComposeRejectsOver59(): void
    {
        self::assertSame(59, \strlen(InstanceNaming::compose('acme', str_repeat('a', 53))));

        $this->expectException(ValidationFailed::class);
        InstanceNaming::compose('acme', str_repeat('a', 54));
    }

    public function testASuffixThatOverflowsIsRefused(): void
    {
        $label = str_repeat('a', 52); // 'acme--' + 52 = 58 characters: the bare label fits, '-1' does not (60)
        self::assertSame($label, InstanceNaming::allocateFree([], 'acme', $label));

        $this->expectException(ValidationFailed::class);
        InstanceNaming::allocateFree(['acme--'.$label], 'acme', $label);
    }

    public function testIsInstanceId(): void
    {
        self::assertTrue(InstanceNaming::isInstanceId('acme--develop-1'));
        self::assertFalse(InstanceNaming::isInstanceId('acme'));
        self::assertFalse(InstanceNaming::isInstanceId('../acme--x'));
        self::assertFalse(InstanceNaming::isInstanceId('Acme--x'));
    }

    public function testALabelThatFitsIsLeftAlone(): void
    {
        self::assertSame('fle-3', InstanceNaming::fitLabel('blog', 'fle-3', 'seed'));
    }

    public function testALabelThatDoesNotFitIsCutAndEndsInTheSeedHash(): void
    {
        $label = InstanceNaming::fitLabel('blog', str_repeat('a', 80), 'feature/x', serviceBudget: 0);

        self::assertSame(InstanceNaming::MAX, \strlen('blog--'.$label));
        self::assertSame('-'.substr(sha1('feature/x'), 0, 6), substr($label, -7));
        self::assertSame(substr($label, -7), substr(InstanceNaming::fitLabel('blog', str_repeat('b', 90), 'feature/x'), -7), 'only the seed decides the hash');
    }

    public function testTheLongestBranchLabelFitsIn59AndKeepsItsHash(): void
    {
        $branch = 'renovate/major-ansible-collections';
        $label = InstanceNaming::fitLabel('ddev-fleet-renovate-ci', 'ci-'.Labels::slug($branch).'-and-more-words-here', $branch, serviceBudget: 0);

        self::assertSame(59, \strlen('ddev-fleet-renovate-ci--'.$label));
        self::assertMatchesRegularExpression('/-'.substr(sha1($branch), 0, 6).'$/', $label);
        self::assertSame('ddev-fleet-renovate-ci--'.$label, InstanceNaming::compose('ddev-fleet-renovate-ci', $label));
    }

    public function testAnAliasThatWouldPassTheDnsLabelShortensTheLabelFurther(): void
    {
        $label = InstanceNaming::fitLabel('blog', str_repeat('a', 80), 's', aliasPrefix: 10, serviceBudget: 0);

        self::assertSame(63 - 10, \strlen('blog--'.$label));
    }

    public function testTheAliasPrefixAndTheReservedSuffixTakeFromTheBudget(): void
    {
        $label = InstanceNaming::fitLabel('blog', str_repeat('a', 80), 's', aliasPrefix: 10, reserve: 4, serviceBudget: 0);

        self::assertSame(63 - 10 - 4, \strlen('blog--'.$label));
    }

    public function testTheServiceBudgetKeepsEveryContainerNameWithinTheDnsLabel(): void
    {
        $label = InstanceNaming::fitLabel('blog', 'ci-feature-fle-1795-harden-media-derivatives-long', 'feature/x', reserve: 4);
        $id = InstanceNaming::compose('blog', $label.'-1');

        self::assertSame([], InstanceNaming::overlongContainers($id, ['web', 'db', 'typesense', 'typesense-dashboard']));
        self::assertLessThanOrEqual(63 - 6 - InstanceNaming::SERVICE_BUDGET - 4, \strlen('blog--'.$label));
    }

    public function testASuffixedIdOfTheIncidentWasTooLong(): void
    {
        $base = 'blog--ci-feature-fle-1795-harden-media-d-8556d2';

        self::assertSame([], InstanceNaming::overlongContainers($base, ['typesense']));
        self::assertSame(['ddev-'.$base.'-1-typesense'], InstanceNaming::overlongContainers($base.'-1', ['web', 'typesense']));
        self::assertCount(1, InstanceNaming::overlongContainers($base, ['typesense-dashboard']));
    }

    public function testALongProjectNameKeepsAMinimumLabel(): void
    {
        $label = InstanceNaming::fitLabel(str_repeat('p', 30), str_repeat('a', 80), 's', reserve: 4);

        self::assertSame(12, \strlen($label));
        self::assertLessThanOrEqual(InstanceNaming::MAX, \strlen(str_repeat('p', 30).'--'.$label) + 4);
    }

    public function testAProjectNameWithNoRoomLeftIsRefused(): void
    {
        $this->expectException(ValidationFailed::class);
        InstanceNaming::fitLabel(str_repeat('p', 40), str_repeat('a', 80), 's', aliasPrefix: 14, reserve: 4);
    }

    public function testExistingIdsMayBeUpTo63CharactersButNewOnesStayAt59(): void
    {
        $id = static fn (int $len): string => 'acme--'.str_repeat('a', $len - 6);

        self::assertTrue(InstanceNaming::isInstanceId($id(59)));
        self::assertFalse(InstanceNaming::isInstanceId($id(60)));
        self::assertTrue(InstanceNaming::isExistingInstanceId($id(63)));
        self::assertFalse(InstanceNaming::isExistingInstanceId($id(64)));
        self::assertFalse(InstanceNaming::isExistingInstanceId('../etc--x'));
    }
}
