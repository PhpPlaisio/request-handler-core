<?php
declare(strict_types=1);

namespace Plaisio\RequestHandler;

use Plaisio\C;
use Plaisio\Exception\InvalidUrlException;
use Plaisio\Exception\NotAuthorizedException;
use Plaisio\Exception\NotPreferredUrlException;
use Plaisio\Page\Page;
use Plaisio\PlaisioInterface;
use Plaisio\PlaisioObject;
use Plaisio\Response\Response;
use SetBased\Exception\FallenException;

/**
 * Core request handler.
 */
class CoreRequestHandler extends PlaisioObject implements RequestHandler
{
  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Send the response as soon as possible. This option is the most performant.
   */
  public const int SEND_RESPONSE_MODE_ASAP = 1;

  /**
   * Send the response after the request has been finalized. This option is the default and the safest option.
   */
  public const int  SEND_RESPONSE_MODE_FINAL = 2;

  /**
   * Do not send the response at all. This option is intended for testing purposes.
   */
  public const int  SEND_RESPONSE_MODE_NIL = 3;

  /**
   * The lightweight event dispatcher.
   *
   * @var AdHocEventDispatcher
   */
  private AdHocEventDispatcher $adHocEventDispatcher;

  /**
   * The ID of the page currently requested.
   *
   * @var int|null
   */
  private ?int $pagId = null;

  /**
   * The page object.
   *
   * @var Page|null
   */
  private ?Page $page = null;

  /**
   * The response sent to the user agent.
   *
   * @var Response|null
   */
  private ?Response $response = null;

  /**
   * The mode for sending the response to the user agent.
   *
   * @var int
   */
  private int $sendResponseMode;

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * CoreRequestHandler constructor.
   *
   * @param PlaisioInterface $object           The parent PhpPlaisio object.
   * @param int              $sendResponseMode The mode for sending the response to the user agent. One of
   *                                           <ul>
   *                                           <li> @see self::C_SEND_RESPONSE_MODE_ASAP
   *                                           <li> @see self::C_SEND_RESPONSE_MODE_FINAL
   *                                           <li> @see self::C_SEND_RESPONSE_MODE_NIL
   *                                           </ul>
   */
  public function __construct(PlaisioInterface $object, int $sendResponseMode = self::SEND_RESPONSE_MODE_FINAL)
  {
    parent::__construct($object);

    $this->adHocEventDispatcher = new AdHocEventDispatcher();
    $this->sendResponseMode     = $sendResponseMode;
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
   * @param int      $event    The ID of the event.
   * @param callable $observer The observer that must be notified when the event occurs.
   *
   * @api
   * @since 1.0.0
   */
  public function addListener(int $event, callable $observer): void
  {
    switch ($event)
    {
      case self::EVENT_END_RESPONSE:
      case self::EVENT_END_FINALIZE:
        $this->adHocEventDispatcher->addListener($event, $observer);
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
  public function handleRequest(): Response
  {
    $success = $this->prepare();
    $success = $success && $this->construct();
    $success = $success && $this->response();
    $success = $success && $this->finalize();
    unset($success);

    if ($this->sendResponseMode===self::SEND_RESPONSE_MODE_FINAL)
    {
      $this->response->send();
    }

    return $this->response;
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
      elseif (mb_strlen($pagAlias)>C::LEN_PAG_ALIAS)
      {
        throw new InvalidUrlException('Page alias too long.');
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
      throw new InvalidUrlException('Page does not exist.');
    }

    $this->pagId = $info['pag_id'];

    if ($info['authorized']===0)
    {
      // Requested page does exist but the user agent is not authorized for the requested page.
      throw new NotAuthorizedException('Not authorized for requested page.');
    }

    $this->nub->pageInfo = $info;
    // Page does exist and the user agent is authorized.
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
      $response = $this->nub->exceptionHandler->handleConstructException($exception);
      $this->setResponse($response);

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
      $this->nub->session->save();

      $this->nub->requestLogger->logRequest($this->response->getStatus());
      $this->nub->DL->commit();
      $this->nub->DL->disconnect();

      $this->adHocEventDispatcher->notify(self::EVENT_END_FINALIZE);
    }
    catch (\Throwable $exception)
    {
      $response = $this->nub->exceptionHandler->handleFinalizeException($exception);
      $this->setResponse($response);

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
      $this->nub->request->validate();
      $this->nub->requestParameterResolver->resolveRequestParameters();
      $this->nub->session->start();
      $this->nub->babel->setLanguage($this->nub->session->getLanId());
      $this->checkAuthorization();
    }
    catch (\Throwable $exception)
    {
      $response = $this->nub->exceptionHandler->handlePrepareException($exception);
      $this->setResponse($response);

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
      if ($uri!==null && $this->nub->request->requestUri!==$uri)
      {
        throw new NotPreferredUrlException($uri);
      }

      $response = $this->page->handleRequest();
      $this->setResponse($response);

      $this->adHocEventDispatcher->notify(self::EVENT_END_RESPONSE);
    }
    catch (\Throwable $exception)
    {
      $response = $this->nub->exceptionHandler->handleResponseException($exception);
      $this->setResponse($response);

      return false;
    }

    return true;
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Sets the response to eb send to the user agent.
   *
   * @param Response $response The response.
   */
  private function setResponse(Response $response): void
  {
    $this->response = $response;

    if ($this->sendResponseMode===self::SEND_RESPONSE_MODE_ASAP)
    {
      $this->response->send();
    }
  }

  //--------------------------------------------------------------------------------------------------------------------
}

//----------------------------------------------------------------------------------------------------------------------
