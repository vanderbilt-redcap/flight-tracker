<?php


namespace Vanderbilt\CareerDevLibrary;

require_once(__DIR__ . '/ClassLoader.php');

# See https://info.orcid.org/documentation/features/public-api/
class ORCID {
    public const NO_MATCHES = "NO_MATCHES";
    public const MORE_THAN_ONE = "MORE_THAN_ONE";
    public const ORCID_DELIM = "|";

    private static $tokens = 40;
    private static $lastRefillTime = 0;
    private static $tokensPerSecond = 24;
    private static $maxTokens = 40;

    const PROFILE_PREFIX = "orcid_";
    const ORCID_ENDPOINTS = [
        'educations',
        'person',
        'employments',
        'fundings',
        'qualifications',
        'memberships',
        'address',
        // 'research-resources',    // not used
        'services',
        // 'distinctions',          // not used
        // 'email',                 // not used
        'works',
        'keywords',
        'other-names',
        'researcher-urls',
    ];
    const ORCID_NAMESPACES = [
        'person' => 'http://www.orcid.org/ns/person',
        'common' => 'http://www.orcid.org/ns/common',
        'activities' => 'http://www.orcid.org/ns/activities',
        'education' => 'http://www.orcid.org/ns/education',
        'employment' => 'http://www.orcid.org/ns/employment',
        'work' => 'http://www.orcid.org/ns/work',
        'funding' => 'http://www.orcid.org/ns/funding',
        'qualification' => 'http://www.orcid.org/ns/qualification',
        'membership' => 'http://www.orcid.org/ns/membership',
        'address' => 'http://www.orcid.org/ns/address',
        // 'research-resource' => 'http://www.orcid.org/ns/research-resource',
        'service' => 'http://www.orcid.org/ns/service',
        // 'distinction' => 'http://www.orcid.org/ns/distinction',
        'invited-position' => 'http://www.orcid.org/ns/invited-position',
        // 'email' => 'http://www.orcid.org/ns/email',
        'keyword' => 'http://www.orcid.org/ns/keyword',
        'other-name' => 'http://www.orcid.org/ns/other-name',
        'researcher-url' => 'http://www.orcid.org/ns/researcher-url'
    ];

    # returns list($orcid, $message, $details)
    public static function downloadORCID($recordId, $first, $middle, $last, $institutionList, $pid) {
        $delim = self::ORCID_DELIM;
        $identifier = "$recordId: $first $last $institutionList";
        if (!$last) {
            return [FALSE, "No last name for $identifier!", []];
        }
        $params = [];
        $params["family-name"] = NameMatcher::explodeLastName($last);
        if ($first) {
            $params["given-names"] = NameMatcher::explodeFirstName($first, $middle);
        }
        $params["affiliation-org-name"] = self::getInstitutionArray(preg_split("/\s*[,\/]\s*/", $institutionList));
        // Test server $baseUrl = "https://pub.sandbox.orcid.org/v3.0/search/";
        $baseUrl = "https://pub.orcid.org/v3.0/search/";
        $q = self::makeQueryFromParams($params);
        if ($q) {
            $url = $baseUrl . "?q=" . $q;
            $options = self::fetchAndParseURL($url);
            if (is_string($options)) {
                $mssg = $options;
                Application::log($mssg, $pid);
                return [FALSE, $mssg];
            }

            if (count($options) == 0) {
                unset($params["affiliation-org-name"]);
                Application::log("Searching name '$first $last' without institution");
                $q = self::makeQueryFromParams($params);
                if ($q) {
                    $url = $baseUrl . "?q=" . $q;
                    $options = self::fetchAndParseURL($url);
                    if (is_string($options)) {
                        $mssg = $options;
                        Application::log($mssg, $pid);
                        return [FALSE, $mssg];
                    }
                }
            }

            $numOptions = count($options);
            if ($numOptions == 0) {
                $mssg = self::NO_MATCHES."$delim$recordId";
                Application::log($mssg, $pid);
                return [FALSE, $mssg];
            } else if ($numOptions > 1) {
                $orcids = [];
                foreach ($options as $result) {
                    if ($result["path"]) {
                        $orcids[] = $result["path"];
                    }
                }
                $mssg = self::MORE_THAN_ONE."$delim$recordId$delim".json_encode($orcids);
                Application::log($mssg, $pid);
                return [$orcids, $mssg];
            } else {
                $orcid = $options[0]["path"];
                return [$orcid, ""];
            }
        } else {
            return [FALSE, "No parameters available for $identifier"];
        }
    }

