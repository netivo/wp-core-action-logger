<?php

declare( strict_types=1 );

namespace Netivo\Module\ActionLogger\Tests\Support;

use Netivo\Module\ActionLogger\Admin\Page\Status;

/**
 * Status page that records the redirect instead of exiting.
 */
final class RedirectCapturingStatusPage extends Status {
	public ?string $redirected = null;

	protected function redirect( string $url ): void {
		$this->redirected = $url;
	}
}
