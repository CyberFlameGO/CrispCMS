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

use crisp\core\PluginAPI;

$Interface = "default";

if(!IS_NATIVE_API){
    PluginAPI::response(crisp\core\Bitmask::GENERIC_ERROR, "Cannot access non-native API endpoint", []);
    exit;
}

if (is_array($GLOBALS["route"]->GET)) {
    $Interface = array_key_first($GLOBALS["route"]->GET);

    $this->Query = $GLOBALS["route"]->GET[$Interface];
    if (strpos($GLOBALS["route"]->GET[$Interface], ".json")) {
        $this->Query = substr($this->Query, 0, -5);
    }
}

switch ($Interface) {
    case "v1":
        require_once __DIR__ . '/case/v1.php';
        break;
    default:
        PluginAPI::response(crisp\core\Bitmask::VERSION_NOT_FOUND, "Invalid Version", []);
}