    public static function downloadORCIDProfile(string $orcid, $pid, array $endpoints = []): array {
        if (empty($endpoints)) {
            $endpoints = self::ORCID_ENDPOINTS;
        }
        $details = [];
        foreach ($endpoints as $endpoint) {
            list($success, $data) = self::fetchORCIDEndpointData($orcid, $endpoint, $pid);
            if ($success) {
                $details[$endpoint] = $data;
            }
        }
        return $details;
    }

    # returns array or FALSE
    public static function isCodedMessage($mssg) {
        $delim = self::ORCID_DELIM;
        $valid = [self::MORE_THAN_ONE, self::NO_MATCHES];
        foreach ($valid as $beginning) {
            $regex = "/^".$beginning."/";
            if (preg_match($regex, $mssg)) {
                $nodes = explode($delim, $mssg);
                if (count($nodes) == 1) {
                    return FALSE;
                } else if (count($nodes) == 2) {
                    return [$nodes[1] => $nodes[1]];
                } else if (count($nodes) == 3) {
                    return [$nodes[1] => $nodes[2]];
                } else {
                    throw new \Exception("Could not decode $mssg! This should never happen.");
                }
            }
        }
        return FALSE;
    }

    public static function fetchORCIDEndpointData($orcid, $endpoint, $pid) {
        self::throttle();

        $baseURL = "https://pub.orcid.org/v3.0/";
        $url = $baseURL . "$orcid/$endpoint";
        list($resp, $output) = URLManagement::downloadURL($url);

        if($resp !== 200) {
            return [FALSE, []];
        }

        if ($resp === 200 && empty($output)) {
            Application::log("No data returned for $endpoint", $pid);
            return [TRUE, []];
        }

        try {
            $xpath = self::initializeXPath($output);
            $parsedData = self::parseEndpointData($xpath, $endpoint);
            return [TRUE, $parsedData];
        } catch (\Exception $e) {
            Application::log("Error parsing XML for $endpoint: " . $e->getMessage(), $pid);
            return [FALSE, []];
        }
    }

    private static function throttle() {
        self::refillTokens();
    
        if (self::$tokens < 1) {
            $sleepTime = (1 / self::$tokensPerSecond) * 1000000;
            usleep($sleepTime);
            self::refillTokens();
        }
    
        self::$tokens -= 1;
    }
    
    private static function refillTokens() {
        $now = microtime(true);
        $timePassed = $now - self::$lastRefillTime;
        $newTokens = $timePassed * self::$tokensPerSecond;
    
        if ($newTokens > 0) {
            self::$tokens = min(self::$maxTokens, self::$tokens + $newTokens);
            self::$lastRefillTime = $now;
        }
    }

    private static function initializeXPath($xml_string) {
        $doc = new \DOMDocument();
        $doc->loadXML($xml_string);
        $xpath = new \DOMXPath($doc);
    
        foreach (self::ORCID_NAMESPACES as $prefix => $uri) {
            $xpath->registerNamespace($prefix, $uri);
        }
    
        return $xpath;
    }

    private static function getNodeValue($xpath, $query, $contextNode = null, $default = null) {
        $nodes = $xpath->query($query, $contextNode);
        if ($nodes->length > 0) {
            return trim($nodes->item(0)->textContent);
        }
        return $default;
    }

