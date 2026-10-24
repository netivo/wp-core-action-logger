<?php

declare( strict_types=1 );

namespace Netivo\Module\ActionLogger\Tests;

use Brain\Monkey;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase as PhpUnitTestCase;

/**
 * Base test case: sets up Brain Monkey and stubs translation/escaping helpers.
 */
abstract class TestCase extends PhpUnitTestCase {
	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Monkey\Functions\stubTranslationFunctions();
		Monkey\Functions\stubEscapeFunctions();
		Monkey\Functions\stubs(
			[
				'wp_unslash'          => null,
				'sanitize_text_field' => null,
				'sanitize_key'        => null,
			]
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}
}
