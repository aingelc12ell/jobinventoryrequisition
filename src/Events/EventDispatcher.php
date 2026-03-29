<?php

declare(strict_types=1);

namespace App\Events;

/**
 * Simple synchronous event dispatcher.
 *
 * Allows registration of callable listeners by event name
 * and dispatches payloads to all registered listeners when
 * an event is fired.
 */
class EventDispatcher
{
    /**
     * Registered listeners keyed by event name.
     *
     * @var array<string, callable[]>
     */
    private array $listeners = [];

    /**
     * Register a listener for a given event name.
     *
     * @param string   $eventName The event to listen for.
     * @param callable $listener  The callback to invoke when the event fires.
     */
    public function listen(string $eventName, callable $listener): void
    {
        $this->listeners[$eventName][] = $listener;
    }

    /**
     * Dispatch an event, calling all registered listeners with the payload.
     *
     * @param string $eventName The event being dispatched.
     * @param array  $payload   Data to pass to each listener.
     */
    public function dispatch(string $eventName, array $payload = []): void
    {
        if (!isset($this->listeners[$eventName])) {
            return;
        }

        foreach ($this->listeners[$eventName] as $listener) {
            $listener($payload);
        }
    }
}
