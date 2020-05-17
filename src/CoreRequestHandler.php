<?php
declare(strict_types=1);

namespace Plaisio\RequestHandler;

use Plaisio\Exception\InvalidUrlException;
use Plaisio\Exception\NotAuthorizedException;
use Plaisio\Exception\NotPreferredUrlException;
use Plaisio\Page\Page;
use Plaisio\PlaisioInterface;
use Plaisio\PlaisioObject;
use SetBased\Exception\FallenException;

/**
 * Core request handler.
 */
class CoreRequestHandler extends PlaisioObject implements RequestHandler
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
   *
   * @param PlaisioInterface $object The parent PhpPlaisio object.
   */
  public function __construct(PlaisioInterface $object)
  {
    parent::__construct($object);

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
    $pagId = $this->nub->cgi->getOptId('pag', 'pag');
    if ($pagId===null)
    {
      $pagAlias = $this->nub->cgi->getOptString('pag_alias');
      if ($pagAlias===null)
      {
        $pagId = $this->nub->pagIdIndex;
      }
    }
    else
    {
      $pagAlias = null;
    }

    $info = $this->nub->DL->abcAuthGetPageInfo($this->nub->company->cmpId,
                                               $pagId,
                                               $this->nub->session->proId,
                                               $this->nub->babel->lanId,
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

    $this->nub->pageInfo = $info;
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
      $class      = $this->nub->pageInfo['pag_class'];
      $this->page = new $class();
    }
    catch (\Throwable $exception)
    {
      $this->nub->exceptionHandler->handleConstructException($exception);

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
      $this->nub->requestLogger->logRequest(is_int($status) ? $status : 0);
      $this->nub->DL->commit();
      $this->nub->DL->disconnect();

      $this->adHocEventDispatcher->notify($this, 'post_commit');
    }
    catch (\Throwable $exception)
    {
      $this->nub->exceptionHandler->handleFinalizeException($exception);

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
      $this->nub->DL->connect();
      $this->nub->DL->begin();

      $this->nub->requestParameterResolver->resolveRequestParameters();

      $this->nub->session->start();

      $this->nub->babel->setLanguage($this->nub->session->getLanId());

      $this->checkAuthorization();

      $this->nub->assets->setPageTitle($this->nub->pageInfo['pag_title']);
    }
    catch (\Throwable $exception)
    {
      $this->nub->exceptionHandler->handlePrepareException($exception);

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
      if ($uri!==null && $this->nub->request->getRequestUri()!==$uri)
      {
        throw new NotPreferredUrlException($uri);
      }

      $response = $this->page->handleRequest();
      $response->send();

      $this->adHocEventDispatcher->notify($this, 'post_render');

      $this->nub->session->save();
    }
    catch (\Throwable $exception)
    {
      $this->nub->exceptionHandler->handleResponseException($exception);

      return false;
    }

    return true;
  }

  //--------------------------------------------------------------------------------------------------------------------
}

//----------------------------------------------------------------------------------------------------------------------
