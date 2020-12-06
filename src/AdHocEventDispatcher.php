<?php
declare(strict_types=1);

namespace Plaisio\RequestHandler;

/**
 * A light weight dispatcher for ad hoc events.
 */
class AdHocEventDispatcher
{
  //--------------------------------------------------------------------------------------------------------------------
  /**
   * True if ans only if this dispatcher is dispatching events.
   *
   * @var bool
   */
  private bool $isRunning = false;

  /**
   * The listeners for events.
   *
   * @var array
   */
  private array $listeners = [];

  /**
   * The event queue.
   *
   * @var \SplQueue
   */
  private \SplQueue $queue;

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Object constructor.
   */
  public function __construct()
  {
    $this->queue = new \SplQueue();
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * @param int      $event    The ID of the event.
   * @param callable $callable The  callable that must be run when the vent occurs.
   */
  public function addListener(int $event, callable $callable)
  {
    if (!isset($this->listeners[$event]))
    {
      $this->listeners[$event] = [];
    }

    $this->listeners[$event][] = $callable;
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Informs all listeners that an event has occurred.
   *
   * @param int $event The ID of the event.
   */
  public function notify(int $event): void
  {
    $this->queue->enqueue($event);
    $this->dispatch();
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Dispatches all queued events.
   */
  private function dispatch(): void
  {
    if ($this->isRunning) return;

    $this->isRunning = true;

    while (!$this->queue->isEmpty())
    {
      $event = $this->queue->dequeue();

      if (isset($this->listeners[$event]))
      {
        foreach ($this->listeners[$event] as $callable)
        {
          call_user_func($callable);
        }
      }
    }

    $this->isRunning = false;
  }

  //--------------------------------------------------------------------------------------------------------------------
}

//----------------------------------------------------------------------------------------------------------------------
