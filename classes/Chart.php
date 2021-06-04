<?php

namespace Vanderbilt\CareerDevLibrary;

require_once(__DIR__ . '/ClassLoader.php');

abstract class Chart {
    abstract public function getHTML($width, $height);
    abstract public function getJSLocations();
    abstract public function getCSSLocations();

    public function getImportHTML() {
        $urlsJS = $this->getJSLocations();
        $urlsCSS = $this->getCSSLocations();

        $html = "";
        foreach ($urlsJS as $url) {
            $html .= "<script src='$url'></script>\n";
        }
        foreach ($urlsCSS as $url) {
            $html .= "<link href='$url' rel='stylesheet' />\n";
        }
        return $html;
    }
}