<?php
declare(strict_types=1);
namespace App\Support;

use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;

/** Builds a Twig environment with the app's shared filters (web + MCP PDF rendering). */
final class TwigFactory
{
    public static function create(string $templatesDir): Environment
    {
        $twig = new Environment(new FilesystemLoader($templatesDir), ['cache' => false, 'autoescape' => 'html']);
        self::register($twig);
        return $twig;
    }

    public static function register(Environment $twig): void
    {
        $twig->addFilter(new TwigFilter('idr', fn($n) => Format::idr((int)$n)));
        $twig->addFilter(new TwigFilter('d', fn($v) => Format::escDate($v)));
        $twig->addFilter(new TwigFilter('qty', fn($v, $unit = null) => Format::qty($v, $unit)));
        $twig->addFilter(new TwigFilter('json_decode', fn($v) => json_decode((string)$v, true)));
    }
}
