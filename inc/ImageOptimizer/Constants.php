<?php
declare(strict_types=1);

namespace SFX\ImageOptimizer;

/**
 * Constants for ImageOptimizer module
 * Centralizes all magic numbers and configuration values
 */
final class Constants
{
    /**
     * Log configuration
     */
    public const LOG_LIMIT = 500;
    public const LOG_OPTION_KEY = 'sfx_webp_conversion_log';

    /**
     * File operation configuration
     */
    public const MAX_DELETE_RETRIES = 5;
    public const RETRY_DELAY_SECONDS = 1;

    /**
     * Memory management
     */
    public const MEMORY_THRESHOLD = 0.85;
    public const MAX_MEMORY_WARNINGS = 5;

    /**
     * Image processing
     */
    public const THUMBNAIL_SIZE = 150;
    public const DEFAULT_QUALITY = 80;
    public const DEFAULT_BATCH_SIZE = 5;
    public const MIN_BATCH_SIZE = 1;
    public const MAX_BATCH_SIZE = 50;
    public const MIN_QUALITY = 1;
    public const MAX_QUALITY = 100;
    public const MAX_DIMENSION = 9999;
    public const MAX_CUSTOM_SIZES = 4;

    /**
     * Default values
     */
    public const DEFAULT_MAX_WIDTHS = '1920,1200,600,300';
    public const DEFAULT_MAX_HEIGHTS = '1080,720,480,360';
    public const DEFAULT_RESIZE_MODE = 'width';

    /**
     * Cleanup configuration
     */
    public const DEFAULT_CLEANUP_BATCH_SIZE = 1000;
    public const MIN_CLEANUP_BATCH_SIZE = 100;
    public const MAX_CLEANUP_BATCH_SIZE = 5000;
    public const CLEANUP_TIMEOUT_SECONDS = 300;

    /**
     * Allowed file extensions
     */
    public const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'avif'];
    public const ORIGINAL_EXTENSIONS = ['jpg', 'jpeg', 'png'];
    public const ORIGINAL_EXTENSIONS_WITH_CASE = ['jpg', 'jpeg', 'png', 'JPG', 'JPEG', 'PNG'];
    public const CONVERTED_EXTENSIONS = ['webp', 'avif'];

    /**
     * Option keys (for reference, actual keys in Settings class)
     */
    public const OPTION_PREFIX = 'sfx_webp_';

    /**
     * Thumbnail suffix string
     */
    public const THUMBNAIL_SUFFIX = '-150x150';

    /**
     * Get thumbnail suffix dynamically
     */
    public static function getThumbnailSuffix(): string
    {
        return '-' . self::THUMBNAIL_SIZE . 'x' . self::THUMBNAIL_SIZE;
    }

    /**
     * Prevent instantiation
     */
    private function __construct() {}
}
