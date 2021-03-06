<?php

namespace Vanderbilt\CareerDevLibrary;

require_once(dirname(__FILE__)."/../Application.php");

class NavigationBar {
	# $ary is associative array of $menuLabel => $link
	public function addMenu($title, $ary) {
		$this->menu[$title] = $ary;
	}

	private static function ensureFA($faTag) {
		if (!preg_match("/^fa-/", $faTag)) {
			$faTag = "fa-".$faTag;
		}
		return $faTag;
	}
	public function addFALink($faTag, $title, $link) {
		$faTag = self::ensureFA($faTag);
		$this->addLink("<i class='fa $faTag'></i> ".$title, $link);
	}

	public function addFAMenu($faTag, $title, $ary) {
		$faTag = self::ensureFA($faTag);
		$this->addMenu("<i class='fa $faTag'></i> ".$title, $ary);
	}

	public function addLink($title, $link) {
		$this->menu[$title] = self::formatLink($link);
	}

	# adds the project id (pid) if not already present
	private function formatLink($link) {
		global $pid;
		if (!$pid) {
			$pid = $_GET['pid'];
		}
		if (preg_match("/^http/", $link)) {
			return $link;
		} else if (!preg_match("/pid=\d+/", $link)) {
			if (!preg_match("/\?/", $link)) {
				$link .= "?pid=".$pid;
			} else if (preg_match("/\?\w/", $link)) {
				$link .= "&pid=".$pid;
			} else {
				$link .= "pid=".$pid;
			}
		}
		if (method_exists("\Vanderbilt\CareerDevLibrary\Application", "isRecordPage") && Application::isRecordPage($link) && ($_GET['id'] || $_GET['record'])) {
		    if ($_GET['record']) {
		        $record = $_GET['record'];
            } else {
		        $record = $_GET['id'];
            }
		    $link .= "&record=$record";
        }

		return $link;
	}

	private static function isJavascript($link) {
		if (preg_match("/;$/", $link)) {
			return TRUE;
		}
		return FALSE;
	}

	public function getHTML() {
		$html = "";
		$html .= "<div class='w3-bar w3-border w3-light-grey'>\n";
		foreach ($this->menu as $title => $item) {
			if (is_array($item)) {
				$ary = $item;
				$html .= "<div class='w3-dropdown-hover w3-mobile'>\n";
				$html .= "<button class='w3-button'>$title</button>\n";
				$html .= "<div class='w3-dropdown-content w3-bar-block w3-dark-grey'>\n";
				foreach ($ary as $menuTitle => $menuLink) {
					if (self::isJavascript($menuLink)) {
						$html .= "<a href='javascript:;' onclick='$menuLink' class='w3-bar-item w3-button'>$menuTitle</a>\n";
					} else {
						$menuLink = self::formatLink($menuLink);
						$html .= "<a href='$menuLink' class='w3-bar-item w3-button'>$menuTitle</a>\n";
					}
				}
				$html .= "</div>\n";
				$html .= "</div>\n";
			} else {
				if (self::isJavascript($item)) {
					$html .= "<a href='javascript:;' onclick='$item' class='w3-bar-item w3-button'>$title</a>\n";
				} else {
					$link = self::formatLink($item);
					$html .= "<a href='$link' class='w3-bar-item w3-bar-link w3-button w3-mobile'>$title</a>\n";
				}
			}
		}
		$html .= "</div>\n";
		return $html;
	}

	private $menu = array();
}
