<?php

namespace App\Libraries\Cement;

/** Read values from the first XLSX worksheet without extracting files or evaluating formulas. */
final class ImportPreview
{
    public function __construct(private Catalog $c)
    {
    }
    public function upload(array $actor, \CodeIgniter\HTTP\IncomingRequest $request): array
    {
        $type = $request->getPost('recordTypeCode');
        if (!is_string($type) || !$this->c->allowed($actor['role'], $type, true)) {
            throw new Problem('Import preview is not authorized', 403);
        }
        $file = $request->getFile('attachment');
        if (!$file || !$file->isValid() || $file->getSize() > 5 * 1024 * 1024 || strtolower($file->getClientExtension()) !== 'xlsx') {
            throw new Problem('Choose an XLSX workbook no larger than 5 MB');
        }
        return ['rows' => $this->xlsx($file->getTempName(), $type),'worksheet' => 'first'];
    }
    private function xml(string $source): \DOMXPath
    {
        $document = new \DOMDocument();
        $document->resolveExternals = false;
        $document->substituteEntities = false;
        $previous = libxml_use_internal_errors(true);
        try {
            $valid = $document->loadXML($source, LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if (!$valid || $document->doctype) {
            throw new Problem('Workbook contains invalid or unsupported XML');
        }
        $xpath = new \DOMXPath($document);
        $xpath->registerNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        return $xpath;
    }
    public function xlsx(string $path, string $type): array
    {
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            throw new Problem('Invalid XLSX archive');
        }
        try {
            if ($zip->numFiles > 1000) {
                throw new Problem('Workbook has too many archive members');
            }$total = 0;
            for ($n = 0;$n < $zip->numFiles;$n++) {
                $stat = $zip->statIndex($n);
                $total += $stat['size'];
                if ($total > 10 * 1024 * 1024) {
                    throw new Problem('Expanded workbook exceeds 10 MB');
                }
            }
            $strings = [];
            $shared = $zip->getFromName('xl/sharedStrings.xml');
            if ($shared !== false) {
                $xml = $this->xml($shared);
                foreach ($xml->query('//x:si') as $item) {
                    $text = '';
                    foreach ($xml->query('.//x:t', $item) as $part) {
                        $text .= $part->textContent;
                    }$strings[] = $text;
                }
            }
            $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
            if ($sheet === false) {
                throw new Problem('The first worksheet is missing');
            }$xml = $this->xml($sheet);
            $rows = [];
            foreach ($xml->query('//x:sheetData/x:row') as $row) {
                if (count($rows) > 100) {
                    throw new Problem('Import at most 100 rows per workbook');
                }$values = [];
                $column = 0;
                foreach ($xml->query('x:c', $row) as $cell) {
                    if ($xml->query('x:f', $cell)->length) {
                        throw new Problem('Formula cells are not imported; export calculated values first');
                    }
                    $reference = $cell->getAttribute('r');
                    if (preg_match('/^([A-Z]+)\d+$/', $reference, $match)) {
                        $column = 0;
                        foreach (str_split($match[1]) as $letter) {
                            $column = $column * 26 + ord($letter) - 64;
                        }$column--;
                    }
                    if ($column > 200) {
                        throw new Problem('Workbook has too many columns');
                    }
                    $kind = $cell->getAttribute('t');
                    $value = $xml->evaluate('string(x:v)', $cell);
                    if ($kind === 's') {
                        $value = $strings[(int)$value] ?? throw new Problem('Invalid shared string index');
                    } elseif ($kind === 'inlineStr') {
                        $value = '';
                        foreach ($xml->query('x:is//x:t', $cell) as $part) {
                            $value .= $part->textContent;
                        }
                    } elseif ($kind === 'b') {
                        $value = $value === '1' ? 'true' : 'false';
                    }
                    $values[$column++] = (string)$value;
                }
                if (!$values || !array_filter($values, fn ($v) => $v !== '')) {
                    continue;
                }$width = max(array_keys($values)) + 1;
                $filled = array_fill(0, $width, '');
                foreach ($values as $index => $value) {
                    $filled[$index] = $value;
                }$rows[] = $filled;
            }
            if (count($rows) < 2) {
                throw new Problem('Workbook needs a header row and data');
            }$headers = $rows[0];
            $width = count($headers);
            $descriptors = [];
            foreach ($this->c->descriptors($type) as $f) {
                $descriptors[$f['alias']] = $f;
            }
            $date1904 = false;
            $workbook = $zip->getFromName('xl/workbook.xml');
            if ($workbook !== false) {
                $book = $this->xml($workbook);
                $date1904 = in_array($book->evaluate('string(//x:workbookPr/@date1904)'), ['1','true']);
            }
            foreach ($rows as $index => &$values) {
                $values = array_pad($values, $width, '');
                if (count($values) > $width) {
                    throw new Problem('Worksheet row has more values than headers');
                }if (!$index) {
                    continue;
                }
                foreach ($headers as $column => $alias) {
                    $kind = $descriptors[$alias]['type'] ?? null;
                    $value = $values[$column];
                    if (in_array($kind, ['date','datetime']) && is_numeric($value)) {
                        $epoch = new \DateTimeImmutable($date1904 ? '1904-01-01' : '1899-12-30', new \DateTimeZone('UTC'));
                        $date = $epoch->modify('+'.(int)round((float)$value * 86400).' seconds');
                        $values[$column] = $date->format($kind === 'date' ? 'Y-m-d' : 'Y-m-d\TH:i:s\Z');
                    }
                }
            }unset($values);
            return $rows;
        } finally {
            $zip->close();
        }
    }
}
