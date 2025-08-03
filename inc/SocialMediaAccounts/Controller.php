<?php

declare(strict_types=1);

namespace SFX\SocialMediaAccounts;

class Controller
{
  public const OPTION_NAME = 'sfx_social_media_accounts_options';

  public function __construct()
  {
    // Initialize components
    AdminPage::register();
    AssetManager::register();
    PostType::init();
    new Shortcode\SC_SocialAccounts();
  }

  public static function get_feature_config(): array
  {
    return [
      'class' => self::class,
      'menu_slug' => AdminPage::$menu_slug,
      'page_title' => AdminPage::$page_title,
      'description' => AdminPage::$description,
      'url' => admin_url('edit.php?post_type=' . PostType::$post_type),
      'error' => 'Missing SocialMediaAccountsController class in theme',
      'hook'  => null,
    ];
  }
} 