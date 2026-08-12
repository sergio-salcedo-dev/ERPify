<?php

declare(strict_types=1);

namespace Erpify\Frontoffice\Dev\Infrastructure\Controller;

use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Twig\Environment;

/**
 * Renders Symfony's Web Debug Toolbar JS loader (`@WebProfiler/Profiler/toolbar_js.html.twig`)
 * for a given profiler token.
 *
 * The API serves JSON, so Symfony's WebDebugToolbarListener never injects the toolbar (it only
 * patches HTML responses with a </body>). The Next.js PWA fetches this loader and executes it to
 * render the interactive toolbar itself; the loader then pulls the bare toolbar markup from
 * `/_wdt/{token}` and wires up `Sfjs`.
 *
 * Wired as a route only under dev/test (config/routes/dev.yaml, config/routes/test.yaml); the
 * #[When] attributes keep the service itself out of the prod container. No #[Route] attribute, so
 * the /api/v1 attribute-route scanner ignores it — its path is declared explicitly in routing.
 *
 * Those #[When] attributes are load-bearing, not decoration: TwigBundle is registered only under
 * dev/test (config/bundles.php), so without them the prod container refuses to compile — "Cannot
 * autowire ... argument $twig ... references class Twig\Environment but no such service exists".
 * That is also why Twig\Environment sits in the composer-require-checker symbol-whitelist: the
 * checker reads the PSR-4 map, not #[When], so it cannot tell this class never ships.
 */
#[AsController]
#[When(env: 'dev')]
#[When(env: 'test')]
final readonly class WebDebugToolbarLoaderController
{
    public function __construct(private Environment $twig)
    {
    }

    public function __invoke(Request $request, string $token): Response
    {
        $loader = $this->twig->render('@WebProfiler/Profiler/toolbar_js.html.twig', [
            'token' => $token,
            'request' => $request,
            'excluded_ajax_paths' => '^/bundles|^/_wdt',
            'full_stack' => false,
            'csp_script_nonce' => null,
            'csp_style_nonce' => null,
        ]);

        return new Response($loader, Response::HTTP_OK, ['Content-Type' => 'text/html; charset=UTF-8']);
    }
}
