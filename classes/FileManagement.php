<?php

namespace Vanderbilt\CareerDevLibrary;

use Dompdf\Dompdf;
use Dompdf\Options;

require_once(__DIR__ . '/ClassLoader.php');
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once(__DIR__ . '/../vendor/autoload.php');
}

class FileManagement {
    public static function makePDFToTempFile($html) {
        $options = new Options();
        $options->set('tempDir', preg_replace("/\/$/", "", APP_PATH_TEMP));   // for images
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->render();
        $output = $dompdf->output();
        $count = 0;
        do {
            $count++;
            $filename = APP_PATH_TEMP.self::makeSafeFilename(REDCapManagement::makeHash(8)."_flight_tracker.pdf");
        } while (file_exists($filename) && ($count < 10000));
        if (!file_exists($filename) && $output) {
            file_put_contents($filename, $output);
            return $filename;
        } else {
            return "";
        }
    }

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

    public static function getProjectForEdoc($edocId) {
        $module = Application::getModule();
        $sql = "SELECT project_id FROM redcap_edocs_metadata WHERE doc_id=?";
        $q = $module->query($sql, [$edocId]);
        if ($row = $q->fetch_assoc()) {
            $pid = $row['project_id'];
            return $pid;
        }
        return "";
    }

    public static function getFileNameForEdoc($edocId) {
        $module = Application::getModule();
        $sql = "SELECT stored_name FROM redcap_edocs_metadata WHERE doc_id=?";
        $q = $module->query($sql, [$edocId]);
        if ($row = $q->fetch_assoc()) {
            $filename = EDOC_PATH.$row['stored_name'];
            if (file_exists($filename)) {
                return $filename;
            } else {
                throw new \Exception("Could not find edoc file: ".$row['stored_name']);
            }
        }
        return "";
    }

    public static function getBase64OfFile($filename, $mimeType) {
        if (file_exists($filename)) {
            $content = file_get_contents($filename);
            if ($content) {
                $base64 = base64_encode($content);
                $header = "data:$mimeType;charset=utf-8;base64, ";
                return $header . $base64;
            }
        }
        return "";
    }

    public static function getMimeSuffix($mimeType) {
        if ($mimeType == "image/bmp") {
            return "bmp";
        } else if ($mimeType == "image/png") {
            return "png";
        } else if (in_array($mimeType, ["image/jpeg", "image/jpg"])) {
            return "jpg";
        } else {
            return preg_replace("/^image\//", "", $mimeType);
        }
    }

    public static function getEdocBase64($id) {
        if (is_numeric($id)) {
            $module = Application::getModule();
            $sql = "SELECT stored_name, mime_type FROM redcap_edocs_metadata WHERE doc_id = ?";
            $q = $module->query($sql, [$id]);
            if ($row = $q->fetch_assoc()) {
                $filename = EDOC_PATH . $row['stored_name'];
                $mimeType = $row['mime_type'];
                if ($mimeType == "application/octet-stream") {
                    if (preg_match("/\.svg$/", $row['stored_name'])) {
                        $mimeType = "image/svg+xml";
                    } else {
                        $mimeType = mime_content_type($row['stored_name']) ?: $mimeType;
                    }
                }
                return self::getBase64OfFile($filename, $mimeType);
            }
        }
        throw new \Exception("Invalid Edoc ID!");
    }

    public static function getEdoc($id) {
        if (!is_numeric($id)) {
            return ["error" => "Invalid id"];
        }
        $module = Application::getModule();
        $sql = "SELECT stored_name, mime_type, doc_name FROM redcap_edocs_metadata WHERE doc_id = ?";
        $q = $module->query($sql, [$id]);
        if ($row = $q->fetch_assoc()) {
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