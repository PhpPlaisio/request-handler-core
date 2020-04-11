<?php
declare(strict_types=1);

namespace Plaisio\RequestHandler;

use Plaisio\Exception\InvalidUrlException;
use Plaisio\Exception\NotAuthorizedException;
use Plaisio\Exception\NotPreferredUrlException;
use Plaisio\Kernel\Nub;
use Plaisio\Page\Page;
use SetBased\Exception\FallenException;

/**
 * Core request handler.
 */
class CoreRequestHandler implements RequestHandler
{
  //--------------------------------------------------------------------------------------------------------------------
  /**
   * The light weight event dispatcher.
   *
   * @var AdHocEventDispatcher
   */
  private $adHocEventDispatcher;

  /**
   * The ID of the page currently requested.
   *
   * @var int|null
   */
  private $pagId;

  /**
   * The page object.
   *
   * @var Page
   */
  private $page;

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * CoreRequestHandler constructor.
   */
  public function __construct()
  {
    $this->adHocEventDispatcher = new AdHocEventDispatcher();
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Adds a listener that must be notified when an event occurs.
   *
   * The following events are implemented:
   * <ul>
   * <li> post_render: This event occurs after a page requested has been successfully handled.
   * <li> post_commit: This event occurs after a page requested has been handled and the database transaction has been
   *                   committed. The listener CAN NOT access the database or session data.
   * </ul>
   *
   * @param string   $event    The name of the event.
   * @param callable $listener The listener that must be notified when the event occurs.
   *
   * @api
   * @since 1.0.0
   */
  public function addListener(string $event, callable $listener): void
  {
    switch ($event)
    {
      case 'post_render':
      case 'post_commit':
        $this->adHocEventDispatcher->addListener($this, $event, $listener);
        break;

      default:
        throw new FallenException('event', $event);
    }
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Returns the ID of the page currently requested.
   *
   * @return int|null
   *
   * @api
   * @since 1.0.0
   */
  public function getPagId(): ?int
  {
    return $this->pagId;
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Handles a page request.
   *
   * @api
   * @since 1.0.0
   */
  public function handleRequest(): void
  {
    $success = $this->prepare();
    if (!$success) return;

    $success = $this->construct();
    if (!$success) return;

    $success = $this->response();
    if (!$success) return;

    $success = $this->finalize();
    if (!$success) return;
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Retrieves information about the requested page and checks if the user has the correct authorization for the
   * requested page.
   */
  private function checkAuthorization(): void
  {
    $pagId = Nub::$cgi->getOptId('pag', 'pag');
    if ($pagId===null)
    {
      $pagAlias = Nub::$cgi->getOptString('pag_alias');
      if ($pagAlias===null)
      {
        $pagId = Nub::$nub->getIndexPagId();
      }
    }
    else
    {
      $pagAlias = null;
    }

    $info = Nub::$dl->abcAuthGetPageInfo(Nub::$companyResolver->getCmpId(),
                                         $pagId,
                                         Nub::$session->getProId(),
                                         Nub::$session->getLanId(),
                                         $pagAlias);
    if ($info===null)
    {
      throw new InvalidUrlException('Page does not exists');
    }

    $this->pagId = $info['pag_id'];

    if ($info['authorized']==0)
    {
      // Requested page does exists but the user agent is not authorized for the requested page.
      throw new NotAuthorizedException('Not authorized for requested page');
    }

    Nub::$nub->pageInfo = $info;
    // Page does exists and the user agent is authorized.
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Construct phase: creating the Page object.
   *
   * Returns true on success, otherwise false.
   *
   * @return bool
   */
  private function construct(): bool
  {
    try
    {
      $class      = Nub::$nub->pageInfo['pag_class'];
      $this->page = new $class();
    }
    catch (\Throwable $exception)
    {
      $handler = Nub::$nub->getExceptionHandler();
      $handler->handleConstructException($exception);

      return false;
    }

    return true;
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * All actions after generating the response by the Page object.
   *
   * Returns true on success, otherwise false.
   *
   * @return bool
   */
  private function finalize(): bool
  {
    try
    {
      $status = http_response_code();
      Nub::$requestLogger->logRequest(is_int($status) ? $status : 0);
      Nub::$dl->commit();
      Nub::$dl->disconnect();

      $this->adHocEventDispatcher->notify($this, 'post_commit');
    }
    catch (\Throwable $exception)
    {
      $handler = Nub::$nub->getExceptionHandler();
      $handler->handleFinalizeException($exception);

      return false;
    }

    return true;
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Preparation phase: all actions before creating the Page object.
   *
   * Returns true on success, otherwise false.
   *
   * @return bool
   */
  private function prepare(): bool
  {
    try
    {
      Nub::$dl->connect();
      Nub::$dl->begin();

      Nub::$requestParameterResolver->resolveRequestParameters();

      Nub::$session->start();

      Nub::$babel->setLanguage(Nub::$session->getLanId());

      $this->checkAuthorization();

      Nub::$assets->setPageTitle(Nub::$nub->pageInfo['pag_title']);
    }
    catch (\Throwable $exception)
    {
      $handler = Nub::$nub->getExceptionHandler();
      $handler->handlePrepareException($exception);

      return false;
    }

    return true;
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Response phase: generating the response by the Page object.
   *
   * Returns true on success, otherwise false.
   *
   * @return bool
   */
  private function response(): bool
  {
    try
    {
      $this->page->checkAuthorization();

      $uri = $this->page->getPreferredUri();
      if ($uri!==null && Nub::$request->getRequestUri()!==$uri)
      {
        throw new NotPreferredUrlException($uri);
      }

      $response = $this->page->handleRequest();
      $response->send();

      $this->adHocEventDispatcher->notify($this, 'post_render');

      Nub::$session->save();
    }
    catch (\Throwable $exception)
    {
      $handler = Nub::$nub->getExceptionHandler();
      $handler->handleResponseException($exception);

      return false;
    }

    return true;
  }

  //--------------------------------------------------------------------------------------------------------------------
}

//----------------------------------------------------------------------------------------------------------------------
