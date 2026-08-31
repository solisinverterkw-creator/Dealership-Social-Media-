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
     * Returns code only if exact match found, otherwise returns null
     * 
     * @param string $fullProductName Full product description
     * @return string|null Standardized product code or null if not found
     */
    public static function getProductCode($fullProductName) {
        $fullProductName = trim($fullProductName);

        // Try exact match first
        if (isset(self::$productMapping[$fullProductName])) {
            return self::$productMapping[$fullProductName];
        }

        // Try case-insensitive match
        foreach (self::$productMapping as $description => $code) {
            if (strtoupper(trim($fullProductName)) === strtoupper(trim($description))) {
                return $code;
            }
        }

        // If no exact match found, return null to exclude from report
        return null;
    }

    /**
     * Get all registered product codes
     */
    public static function getAllProductCodes() {
        return array_unique(array_values(self::$productMapping));
    }

    /**
     * Get priority-sorted list of codes that actually exist in data
     * Only returns codes from the priority list to avoid unnecessary columns
     */
    public static function getSortedProductCodes($extractedCodes = []) {
        if (empty($extractedCodes)) {
            return self::$codePriority;
        }

        $sorted = [];

        // Add codes in priority order (ONLY from priority list)
        foreach (self::$codePriority as $code) {
            if (in_array($code, $extractedCodes)) {
                $sorted[] = $code;
            }
        }

        // DO NOT add codes that aren't in priority list
        // This prevents unnecessary columns from showing up

        return $sorted;
    }

    /**
     * Add or update a product code mapping
     */
    public static function setProductMapping($fullDescription, $code) {
        self::$productMapping[trim($fullDescription)] = strtoupper(trim($code));
    }
}
