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
    public const MAX_CUSTOM_SIZES = 5;

    /**
     * Default values
     */
    public const DEFAULT_MAX_WIDTHS = '1920,1200,600,300';
    public const DEFAULT_MAX_HEIGHTS = '1080,720,480,360';
    public const DEFAULT_RESIZE_MODE = 'width';

    /**
     * Encoder version. Bumping it re-flags every already-converted image for
     * reprocessing via pixrefiner_stamp comparison — a full pass over the
     * media library on every installed site.
     *
     * One rule decides it. Bump when existing output is wrong: corrupt,
     * mis-encoded, or no longer what the current settings ask for. Otherwise
     * bump only for a migration someone has explicitly approved, whose
     * measured benefit is worth that pass. Output that is merely larger,
     * slower to produce, or unlike what a newer encoder would emit is not a
     * reason by itself.
     *
     * Dropping the libwebp `webp:method` override is the second case
     * declined: files encoded at method 6 are correct and marginally smaller
     * than the default produces, so this deliberately stayed at 2.
     */
    public const ENCODER_VERSION = 2;

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
