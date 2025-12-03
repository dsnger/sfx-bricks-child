<?php

declare(strict_types=1);

namespace SFX\CustomDashboard;

/**
 * Color utility class for HSL conversion and palette generation
 * 
 * Inspired by shadcn/ui's color system for generating semantic color palettes
 *
 * @package SFX_Bricks_Child_Theme
 */
class ColorUtils
{
    /**
     * Convert hex color to HSL values
     *
     * @param string $hex Hex color code (with or without #)
     * @return array{h: float, s: float, l: float} HSL values (h: 0-360, s: 0-100, l: 0-100)
     */
    public static function hexToHsl(string $hex): array
    {
        $hex = ltrim($hex, '#');
        
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        
        $r = hexdec(substr($hex, 0, 2)) / 255;
        $g = hexdec(substr($hex, 2, 2)) / 255;
        $b = hexdec(substr($hex, 4, 2)) / 255;
        
        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $l = ($max + $min) / 2;
        
        if ($max === $min) {
            $h = $s = 0;
        } else {
            $d = $max - $min;
            $s = $l > 0.5 ? $d / (2 - $max - $min) : $d / ($max + $min);
            
            switch ($max) {
                case $r:
                    $h = (($g - $b) / $d + ($g < $b ? 6 : 0)) / 6;
                    break;
                case $g:
                    $h = (($b - $r) / $d + 2) / 6;
                    break;
                case $b:
                    $h = (($r - $g) / $d + 4) / 6;
                    break;
                default:
                    $h = 0;
            }
        }
        
        return [
            'h' => round($h * 360, 1),
            's' => round($s * 100, 1),
            'l' => round($l * 100, 1),
        ];
    }

    /**
     * Convert HSL to hex color
     *
     * @param float $h Hue (0-360)
     * @param float $s Saturation (0-100)
     * @param float $l Lightness (0-100)
     * @return string Hex color code with #
     */
    public static function hslToHex(float $h, float $s, float $l): string
    {
        $h = $h / 360;
        $s = $s / 100;
        $l = $l / 100;
        
        if ($s === 0.0) {
            $r = $g = $b = $l;
        } else {
            $q = $l < 0.5 ? $l * (1 + $s) : $l + $s - $l * $s;
            $p = 2 * $l - $q;
            $r = self::hueToRgb($p, $q, $h + 1/3);
            $g = self::hueToRgb($p, $q, $h);
            $b = self::hueToRgb($p, $q, $h - 1/3);
        }
        
        return sprintf('#%02x%02x%02x', 
            (int) round($r * 255), 
            (int) round($g * 255), 
            (int) round($b * 255)
        );
    }

    /**
     * Helper for HSL to RGB conversion
     */
    private static function hueToRgb(float $p, float $q, float $t): float
    {
        if ($t < 0) $t += 1;
        if ($t > 1) $t -= 1;
        if ($t < 1/6) return $p + ($q - $p) * 6 * $t;
        if ($t < 1/2) return $q;
        if ($t < 2/3) return $p + ($q - $p) * (2/3 - $t) * 6;
        return $p;
    }

    /**
     * Format HSL values as CSS string (without hsl() wrapper for CSS variables)
     *
     * @param array{h: float, s: float, l: float} $hsl
     * @return string e.g., "222.2 84% 4.9%"
     */
    public static function hslToCssValue(array $hsl): string
    {
        return sprintf('%s %s%% %s%%', $hsl['h'], $hsl['s'], $hsl['l']);
    }

    /**
     * Calculate relative luminance of a color
     *
     * @param string $hex Hex color code
     * @return float Luminance value (0-1)
     */
    public static function getLuminance(string $hex): float
    {
        $hex = ltrim($hex, '#');
        
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        
        $r = hexdec(substr($hex, 0, 2)) / 255;
        $g = hexdec(substr($hex, 2, 2)) / 255;
        $b = hexdec(substr($hex, 4, 2)) / 255;
        
        $r = $r <= 0.03928 ? $r / 12.92 : pow(($r + 0.055) / 1.055, 2.4);
        $g = $g <= 0.03928 ? $g / 12.92 : pow(($g + 0.055) / 1.055, 2.4);
        $b = $b <= 0.03928 ? $b / 12.92 : pow(($b + 0.055) / 1.055, 2.4);
        
        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    }

    /**
     * Calculate contrast ratio between two colors
     *
     * @param string $hex1 First hex color
     * @param string $hex2 Second hex color
     * @return float Contrast ratio (1-21)
     */
    public static function getContrastRatio(string $hex1, string $hex2): float
    {
        $l1 = self::getLuminance($hex1);
        $l2 = self::getLuminance($hex2);
        
        $lighter = max($l1, $l2);
        $darker = min($l1, $l2);
        
        return ($lighter + 0.05) / ($darker + 0.05);
    }

