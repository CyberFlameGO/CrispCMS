<?php

/*
 * Copyright (C) 2021 Justin René Back <justin@tosdr.org>
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place - Suite 330, Boston, MA  02111-1307, USA.
 */

namespace crisp\api;

use crisp\core\Postgres;
use crisp\core\Redis;
use Exception;
use PDO;
use function curl_exec;
use function curl_init;
use function curl_setopt_array;

/**
 * Some useful phoenix functions
 */
class Phoenix {

    private static ?\Redis $Redis_Database_Connection = null;
    private static ?PDO $Postgres_Database_Connection = null;

    private static function initPGDB() {
        $PostgresDB = new Postgres();
        self::$Postgres_Database_Connection = $PostgresDB->getDBConnector();
    }

    private static function initDB() {
        $RedisDB = new Redis();
        self::$Redis_Database_Connection = $RedisDB->getDBConnector();
    }

    /**
     * Generates tosdr.org api data from a service id
     * @param string $ID The service ID from Phoenix to generate the API Files from
     * @return array The API data
     */
    public static function generateApiFiles(string $ID, int $Version = 1) {
        if (self::$Redis_Database_Connection === NULL) {
            self::initDB();
        }

        if (self::$Redis_Database_Connection->keys("pg_generateapifiles_" . $ID . "_$Version")) {
            return unserialize(self::$Redis_Database_Connection->get("pg_generateapifiles_" . $ID . "_$Version"));
        }

        if (self::$Postgres_Database_Connection === NULL) {
            self::initPGDB();
        }
        $SkeletonData = null;

        switch ($Version) {
            case 1:
            case 2:
                $ServiceLinks = array();
                $ServicePoints = array();
                $ServicePointsData = array();

                $points = self::getPointsByServicePG($ID);
                $service = self::getServicePG($ID);
                $documents = self::getDocumentsByServicePG($ID);
                foreach ($documents as $Links) {
                    $ServiceLinks[$Links["name"]] = array(
                        "name" => $Links["name"],
                        "url" => $Links["url"]
                    );
                }
                foreach ($points as $Point) {
                    if ($Point["status"] == "approved") {
                        array_push($ServicePoints, $Point["id"]);
                    }
                }
                foreach ($points as $Point) {
                    $Document = array_column($documents, null, 'id')[$Point["document_id"]];
                    $Case = self::getCasePG($Point["case_id"]);
                    if ($Point["status"] == "approved") {
                        $ServicePointsData[$Point["id"]] = array(
                            "discussion" => "https://edit.tosdr.org/points/" . $Point["id"],
                            "id" => $Point["id"],
                            "needsModeration" => ($Point["status"] != "approved"),
                            "quoteDoc" => $Document["name"],
                            "quoteText" => $Point["quoteText"],
                            "services" => array($ID),
                            "set" => "set+service+and+topic",
                            "slug" => $Point["slug"],
                            "title" => $Point["title"],
                            "topics" => array(),
                            "tosdr" => array(
                                "binding" => true,
                                "case" => $Case["title"],
                                "point" => $Case["classification"],
                                "score" => $Case["score"],
                                "tldr" => $Point["analysis"]
                            ),
                        );
                    }
                }

                $SkeletonData = array(
                    "id" => $service["_source"]["id"],
                    "name" => $service["_source"]["name"],
                    "slug" => $service["_source"]["slug"],
                    "image" => Config::get("s3_logos") . "/" . ($service["_source"]["image"]),
                    "class" => ($service["_source"]["rating"] == "N/A" ? false : ($service["_source"]["is_comprehensively_reviewed"] ? $service["_source"]["rating"] : false)),
                    "links" => $ServiceLinks,
                    "points" => $ServicePoints,
                    "pointsData" => $ServicePointsData,
                    "urls" => explode(",", $service["_source"]["url"])
                );
                break;
            case 3:
                $ServiceLinks = array();
                $ServicePoints = array();
                $ServicePointsData = array();

                $points = self::getPointsByServicePG($ID);
                $service = self::getServicePG($ID);
                $documents = self::getDocumentsByServicePG($ID);
                foreach ($points as $Point) {
                    $Document = array_column($documents, null, 'id')[$Point["document_id"]];
                    $Case = self::getCasePG($Point["case_id"]);
                    $ServicePointsData[] = array(
                        "discussion" => "https://edit.tosdr.org/points/" . $Point["id"],
                        "id" => $Point["id"],
                        "needsModeration" => ($Point["status"] != "approved"),
                        "document" => $Document,
                        "quote" => $Point["quoteText"],
                        "services" => array($ID),
                        "set" => "set+service+and+topic",
                        "slug" => $Point["slug"],
                        "title" => $Point["title"],
                        "topics" => array(),
                        "case" => $Case
                    );
                }

                $SkeletonData = $service["_source"];

                $SkeletonData["image"] = Config::get("s3_logos") . "/" . $service["_source"]["image"];
                $SkeletonData["documents"] = $documents;
                $SkeletonData["points"] = $ServicePointsData;
                $SkeletonData["urls"] = explode(",", $service["_source"]["url"]);
                break;
        }

        self::$Redis_Database_Connection->set("pg_generateapifiles_" . $ID . "_$Version", serialize($SkeletonData), 3600);

        return $SkeletonData;
    }

