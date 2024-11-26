<?php

namespace Vanderbilt\CareerDevLibrary;

require_once(__DIR__ . '/ClassLoader.php');

class MeSHTerms {
    const CURRENT_MESH_TERMS = "current_mesh_terms";
    const SUPPLEMENTAL_MESH_TERMS = "supplemental_mesh_terms";
    const MESH_SEPARATOR = "\n";

    # https://nlmpubs.nlm.nih.gov/projects/mesh/MESH_FILES/asciimesh/
    public static function getTerms($pid): array {
        $meshTerms = Application::getSystemSetting(self::CURRENT_MESH_TERMS);
        if (is_array($meshTerms)) {
            $year = $meshTerms["year"] ?? "";
        } else {
            $year = "";
            $meshTerms = [];
        }

        $separator = self::MESH_SEPARATOR;
        $currYear = date("Y");
        if ($year != $currYear) {
            $url = "https://nlmpubs.nlm.nih.gov/projects/mesh/MESH_FILES/asciimesh/d$currYear.bin";
            $terms = self::downloadAndParseMeSHURL($url, $pid);
            if (is_array($terms)) {
                $meshTerms = ["year" => $currYear, "terms" => implode($separator, $terms)];
                Application::saveSystemSetting(self::CURRENT_MESH_TERMS, $meshTerms);
            } else {
                if (($meshTerms["terms"] ?? "") === "") {
                    return [];
                }
                $terms = explode($separator, $meshTerms["terms"]);
            }
        } else {
            # current year's data
            if (($meshTerms["terms"] ?? "") === "") {
                return [];
            }
            $terms = explode($separator, $meshTerms["terms"]);
        }
        unset($meshTerms);

        $supplementalTerms = Application::getSystemSetting(self::SUPPLEMENTAL_MESH_TERMS) ?: [];
        $supTerms = [];
        if (isset($supplementalTerms['terms']) && ($supplementalTerms['terms'] !== "") && !is_array($supplementalTerms['terms'])) {
            $supTerms = explode($separator, $supplementalTerms['terms']);
        }
        $thresholdTs = strtotime("-1 week");
        if (empty($supplementalTerms) || ($supplementalTerms['ts'] < $thresholdTs)) {
            $url = "https://nlmpubs.nlm.nih.gov/projects/mesh/MESH_FILES/asciimesh/c$currYear.bin";
            $supTerms = self::downloadAndParseMeSHURL($url, $pid);
            $supplementalTerms = ["terms" => implode($separator, $supTerms), "ts" => time()];
            Application::saveSystemSetting(self::SUPPLEMENTAL_MESH_TERMS, $supplementalTerms);
        }
        return array_merge($terms, $supTerms);
    }

    private static function downloadAndParseMeSHURL(string $url, $pid) {
        list($resp, $data) = URLManagement::downloadURL($url, $pid);
        if ($resp == 200) {
            $lines = preg_split("/[\n\r]+/", $data);
            $terms = [];
            foreach ($lines as $line) {
                if (preg_match("/^MH = (.+)$/", $line, $matches)) {
                    $term = $matches[1];
                    if ($term) {
                        $terms[] = $term;
                    }
                }
            }
            return $terms;
        } else {
            Application::log("Warning! Code: $resp when downloading $url", $pid);
            return FALSE;
        }
    }

    public static function getOptions(string $defaultValue, $pid): array {
        $terms = self::getTerms($pid);
        $selected = ($defaultValue === "") ? "selected" : "";
        $options = ["<option value=\"\" $selected></option>"];
        foreach ($terms as $term) {
            $encoded = htmlentities($term);
            $selected = ($defaultValue === $term) ? "selected" : "";
            $options[] = "<option value=\"$term\" $selected>$encoded</option>";
        }
        return $options;
    }

    public static function makeHTMLTable(int $numTerms, $pid, $searchAction = ""): string {
        $html = "<table class='centered max-width' id='mesh_table'><tbody>";
        for ($i = 1; $i <= $numTerms; $i++) {
            $optional = ($i > 1) ? "Optional " : "";
            $defaultValue = Sanitizer::sanitize($_GET['mesh_term_'.$i] ?? "");
            $meshOptions = self::getOptions($defaultValue, $pid);
            $html .= "<tr><td class='alignright small_padding'><div class='meshOverlay'></div><label for='mesh_term_$i' title='MeSH Terms are updated weekly with supplemental material.' style='border-bottom: 1px dotted #888;'>$optional"."MeSH Term #$i</label>:</td><td class='left-align'><select id='mesh_term_$i' name='mesh_term_$i' class='meshcombobox'>".implode("", $meshOptions)."</select></td></tr>";
        }
        if ($searchAction !== "") {
            $html .= "<tr><td colspan='2' class='centered'><div class='meshOverlay'></div><button onclick='$searchAction'>Search MeSH Terms!</button></td></tr>";
        }
        $html .= "</tbody></table>";
        $autocompleteJSUrl = Application::link("js/mesh_autocomplete.js", $pid);
        $html .= "<script src='$autocompleteJSUrl'></script>";
        return $html;
    }
}