    /**
     * Determine if a color is light or dark
     *
     * @param string $hex Hex color code
     * @return bool True if light, false if dark
     */
    public static function isLightColor(string $hex): bool
    {
        return self::getLuminance($hex) > 0.5;
    }

    /**
     * Get contrasting foreground color (black or white)
     *
     * @param string $hex Background hex color
     * @return string Contrasting foreground hex color
     */
    public static function getContrastingForeground(string $hex): string
    {
        return self::isLightColor($hex) ? '#000000' : '#ffffff';
    }

    /**
     * Adjust lightness of a color
     *
     * @param string $hex Hex color code
     * @param float $amount Amount to adjust (-100 to 100)
     * @return string Adjusted hex color
     */
    public static function adjustLightness(string $hex, float $amount): string
    {
        $hsl = self::hexToHsl($hex);
        $hsl['l'] = max(0, min(100, $hsl['l'] + $amount));
        return self::hslToHex($hsl['h'], $hsl['s'], $hsl['l']);
    }

    /**
     * Adjust saturation of a color
     *
     * @param string $hex Hex color code
     * @param float $amount Amount to adjust (-100 to 100)
     * @return string Adjusted hex color
     */
    public static function adjustSaturation(string $hex, float $amount): string
    {
        $hsl = self::hexToHsl($hex);
        $hsl['s'] = max(0, min(100, $hsl['s'] + $amount));
        return self::hslToHex($hsl['h'], $hsl['s'], $hsl['l']);
    }

