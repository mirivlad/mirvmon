<?php

declare(strict_types=1);

namespace App\Http;

use App\Middlewares\RequestIdMiddleware;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpException;
use Throwable;

final class ErrorResponder
{
    public function __construct(
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly bool $debug,
        private readonly bool $logErrors = true
    ) {
    }

    public function __invoke(
        ServerRequestInterface $request,
        Throwable $exception,
        bool $displayErrorDetails = false,
        bool $logErrors = false,
        bool $logErrorDetails = false
    ): ResponseInterface {
        return $this->respond($request, $exception);
    }

    public function respond(
        ServerRequestInterface $request,
        Throwable $exception
    ): ResponseInterface {
        $status = $exception instanceof HttpException
            ? $exception->getCode()
            : 500;
        $status = $status >= 400 && $status <= 599 ? $status : 500;
        $requestId = (string) $request->getAttribute(RequestIdMiddleware::ATTRIBUTE, '');
        if ($status >= 500 && $requestId !== '' && $this->logErrors) {
            error_log(sprintf(
                '[mirvmon] request_id=%s exception=%s',
                $requestId,
                $exception::class
            ));
        }

        $apiRequest = str_starts_with($request->getUri()->getPath(), '/api/')
            || str_contains(strtolower($request->getHeaderLine('Accept')), 'application/json');

        $response = $this->responseFactory->createResponse($status);

        if ($apiRequest) {
            $code = match ($status) {
                400 => 'bad_request',
                401 => 'unauthorized',
                403 => 'forbidden',
                404 => 'not_found',
                405 => 'method_not_allowed',
                413 => 'payload_too_large',
                default => 'internal_error',
            };
            $message = match ($status) {
                400 => 'Bad request.',
                401 => 'Authentication required.',
                403 => 'Access denied.',
                404 => 'Resource not found.',
                405 => 'Method not allowed.',
                413 => 'Payload too large.',
                default => 'Internal server error.',
            };
            $payload = ['error' => ['code' => $code, 'message' => $message]];
            if ($this->debug && $status === 500) {
                $payload['error']['detail'] = $exception->getMessage();
            }

            $response->getBody()->write((string) json_encode(
                $payload,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            ));

            return $response->withHeader('Content-Type', 'application/json');
        }

        $title = match ($status) {
            400 => 'Некорректный запрос',
            401 => 'Требуется авторизация',
            403 => 'Доступ запрещён',
            404 => 'Страница не найдена',
            405 => 'Метод не поддерживается',
            413 => 'Запрос слишком большой',
            default => 'Внутренняя ошибка сервера',
        };
        $detail = $this->debug && $status === 500
            ? '<pre>' . htmlspecialchars($exception->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</pre>'
            : '';
        $response->getBody()->write(
            '<!doctype html><html lang="ru"><meta charset="utf-8"><title>'
            . $title
            . '</title><body><main><h1>'
            . $title
            . '</h1>'
            . $detail
            . '</main></body></html>'
        );

        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }
}
