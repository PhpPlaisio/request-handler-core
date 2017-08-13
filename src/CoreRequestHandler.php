<?php
//----------------------------------------------------------------------------------------------------------------------
namespace SetBased\Abc\RequestHandler;

use SetBased\Abc\Abc;
use SetBased\Abc\C;
use SetBased\Abc\Error\InvalidUrlException;
use SetBased\Abc\Error\NotAuthorizedException;
use SetBased\Abc\Helper\HttpHeader;
use SetBased\Abc\Page\Page;
use SetBased\Stratum\Exception\ResultException;

/**
 * Core request handler.
 */
class CoreRequestHandler implements RequestHandler
{
  //--------------------------------------------------------------------------------------------------------------------
  /**
   * The page object.
   *
   * @var Page
   */
  private $page;

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * {@inheritdoc}
   */
  public function handleRequest()
  {
    // Start output buffering.
    ob_start();

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

      Abc::$assets->setPageTitle(Abc::getInstance()->pageInfo['pag_title']);

      $page_class = Abc::getInstance()->pageInfo['pag_class'];
      try
      {
        $this->page = new $page_class();
      }
      catch (ResultException $e)
      {
        // On a development environment rethrow the exception.
        if ($_SERVER['ABC_ENV']=='dev') throw $e;

        // A ResultException during the construction of a page object is (almost) always caused by an invalid URL.
        throw new InvalidUrlException('No data found', $e);
      }

      // Perform addition authorization and security checks.
      $this->page->checkAuthorization();

      $uri = $this->page->getPreferredUri();
      if (isset($uri) && $uri!=$_SERVER['REQUEST_URI'])
      {
        // The preferred URI differs from the requested URI. Redirect the user agent to the preferred URL.
        Abc::$DL->rollback();
        HttpHeader::redirectMovedPermanently($uri);
      }
      else
      {
        // Echo the page content.
        $this->page->echoPage();

        // Flush the page content.
        if (ob_get_level()) ob_flush();
      }
    }
    catch (NotAuthorizedException $e)
    {
      // The user has no authorization for the requested URL.
      $this->handleNotAuthorizedException();
    }
    catch (InvalidUrlException $e)
    {
      // The URL is invalid.
      $this->handleInvalidUrlException();
    }
    catch (\Throwable $e)
    {
      // Some other exception has occurred.
      $this->handleException($e);
    }

    Abc::$session->save();
    Abc::$requestLogger->logRequest(HttpHeader::$status);

    Abc::$DL->commit();
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Handles any other caught exception.
   *
   * @param \Throwable $throwable The caught \Throwable.
   */
  protected function handleException($throwable)
  {
    Abc::$DL->rollback();

    // Set the HTTP status to 500 (Internal Server Error).
    HttpHeader::serverErrorInternalServerError();

    $logger = Abc::getInstance()->getErrorLogger();
    $logger->logError($throwable);
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Handles a caught InvalidUrlException.
   */
  protected function handleInvalidUrlException()
  {
    Abc::$DL->rollback();

    // Set the HTTP status to 404 (Not Found).
    HttpHeader::clientErrorNotFound();
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Handles a caught NotAuthorizedException.
   */
  protected function handleNotAuthorizedException()
  {
    if (Abc::$session->isAnonymous())
    {
      // The user is not logged on and most likely the user has requested a page for which the user must be logged on.
      Abc::$DL->rollback();
      // Redirect the user agent to the login page. After the user has successfully logged on the user agent will be
      // redirected to currently requested URL.

      HttpHeader::redirectSeeOther(Abc::getInstance()->getLoginUrl($_SERVER['REQUEST_URI']));
    }
    else
    {
      // The user is logged on and the user has requested an URL for which the user has no authorization.
      Abc::$DL->rollback();

      // Set the HTTP status to 404 (Not Found).
      HttpHeader::clientErrorNotFound();
    }
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Retrieves information about the requested page and checks if the user has the correct authorization for the
   * requested page.
   */
  private function checkAuthorization()
  {
    if (isset($_GET['pag']))
    {
      $pagId    = Abc::deObfuscate($_GET['pag'], 'pag');
      $pagAlias = null;
    }
    else if (isset($_GET['pag_alias']))
    {
      $pagId    = null;
      $pagAlias = $_GET['pag_alias'];
    }
    else
    {
      $pagId    = C::PAG_ID_INDEX;
      $pagAlias = null;
    }

    Abc::getInstance()->pageInfo = Abc::$DL->abcAuthGetPageInfo(Abc::$session->getCmpId(),
                                                                $pagId,
                                                                Abc::$session->getProId(),
                                                                Abc::$session->getLanId(),
                                                                $pagAlias);
    if (Abc::getInstance()->pageInfo===null)
    {
      if ($pagId!==null)
      {
        throw new NotAuthorizedException('User %d is not authorized for page ID=%d.',
                                         Abc::$session->getUsrId(),
                                         $pagId);
      }
      else
      {
        throw new NotAuthorizedException("User %d is not authorized for page alias='%s'.",
                                         Abc::$session->getUsrId(),
                                         $pagAlias);
      }
    }
  }

  //--------------------------------------------------------------------------------------------------------------------
}

//----------------------------------------------------------------------------------------------------------------------