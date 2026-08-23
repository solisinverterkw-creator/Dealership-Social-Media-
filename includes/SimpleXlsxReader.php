<?php
/**
 * Minimal .xlsx reader with zero external dependencies — this server's PHP
 * has no `zip` extension, so ZIP entries are located via a hand-rolled central
 * directory parse and decompressed with zlib's gzinflate() (deflate is the
 * standard xlsx compression method).
 * Only pulls cell values containing "@" (candidate emails) — good enough for
 * "here's a spreadsheet, find the emails in it" regardless of which column
 * they're in or whether there's a header row.
 */
class SimpleXlsxReader
{
    /**
     * Full-row reader (used by Sales/Stock CSV-or-Excel import) — returns every
     * cell of the first worksheet as a plain string, row by row, matching
     * fgetcsv()'s shape so callers can loop over either format identically.
     */
    public function readFirstSheet(string $filePath): array
    {
        $data = file_get_contents($filePath);
        if ($data === false) {
            throw new RuntimeException('Could Not Read The Uploaded File.');
        }

        $centralDir = $this->parseCentralDirectory($data);
        if (empty($centralDir)) {
            throw new RuntimeException('Could Not Open The Excel File — It May Be Corrupted Or Not A Valid .xlsx File.');
        }

        $sheetFile = null;
        foreach (array_keys($centralDir) as $name) {
            if ($name === 'xl/worksheets/sheet1.xml') {
                $sheetFile = $name;
                break;
            }
        }
        if ($sheetFile === null) {
            foreach (array_keys($centralDir) as $name) {
                if (preg_match('#^xl/worksheets/sheet\d+\.xml$#', $name)) {
                    $sheetFile = $name;
                    break;
                }
            }
        }
        if ($sheetFile === null) {
            throw new RuntimeException('Could Not Find Worksheet Data In This .xlsx File.');
        }

        $sharedStrings = $this->extractSharedStrings($data, $centralDir);
        $sheetXml = $this->extractEntry($data, $centralDir, $sheetFile);
        if ($sheetXml === null) {
            throw new RuntimeException('Could Not Read Worksheet Data In This .xlsx File.');
        }

        return $this->parseSheetXmlToRows($sheetXml, $sharedStrings);
    }

    /**
     * Reads a specific worksheet by its visible tab name (e.g. "Undelivered
     * Stock") — workbooks with several tabs (Summary, Payments, Open Orders,
     * etc.) aren't always laid out with the wanted data on sheet1. Returns
     * null (not a thrown error) when no tab with that name exists, so callers
     * can fall back to readFirstSheet() instead of hard-failing.
     */
    public function readSheetByName(string $filePath, string $sheetName): ?array
    {
        $data = file_get_contents($filePath);
        if ($data === false) {
            throw new RuntimeException('Could Not Read The Uploaded File.');
        }

        $centralDir = $this->parseCentralDirectory($data);
        if (empty($centralDir)) {
            throw new RuntimeException('Could Not Open The Excel File — It May Be Corrupted Or Not A Valid .xlsx File.');
        }

        $workbookXml = $this->extractEntry($data, $centralDir, 'xl/workbook.xml');
        $relsXml = $this->extractEntry($data, $centralDir, 'xl/_rels/workbook.xml.rels');
        if ($workbookXml === null || $relsXml === null) {
            return null;
        }

        $workbook = @simplexml_load_string($workbookXml);
        $rels = @simplexml_load_string($relsXml);
        if (!$workbook || !$rels || !isset($workbook->sheets->sheet)) {
            return null;
        }

        $targetRid = null;
        foreach ($workbook->sheets->sheet as $sheet) {
            if (strcasecmp(trim((string)$sheet['name']), trim($sheetName)) === 0) {
                $ns = $sheet->attributes('r', true);
                $targetRid = (string)($ns['id'] ?? '');
                break;
            }
        }
        if ($targetRid === '' || $targetRid === null) {
            return null; // no tab with that name — let the caller fall back
        }

        $target = null;
        foreach ($rels->Relationship as $rel) {
            if ((string)$rel['Id'] === $targetRid) {
                $target = (string)$rel['Target'];
                break;
            }
        }
        if ($target === null) {
            return null;
        }

        $sheetFile = 'xl/' . ltrim($target, '/');
        $sharedStrings = $this->extractSharedStrings($data, $centralDir);
        $sheetXml = $this->extractEntry($data, $centralDir, $sheetFile);
        if ($sheetXml === null) {
            return null;
        }

        return $this->parseSheetXmlToRows($sheetXml, $sharedStrings);
    }

    private function parseSheetXmlToRows(string $sheetXml, array $sharedStrings): array
    {
        $sxml = @simplexml_load_string($sheetXml);
        if (!$sxml || !isset($sxml->sheetData)) {
            throw new RuntimeException('Could Not Parse Worksheet XML In This .xlsx File.');
        }

        $rows = [];
        foreach ($sxml->sheetData->row as $rowXml) {
            $rowData = [];
            $colIndex = 0;
            foreach ($rowXml->c as $cell) {
                $ref = (string)$cell['r'];
                $colLetters = preg_replace('/[0-9]+/', '', $ref);
                $targetIndex = $colLetters !== '' ? $this->colLettersToIndex($colLetters) : $colIndex;
                while ($colIndex < $targetIndex) {
                    $rowData[$colIndex] = '';
                    $colIndex++;
                }

                $type = (string)$cell['t'];
                if ($type === 's') {
                    $rawIndex = isset($cell->v) ? (int)$cell->v : null;
                    $value = $rawIndex !== null ? ($sharedStrings[$rawIndex] ?? '') : '';
                } elseif ($type === 'inlineStr') {
                    $value = isset($cell->is->t) ? (string)$cell->is->t : '';
                } else {
                    $value = isset($cell->v) ? (string)$cell->v : '';
                }

                $rowData[$colIndex] = $value;
                $colIndex++;
            }
            $rows[] = $rowData;
        }

        return $rows;
    }

