<?php

/*
 * Copyright (C) 2021 Justin René Back <justin@tosdr.org>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */


use crisp\api\Helper;
use crisp\core\APIPermissions;
use crisp\core\Config;
use crisp\core\Theme;
use crisp\plugin\curator\PhoenixUser;
use Twig\TwigFilter;
use Twig\TwigFunction;

if(!defined('CRISP_COMPONENT')){
    echo 'Cannot access this component directly!';
    exit;
}

include __DIR__ . '/includes/Users.php';
include __DIR__ . '/includes/PhoenixUser.php';

if (isset($_SESSION[Config::$Cookie_Prefix . 'session_login'])) {

    $User = new PhoenixUser($_SESSION[Config::$Cookie_Prefix . 'session_login']['user']);

    if (!$User->isSessionValid()) {
        unset($_SESSION[Config::$Cookie_Prefix . 'session_login']);
    } else {
        Theme::addToNavbar('curator', '<span class="badge bg-info"><i class="fas fa-hands-helping"></i> MANAGE</span>', '/dashboard', '_self', -1, 'right');
    }

    $userDetails = $User->fetch();

    $_locale = Helper::getLocale();

    /* Navbar */

        $navbar[] = array('ID' => 'dashboard', 'html' => $this->getTranslation('views.curator_dashboard.header'), 'href' => "/$_locale/dashboard", 'target' => '_self');


    if ($userDetails['curator']) {
        $navbar[] = array('ID' => 'service_requests', 'html' => $this->getTranslation('views.service_requests.header'), 'href' => "/$_locale/service_requests", 'target' => '_self');
    }


        $navbar[] = array('ID' => 'apikeys', 'html' => $this->getTranslation('views.apikeys.header'), 'href' => "/$_locale/apikeys", 'target' => '_self');

    $this->TwigTheme->addGlobal('route', $GLOBALS['route']->GET);
    $this->TwigTheme->addGlobal('management_navbar', $navbar);
    $this->TwigTheme->addGlobal('api_permissions', APIPermissions::getConstants());
    $this->TwigTheme->addFunction(new TwigFunction('fetch_phoenix_user', [new PhoenixUser(), 'fetchStatic']));
    $this->TwigTheme->addFilter(new TwigFilter('strtotime', 'strtotime'));
    $this->TwigTheme->addFunction(new TwigFunction('time', 'time'));
} else {
    Theme::addToNavbar('login', $this->getTranslation('views.login.header'), '/login', '_self', 99);
}
