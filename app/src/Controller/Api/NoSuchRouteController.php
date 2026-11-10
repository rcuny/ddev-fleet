<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Api\Http\ApiProblem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\RouterInterface;

/**
 * The last route under /api/. The router answers an unmatched URL (404) or method (405) before the firewall runs, which
 * would let an anonymous caller map the API by telling 401 from 404 and 405. This route matches everything the real
 * routes do not, so the firewall authenticates first (the `^/api/` access rule) and only a valid token learns that
 * a path or method does not exist.
 */
final readonly class NoSuchRouteController
{
    public function __construct(private RouterInterface $router)
    {
    }

    #[Route('/api/{path}', name: 'api_no_such_route', requirements: ['path' => '.*'], priority: -1000)]
    public function __invoke(Request $request): never
    {
        $path = $request->getPathInfo();
        $allowed = [];
        foreach ($this->router->getRouteCollection() as $name => $route) {
            if ('api_no_such_route' === $name || !str_starts_with($route->getPath(), '/api/')) {
                continue;
            }
            if (1 === preg_match($route->compile()->getRegex(), $path)) {
                array_push($allowed, ...($route->getMethods() ?: ['GET']));
            }
        }

        if ([] !== $allowed) {
            throw new ApiProblem(405, 'method_not_allowed', 'This method is not allowed here.', ['Allow' => implode(', ', array_values(array_unique($allowed)))]);
        }

        throw new ApiProblem(404, 'not_found', 'No such resource.');
    }
}
