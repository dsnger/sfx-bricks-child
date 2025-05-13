<?php

declare(strict_types=1);

namespace SFX\SecurityHeader;


class Controller
{

    /**
     * Initialize the controller by registering all hooks
     */
    public function __construct()
    {
      AdminPage::register();
    }

}