    /**
     * Retrieve points by a service from postgres
     * @see https://github.com/tosdr/edit.tosdr.org/blob/8b900bf8879b8ed3a4a2a6bbabbeafa7d2ab540c/db/schema.rb#L89-L111 Database Schema
     * @param string $ID The ID of the Service
     * @return array
     */
    public static function getPointsByServicePG($ID) {
        if (self::$Redis_Database_Connection === NULL) {
            self::initDB();
        }

        if (self::$Redis_Database_Connection->keys("pg_pointsbyservice_$ID")) {
            return unserialize(self::$Redis_Database_Connection->get("pg_pointsbyservice_$ID"));
        }

        if (self::$Postgres_Database_Connection === NULL) {
            self::initPGDB();
        }



        $statement = self::$Postgres_Database_Connection->prepare("SELECT * FROM points WHERE service_id = :ID");

        $statement->execute(array(":ID" => $ID));

        $Result = $statement->fetchAll(PDO::FETCH_ASSOC);



        self::$Redis_Database_Connection->set("pg_pointsbyservice_$ID", serialize($Result), 900);

        return $Result;
    }

    /**
     * Get all documents by a service from postgres
     * @see https://github.com/tosdr/edit.tosdr.org/blob/8b900bf8879b8ed3a4a2a6bbabbeafa7d2ab540c/db/schema.rb#L64-L77 Database Schema
     * @param string $ID The Service ID
     * @return array
     */
    public static function getDocumentsByServicePG(string $ID) {
        if (self::$Redis_Database_Connection === NULL) {
            self::initDB();
        }

        if (self::$Redis_Database_Connection->keys("pg_getdocumentbyservice_$ID")) {
            return unserialize(self::$Redis_Database_Connection->get("pg_getdocumentbyservice_$ID"));
        }

        if (self::$Postgres_Database_Connection === NULL) {
            self::initPGDB();
        }

        $statement = self::$Postgres_Database_Connection->prepare("SELECT * FROM documents WHERE service_id = :ID");

        $statement->execute(array(":ID" => $ID));

        $Result = $statement->fetchAll(PDO::FETCH_ASSOC);

        self::$Redis_Database_Connection->set("pg_getdocumentbyservice_$ID", serialize($Result), 900);

        return $Result;
    }

    /**
     * List all points from postgres
     * @see https://github.com/tosdr/edit.tosdr.org/blob/8b900bf8879b8ed3a4a2a6bbabbeafa7d2ab540c/db/schema.rb#L89-L111 Database Schema
     * @return array
     */
    public static function getPointsPG() {
        if (self::$Postgres_Database_Connection === NULL) {
            self::initDB();
        }

        if (self::$Redis_Database_Connection->keys("pg_points")) {
            return unserialize(self::$Redis_Database_Connection->get("pg_points"));
        }

        if (self::$Postgres_Database_Connection === NULL) {
            self::initPGDB();
        }

        $Result = self::$Postgres_Database_Connection->query("SELECT * FROM points")->fetchAll(PDO::FETCH_ASSOC);

        self::$Redis_Database_Connection->set("pg_points", serialize($Result), 900);

        return $Result;
    }

    /**
     * Gets details about a point from postgres
     * @see https://github.com/tosdr/edit.tosdr.org/blob/8b900bf8879b8ed3a4a2a6bbabbeafa7d2ab540c/db/schema.rb#L89-L111 Database Schema
     * @param string $ID The ID of a point
     * @return array
     */
    public static function getPointPG(string $ID) {
        if (self::$Postgres_Database_Connection === NULL) {
            self::initPGDB();
        }

        $statement = self::$Postgres_Database_Connection->prepare("SELECT * FROM points WHERE id = :ID");

        $statement->execute(array(":ID" => $ID));

        $Result = $statement->fetch(PDO::FETCH_ASSOC);

        return $Result;
    }

