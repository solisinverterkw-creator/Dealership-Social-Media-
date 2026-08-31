<?php

/**
 * Maps full product descriptions to standardized product codes
 * Example: "SUZUKI ALTO AET306 VXR M1 658 CC" → "ALTO VXR"
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
        'SUZUKI FRONX SUV GL AT 1462CC' => 'FRONX GL AT',
        'SUZUKI FRONX SUV GLX AT 1462CC HYBD' => 'FRONX GLX',

        // EVERY variants
        'SUZUKI EVERY VXR 658 CC' => 'EVERY VXR',
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
        'SWIFT GL CVT',
        'SWIFT GLX',
        'FRONX MT',
        'FRONX GL AT',
        'FRONX GLX',
        'EVERY VXR',
    ];

    /**
     * Convert full product description to standardized code
     * Uses exact match first, then pattern matching as fallback
     * 
     * @param string $fullProductName Full product description
     * @return string Standardized product code
     */
    public static function getProductCode($fullProductName) {
        $fullProductName = trim($fullProductName);

        // Try exact match first
        if (isset(self::$productMapping[$fullProductName])) {
            return self::$productMapping[$fullProductName];
        }

        // Case-insensitive match
        foreach (self::$productMapping as $description => $code) {
            if (strtoupper($fullProductName) === strtoupper($description)) {
                return $code;
            }
        }

        // Pattern-based fallback: extract model + variant
        return self::extractVariantFallback($fullProductName);
    }

    /**
     * Fallback extraction if product not in mapping
     * Extracts first two significant words as model and variant
     */
    private static function extractVariantFallback($fullProductName) {
        $fullProductName = trim($fullProductName);
        if (empty($fullProductName)) {
            return '';
        }

        // Remove leading "SUZUKI" if present
        $productName = preg_replace('/^SUZUKI\s+/i', '', $fullProductName);
        $productName = trim($productName);

        // Extract model + variant pattern
        if (preg_match('/^(ALTO|CULTUS|SWIFT|EVERY|FRONX)\s+([A-Z]+)(?:\s+[A-Z]+)?\s+/i', $productName, $matches)) {
            $model = strtoupper($matches[1]);
            $variant = strtoupper($matches[2]);

            // Handle CVT specially
            if (preg_match('/^(ALTO|CULTUS|SWIFT|EVERY|FRONX)\s+([A-Z]+\s+CVT)/i', $productName, $cvtMatches)) {
                $model = strtoupper($cvtMatches[1]);
                $variant = strtoupper($cvtMatches[2]);
            }

            return "{$model} {$variant}";
        }

        // Final fallback: first two words
        $parts = explode(' ', $productName);
        if (count($parts) >= 2) {
            return strtoupper($parts[0] . ' ' . $parts[1]);
        }

        return strtoupper($productName);
    }

    /**
     * Get all registered product codes
     */
    public static function getAllProductCodes() {
        return array_unique(array_values(self::$productMapping));
    }

    /**
     * Get priority-sorted list of all codes
     */
    public static function getSortedProductCodes($extractedCodes = []) {
        $sorted = [];

        // Add codes in priority order
        foreach (self::$codePriority as $code) {
            if (empty($extractedCodes) || in_array($code, $extractedCodes)) {
                $sorted[] = $code;
            }
        }

        // Add remaining codes alphabetically
        if (!empty($extractedCodes)) {
            $remaining = array_diff($extractedCodes, $sorted);
            sort($remaining);
            $sorted = array_merge($sorted, $remaining);
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
