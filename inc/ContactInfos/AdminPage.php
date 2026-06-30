<?php

declare(strict_types=1);

namespace SFX\ContactInfos;

/**
 * Feature registry metadata for Contact Infos (admin menu is owned by PostType).
 */
class AdminPage
{
    public static string $menu_slug = 'sfx-contact-infos';
    public static string $page_title = 'Company Information';
    public static string $description = 'Manage company information and branches';
}
