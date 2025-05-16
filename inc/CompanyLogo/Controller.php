<?php

declare(strict_types=1);

namespace SFX\CompanyLogo;

class Controller
{


  public const OPTION_NAME = 'sfx_company_logo_options';

  public function __construct()
  {
    Settings::register(self::OPTION_NAME);
    AdminPage::register();
    AssetManager::register();
    new Shortcode\SC_Logo(self::OPTION_NAME);

    // Initialize the theme only after ACF is confirmed to be active
    add_action('init', [$this,'handle_options']);
    add_action('update_option_' . self::OPTION_NAME, [$this, 'handle_options'], 10, 2);
  }


  public function handle_options():void
  {
  //

  }


  public function handle_company_logo($company_logo)
  {
    // Handle the company logo
  } 


  private function is_option_enabled(string $option_key): bool
  {
    $options = get_option(self::OPTION_NAME, []);
    return !empty($options[$option_key]);
  }


  public static function get_feature_config(): array
  {
    return [
      'class' => self::class,
      'menu_slug' => AdminPage::$menu_slug,
      'page_title' => AdminPage::$page_title,
      'description' => AdminPage::$description,
      'error' => 'Missing CompanyLogoController class in theme',
      'hook'  => null,
    ];
  }
}