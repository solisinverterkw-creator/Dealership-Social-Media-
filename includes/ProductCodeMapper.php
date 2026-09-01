<?php

/**
 * Maps full product descriptions to standardized product codes
 * Example: "SUZUKI ALTO AET306 AGS M1 658 CC" → "ALTO AGS"
 */
class ProductCodeMapper {

    // Full mapping table: product description patterns → standardized codes
    private static $productMapping = [
        // ALTO variants
        'SUZUKI ALTO AET306 VXR M1 658 CC' => 'ALTO VXR',
        'SUZUKI ALTO AET306 VXR AGS M1 658 CC' => 'ALTO VXR AGS',
        'SUZUKI ALTO AET306 AGS M1 658 CC' => 'ALTO AGS',
        'SUZUKI ALTO AET306 VXL AGS M1 658 CC' => 'ALTO VXL AGS',

        // CULTUS variants
        'SUZUKI CULTUS AVK310 VXR M2 998 CC' => 'CULTUS VXR',
        'SUZUKI CULTUS AVK310 VXL M2 998 CC' => 'CULTUS VXL',
        'SUZUKI CULTUS AVK310 AGS M2 998 CC' => 'CULTUS AGS',

        // SWIFT variants
        'SUZUKI SWIFT GL M2 1197 CC' => 'SWIFT MT',
        'SUZUKI SWIFT GL CVT M2 1197 CC' => 'SWIFT GL CVT',
        'SUZUKI SWIFT GLX CVT M2 1197 CC' => 'SWIFT GLX',

        // FRONX variants
        'SUZUKI FRONX SUV GL MT 1462CC' => 'FRONX MT',
        'SUZUKI FRONX SUV GL AT 1462CC' => 'FRONX GL',
        'SUZUKI FRONX SUV GLX AT 1462CC HYBD' => 'FRONX GLX',

        // EVERY variants
        'SUZUKI EVERY VXR 658 CC' => 'EVERY VXR',
        'SUZUKI EVERY VX 658 CC' => 'EVERY VX',
    ];

    // Priority order for display (defines column order in stock report)
    public static $codePriority = [
        'ALTO VXR',
        'ALTO VXR AGS',
        'ALTO AGS',
        'ALTO VXL AGS',
        'CULTUS VXR',
        'CULTUS VXL',
        'CULTUS AGS',
        'SWIFT MT',
        'SWIFT GL',
        'SWIFT GL CVT',
        'SWIFT GLX',
        'FRONX MT',
        'FRONX GL',
        'FRONX GLX',
        'EVERY VX',
        'EVERY VXR',
    ];

    /**
     * Convert full product description to standardized code
     * Always returns a non-empty string code so no raw product is lost.
     * 
     * @param string $fullProductName Full product description
     * @return string|null Standardized product code
     */
    public static function getProductCode($fullProductName) {
        $fullProductName = trim((string)$fullProductName);
        if ($fullProductName === '') {
            return null;
        }

        // 1. Try exact match first
        if (isset(self::$productMapping[$fullProductName])) {
            return self::$productMapping[$fullProductName];
        }

        // 2. Try case-insensitive exact match
        $upperFull = strtoupper($fullProductName);
        foreach (self::$productMapping as $description => $code) {
            if ($upperFull === strtoupper(trim($description))) {
                return $code;
            }
        }

        // 3. Intelligent Pattern & Keyword Matching for Suzuki Vehicles
        // ALTO
        if (str_contains($upperFull, 'ALTO')) {
            if (str_contains($upperFull, 'VXR') && str_contains($upperFull, 'AGS')) return 'ALTO VXR AGS';
            if (str_contains($upperFull, 'VXL')) return 'ALTO VXL AGS';
            if (str_contains($upperFull, 'VXR')) return 'ALTO VXR';
            if (str_contains($upperFull, 'AGS')) return 'ALTO AGS';
            return 'ALTO VXR';
        }

        // CULTUS
        if (str_contains($upperFull, 'CULTUS')) {
            if (str_contains($upperFull, 'AGS')) return 'CULTUS AGS';
            if (str_contains($upperFull, 'VXL')) return 'CULTUS VXL';
            if (str_contains($upperFull, 'VXR')) return 'CULTUS VXR';
            return 'CULTUS VXR';
        }

        // SWIFT
        if (str_contains($upperFull, 'SWIFT')) {
            if (str_contains($upperFull, 'GLX')) return 'SWIFT GLX';
            if (str_contains($upperFull, 'GL') && str_contains($upperFull, 'CVT')) return 'SWIFT GL CVT';
            if (str_contains($upperFull, 'GL')) return 'SWIFT GL';
            if (str_contains($upperFull, 'MT')) return 'SWIFT MT';
            return 'SWIFT MT';
        }

        // FRONX
        if (str_contains($upperFull, 'FRONX')) {
            if (str_contains($upperFull, 'GLX')) return 'FRONX GLX';
            if (str_contains($upperFull, 'GL')) return 'FRONX GL';
            if (str_contains($upperFull, 'MT')) return 'FRONX MT';
            return 'FRONX GLX';
        }

        // EVERY
        if (str_contains($upperFull, 'EVERY')) {
            if (str_contains($upperFull, 'VXR')) return 'EVERY VXR';
            if (str_contains($upperFull, 'VX')) return 'EVERY VX';
            return 'EVERY VXR';
        }

        // WAGON R
        if (str_contains($upperFull, 'WAGON')) {
            if (str_contains($upperFull, 'VXL')) return 'WAGON R VXL';
            if (str_contains($upperFull, 'VXR')) return 'WAGON R VXR';
            if (str_contains($upperFull, 'AGS')) return 'WAGON R AGS';
            return 'WAGON R';
        }

        // BOLAN
        if (str_contains($upperFull, 'BOLAN')) return 'BOLAN';

        // MEHRAN
        if (str_contains($upperFull, 'MEHRAN')) return 'MEHRAN';

        // MEGA CARRY
        if (str_contains($upperFull, 'MEGA CARRY') || str_contains($upperFull, 'MEGACARRY')) return 'MEGA CARRY';

        // 4. Fallback: return cleaned up title (strip "SUZUKI " prefix, uppercase)
        $clean = preg_replace('/^SUZUKI\s+/i', '', $upperFull);
        return trim($clean) !== '' ? trim($clean) : $upperFull;
    }

    /**
     * Get all registered product codes
     */
    public static function getAllProductCodes() {
        return array_unique(array_values(self::$productMapping));
    }

    /**
     * Get priority-sorted list of codes that actually exist in data
     */
    public static function getSortedProductCodes($extractedCodes = []) {
        if (empty($extractedCodes)) {
            return self::$codePriority;
        }

        $sorted = [];

        // Add codes in priority order
        foreach (self::$codePriority as $code) {
            if (in_array($code, $extractedCodes, true)) {
                $sorted[] = $code;
            }
        }

        // Add any additional codes that exist in data but not in priority list
        foreach ($extractedCodes as $code) {
            if (!in_array($code, $sorted, true)) {
                $sorted[] = $code;
            }
        }

        return $sorted;
    }

    /**
     * Add or update a product code mapping
     */
    public static function setProductMapping($fullDescription, $code) {
        self::$productMapping[trim($fullDescription)] = strtoupper(trim($code));
    }
}
