<?php

declare( strict_types=1 );

namespace Netivo\Module\ActionLogger\Tests\Support;

use Netivo\Module\ActionLogger\Payload\EventPayload;
use Netivo\Module\ActionLogger\Transport\EventSink;

/**
 * Event sink that keeps dispatched events for assertions.
 */
final class RecordingSink implements EventSink {
	/** @var EventPayload[] */
	public array $events = [];

	public function dispatch( EventPayload $event ): void {
		$this->events[] = $event;
	}

	/**
	 * Serialized events.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function payloads(): array {
		return array_map( static fn ( EventPayload $event ): array => $event->to_array(), $this->events );
	}

	/**
	 * Action slugs of dispatched events.
	 *
	 * @return string[]
	 */
	public function actions(): array {
		return array_map( static fn ( EventPayload $event ): string => $event->action(), $this->events );
	}

	public function last(): EventPayload {
		$event = end( $this->events );
		if ( false === $event ) {
			throw new \LogicException( 'No event was dispatched.' );
		}

		return $event;
	}
}
