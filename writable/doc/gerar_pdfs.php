<?php
/**
 * Script para gerar PDFs dos relatórios de etapa
 * Execute: php writable/doc/gerar_pdfs.php
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

// Parsedown para converter Markdown -> HTML
class SimpleMarkdown {
    public static function parse($text) {
        // Headers
        $text = preg_replace('/^### (.+)$/m', '<h3>$1</h3>', $text);
        $text = preg_replace('/^## (.+)$/m', '<h2>$1</h2>', $text);
        $text = preg_replace('/^# (.+)$/m', '<h1>$1</h1>', $text);
        
        // Bold
        $text = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $text);
        
        // Checkboxes
        $text = str_replace('- ✅', '<li style="list-style:none;">✅', $text);
        $text = str_replace('- [ ]', '<li style="list-style:none;">☐', $text);
        
        // Lists
        $text = preg_replace('/^- (.+)$/m', '<li>$1</li>', $text);
        
        // Tables (simple)
        $lines = explode("\n", $text);
        $inTable = false;
        $result = [];
        foreach ($lines as $line) {
            if (preg_match('/^\|(.+)\|$/', $line)) {
                if (strpos($line, '---') !== false) continue;
                if (!$inTable) {
                    $result[] = '<table border="1" cellpadding="8" cellspacing="0" style="border-collapse:collapse; width:100%; margin:10px 0;">';
                    $inTable = true;
                }
                $cells = explode('|', trim($line, '|'));
                $result[] = '<tr>';
                foreach ($cells as $cell) {
                    $result[] = '<td>' . trim($cell) . '</td>';
                }
                $result[] = '</tr>';
            } else {
                if ($inTable) {
                    $result[] = '</table>';
                    $inTable = false;
                }
                $result[] = $line;
            }
        }
        if ($inTable) $result[] = '</table>';
        $text = implode("\n", $result);
        
        // Horizontal rule
        $text = preg_replace('/^---$/m', '<hr>', $text);
        
        // Line breaks
        $text = nl2br($text);
        
        return $text;
    }
}

$arquivos = [
    'RELATORIO_ETAPA_1.md',
    'RELATORIO_ETAPA_2.md',
    'RELATORIO_ETAPA_3.md',
    'RELATORIO_ETAPA_4_MVP.md',
];

$options = new Options();
$options->set('isRemoteEnabled', true);
$options->set('defaultFont', 'DejaVu Sans');

foreach ($arquivos as $arquivo) {
    $mdPath = __DIR__ . '/' . $arquivo;
    if (!file_exists($mdPath)) {
        echo "Arquivo não encontrado: $arquivo\n";
        continue;
    }
    
    $markdown = file_get_contents($mdPath);
    $html = SimpleMarkdown::parse($markdown);
    
    $fullHtml = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { 
            font-family: DejaVu Sans, sans-serif; 
            font-size: 11pt; 
            line-height: 1.6;
            color: #333;
            padding: 20px;
        }
        h1 { 
            color: #1a1a1a; 
            font-size: 18pt; 
            border-bottom: 2px solid #6366f1;
            padding-bottom: 10px;
        }
        h2 { 
            color: #4338ca; 
            font-size: 14pt; 
            margin-top: 25px;
        }
        h3 { 
            color: #555; 
            font-size: 12pt; 
        }
        hr { 
            border: none; 
            border-top: 1px solid #ddd; 
            margin: 20px 0;
        }
        li { 
            margin: 5px 0; 
        }
        table {
            font-size: 10pt;
        }
        strong {
            color: #1a1a1a;
        }
    </style>
</head>
<body>' . $html . '</body>
</html>';

    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($fullHtml);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
    
    $pdfPath = __DIR__ . '/' . str_replace('.md', '.pdf', $arquivo);
    file_put_contents($pdfPath, $dompdf->output());
    
    echo "✅ Gerado: " . str_replace('.md', '.pdf', $arquivo) . "\n";
}

echo "\nTodos os PDFs foram gerados em: " . __DIR__ . "\n";
