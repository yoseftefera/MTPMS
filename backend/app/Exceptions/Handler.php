<?php

namespace App\Exceptions;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * A list of exception types with their corresponding custom log levels.
     *
     * @var array<class-string<\Throwable>, \Psr\Log\LogLevel::*>
     */
    protected $levels = [
        //
    ];

    /**
     * A list of the exception types that are not reported.
     *
     * @var array<int, class-string<\Throwable>>
     */
    protected $dontReport = [
        TenantNotFoundException::class,
        BudgetExceededException::class,
    ];

    /**
     * A list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     */
    public function register(): void
    {
        $this->reportable(function (Throwable $e) {
            //
        });
    }

    /**
     * Render an exception into an HTTP response.
     *
     * For all API requests (Accept: application/json or /api/ prefix),
     * delegates to renderApiException for consistent JSON envelopes.
     */
    public function render($request, Throwable $e): SymfonyResponse
    {
        // Unwrap NotFoundHttpException caused by model binding to get the original ModelNotFoundException
        if ($e instanceof NotFoundHttpException && $e->getPrevious() instanceof ModelNotFoundException) {
            $e = $e->getPrevious();
        }

        if ($request->expectsJson() || str_starts_with($request->path(), 'api/')) {
            return $this->renderApiException($e, $request);
        }

        return parent::render($request, $e);
    }

    /**
     * Render an exception into an HTTP response for API requests.
     * Maps all exceptions to the standard JSON response envelope.
     */
    public function renderApiException(Throwable $e, Request $request): SymfonyResponse
    {
        return match (true) {
            // Pass through HTTP response exceptions (e.g., rate limiting HTTP 429)
            $e instanceof HttpResponseException => $e->getResponse(),

            $e instanceof ValidationException => response()->json([
                'success' => false,
                'data'    => null,
                'message' => 'Validation failed.',
                'errors'  => $e->errors(),
                'meta'    => null,
            ], 422),

            $e instanceof AuthenticationException => response()->json([
                'success' => false,
                'data'    => null,
                'message' => 'Unauthenticated.',
                'errors'  => null,
                'meta'    => null,
            ], 401),

            $e instanceof AuthorizationException => response()->json([
                'success' => false,
                'data'    => null,
                'message' => 'Forbidden.',
                'errors'  => null,
                'meta'    => null,
            ], 403),

            $e instanceof ModelNotFoundException => response()->json([
                'success' => false,
                'data'    => null,
                'message' => 'Resource not found.',
                'errors'  => null,
                'meta'    => null,
            ], 404),

            $e instanceof NotFoundHttpException => response()->json([
                'success' => false,
                'data'    => null,
                'message' => 'Resource not found.',
                'errors'  => null,
                'meta'    => null,
            ], 404),

            $e instanceof BudgetExceededException => response()->json([
                'success' => false,
                'data'    => null,
                'message' => $e->getMessage(),
                'errors'  => ['budget' => [$e->getMessage()]],
                'meta'    => null,
            ], 422),

            $e instanceof TenantNotFoundException => response()->json([
                'success' => false,
                'data'    => null,
                'message' => 'Tenant not found.',
                'errors'  => null,
                'meta'    => null,
            ], 401),

            $e instanceof UnauthorizedTenantAccessException => response()->json([
                'success' => false,
                'data'    => null,
                'message' => 'Access denied.',
                'errors'  => null,
                'meta'    => null,
            ], 403),

            default => response()->json([
                'success' => false,
                'data'    => null,
                'message' => app()->isProduction() ? 'An unexpected error occurred.' : $e->getMessage(),
                'errors'  => null,
                'meta'    => null,
            ], 500),
        };
    }
}
