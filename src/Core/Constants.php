<?php

declare(strict_types=1);

namespace Nesto\Core;

/** Well-known tokens, same names as NestJS. */
final class Constants
{
    /** Register a provider with this token to add a global guard/pipe/interceptor/filter with DI. */
    public const APP_GUARD = 'APP_GUARD';
    public const APP_PIPE = 'APP_PIPE';
    public const APP_INTERCEPTOR = 'APP_INTERCEPTOR';
    public const APP_FILTER = 'APP_FILTER';

    /** The current Nesto\Http\Request (request-scoped). */
    public const REQUEST = 'REQUEST';

    /** Attribute name on the PSR request that holds matched route params. */
    public const ROUTE_PARAMS = '__route_params';
    public const ROUTE = '__route';
    /** Params captured by #[Controller(host: ':account.example.com')] (Nest's req.hosts). */
    public const HOST_PARAMS = '__host_params';
}
