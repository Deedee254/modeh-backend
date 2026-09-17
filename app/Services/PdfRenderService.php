<?php

namespace App\Services;

use Dompdf\Dompdf;
use Dompdf\Options;

class PdfRenderService
{
    public function render(string $html, string $paper = 'A4', string $orientation = 'portrait'): string
    {
        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);
        $options->set('isJavascriptEnabled', false);
        $options->set('isHtml5ParserEnabled', true);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper($paper, $orientation);
        $dompdf->render();

        $pdf = $dompdf->output();
        unset($dompdf);

        return $pdf;
    }
}