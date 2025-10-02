<?php

namespace Vanderbilt\CareerDevLibrary;

require_once(__DIR__ . '/ClassLoader.php');

abstract class Chart
{
	abstract public function getHTML($width, $height);
	abstract public function getJSLocations();
	abstract public function getCSSLocations();

	public static function ensureHex($color) {
		$possibleLengths = [6, 8];
		foreach ($possibleLengths as $length) {
			if (preg_match("/^[0-9A-Fa-f]{".$length."}$/", $color)) {
				return "#".$color;
			} elseif (preg_match("/^#[0-9A-Fa-f]{".$length."}$/", $color)) {
				return $color;
			}
		}
		return "";
	}

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
