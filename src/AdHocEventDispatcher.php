<?php

namespace SetBased\Abc\RequestHandler;

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
  private $isRunning = false;

  /**
   * The listeners for events.
   *
   * @var array
   */
  private $listeners = [];

  /**
   * The event queue.
   *
   * @var \SplQueue
   */
  private $queue;

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
   * @param object   $emitter  The object that emits the event.
   * @param string   $event    The name of the event.
   * @param callable $callable The  callable that must be run when the vent occurs.
   */
  public function addListener($emitter, string $event, callable $callable)
  {
    $id = spl_object_id($emitter);
    if (!isset($this->listeners[$id]))
    {
      $this->listeners[$id] = [];
    }

    if (!isset($this->listeners[$id][$event]))
    {
      $this->listeners[$id][$event] = [];
    }

    $this->listeners[$id][$event][] = $callable;
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Informs all listeners that an event has occurred.
   *
   * @param object $emitter The object that emits the event.
   * @param string $event   The name of the event.
   */
  public function notify($emitter, string $event): void
  {
    $this->queue->enqueue(['id' => spl_object_id($emitter), 'emitter' => $emitter, 'event' => $event]);
    $this->dispatch();
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Dispatches all queued events.
   */
  private function dispatch(): void
  {
    // Return immediately if this dispatcher is dispatching events already.
    if ($this->isRunning) return;

    $this->isRunning = true;

    while (!$this->queue->isEmpty())
    {
      $event = $this->queue->dequeue();

      if (isset($this->listeners[$event['id']][$event['event']]))
      {
        foreach ($this->listeners[$event['id']][$event['event']] as $callable)
        {
          $callable();
        }
      }
    }

    $this->isRunning = false;
  }

  //--------------------------------------------------------------------------------------------------------------------

}

//----------------------------------------------------------------------------------------------------------------------