    private function colLettersToIndex(string $letters): int
    {
        $letters = strtoupper($letters);
        $index = 0;
        for ($i = 0; $i < strlen($letters); $i++) {
            $index = $index * 26 + (ord($letters[$i]) - ord('A') + 1);
        }
        return $index - 1;
    }

    public function extractCandidateEmails(string $filePath): array
    {
        $data = file_get_contents($filePath);
        if ($data === false) {
            return [];
        }

        $centralDir = $this->parseCentralDirectory($data);
        if (empty($centralDir)) {
            return [];
        }

        $sheetName = null;
        foreach (array_keys($centralDir) as $name) {
            if ($name === 'xl/worksheets/sheet1.xml') {
                $sheetName = $name;
                break;
            }
        }
        if ($sheetName === null) {
            foreach (array_keys($centralDir) as $name) {
                if (preg_match('#^xl/worksheets/sheet\d+\.xml$#', $name)) {
                    $sheetName = $name;
                    break;
                }
            }
        }
        if ($sheetName === null) {
            return [];
        }

        $sharedStrings = $this->extractSharedStrings($data, $centralDir);
        $sheetXml = $this->extractEntry($data, $centralDir, $sheetName);
        if ($sheetXml === null) {
            return [];
        }

        return $this->extractEmailsFromSheetXml($sheetXml, $sharedStrings);
    }

    private function parseCentralDirectory(string $data): array
    {
        $eocdPos = strrpos($data, "PK\x05\x06");
        if ($eocdPos === false) {
            return [];
        }
        $entryCount = unpack('v', substr($data, $eocdPos + 10, 2))[1];
        $cdOffset = unpack('V', substr($data, $eocdPos + 16, 4))[1];

        $entries = [];
        $pos = $cdOffset;
        for ($i = 0; $i < $entryCount; $i++) {
            if (substr($data, $pos, 4) !== "PK\x01\x02") {
                break;
            }
            $compMethod = unpack('v', substr($data, $pos + 10, 2))[1];
            $compSize = unpack('V', substr($data, $pos + 20, 4))[1];
            $nameLen = unpack('v', substr($data, $pos + 28, 2))[1];
            $extraLen = unpack('v', substr($data, $pos + 30, 2))[1];
            $commentLen = unpack('v', substr($data, $pos + 32, 2))[1];
            $localOffset = unpack('V', substr($data, $pos + 42, 4))[1];
            // Zip entry paths are always forward-slash per spec, but normalize
            // backslashes too in case some Windows-side tool wrote them that way.
            $name = str_replace('\\', '/', substr($data, $pos + 46, $nameLen));

            $entries[$name] = [
                'comp_method' => $compMethod,
                'comp_size' => $compSize,
                'local_offset' => $localOffset,
            ];

            $pos += 46 + $nameLen + $extraLen + $commentLen;
        }
        return $entries;
    }

    private function extractEntry(string $data, array $centralDir, string $name): ?string
    {
        if (!isset($centralDir[$name])) {
            return null;
        }
        $entry = $centralDir[$name];
        $pos = $entry['local_offset'];
        if (substr($data, $pos, 4) !== "PK\x03\x04") {
            return null;
        }
        $nameLen = unpack('v', substr($data, $pos + 26, 2))[1];
        $extraLen = unpack('v', substr($data, $pos + 28, 2))[1];
        $dataStart = $pos + 30 + $nameLen + $extraLen;
        $compressed = substr($data, $dataStart, $entry['comp_size']);

        if ($entry['comp_method'] === 0) {
            return $compressed;
        }
        $inflated = @gzinflate($compressed);
        return $inflated !== false ? $inflated : null;
    }

    private function extractSharedStrings(string $data, array $centralDir): array
    {
        $xml = $this->extractEntry($data, $centralDir, 'xl/sharedStrings.xml');
        if ($xml === null) {
            return [];
        }
        $sxml = @simplexml_load_string($xml);
        if (!$sxml) {
            return [];
        }
        $strings = [];
        foreach ($sxml->si as $si) {
            if (isset($si->t)) {
                $strings[] = (string)$si->t;
            } else {
                $text = '';
                foreach ($si->r as $r) {
                    $text .= (string)$r->t;
                }
                $strings[] = $text;
            }
        }
        return $strings;
    }

    private function extractEmailsFromSheetXml(string $xml, array $sharedStrings): array
    {
        $sxml = @simplexml_load_string($xml);
        if (!$sxml || !isset($sxml->sheetData)) {
            return [];
        }
        $values = [];
        foreach ($sxml->sheetData->row as $row) {
            foreach ($row->c as $cell) {
                if (!isset($cell->v)) {
                    continue;
                }
                $type = (string)$cell['t'];
                $raw = (string)$cell->v;
                $value = $type === 's' ? ($sharedStrings[(int)$raw] ?? '') : $raw;
                $value = trim($value);
                if ($value !== '' && str_contains($value, '@')) {
                    $values[] = $value;
                }
            }
        }
        return array_values(array_unique($values));
    }
}
