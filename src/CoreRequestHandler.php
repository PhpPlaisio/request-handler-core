<?php
declare(strict_types=1);

namespace SetBased\Abc\RequestHandler;

use SetBased\Abc\Abc;
use SetBased\Abc\Exception\InvalidUrlException;
use SetBased\Abc\Exception\NotAuthorizedException;
use SetBased\Abc\Exception\NotPreferredUrlException;
use SetBased\Abc\Helper\HttpHeader;
use SetBased\Abc\Page\Page;
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
    try
    {
      try
      {
        $this->prepare();
      }
      catch (\Throwable $exception)
      {
        $handler = Abc::$abc->getExceptionHandler();
        $handler->handlePrepareException($exception);
      }

      try
      {
        $this->construct();
      }
      catch (\Throwable $exception)
      {
        $handler = Abc::$abc->getExceptionHandler();
        $handler->handleConstructException($exception);
      }

      $this->response();
    }
    catch (\Throwable $exception)
    {
      $handler = Abc::$abc->getExceptionHandler();
      $handler->handleResponseException($exception);
    }

    try
    {
      $this->finalize();
    }
    catch (\Throwable $exception)
    {
      $handler = Abc::$abc->getExceptionHandler();
      $handler->handleFinalizeException($exception);
    }
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Retrieves information about the requested page and checks if the user has the correct authorization for the
   * requested page.
   */
  private function checkAuthorization(): void
  {
    $pagId = Abc::$cgi->getOptId('pag', 'pag');
    if ($pagId===null)
    {
      $pagAlias = Abc::$cgi->getOptString('pag_alias');
      if ($pagAlias===null)
      {
        $pagId = Abc::$abc->getIndexPagId();
      }
    }
    else
    {
      $pagAlias = null;
    }

    $info = Abc::$DL->abcAuthGetPageInfo(Abc::$companyResolver->getCmpId(),
                                         $pagId,
                                         Abc::$session->getProId(),
                                         Abc::$session->getLanId(),
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

    Abc::$abc->pageInfo = $info;
    // Page does exists and the user agent is authorized.
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Contruct phase: creating the Page object.
   */
  private function construct(): void
  {
    $class      = Abc::$abc->pageInfo['pag_class'];
    $this->page = new $class();
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * All action after generating the response by the Page object.
   */
  private function finalize(): void
  {
    Abc::$requestLogger->logRequest(HttpHeader::$status);
    Abc::$DL->commit();

    $this->adHocEventDispatcher->notify($this, 'post_commit');
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Preparation phase: all actions before creating the Page object.
   */
  private function prepare(): void
  {
    Abc::$DL::begin();

    // Get the CGI variables from a clean URL.
    Abc::$requestParameterResolver->resolveRequestParameters();

    // Retrieve the session or create an new session.
    Abc::$session->start();

    // Initialize Babel.
    Abc::$babel->setLanguage(Abc::$session->getLanId());

    // Test the user is authorized for the requested page.
    $this->checkAuthorization();

    Abc::$assets->setPageTitle(Abc::$abc->pageInfo['pag_title']);
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Response phase: generating the response by the Page object.
   */
  private function response(): void
  {
    // Perform additional authorization and security checks.
    $this->page->checkAuthorization();

    // Test for preferred URI.
    $uri = $this->page->getPreferredUri();
    if ($uri!==null && Abc::$request->getRequestUri()!==$uri)
    {
      // The preferred URI differs from the requested URI.
      throw new NotPreferredUrlException($uri);
    }

    // Echo the page content.
    if (Abc::$request->isAjax())
    {
      $this->page->echoXhrResponse();
    }
    else
    {
      $this->page->echoPage();
    }

    $this->adHocEventDispatcher->notify($this, 'post_render');

    Abc::$session->save();
  }

  //--------------------------------------------------------------------------------------------------------------------
}

//----------------------------------------------------------------------------------------------------------------------
