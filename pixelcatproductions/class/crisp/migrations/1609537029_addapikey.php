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


namespace crisp\migrations;

class addapikey extends \crisp\core\Migrations {

    public function run() {
        try {
            $this->begin();
            $this->createTable("APIKeys",
                    array("key", $this::DB_VARCHAR),
                    array("UserID", $this::DB_INTEGER),
                    array("last_changed", $this::DB_TIMESTAMP, "DEFAULT NULL"),
                    array("revoked", $this::DB_INTEGER, "NOT NULL DEFAULT 0"),
                    array("created_at", $this::DB_TIMESTAMP, "NOT NULL DEFAULT CURRENT_TIMESTAMP")
            );
            $this->addIndex("APIKeys", "key", $this::DB_UNIQUEKEY, "apikey");
            return $this->end();
        } catch (\Exception $ex) {
            echo $ex->getMessage() . PHP_EOL;
            $this->rollback();
            return false;
        }
    }

}
