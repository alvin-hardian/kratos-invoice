<?php
declare(strict_types=1);
namespace App\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

final class AuthMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $req, RequestHandlerInterface $h): ResponseInterface
    {
        $path = $req->getUri()->getPath();
        if (str_starts_with($path, '/login') || str_starts_with($path, '/mcp') || str_starts_with($path, '/assets') || str_starts_with($path, '/fonts') || str_starts_with($path, '/css') || str_starts_with($path, '/js') || str_starts_with($path, '/vendor')) {
            return $h->handle($req);
        }
        if (empty($_SESSION['uid'])) {
            $res = new Response();
            return $res->withHeader('Location', '/login')->withStatus(302);
        }
        return $h->handle($req);
    }
}
