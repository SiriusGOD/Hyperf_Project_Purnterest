<?php

declare(strict_types=1);
/**
 * This file is part of Hyperf.
 *
 * @link     https://www.hyperf.io
 * @document https://hyperf.wiki
 * @contact  group@hyperf.io
 * @license  https://github.com/hyperf/hyperf/blob/master/LICENSE
 */
namespace App\Exception\Handler;

use App\Constants\ApiCode;
use Hyperf\Contract\StdoutLoggerInterface;
use Hyperf\ExceptionHandler\ExceptionHandler;
use Hyperf\Logger\LoggerFactory;
use Hyperf\View\RenderInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

class AppExceptionHandler extends ExceptionHandler
{
    protected \Hyperf\HttpServer\Contract\ResponseInterface $response;

    protected \Psr\Log\LoggerInterface $loggerFactory;

    /**
     * @Inject
     */
    protected RenderInterface $render;

    public function __construct(protected StdoutLoggerInterface $logger, \Hyperf\HttpServer\Contract\ResponseInterface $response, LoggerFactory $loggerFactory)
    {
        $this->response = $response;
        $this->loggerFactory = $loggerFactory->get('error', 'error');
    }

    public function handle(Throwable $throwable, ResponseInterface $response)
    {
        var_dump(get_class($throwable));
        $this->logger->error(sprintf('%s[%s] in %s', $throwable->getMessage(), $throwable->getLine(), $throwable->getFile()));
        $this->logger->error($throwable->getTraceAsString());
        $this->loggerFactory->error(sprintf('%s[%s] in %s', $throwable->getMessage(), $throwable->getLine(), $throwable->getFile()));
        $this->loggerFactory->error($throwable->getTraceAsString());
        $message = '';
        if (env('APP_ENV') != 'product') {
            $message = $throwable->getTraceAsString();
            return $this->response->withStatus(ApiCode::FATAL_ERROR)->json([
                'code' => ApiCode::FATAL_ERROR,
                'msg'  => $message,
            ]);
        }

        return $this->response->redirect('/');
    }

    public function isValid(Throwable $throwable): bool
    {
        return true;
    }
}