    private static function parseEndpointData($xpath, $endpoint) {
        switch ($endpoint) {
            case 'person':
                $relativeUrl = '//person:person';
                $fields = [
                    'given_names' => '//person:name/personal-details:given-names',
                    'family_name' => '//person:name/personal-details:family-name',
                    'credit_name' => '//person:name/personal-details:credit-name',
                ];
                break;
            case 'fundings':
                $relativeUrl = '//activities:fundings/activities:group/funding:funding-summary';
                $fields = [
                    'funding_title' => './/funding:title/common:title',
                    'funding_grant' => './/funding:type',
                    'funding_start_year' => './/common:start-date/common:year',
                    'funding_start_month' => './/common:start-date/common:month',
                    'funding_end_year' => './/common:end-date/common:year',
                    'funding_end_month' => './/common:end-date/common:month',
                    'funding_organization_name' => './/common:organization/common:name',
                    'funding_organization_city' => './/common:organization/common:address/common:city',
                    'funding_organization_country' => './/common:organization/common:address/common:country',
                    'external_id_type' => './/common:external-ids/common:external-id/common:external-id-type',
                    'external_id_value' => './/common:external-ids/common:external-id/common:external-id-value',
                    'external_id_relationship' => './/common:external-ids/common:external-id/common:external-id-relationship',
                ];
                break;
            case 'address':
                $relativeUrl = '//address:addresses/address:address';
                $fields = [
                    'created_date' => './/common:created-date',
                    'last_modified_date' => './/common:last-modified-date',
                    'country' => './/address:country',
                ];
                break;
            case 'services':
                $relativeUrl = '//activities:services/activities:affiliation-group/service:service-summary';
                $fields = [
                    'department_name' => './/common:department-name',
                    'start_year' => './/common:start-date/common:year',
                    'start_month' => './/common:start-date/common:month',
                    'start_day' => './/common:start-date/common:day',
                    'organization_name' => './/common:organization/common:name',
                    'organization_city' => './/common:organization/common:address/common:city',
                    'organization_country' => './/common:organization/common:address/common:country',
                    'organization_url' => './/common:url',
                ];
                break;
            case 'distinctions':
                $relativeUrl = '//activities:distinctions/activities:affiliation-group/distinction:distinction-summary';
                $fields = [
                    'department_name' => './/common:department-name',
                    'start_year' => './/common:start-date/common:year',
                    'start_month' => './/common:start-date/common:month',
                    'start_day' => './/common:start-date/common:day',
                    'organization_name' => './/common:organization/common:name',
                    'organization_city' => './/common:organization/common:address/common:city',
                    'organization_country' => './/common:organization/common:address/common:country',
                    'organization_url' => './/common:url',
                ];
                break;
            case 'qualifications':
                $relativeUrl = '//activities:qualifications/activities:affiliation-group/qualification:qualification-summary';
                $fields = [
                    'department_name' => './/common:organization/common:name',
                    'role_title' => './/common:role-title',
                    'role_start_year' => './/common:start-date/common:year',
                    'role_start_month' => './/common:start-date/common:month',
                    'role_start_day' => './/common:start-date/common:day',
                    'role_end_year' => './/common:end-date/common:year',
                    'role_end_month' => './/common:end-date/common:month',
                    'role_end_day' => './/common:end-date/common:day',
                    'role_organization_name' => './/common:organization/common:name',
                    'role_organization_city' => './/common:organization/common:address/common:city',
                    'role_organization_country' => './/common:organization/common:address/common:country',
                ];
                break;
            case 'invited-postions':
                $relativeUrl = '//activities:invited-positions/activities:affiliation-group/invited-position:invited-position-summary';
                $fields = [
                    'department_name' => './/common:department-name',
                    'start_year' => './/common:start-date/common:year',
                    'start_month' => './/common:start-date/common:month',
                    'start_day' => './/common:start-date/common:day',
                    'organization_name' => './/common:organization/common:name',
                    'organization_city' => './/common:organization/common:address/common:city',
                    'organization_country' => './/common:organization/common:address/common:country',
                    'organization_url' => './/common:url',
                ];
                break;
            case 'memberships':
                $relativeUrl = '//membership:membership-summary';
                $fields = [
                    'role_title' =>  './/common:role-title',
                    'membership_organization_name' =>  './/common:organization/common:name',
                    'membership_organization_city' =>  './/common:organization/common:address/common:city',
                    'membership_organization_region' =>  './/common:organization/common:address/common:region',
                    'membership_organization_country' =>  './/common:organization/common:address/common:country',
                ];
                break;
            case 'educations':
                $relativeUrl = '//activities:affiliation-group/education:education-summary';
                $fields = [
                    'department' => './/common:department-name',
                    'role' => './/common:role-title',
                    'organization' => './/common:organization/common:name',
                    'start_year' => './/common:start-date/common:year',
                    'end_year' => './/common:end-date/common:year',
                ];
                break;
            case 'employments':
                $relativeUrl = '//activities:affiliation-group/employment:employment-summary';
                $fields = [
                    'department_name' =>  './/common:department-name',
                    'role_title' =>  './/common:role-title',
                    'organization_name' =>  './/common:organization/common:name',
                    'organization_city' =>  './/common:organization/common:address/common:city',
                    'organization_region' =>  './/common:organization/common:address/common:region',
                    'organization_country' =>  './/common:organization/common:address/common:country',
                ];
                break;
            case 'research-resources':
                $relativeUrl = '//activities:research-resources/activities:group/research-resource:research-resource-summary';
                $fields = [
                    'title' =>  './/research-resource:proposal/research-resource:title/common:title',
                    'host_organization_name' =>  './/research-resource:proposal/research-resource:hosts/common:organization/common:name',
                    'host_organization_city' =>  './/research-resource:proposal/research-resource:hosts/common:organization/common:address/common:city',
                    'host_organization_region' =>  './/research-resource:proposal/research-resource:hosts/common:organization/common:address/common:region',
                    'host_organization_country' =>  './/research-resource:proposal/research-resource:hosts/common:organization/common:address/common:country',
                    'start_date_year' =>  './/research-resource:proposal/common:start-date/common:year',
                    'start_date_month' =>  './/research-resource:proposal/common:start-date/common:month',
                    'start_date_day' =>  './/research-resource:proposal/common:start-date/common:day',
                    'end_date_year' =>  './/research-resource:proposal/common:end-date/common:year',
                    'end_date_month' =>  './/research-resource:proposal/common:end-date/common:month',
                    'end_date_day' =>  './/research-resource:proposal/common:end-date/common:day',
                    'url' =>  './/research-resource:proposal/common:url',
                ];
                break;
            case 'email':
                $relativeUrl = '//email:email';
                $fields = [
                    'email' =>  './/email:email'
                ];
                break;
            case 'keywords':
                $relativeUrl = '//keyword:keywords';
                $fields = [
                    'content' =>  './/keyword:content'
                ];
                break;
            case 'works':
                $relativeUrl = '//activities:works/activities:group/work:work-summary';
                $fields = [
                    'title' =>  './/work:title/common:title',
                    'external_id_type' =>  './/common:external-ids/common:external-id/common:external-id-type',
                    'external_id_value' =>  './/common:external-ids/common:external-id/common:external-id-value',
                    'external_id_url' =>  './/common:external-ids/common:external-id/common:external-id-url',
                    'external_id_relationship' =>  './/common:external-ids/common:external-id/common:external-id-relationship',
                    'url' =>  './/common:url',
                    'type' =>  './/work:type',
                    'publication_year' =>  './/common:publication-date/common:year',
                    'publication_month' =>  './/common:publication-date/common:month',
                    'publication_day' =>  './/common:publication-date/common:day',
                ];
                break;
            case 'other-names':
                $relativeUrl = '//other-name:other-names';
                $fields = [
                    'other_names' =>  './/other-name:other-name/other-name:content'
                ];
                break;
            case 'researcher-urls':
                $relativeUrl = '//researcher-url:researcher-urls';
                $fields = [
                    'url_name' =>  './/researcher-url:researcher-url/researcher-url:url-name',
                    'url' =>  './/researcher-url:researcher-url/researcher-url:url'
                ];
                break;
            default:
                throw new \Exception("Unsupported endpoint: $endpoint");
        }
        return self::genericParseData($xpath, $relativeUrl, $fields);
    }

