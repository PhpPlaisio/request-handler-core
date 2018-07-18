<?php

namespace SetBased\Abc\RequestHandler;

use SetBased\Abc\Abc;
use SetBased\Abc\Exception\BadRequestException;
use SetBased\Abc\Exception\InvalidUrlException;
use SetBased\Abc\Exception\NotAuthorizedException;
use SetBased\Abc\Exception\NotPreferredUrlException;
use SetBased\Abc\Helper\HttpHeader;
use SetBased\Abc\Page\Page;
use SetBased\Exception\FallenException;
use SetBased\Stratum\Exception\ResultException;

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

      $class = Abc::$abc->pageInfo['pag_class'];
      try
      {
        $this->page = new $class();
      }
      catch (ResultException $exception)
      {
        // On a development environment rethrow the exception.
        if (Abc::$request->isEnvDev()) throw $exception;

        // A ResultException during the construction of a page object is (almost) always caused by an invalid URL.
        throw new InvalidUrlException('No data found', $exception);
      }

      // Perform addition authorization and security checks.
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
    catch (NotAuthorizedException $exception)
    {
      // The user has no authorization for the requested URL.
      $this->handleNotAuthorizedException($exception);
    }
    catch (InvalidUrlException $exception)
    {
      // The URL is invalid.
      $this->handleInvalidUrlException($exception);
    }
    catch (BadRequestException $exception)
    {
      // The request is bad.
      $this->handleBadRequestException($exception);
    }
    catch (NotPreferredUrlException $exception)
    {
      // The request is bad.
      $this->handleNotPreferredUrlException($exception);
    }
    catch (\Throwable $exception)
    {
      // Some other exception has occurred.
      $this->handleException($exception);
    }

    Abc::$requestLogger->logRequest(HttpHeader::$status);
    Abc::$DL->commit();

    $this->adHocEventDispatcher->notify($this, 'post_commit');
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Handles a caught BadRequestException.
   *
   * @param BadRequestException $exception The caught exception.
   */
  protected function handleBadRequestException(BadRequestException $exception): void
  {
    Abc::$DL->rollback();

    // Set the HTTP status to 400 (Bad Request).
    HttpHeader::clientErrorBadRequest();

    // Only on development environment log the error.
    if (Abc::$request->isEnvDev())
    {
      $logger = Abc::$abc->getErrorLogger();
      $logger->logError($exception);
    }
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Handles any other caught exception.
   *
   * @param \Throwable $throwable The caught \Throwable.
   */
  protected function handleException(\Throwable $throwable): void
  {
    Abc::$DL->rollback();

    // Set the HTTP status to 500 (Internal Server Error).
    HttpHeader::serverErrorInternalServerError();

    $logger = Abc::$abc->getErrorLogger();
    $logger->logError($throwable);
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Handles a caught InvalidUrlException.
   *
   * @param InvalidUrlException $exception The caught exception.
   */
  protected function handleInvalidUrlException(InvalidUrlException $exception): void
  {
    Abc::$DL->rollback();

    // Set the HTTP status to 404 (Not Found).
    HttpHeader::clientErrorNotFound();

    // Only on development environment log the error.
    if (Abc::$request->isEnvDev())
    {
      $logger = Abc::$abc->getErrorLogger();
      $logger->logError($exception);
    }
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Handles a caught NotAuthorizedException.
   *
   * @param NotAuthorizedException $exception The caught exception.
   */
  protected function handleNotAuthorizedException(NotAuthorizedException $exception): void
  {
    if (Abc::$session->isAnonymous())
    {
      // The user is not logged on and most likely the user has requested a page for which the user must be logged on.
      Abc::$DL->rollback();

      // Redirect the user agent to the login page. After the user has successfully logged on the user agent will be
      // redirected to currently requested URL.
      HttpHeader::redirectSeeOther(Abc::$abc->getLoginUrl(Abc::$request->getRequestUri()));
    }
    else
    {
      // The user is logged on and the user has requested an URL for which the user has no authorization.
      Abc::$DL->rollback();

      // Set the HTTP status to 404 (Not Found).
      HttpHeader::clientErrorNotFound();

      // Only on development environment log the error.
      if (Abc::$request->isEnvDev())
      {
        $logger = Abc::$abc->getErrorLogger();
        $logger->logError($exception);
      }
    }
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Handles a caught NotPreferredUrlException.
   *
   * @param NotPreferredUrlException $exception The caught exception.
   */
  protected function handleNotPreferredUrlException(NotPreferredUrlException $exception): void
  {
    Abc::$DL->rollback();

    // Redirect the user agent to the preferred URL.
    HttpHeader::redirectMovedPermanently($exception->preferredUri);
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
}

//----------------------------------------------------------------------------------------------------------------------
