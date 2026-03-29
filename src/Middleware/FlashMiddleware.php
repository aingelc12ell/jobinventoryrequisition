<?php

declare(strict_types=1);

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Flash message middleware.
 *
 * Reads any flash message stored in $_SESSION['flash'], removes it from
 * the session so it is only displayed once, and attaches it to the request
 * as the 'flash' attribute. Controllers and views can then retrieve the
 * flash message via $request->getAttribute('flash').
 */
class FlashMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $flash = null;

        if (isset($_SESSION['flash'])) {
            $flash = $_SESSION['flash'];
            unset($_SESSION['flash']);
        }

        $request = $request->withAttribute('flash', $flash);

        return $handler->handle($request);
    }
}
