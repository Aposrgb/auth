<?php


namespace App\EventListener;

use App\Helper\Exception\ApiException;
use App\Helper\Exception\ResponseCode;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelInterface;

class ExceptionListener extends AbstractController
{
    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    private string $environment;

    public function __construct(LoggerInterface $logger, KernelInterface $kernel)
    {
        $this->logger = $logger;
        $this->environment = $kernel->getEnvironment();
    }

    public function onKernelException(ExceptionEvent $event): void
    {return;
       $path = $event->getRequest()->getPathInfo();
       $exception = $event->getThrowable();
       $this->logger->error($exception->getMessage(), $exception->getTrace());
       if (!str_contains($path, "/api")){
           $msg = $exception instanceof ClientException?$exception->getResponse()->getBody():null;
           $event->setResponse($this->render("error/error.html.twig", [
               "ex" => $exception,
               "msg" => $msg
           ]));
           return;
       }
       $apiException = $exception;
       if (!$exception instanceof ApiException) {
           $statusCode = method_exists($exception, 'getStatusCode') ?
               $exception->getStatusCode() :
               Response::HTTP_INTERNAL_SERVER_ERROR;

           $apiException = new ApiException(
               ResponseCode::getStatusTexts()[$statusCode] ?? null,
               $this->environment === 'dev' ? $exception->getMessage() : null,
               $statusCode,
               [],
           );
       }
       $errorJsonResponse = new JsonResponse($apiException->responseBody(), $apiException->getStatusCode());
       $event->setResponse($errorJsonResponse);
    }

}
