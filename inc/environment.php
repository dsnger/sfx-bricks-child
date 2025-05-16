<?php

declare(strict_types=1);

namespace SFX;

if (!defined('ABSPATH')) {
  exit;
}

/**
 * Environment configuration handler for the theme
 */
class Environment
{
  private static array $env_vars = [];
  private static bool $initialized = false;

  /**
   * Initialize the environment handler
   * 
   * @return void
   */
  public static function init(): void
  {
    if (self::$initialized) {
      return;
    }

    $env_file = get_stylesheet_directory() . '/.env.local';
    if (file_exists($env_file)) {
      self::load_env_file($env_file);
    }

    self::$initialized = true;
  }

  /**
   * Load environment variables from a file
   * 
   * @param string $file_path Path to the environment file
   * @return void
   */
  private static function load_env_file(string $file_path): void
  {
    $file_contents = file_get_contents($file_path);
    if (!$file_contents) {
      return;
    }

    $lines = explode("\n", $file_contents);
    foreach ($lines as $line) {
      $line = trim($line);
      
      // Skip comments and empty lines
      if (empty($line) || strpos($line, '#') === 0) {
        continue;
      }
      
      // Parse variable assignment
      if (strpos($line, '=') !== false) {
        list($key, $value) = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        
        // Remove quotes if present
        if ((strpos($value, '"') === 0 && strrpos($value, '"') === strlen($value) - 1) ||
            (strpos($value, "'") === 0 && strrpos($value, "'") === strlen($value) - 1)) {
          $value = substr($value, 1, -1);
        }
        
        self::$env_vars[$key] = $value;
      }
    }
  }

  /**
   * Get an environment variable
   * 
   * @param string $key Environment variable name
   * @param mixed $default Default value if variable doesn't exist
   * @return mixed
   */
  public static function get(string $key, $default = null)
  {
    if (!self::$initialized) {
      self::init();
    }
    
    return self::$env_vars[$key] ?? $default;
  }

  /**
   * Check if the theme is in development mode
   * 
   * @return bool
   */
  public static function is_dev_mode(): bool
  {
    return self::get('SFX_THEME_DEV_MODE', 'false') === 'true';
  }
}

// Initialize environment on load
Environment::init(); 