    /**
     * Get details of a point from phoenix
     * @param string $ID The ID of the point
     * @param bool $Force Force update from phoenix
     * @return object
     * @deprecated Use Phoenix::getPointPG
     * @throws Exception
     */
    public static function getPoint(string $ID, bool $Force = false) {
        if (self::$Redis_Database_Connection === null) {
            self::initDB();
        }

        if (self::$Redis_Database_Connection->exists(Config::get("phoenix_api_endpoint") . "/points/id/$ID") && !$Force) {
            return json_decode(self::$Redis_Database_Connection->get(Config::get("phoenix_api_endpoint") . "/points/id/$ID"));
        }

        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_URL => Config::get("phoenix_url") . Config::get("phoenix_api_endpoint") . "/points/$ID",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => "",
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => "GET",
            CURLOPT_USERAGENT => "CrispCMS ToS;DR",
        ));
        $raw = curl_exec($curl);
        $response = json_decode($raw);

        if ($response === null) {
            throw new Exception("Failed to crawl! " . $raw);
        }
        if ($response->error) {
            throw new Exception($response->error);
        }


        if (self::$Redis_Database_Connection->set(Config::get("phoenix_api_endpoint") . "/points/id/$ID", json_encode($response), 2592000)) {
            return $response;
        }
        throw new Exception("Failed to contact REDIS");
    }

    /**
     * Gets details about a case from postgres
     * @see https://github.com/tosdr/edit.tosdr.org/blob/8b900bf8879b8ed3a4a2a6bbabbeafa7d2ab540c/db/schema.rb#L42-L52 Database Schema
     * @param string $ID The id of a case
     * @return array
     */
    public static function getCasePG(string $ID) {
        if (self::$Redis_Database_Connection === NULL) {
            self::initDB();
        }

        if (self::$Redis_Database_Connection->keys("pg_case_$ID")) {
            return unserialize(self::$Redis_Database_Connection->get("pg_case_$ID"));
        }


        if (self::$Postgres_Database_Connection === NULL) {
            self::initPGDB();
        }

        $statement = self::$Postgres_Database_Connection->prepare("SELECT * FROM cases WHERE id = :ID");

        $statement->execute(array(":ID" => $ID));

        $Result = $statement->fetch(PDO::FETCH_ASSOC);

        self::$Redis_Database_Connection->set("pg_case_$ID", serialize($Result), 900);

        return $Result;
    }

    /**
     * Get details of a case
     * @param string $ID The ID of a case
     * @param bool $Force Force update from Phoenix
     * @return object
     * @deprecated Use Phoenix::getCasePG
     * @throws Exception
     */
    public static function getCase(string $ID, bool $Force = false) {
        if (self::$Redis_Database_Connection === null) {
            self::initDB();
        }

        if (self::$Redis_Database_Connection->exists(Config::get("phoenix_api_endpoint") . "/cases/id/$ID") && !$Force) {
            return json_decode(self::$Redis_Database_Connection->get(Config::get("phoenix_api_endpoint") . "/cases/id/$ID"));
        }

        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_URL => Config::get("phoenix_url") . Config::get("phoenix_api_endpoint") . "/cases/$ID",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => "",
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => "GET",
            CURLOPT_USERAGENT => "CrispCMS ToS;DR",
        ));
        $raw = curl_exec($curl);
        $response = json_decode($raw);

        if ($response === null) {
            throw new Exception("Failed to crawl! " . $raw);
        }
        if ($response->error) {
            throw new Exception($response->error);
        }


        if (self::$Redis_Database_Connection->set(Config::get("phoenix_api_endpoint") . "/cases/id/$ID", json_encode($response), 2592000)) {
            return $response;
        }
        throw new Exception("Failed to contact REDIS");
    }

    /**
     * Gets details about a topic from postgres
     * @see https://github.com/tosdr/edit.tosdr.org/blob/8b900bf8879b8ed3a4a2a6bbabbeafa7d2ab540c/db/schema.rb#L170-L177 Database Schema
     * @param string $ID The topic id
     * @return array
     */
    public static function getTopicPG(string $ID) {
        if (self::$Postgres_Database_Connection === NULL) {
            self::initDB();
        }

        if (self::$Redis_Database_Connection->keys("pg_topic_$ID")) {
            return unserialize(self::$Redis_Database_Connection->get("pg_topic_$ID"));
        }

        if (self::$Postgres_Database_Connection === NULL) {
            self::initPGDB();
        }

        $statement = self::$Postgres_Database_Connection->prepare("SELECT * FROM topics WHERE id = :ID");

        $statement->execute(array(":ID" => $ID));

        $Result = $statement->fetch(PDO::FETCH_ASSOC);

        self::$Redis_Database_Connection->set("pg_topic_$ID", serialize($Result), 900);

        return $Result;
    }

    /**
     * Get details of a topic
     * @param string $ID The topic id
     * @param bool $Force Force update from phoenix
     * @return object
     * @deprecated Use Phoenix::getTopicPG
     * @throws Exception
     */
    public static function getTopic(string $ID, bool $Force = false) {
        if (self::$Redis_Database_Connection === null) {
            self::initDB();
        }

        if (self::$Redis_Database_Connection->exists(Config::get("phoenix_api_endpoint") . "/topics/id/$ID") && !$Force) {
            return json_decode(self::$Redis_Database_Connection->get(Config::get("phoenix_api_endpoint") . "/topics/id/$ID"));
        }

        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_URL => Config::get("phoenix_url") . Config::get("phoenix_api_endpoint") . "/topics/$ID",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => "",
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => "GET",
            CURLOPT_USERAGENT => "CrispCMS ToS;DR",
        ));
        $raw = curl_exec($curl);
        $response = json_decode($raw);

        if ($response === null) {
            throw new Exception("Failed to crawl! " . $raw);
        }
        if ($response->error) {
            throw new Exception($response->error);
        }


        if (self::$Redis_Database_Connection->set(Config::get("phoenix_api_endpoint") . "/topics/id/$ID", json_encode($response), 2592000)) {
            return $response;
        }
        throw new Exception("Failed to contact REDIS");
    }

    /**
     * Get details of a service by name
     * @param string $Name The name of the service
     * @param bool $Force Force update from phoenix
     * @return object
     * @deprecated Use Phoenix::getServiceByNamePG
     * @throws Exception
     */
    public static function getServiceByName(string $Name, bool$Force = false) {
        $Name = strtolower($Name);
        if (self::$Redis_Database_Connection === null) {
            self::initDB();
        }

        if (self::$Redis_Database_Connection->exists(Config::get("phoenix_api_endpoint") . "/services/name/$Name") && !$Force) {


            $response = json_decode(self::$Redis_Database_Connection->get(Config::get("phoenix_api_endpoint") . "/services/name/$Name"));

            $response->nice_service = Helper::filterAlphaNum($response->name);
            $response->has_image = (file_exists(__DIR__ . "/../../../../" . Config::get("theme_dir") . "/" . Config::get("theme") . "/img/logo/" . $response->nice_service . ".svg") ? true : file_exists(__DIR__ . "/../../../../" . Config::get("theme_dir") . "/" . Config::get("theme") . "/img/logo/" . $response->nice_service . ".png") );
            $response->image = "/img/logo/" . $response->nice_service . (file_exists(__DIR__ . "/../../../../" . Config::get("theme_dir") . "/" . Config::get("theme") . "/img/logo/" . $response->nice_service . ".svg") ? ".svg" : ".png");

            return $response;
        }
        throw new Exception("Service is not initialized!");
    }

    /**
     * Search for a service via postgres
     * @see https://github.com/tosdr/edit.tosdr.org/blob/8b900bf8879b8ed3a4a2a6bbabbeafa7d2ab540c/db/schema.rb#L134-L148 Database Schema
     * @param string $Name The name of a service
     * @return array
     */
    public static function searchServiceByNamePG(string $Name) {
        if (self::$Postgres_Database_Connection === NULL) {
            self::initDB();
        }

        if (self::$Redis_Database_Connection->keys("pg_searchservicebyname_$Name")) {
            $response = unserialize(self::$Redis_Database_Connection->get("pg_searchservicebyname_$Name"));

            foreach ($response as $Key => $Service) {
                $response[$Key]["nice_service"] = Helper::filterAlphaNum($response[$Key]["name"]);
                $response["image"] = $response["id"] . ".png";
            }
            return $response;
        }

        if (self::$Postgres_Database_Connection === NULL) {
            self::initPGDB();
        }

        $statement = self::$Postgres_Database_Connection->prepare("SELECT * FROM services WHERE LOWER(name) LIKE :ID");

        $statement->execute(array(":ID" => "%$Name%"));

        $response = $statement->fetchAll(PDO::FETCH_ASSOC);

        foreach ($response as $Key => $Service) {
            $response[$Key]["nice_service"] = Helper::filterAlphaNum($response[$Key]["name"]);
            $response[$Key]["has_image"] = (file_exists(__DIR__ . "/../../../../" . Config::get("theme_dir") . "/" . Config::get("theme") . "/img/logo/" . $response[$Key]["nice_service"] . ".svg") ? true : file_exists(__DIR__ . "/../../../../" . Config::get("theme_dir") . "/" . Config::get("theme") . "/img/logo/" . $response[$Key]["nice_service"] . ".png") );
            $response[$Key]["image"] = "/img/logo/" . $response[$Key]["nice_service"] . (file_exists(__DIR__ . "/../../../../" . Config::get("theme_dir") . "/" . Config::get("theme") . "/img/logo/" . $response[$Key]["nice_service"] . ".svg") ? ".svg" : ".png");
        }

        self::$Redis_Database_Connection->set("pg_searchservicebyname_$Name", serialize($response), 900);

        return $response;
    }

    /**
     * Get details of a service from postgres via a slug
     * @see https://github.com/tosdr/edit.tosdr.org/blob/8b900bf8879b8ed3a4a2a6bbabbeafa7d2ab540c/db/schema.rb#L134-L148 Database Schema
     * @param string $Name The slug of a service
     * @return array
     */
    public static function getServiceBySlugPG(string $Name) {
        if (self::$Postgres_Database_Connection === NULL) {
            self::initDB();
        }

        if (self::$Redis_Database_Connection->keys("pg_getservicebyslug_$Name")) {
            return unserialize(self::$Redis_Database_Connection->get("pg_getservicebyslug_$Name"));
        }

        if (self::$Postgres_Database_Connection === NULL) {
            self::initPGDB();
        }

        $statement = self::$Postgres_Database_Connection->prepare("SELECT * FROM services WHERE LOWER(slug) = LOWER(:ID)");

        $statement->execute(array(":ID" => $Name));


        if ($statement->rowCount() == 0) {
            return false;
        }

        $Result = $statement->fetch(PDO::FETCH_ASSOC);

        self::$Redis_Database_Connection->set("pg_getservicebyslug_$Name", serialize($Result), 900);

        return $Result;
    }

    /**
     * Get details of a service via postgres by name
     * @see https://github.com/tosdr/edit.tosdr.org/blob/8b900bf8879b8ed3a4a2a6bbabbeafa7d2ab540c/db/schema.rb#L134-L148 Database Schema
     * @param string $Name the exact name of the service
     * @return array
     */
    public static function getServiceByNamePG(string $Name) {
        if (self::$Postgres_Database_Connection === NULL) {
            self::initPGDB();
        }

        $statement = self::$Postgres_Database_Connection->prepare("SELECT * FROM services WHERE LOWER(name) = LOWER(:ID)");

        $statement->execute(array(":ID" => $Name));

        if ($statement->rowCount() == 0) {
            return false;
        }

        $response = $statement->fetch(PDO::FETCH_ASSOC);

        $response["nice_service"] = Helper::filterAlphaNum($response["name"]);
        $response["image"] = $response["id"] . ".png";
        return $response;
    }

    /**
     * Check if a service exists from postgres via slug
     * @param string $Name The slug of the service
     * @return bool
     */
    public static function serviceExistsBySlugPG(string $Name) {

        if (self::$Postgres_Database_Connection === NULL) {
            self::initPGDB();
        }

        $statement = self::$Postgres_Database_Connection->prepare("SELECT * FROM services WHERE LOWER(slug) = LOWER(:ID)");

        $statement->execute(array(":ID" => $Name));

        return $statement->rowCount() > 0;
    }

    /**
     * Create a service on phoenix
     * @return bool
     */
    public static function createService(string $Name, string $Url, string $Wikipedia, string $User) {
        if (self::$Postgres_Database_Connection === NULL) {
            self::initPGDB();
        }

        if (self::serviceExistsByNamePG($Name)) {
            return false;
        }

        $statement = self::$Postgres_Database_Connection->prepare("INSERT INTO services (name, url, wikipedia, created_at, updated_at) VALUES (:name, :url, :wikipedia, NOW(), NOW())");

        $statement->execute([":name" => $Name, ":url" => $Url, ":wikipedia" => $Wikipedia]);

        $service_id = self::$Postgres_Database_Connection->lastInsertId();

        $Result = ($statement->rowCount() > 0 ? true : false);

        if ($Result) {
            self::createVersion("Service", $service_id, "create", "Created service", $User, null);
            return $service_id;
        }

        return false;
    }

    /**
     * Create a version on phoenix
     * @return bool
     */
    public static function createDocument(string $Name, string $Url, string $Xpath, string $Service, string $User) {
        if (self::$Postgres_Database_Connection === NULL) {
            self::initPGDB();
        }

        if (!self::serviceExistsPG($Service)) {
            return false;
        }

        $statement = self::$Postgres_Database_Connection->prepare("INSERT INTO documents (name, url, xpath, created_at, updated_at, service_id) VALUES (:name, :url, :xpath, NOW(), NOW(), :service_id)");

        $statement->execute([":name" => $Name, ":url" => $Url, ":xpath" => $Xpath, ":service_id" => $Service]);

        $Result = ($statement->rowCount() > 0 ? true : false);

        $document_id = self::$Postgres_Database_Connection->lastInsertId();

        if ($Result) {
            self::createVersion("Document", $document_id, "create", "Created document", $User, null);
            return $document_id;
        }

        return false;
    }

    /**
     * Create a document on phoenix
     * @return bool
     */
    public static function createVersion(string $itemType, string $itemId, string $event, string $objectChanges = null, string $whodunnit, string $object = null) {
        if (self::$Postgres_Database_Connection === NULL) {
            self::initPGDB();
        }

        $statement = self::$Postgres_Database_Connection->prepare("INSERT INTO versions (item_type, item_id, event, created_at, object_changes, whodunnit, object) VALUES (:item_type, :item_id, :event, NOW(), :object_changes, :whodunnit, :object)");

        $statement->execute([
            ":item_type" => $itemType,
            ":item_id" => $itemId,
            ":event" => $event,
            ":object_changes" => $objectChanges,
            ":whodunnit" => $whodunnit,
            ":object" => $object
        ]);

        $Result = ($statement->rowCount() > 0 ? true : false);

        return $Result;
    }

    /**
     * Check if a service exists from postgres via name
     * @param string $Name The name of the service
     * @return bool
     */
    public static function serviceExistsByNamePG(string $Name) {

        if (self::$Postgres_Database_Connection === NULL) {
            self::initPGDB();
        }

        $statement = self::$Postgres_Database_Connection->prepare("SELECT * FROM services WHERE LOWER(name) = LOWER(:ID)");

        $statement->execute(array(":ID" => $Name));

        return $statement->rowCount() > 0;
    }

    /**
     * Check if a service exists by name
     * @param string $Name The name of the service
     * @return bool
     * @deprecated Use Phoenix::serviceExistsByNamePG
     */
    public static function serviceExistsByName(string $Name) {
        $Name = strtolower($Name);

        if (self::$Redis_Database_Connection === null) {
            self::initDB();
        }

        return self::$Redis_Database_Connection->exists(Config::get("phoenix_api_endpoint") . "/services/name/$Name");
    }

    /**
     * Check if the point exists by name
     * @param string $ID The ID of the point
     * @deprecated Use Phoenix::pointExistsPG
     * @return bool
     */
    public static function pointExists(string $ID) {
        if (self::$Redis_Database_Connection === null) {
            self::initDB();
        }

        return self::$Redis_Database_Connection->exists(Config::get("phoenix_api_endpoint") . "/points/id/$ID");
    }

    /**
     * Check if a point exists from postgres via slug
     * @param string $ID The id of the point
     * @return bool
     */
    public static function pointExistsPG(string $ID) {
        if (self::$Postgres_Database_Connection === NULL) {
            self::initDB();
        }

        if (self::$Redis_Database_Connection->keys("pg_pointexists_$ID")) {
            return unserialize(self::$Redis_Database_Connection->get("pg_pointexists_$ID"));
        }

        if (self::$Postgres_Database_Connection === NULL) {
            self::initPGDB();
        }

        $statement = self::$Postgres_Database_Connection->prepare("SELECT * FROM points WHERE id = :ID");

        $statement->execute(array(":ID" => $ID));

        $Result = ($statement->rowCount() > 0 ? true : false);

        self::$Redis_Database_Connection->set("pg_pointexists_$ID", serialize($Result), 900);

        return $Result;
    }

    /**
     * Check if a service exists from postgres via the ID
     * @param string $ID The ID of the service
     * @return bool
     */
    public static function serviceExistsPG(string $ID) {
        if (self::$Postgres_Database_Connection === NULL) {
            self::initDB();
        }

        if (self::$Redis_Database_Connection->keys("pg_serviceexists_$ID")) {
            return unserialize(self::$Redis_Database_Connection->get("pg_serviceexists_$ID"));
        }

        if (self::$Postgres_Database_Connection === NULL) {
            self::initPGDB();
        }

        $statement = self::$Postgres_Database_Connection->prepare("SELECT * FROM services WHERE id = :ID");

        $statement->execute(array(":ID" => $ID));

        $Result = ($statement->rowCount() > 0 ? true : false);

        self::$Redis_Database_Connection->set("pg_serviceexists_$ID", serialize($Result), 900);

        return $Result;
    }

    /**
     * Check if a service exists by name
     * @param string $ID The ID of the service
     * @return bool
     * @deprecated Use Phoenix::serviceExistsPG
     */
    public static function serviceExists(string $ID) {
        if (self::$Redis_Database_Connection === null) {
            self::initDB();
        }

        return self::$Redis_Database_Connection->exists(Config::get("phoenix_api_endpoint") . "/services/id/$ID");
    }

    public static function getServicePG(string $ID) {
        if (self::$Postgres_Database_Connection === NULL) {
            self::initDB();
        }

        if (self::$Redis_Database_Connection->keys("pg_service_$ID")) {
            $response = unserialize(self::$Redis_Database_Connection->get("pg_service_$ID"));
            $response["image"] = $response["id"] . ".png";
            $dummy;

            $dummy["_source"] = $response;
            return $dummy;
        }

        if (self::$Postgres_Database_Connection === NULL) {
            self::initPGDB();
        }

        $statement = self::$Postgres_Database_Connection->prepare("SELECT * FROM services WHERE id = :ID");

        $statement->execute(array(":ID" => $ID));

        if ($statement->rowCount() == 0) {
            return false;
        }

        $response = $statement->fetch(PDO::FETCH_ASSOC);

        self::$Redis_Database_Connection->set("pg_service_$ID", serialize($response), 900);


        $response["nice_service"] = Helper::filterAlphaNum($response["name"]);
        $response["image"] = $response["id"] . ".png";
        $dummy;

        $dummy["_source"] = $response;
        return $dummy;
    }

    /**
     * Get details of a service by name
     * @param string $ID The ID of a service
     * @param bool $Force Force update from phoenix
     * @return object
     * @deprecated Use Phoenix::getServicePG
     * @throws Exception
     */
    public static function getService(string $ID, bool $Force = false) {
        if (self::$Redis_Database_Connection === null) {
            self::initDB();
        }

        if (self::$Redis_Database_Connection->exists(Config::get("phoenix_api_endpoint") . "/services/id/$ID") && !$Force) {

            $response = json_decode(self::$Redis_Database_Connection->get(Config::get("phoenix_api_endpoint") . "/services/id/$ID"));


            $response->nice_service = Helper::filterAlphaNum($response->name);
            $response->has_image = (file_exists(__DIR__ . "/../../../../" . Config::get("theme_dir") . "/" . Config::get("theme") . "/img/logo/" . $response->nice_service . ".svg") ? true : file_exists(__DIR__ . "/../../../../" . Config::get("theme_dir") . "/" . Config::get("theme") . "/img/logo/" . $response->nice_service . ".png") );
            $response->image = "/img/logo/" . $response->nice_service . (file_exists(__DIR__ . "/../../../../" . Config::get("theme_dir") . "/" . Config::get("theme") . "/img/logo/" . $response->nice_service . ".svg") ? ".svg" : ".png");
            return $response;
        }

        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_URL => Config::get("phoenix_url") . Config::get("phoenix_api_endpoint") . "/services/$ID",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => "",
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => "GET",
            CURLOPT_USERAGENT => "CrispCMS ToS;DR",
        ));
        $raw = curl_exec($curl);
        $response = json_decode($raw);

        if ($response === null) {
            throw new Exception("Failed to crawl! " . $raw);
        }

        if ($response->error) {
            throw new Exception($response->error);
        }


        if (self::$Redis_Database_Connection->set(Config::get("phoenix_api_endpoint") . "/services/id/$ID", json_encode($response), 43200) && self::$Redis_Database_Connection->set(Config::get("phoenix_api_endpoint") . "/services/name/" . strtolower($response->name), json_encode($response), 15778476)) {
            $response = json_decode(self::$Redis_Database_Connection->get(Config::get("phoenix_api_endpoint") . "/services/id/$ID"));


            $response->nice_service = Helper::filterAlphaNum($response->name);
            $response->has_image = (file_exists(__DIR__ . "/../../../../" . Config::get("theme_dir") . "/" . Config::get("theme") . "/img/logo/" . $response->nice_service . ".svg") ? true : file_exists(__DIR__ . "/../../../../" . Config::get("theme_dir") . "/" . Config::get("theme") . "/img/logo/" . $response->nice_service . ".png") );
            $response->image = "/img/logo/" . $response->nice_service . (file_exists(__DIR__ . "/../../../../" . Config::get("theme_dir") . "/" . Config::get("theme") . "/img/logo/" . $response->nice_service . ".svg") ? ".svg" : ".png");
            return $response;
        }
        throw new Exception("Failed to contact REDIS");
    }

    /**
     * List all topics from postgres
     * @see https://github.com/tosdr/edit.tosdr.org/blob/8b900bf8879b8ed3a4a2a6bbabbeafa7d2ab540c/db/schema.rb#L170-L177 Database Schema
     * @return array
     */
    public static function getTopicsPG() {
        if (self::$Postgres_Database_Connection === NULL) {
            self::initDB();
        }

        if (self::$Redis_Database_Connection->keys("pg_topics")) {
            return unserialize(self::$Redis_Database_Connection->get("pg_topics"));
        }

        if (self::$Postgres_Database_Connection === NULL) {
            self::initPGDB();
        }

        $Result = self::$Postgres_Database_Connection->query("SELECT * FROM topics")->fetchAll(PDO::FETCH_ASSOC);

        self::$Redis_Database_Connection->set("pg_topics", serialize($Result), 900);

        return $Result;
    }

    /**
     * Get a list of topics
     * @param bool $Force Force update from phoenix
     * @return object
     * @deprecated Use Phoenix::getServicesPG
     * @throws Exception
     */
    public static function getTopics(bool $Force = false) {
        if (self::$Redis_Database_Connection === null) {
            self::initDB();
        }

        if (self::$Redis_Database_Connection->exists(Config::get("phoenix_api_endpoint") . "/topics") && !$Force) {
            return json_decode(self::$Redis_Database_Connection->get(Config::get("phoenix_api_endpoint") . "/topics"));
        }

        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_URL => Config::get("phoenix_url") . Config::get("phoenix_api_endpoint") . "/topics",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => "",
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => "GET",
            CURLOPT_USERAGENT => "CrispCMS ToS;DR",
        ));
        $raw = curl_exec($curl);
        $response = json_decode($raw);

        if ($response === null) {
            throw new Exception("Failed to crawl! " . $raw);
        }
        if ($response->error) {
            throw new Exception($response->error);
        }


        if (self::$Redis_Database_Connection->set(Config::get("phoenix_api_endpoint") . "/topics", json_encode($response), 86400)) {
            return $response;
        }
        throw new Exception("Failed to contact REDIS");
    }

    /**
     * List all cases from postgres
     * @see https://github.com/tosdr/edit.tosdr.org/blob/8b900bf8879b8ed3a4a2a6bbabbeafa7d2ab540c/db/schema.rb#L42-L52 Database Schema
     * @return array
     */
    public static function getCasesPG(bool $FreshData = false) {
        if (self::$Redis_Database_Connection === NULL) {
            self::initDB();
        }

        if (self::$Redis_Database_Connection->keys("pg_cases") && !$FreshData) {
            return unserialize(self::$Redis_Database_Connection->get("pg_cases"));
        }

        if (self::$Postgres_Database_Connection === NULL) {
            self::initPGDB();
        }

        $Result = self::$Postgres_Database_Connection->query("SELECT * FROM cases ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);

        if (!$FreshData) {
            self::$Redis_Database_Connection->set("pg_cases", serialize($Result), 900);
        }

        return $Result;
    }

    /**
     * Get a list of cases
     * @param bool $Force Force update from phoenix
     * @return object
     * @deprecated Use Phoenix::getCasesPG
     * @throws Exception
     */
    public static function getCases(bool $Force = false) {
        if (self::$Redis_Database_Connection === null) {
            self::initDB();
        }

        if (self::$Redis_Database_Connection->exists(Config::get("phoenix_api_endpoint") . "/cases") && !$Force) {
            return json_decode(self::$Redis_Database_Connection->get(Config::get("phoenix_api_endpoint") . "/cases"));
        }

        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_URL => Config::get("phoenix_url") . Config::get("phoenix_api_endpoint") . "/cases",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => "",
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => "GET",
            CURLOPT_USERAGENT => "CrispCMS ToS;DR",
        ));
        $raw = curl_exec($curl);
        $response = json_decode($raw);

        if ($response === null) {
            throw new Exception("Failed to crawl! " . $raw);
        }

        if ($response->error) {
            throw new Exception($response->error);
        }


        if (self::$Redis_Database_Connection->set(Config::get("phoenix_api_endpoint") . "/cases", json_encode($response), 3600)) {
            return $response;
        }
        throw new Exception("Failed to contact REDIS");
    }

    /**
     * List all services from postgres
     * @see https://github.com/tosdr/edit.tosdr.org/blob/8b900bf8879b8ed3a4a2a6bbabbeafa7d2ab540c/db/schema.rb#L134-L148 Database Schema
     * @return array
     */
    public static function getServicesPG() {
        if (self::$Postgres_Database_Connection === NULL) {
            self::initDB();
        }

        if (self::$Redis_Database_Connection->keys("pg_services")) {
            return unserialize(self::$Redis_Database_Connection->get("pg_services"));
        }

        if (self::$Postgres_Database_Connection === NULL) {
            self::initPGDB();
        }

        $Result = self::$Postgres_Database_Connection->query("SELECT * FROM services WHERE status IS NULL or status = ''")->fetchAll(PDO::FETCH_ASSOC);

        self::$Redis_Database_Connection->set("pg_services", serialize($Result), 900);

        return $Result;
    }

    /**
     * Get a list of services
     * @param bool $Force Force update from phoenix
     * @return object
     * @deprecated Please use Phoenix::getServicesPG
     * @throws Exception
     */
    public static function getServices(bool $Force = false) {
        if (self::$Redis_Database_Connection === null) {
            self::initDB();
        }

        if (self::$Redis_Database_Connection->exists(Config::get("phoenix_api_endpoint") . "/services") && !$Force) {
            return json_decode(self::$Redis_Database_Connection->get(Config::get("phoenix_api_endpoint") . "/services"));
        }

        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_URL => Config::get("phoenix_url") . Config::get("phoenix_api_endpoint") . "/services",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => "",
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => "GET",
            CURLOPT_USERAGENT => "CrispCMS ToS;DR",
        ));
        $raw = curl_exec($curl);
        $response = json_decode($raw);

        if ($response === null) {
            throw new Exception("Failed to crawl! " . $raw);
        }

        if ($response->error) {
            throw new Exception($response->error);
        }


        if (self::$Redis_Database_Connection->set(Config::get("phoenix_api_endpoint") . "/services", json_encode($response), 3600)) {
            return $response;
        }
        throw new Exception("Failed to contact REDIS");
    }

}
