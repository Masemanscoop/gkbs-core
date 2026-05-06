<?php

declare(strict_types=1);

namespace GKBS\Core\Tests\Unit;

use GKBS\Core\Loader;
use PHPUnit\Framework\TestCase;

/**
 * Loader uses defined() / define() — those are global. PHPUnit
 * cannot un-define between tests, so we check the runtime contract
 * with a single test that drives both branches.
 */
final class LoaderTest extends TestCase
{
    protected function setUp(): void
    {
        Loader::reset();
    }

    public function test_first_register_returns_true_and_defines_constants(): void
    {
        $alreadyDefined = defined('GKBS_CORE_LOADED_VERSION');

        if ($alreadyDefined) {
            self::markTestSkipped('Constant already defined in this PHP process; cannot test first-load path.');
        }

        $result = Loader::register(__FILE__, '0.1.0-alpha.0');
        self::assertTrue($result);
        self::assertTrue(defined('GKBS_CORE_LOADED_VERSION'));
        self::assertSame('0.1.0-alpha.0', constant('GKBS_CORE_LOADED_VERSION'));

        $resultAgain = Loader::register(__FILE__, '0.0.5');
        self::assertFalse($resultAgain, 'Lower version must not override.');
    }

    public function test_plugin_returns_singleton(): void
    {
        $first  = Loader::plugin();
        $second = Loader::plugin();
        self::assertSame($first, $second);
    }
}
