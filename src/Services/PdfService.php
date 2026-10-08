<?php
declare(strict_types=1);
namespace App\Services;

use Slim\Views\Twig;

final class PdfService
{
    public static function render(string $tpl, array $data, Twig $twig): string
    {
        $html = $twig->fetch($tpl, $data);
        $opt = new \Dompdf\Options();
        $opt->set('isRemoteEnabled', false);
        $opt->set('defaultFont', 'Inter');
        $opt->set('fontDir', __DIR__ . '/../../public/fonts');
        $opt->set('fontCache', sys_get_temp_dir());
        $dom = new \Dompdf\Dompdf($opt);
        // register local fonts if present
        $dom->getFontMetrics();
        $dom->loadHtml($html, 'UTF-8');
        $dom->setPaper('A4', 'portrait');
        $dom->render();
        return (string)$dom->output();
    }
}