    /**
     * Generate a complete semantic color palette from a primary color
     *
     * @param string $primaryHex Primary brand color
     * @param string $mode 'light' or 'dark'
     * @return array<string, array{h: float, s: float, l: float}> Semantic color palette
     */
    public static function generatePalette(string $primaryHex, string $mode = 'light'): array
    {
        $primary = self::hexToHsl($primaryHex);
        $isDark = $mode === 'dark';
        
        // Base neutrals derived from primary hue
        $baseHue = $primary['h'];
        $baseSat = min($primary['s'] * 0.15, 15); // Very desaturated for neutrals
        
        if ($isDark) {
            // Dark mode palette
            return [
                // Background colors
                'background' => ['h' => $baseHue, 's' => $baseSat, 'l' => 7],
                'foreground' => ['h' => $baseHue, 's' => 5, 'l' => 95],
                
                // Card colors
                'card' => ['h' => $baseHue, 's' => $baseSat, 'l' => 10],
                'card-foreground' => ['h' => $baseHue, 's' => 5, 'l' => 95],
                
                // Popover (same as card in most cases)
                'popover' => ['h' => $baseHue, 's' => $baseSat, 'l' => 10],
                'popover-foreground' => ['h' => $baseHue, 's' => 5, 'l' => 95],
                
                // Primary (brand color, slightly adjusted for dark mode)
                'primary' => ['h' => $primary['h'], 's' => min($primary['s'], 85), 'l' => max($primary['l'], 55)],
                'primary-foreground' => self::hexToHsl(self::getContrastingForeground(
                    self::hslToHex($primary['h'], min($primary['s'], 85), max($primary['l'], 55))
                )),
                
                // Secondary (muted version of primary)
                'secondary' => ['h' => $baseHue, 's' => $baseSat + 5, 'l' => 18],
                'secondary-foreground' => ['h' => $baseHue, 's' => 5, 'l' => 90],
                
                // Muted colors
                'muted' => ['h' => $baseHue, 's' => $baseSat, 'l' => 18],
                'muted-foreground' => ['h' => $baseHue, 's' => 5, 'l' => 65],
                
                // Accent
                'accent' => ['h' => $baseHue, 's' => $baseSat + 5, 'l' => 18],
                'accent-foreground' => ['h' => $baseHue, 's' => 5, 'l' => 95],
                
                // Destructive
                'destructive' => ['h' => 0, 's' => 62, 'l' => 50],
                'destructive-foreground' => ['h' => 0, 's' => 0, 'l' => 98],
                
                // Border and input
                'border' => ['h' => $baseHue, 's' => $baseSat, 'l' => 20],
                'input' => ['h' => $baseHue, 's' => $baseSat, 'l' => 20],
                'ring' => ['h' => $primary['h'], 's' => $primary['s'], 'l' => 55],
                
                // Chart colors (derived from primary)
                'chart-1' => ['h' => $primary['h'], 's' => 70, 'l' => 55],
                'chart-2' => ['h' => fmod($primary['h'] + 30, 360), 's' => 65, 'l' => 50],
                'chart-3' => ['h' => fmod($primary['h'] + 60, 360), 's' => 60, 'l' => 55],
                'chart-4' => ['h' => fmod($primary['h'] + 180, 360), 's' => 55, 'l' => 50],
                'chart-5' => ['h' => fmod($primary['h'] + 270, 360), 's' => 60, 'l' => 55],
                
                // Status colors
                'success' => ['h' => 142, 's' => 70, 'l' => 45],
                'success-foreground' => ['h' => 0, 's' => 0, 'l' => 98],
                'warning' => ['h' => 38, 's' => 92, 'l' => 50],
                'warning-foreground' => ['h' => 0, 's' => 0, 'l' => 10],
                'info' => ['h' => 199, 's' => 89, 'l' => 48],
                'info-foreground' => ['h' => 0, 's' => 0, 'l' => 98],
            ];
        } else {
            // Light mode palette
            return [
                // Background colors
                'background' => ['h' => $baseHue, 's' => $baseSat, 'l' => 100],
                'foreground' => ['h' => $baseHue, 's' => 50, 'l' => 10],
                
                // Card colors
                'card' => ['h' => $baseHue, 's' => $baseSat, 'l' => 100],
                'card-foreground' => ['h' => $baseHue, 's' => 50, 'l' => 10],
                
                // Popover
                'popover' => ['h' => $baseHue, 's' => $baseSat, 'l' => 100],
                'popover-foreground' => ['h' => $baseHue, 's' => 50, 'l' => 10],
                
                // Primary (brand color)
                'primary' => $primary,
                'primary-foreground' => self::hexToHsl(self::getContrastingForeground($primaryHex)),
                
                // Secondary
                'secondary' => ['h' => $baseHue, 's' => $baseSat + 10, 'l' => 96],
                'secondary-foreground' => ['h' => $baseHue, 's' => 50, 'l' => 15],
                
                // Muted colors
                'muted' => ['h' => $baseHue, 's' => $baseSat + 10, 'l' => 96],
                'muted-foreground' => ['h' => $baseHue, 's' => 10, 'l' => 45],
                
                // Accent
                'accent' => ['h' => $baseHue, 's' => $baseSat + 10, 'l' => 96],
                'accent-foreground' => ['h' => $baseHue, 's' => 50, 'l' => 15],
                
                // Destructive
                'destructive' => ['h' => 0, 's' => 84, 'l' => 60],
                'destructive-foreground' => ['h' => 0, 's' => 0, 'l' => 98],
                
                // Border and input
                'border' => ['h' => $baseHue, 's' => $baseSat + 5, 'l' => 90],
                'input' => ['h' => $baseHue, 's' => $baseSat + 5, 'l' => 90],
                'ring' => ['h' => $primary['h'], 's' => $primary['s'], 'l' => $primary['l']],
                
                // Chart colors
                'chart-1' => ['h' => $primary['h'], 's' => 75, 'l' => 50],
                'chart-2' => ['h' => fmod($primary['h'] + 30, 360), 's' => 70, 'l' => 45],
                'chart-3' => ['h' => fmod($primary['h'] + 60, 360), 's' => 65, 'l' => 50],
                'chart-4' => ['h' => fmod($primary['h'] + 180, 360), 's' => 60, 'l' => 45],
                'chart-5' => ['h' => fmod($primary['h'] + 270, 360), 's' => 65, 'l' => 50],
                
                // Status colors
                'success' => ['h' => 142, 's' => 76, 'l' => 36],
                'success-foreground' => ['h' => 0, 's' => 0, 'l' => 98],
                'warning' => ['h' => 38, 's' => 92, 'l' => 50],
                'warning-foreground' => ['h' => 0, 's' => 0, 'l' => 10],
                'info' => ['h' => 199, 's' => 89, 'l' => 48],
                'info-foreground' => ['h' => 0, 's' => 0, 'l' => 98],
            ];
        }
    }

    /**
     * Generate CSS custom properties string from palette
     *
     * @param array<string, array{h: float, s: float, l: float}> $palette
     * @return string CSS custom properties
     */
    public static function paletteToCss(array $palette): string
    {
        $css = '';
        foreach ($palette as $name => $hsl) {
            $css .= sprintf("  --%s: %s;\n", $name, self::hslToCssValue($hsl));
        }
        return $css;
    }

    /**
     * Generate the header gradient based on primary color
     *
     * @param string $primaryHex Primary hex color
     * @param string $mode 'light' or 'dark'
     * @return string CSS gradient value
     */
    public static function generateHeaderGradient(string $primaryHex, string $mode = 'light'): string
    {
        $hsl = self::hexToHsl($primaryHex);
        
        // Create gradient from primary to a shifted hue
        $startColor = $primaryHex;
        $endHue = fmod($hsl['h'] + 30, 360);
        $endSat = $mode === 'dark' ? min($hsl['s'], 70) : $hsl['s'];
        $endLight = $mode === 'dark' ? max($hsl['l'], 45) : max($hsl['l'] - 10, 30);
        $endColor = self::hslToHex($endHue, $endSat, $endLight);
        
        return "linear-gradient(135deg, {$startColor} 0%, {$endColor} 100%)";
    }
}

