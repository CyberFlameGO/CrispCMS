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

use crisp\api\Config;
use crisp\api\Helper;
use crisp\core\Theme;

Theme::addtoNavbar("frontpage", crisp\api\Translation::fetch("components.navbar.home"), Helper::generateLink("frontpage"), "_self", -99);
Theme::addtoNavbar("ratings", crisp\api\Translation::fetch("components.navbar.ratings"), Helper::generateLink("frontpage#ratings"), "_self", -98);
Theme::addtoNavbar("api", crisp\api\Translation::fetch("components.navbar.api"), Helper::generateLink("api"), "_self", -95);
Theme::addtoNavbar("forum", crisp\api\Translation::fetch("components.navbar.forum"), Helper::generateLink(Config::get("forum_url"), true), "_self", -94);
Theme::addtoNavbar("status", crisp\api\Translation::fetch("components.navbar.status"), Helper::generateLink(Config::get("status_url"), true), "_self", -92);
Theme::addtoNavbar("donate", crisp\api\Translation::fetch("components.navbar.donate"), Helper::generateLink("donate"), "_self", 0, "right");
Theme::addtoNavbar("about", crisp\api\Translation::fetch("components.navbar.about"), Helper::generateLink("about"), "_self", -97);
Theme::addtoNavbar("downloads", crisp\api\Translation::fetch("components.navbar.download"), Helper::generateLink("downloads"), "_self", -96);
Theme::addtoNavbar("new_service", crisp\api\Translation::fetch("components.navbar.request_service"), Helper::generateLink("new_service"), "_self", 100);
if (explode("/", $_GET["route"])[1] !== "api") {
    if (Config::get("maintenance_enabled") || isset($_GET["simulate_maintenance"])) {
        http_response_code(503);
        echo $TwigTheme->render("errors/maintenance.twig");
        exit;
    }
    if (Config::get("highload_enabled") || isset($_GET["simulate_highload"])) {
        http_response_code(503);
        echo $TwigTheme->render("errors/highload.twig");
        exit;
    }
}