    private static function genericParseData(\DOMXPath $xpath, string $relativeUrl, array $fields): array {
        $results = [];
        $nodeList = $xpath->query($relativeUrl);

        for ($i = 0; $i < $nodeList->count(); $i++) {
            $node = $nodeList->item($i);
            if (method_exists($node, 'getAttribute')) {
                $orcidPath = $node->getAttribute('path');

                $orcidParts = explode('/', ltrim($orcidPath, '/'));
                $orcidId = $orcidParts[0];

                $resultRow = ['id' => $orcidId];
                foreach ($fields as $field => $query) {
                    $resultRow[$field] = self::getNodeValue($xpath, $query, $node);
                }
                $results[] = $resultRow;
            }
        }

        return $results;

    }

    # returns string if an error; else returns an array
    private static function fetchAndParseURL($url) {
        $i = 0;
        $numRetries = 3;
        $xml = NULL;
        do {
            usleep(300000);

            list($resp, $output) = URLManagement::downloadURL($url);
            if ($resp == 200) {
                $xml = simplexml_load_string(utf8_encode($output));
            }
            $i++;
        } while ((!$xml || ($resp != 200)) && ($numRetries > $i));
        if ($resp != 200) {
            $mssg = "Could not contact $url; response $resp";
            return $mssg;
        } else if (!$xml) {
            $mssg = "Could not contact $url; could not parse $output";
            return $mssg;
        }

        $searchNs = "http://www.orcid.org/ns/search";
        $commonNs = "http://www.orcid.org/ns/common";
        $options = [];
        foreach ($xml->children($searchNs) as $result) {
            foreach ($result->children($commonNs) as $child) {
                $result = [];
                foreach ($child->children($commonNs) as $grandChild) {
                    $result[$grandChild->getName()] = strval($grandChild);
                }
                $options[] = $result;
            }
        }
        return $options;

    }

    private static function makeQueryFromParams($params) {
        $queryStrings = [];
        foreach ($params as $key => $value) {
            if (is_array($value)) {
                $values = [];
                foreach ($value as $v) {
                    if ($v) {
                        $values[] = $v;
                    }
                }
                if (count($values) > 1) {
                    $queryStrings[] = $key . ":" . "(" . implode(" OR ", $values) . ")";
                } else {
                    $value = $values[0];
                    $queryStrings[] = $key . ":" . $value;
                }
            } else if ($value) {
                $queryStrings[] = $key . ":" . $value;
            }
        }
        return urlencode(implode(" AND ", $queryStrings));
    }

    private static function getInstitutionArray($additionalInstitutions) {
        $institutions = Application::getInstitutions();
        foreach ($additionalInstitutions as $institution) {
            if ($institution && !in_array($institution, $institutions)) {
                $institutions[] = self::formatInstitutionName($institution);
            }
        }
        return $institutions;
    }

    private static function formatInstitutionName($inst) {
        $inst = str_replace("&", "", $inst);
        $inst = preg_replace("/\s\s+/", " ", $inst);
        return $inst;
    }

    public static function encodeKey(string $key): string {
        return preg_replace("/[-\s]+/", "_", $key);
    }
}
