<?php

namespace Vanderbilt\CareerDevLibrary;

require_once(__DIR__ . '/ClassLoader.php');

class FileManagement {
    public static function makeSafeFilename($filename) {
        $filename = str_replace("..", "", $filename);
        $filename = str_replace("/", "", $filename);
        $filename = str_replace("\\", "", $filename);
        return $filename;
    }

    public static function getFileSuffix($file) {
        $nodes = preg_split("/\./", $file);
        return $nodes[count($nodes) - 1];
    }

    public static function cleanupDirectory($dir, $regex) {
        $files = self::regexInDirectory($regex, $dir);
        if (!preg_match("/\/$/", $dir)) {
            $dir .= "/";
        }
        if (!empty($files)) {
            Application::log("Removing files (".implode(", ", $files).") from $dir");
            foreach ($files as $file) {
                if (file_exists($dir.$file)) {
                    unlink($dir.$file);
                }
            }
        }
    }

    public static function regexInDirectory($regex, $dir) {
        $files = scandir($dir);
        $foundFiles = [];
        foreach ($files as $file) {
            if (preg_match($regex, $file)) {
                $foundFiles[] = $file;
            }
        }
        return $foundFiles;
    }

    public static function getTimestampOfFile($file) {
        $nodes = preg_split("/\//", $file);
        if (count($nodes) > 1) {
            $file = $nodes[count($nodes) - 1];
        }
        if (preg_match("/^\d\d\d\d\d\d\d\d\d\d\d\d\d\d_/", $file, $matches)) {
            return preg_replace("/_$/", "", $matches[0]);
        }
        return 0;
    }

    public static function copyTempFileToTimestamp($file, $timespanInSeconds) {
        if (strpos($file, APP_PATH_TEMP) === FALSE) {
            throw new \Exception("File $file must be in temporary directory");
        }
        if (file_exists($file)) {
            $dir = dirname($file);
            $basename = preg_replace("/^\d\d\d\d\d\d\d\d\d\d\d\d\d\d_/", "", basename($file));
            $filename = self::makeSafeFilename(date("YmdHis", time() + (int) $timespanInSeconds)."_".$basename);
            $newLocation = $dir."/".$filename;
            Application::log("Copying $file to $newLocation");
            flush();
            $fpIn = fopen($file, "r");
            $fpOut = fopen($newLocation, "w");
            while ($line = fgets($fpIn)) {
                fwrite($fpOut, $line);
                fflush($fpOut);
            }
            fclose($fpOut);
            fclose($fpIn);
            return $newLocation;
        } else {
            throw new \Exception("File $file does not exist");
        }
    }

    public static function getFileNameForEdoc($edocId) {
        $sql = "SELECT stored_name FROM redcap_edocs_metadata WHERE doc_id='".db_real_escape_string($edocId)."'";
        $q = db_query($sql);
        if ($row = db_fetch_assoc($q)) {
            $filename = EDOC_PATH.$row['stored_name'];
            if (file_exists($filename)) {
                return $filename;
            } else {
                throw new \Exception("Could not find edoc file: ".$row['stored_name']);
            }
        }
        return "";
    }

    public static function getEdoc($id) {
        if (!is_numeric($id)) {
            return ["error" => "Invalid id"];
        }
        $sql = "SELECT stored_name, mime_type, doc_name FROM redcap_edocs_metadata WHERE doc_id = '".db_real_escape_string($id)."'";
        $q = db_query($sql);
        if ($error = db_error()) {
            return ["error" => $error];
        } else {
            if ($row = db_fetch_assoc($q)) {
                $filename = EDOC_PATH.$row['stored_name'];
                header('Content-Type: '.$row['mime_type']);
                header('Content-Disposition: attachment; filename="'.$row['doc_name'].'"');
                readfile($filename);
                return ["status" => "Success"];
            } else {
                return ["error" => "Could not find entry"];
            }
        }
    }
}