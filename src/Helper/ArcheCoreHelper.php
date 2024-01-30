<?php

namespace Drupal\arche_core_gui_api\Helper;

/**
 * Description of ArcheHelper Static Class
 *
 * @author nczirjak
 */
class ArcheCoreHelper {

    private static $prefixesToChange = array(
        "http://fedora.info/definitions/v4/repository#" => "fedora",
        "http://www.ebu.ch/metadata/ontologies/ebucore/ebucore#" => "ebucore",
        "http://www.loc.gov/premis/rdf/v1#" => "premis",
        "http://www.jcp.org/jcr/nt/1.0#" => "nt",
        "http://www.w3.org/2000/01/rdf-schema#" => "rdfs",
        "http://www.w3.org/ns/ldp#" => "ldp",
        "http://www.iana.org/assignments/relation/" => "iana",
        "https://vocabs.acdh.oeaw.ac.at/schema#" => "acdh",
        "https://id.acdh.oeaw.ac.at/" => "acdhID",
        "http://purl.org/dc/elements/1.1/" => "dc",
        "http://purl.org/dc/terms/" => "dcterms",
        "http://www.w3.org/2002/07/owl#" => "owl",
        "http://xmlns.com/foaf/0.1/" => "foaf",
        "http://www.w3.org/1999/02/22-rdf-syntax-ns#" => "rdf",
        "http://www.w3.org/2004/02/skos/core#" => "skos",
        "http://hdl.handle.net/21.11115/" => "handle",
        "http://xmlns.com/foaf/spec/" => "foaf"
    );
    private $resources = [];

    /**
     * Check if the drupal DB has the cached data
     * @param string $cacheId
     * @return bool
     */
    public function isCacheExists(string $cacheId): bool {
        if (\Drupal::cache()->get($cacheId)) {
            return true;
        }
        return false;
    }

    /**
     * Fetch the defined ARCHE GUI endpoint 
     * @param string $url
     * @param array $params
     * @return string
     */
    public function fetchApiEndpoint(string $url, array $params = []): string {
        $client = new \GuzzleHttp\Client();

        try {
            $response = $client->request('GET', $url, [
                'query' => $params,
            ]);
            return $response->getBody()->getContents();
        } catch (\Exception $ex) {
            return "";
        }
    }

    /**
     * Create shortcut from the property for the gui
     *
     * @param string $prop
     * @return string
     */
    public static function createShortcut(string $prop): string {
        $prefix = array();

        if (strpos($prop, '#') !== false) {
            $prefix = explode('#', $prop);
            $property = end($prefix);
            $prefix = $prefix[0];
            if (isset(self::$prefixesToChange[$prefix . '#'])) {
                return self::$prefixesToChange[$prefix . '#'] . ':' . $property;
            }
        } else {
            $prefix = explode('/', $prop);
            $property = end($prefix);
            $pref = str_replace($property, '', $prop);
            if (isset(self::$prefixesToChange[$pref])) {
                return self::$prefixesToChange[$pref] . ':' . $property;
            }
        }
        return '';
    }

    public static function createFullPropertyFromShortcut(string $prop): string {
        $domain = self::getDomainFromShortCut($prop);
        $value = self::getValueFromShortCut($prop);
        if ($domain) {
            foreach (self::$prefixesToChange as $k => $v) {
                if ($v == $domain) {
                    return $k . $value;
                }
            }
        }
        return "";
    }

    /**
     * Extract the GUI data from the RDF data for a given resource (id)
     * @param object $obj
     * @param string $id
     * @return type
     */
    public function extractDataFromCoreApiWithId(object $obj, string $id) {
        $root = [];
        $relArr = [];

        while ($triple = $obj->fetchObject()) {
            $rid = (string) $triple->id;

            if ($rid === $id) {
                $triple->lang = ($triple->lang === null) ? 'en' : $triple->lang;
                if ($triple->type === 'REL') {
                    $root[$triple->property][$triple->value] = $triple;
                } else {
                    $root[$triple->property][] = $triple;
                }
            } else {
                $object = (object) [
                            'id' => $triple->id,
                            'property' => $triple->property,
                            'type' => $triple->type,
                            'lang' => ($triple->lang === null ? 'en' : $triple->lang),
                            'value' => $triple->value
                ];

                if ($triple->property === "https://vocabs.acdh.oeaw.ac.at/schema#hasTitle") {
                    $relArr[$triple->id][$triple->lang] = $object;
                }
            }
        }


        foreach ($root as $rpk => $rpv) {
            foreach ($rpv as $rk => $rv) {
                if (array_key_exists($rk, $relArr)) {
                    $root[$rpk][$rk]->values = $relArr[$rk];
                }
            }
        }


        return $root;
    }

    /**
     * Get all metadata for a given resource
     * @param object $pdoStmt
     * @param int $resId
     * @param array $contextRelatives
     * @return object
     */
    public function extractExpertView_(object $pdoStmt, int $resId, array $contextRelatives, string $lang = "en"): array {
        $this->resources = [(string) $resId => (object) ['id' => $resId]];
        $relArr = [];
        $resId = (string) $resId;
        $resource = [];
        $resources = [$resId => $resource];
        while ($triple = $pdoStmt->fetchObject()) {
            //echo "$triple->id $triple->property $triple->value\n";
            $id = (string) $triple->id;
            $property = $triple->property;
            $context = $id === $resId ? $contextResource : $contextRelatives;
            $shortProperty = $context[$triple->property] ?? false;
            $resources[$id] ??= (object) ['id' => $id];
            $tid = null;
            if ($triple->type === 'REL') {
                $tid = $triple->value;
                $resources[$tid] ??= (object) ['id' => $tid];
            }

            if ($triple->type !== 'REL') {
                if ($shortProperty) {
                    // ordinary property existing in the context
                    $resources[$id]->{$shortProperty}[$triple->lang] = \acdhOeaw\arche\lib\TripleValue::fromDbRow($triple);
                } elseif ($id === $resId) {
                    // metadata property - out of context but belongs to the main resource 
                    $resource[$property][$resId] = \acdhOeaw\arche\lib\TripleValue::fromDbRow($triple);
                }
            } elseif ($shortProperty) {
                $resources[$id]->{$shortProperty}[$tid] = $resources[$tid];
            } elseif ($id === $resId) {
                $resource[$property][$tid] = $resources[$tid];
            }
        }
        $this->resources = $resource;

        $this->changePropertyToShortcut();

        return $this->resources;
    }

    public function extractExpertView(object $pdoStmt, int $resId, array $contextRelatives, string $lang = "en"): object {
        $this->resources = [(string) $resId => (object) ['id' => $resId, 'language' => $lang]];
        $relArr = [];
        while ($triple = $pdoStmt->fetchObject()) {

            $id = (string) $triple->id;
            $this->resources[$id] ??= (object) ['id' => (int) $id];

            if ($triple->id !== $resId && isset($contextRelatives[$triple->property])) {

                $property = $contextRelatives[$triple->property];
                $relvalues = \acdhOeaw\arche\lib\TripleValue::fromDbRow($triple);

                if ($property === 'title') {
                    //if we have the title for the actual gui lang then apply it
                    if ($relvalues->lang === $lang) {
                        $this->resources[$id]->relvalue = $relvalues->value;
                        $this->resources[$id]->value = $relvalues->value;
                        $this->resources[$id]->lang = $lang;
                    } else {
                        //if the lang is different then we add it to the titles arr
                        $this->resources[$id]->titles[$relvalues->lang] = $relvalues->value;
                    }
                }

                if ($property === 'class') {
                    $this->resources[$id]->property = $relvalues->value;
                }

                $this->resources[$id]->type = "REL";
                $this->resources[$id]->repoid = $id;
            } elseif ($triple->id === $resId) {
                $property = $triple->property;

                if ($triple->type === 'REL') {
                    $relArr[$triple->value]['id'] = $triple->value;
                    $tid = $triple->value;
                    $this->resources[$tid] ??= (object) ['id' => (int) $tid];
                    $this->resources[$id]->$property[$tid] = (object) $this->resources[$tid];
                } else {
                    if (!($triple->lang)) {
                        $triple->lang = $lang;
                    }
                    $this->resources[$id]->$property[$id] = (object) $triple;
                }
            }
        }
        if (count($this->resources) < 2) {
            return new \stdClass();
        }

        $this->changePropertyToShortcut((string) $resId);

        $this->setDefaultTitle($lang, $resId);

        return $this->resources[(string) $resId];
    }

     /**
     * If the property doesn't have the actual lang related value, then we 
     * have to create one based on en/de/und/or first array element
     */
    private function setDefaultTitle(string $lang, string $resId) {
 //var_dump($this->resources[$resId]->{'acdh:hasCurator'}[11214]->id);
        foreach ($this->resources[$resId] as $prop => $pval) {

            if (is_array($pval)) {
                foreach ($pval as $rid => $tv) {
                    if (!$tv->value) {
                        if ($tv->titles) {

                            if (array_key_exists($lang, $tv->titles)) {
                                $this->resources[$resId]->$prop[$rid]->value = $tv->titles[$lang];
                            } else {
                                if ($lang === "en" && array_key_exists('de', $tv->titles)) {
                                    $this->resources[$resId]->$prop[$rid]->value = $tv->titles['de'];
                                } elseif ($lang === "de" && array_key_exists('en', $tv->titles)) {
                                    $this->resources[$resId]->$prop[$rid]->value = $tv->titles['en'];
                                } elseif (array_key_exists('und', $tv->titles)) {
                                    $this->resources[$resId]->$prop[$rid]->value = $tv->titles['und'];
                                } else {
                                    $this->resources[$resId]->$prop[$rid]->value = reset($tv->titles);
                                }
                            }
                        }
                    }
                }
            }
        }
    }
    /**
     * change the long proeprty urls inside the resource array
     * @param string $resId
     */
    private function changePropertyToShortcut(string $resId = "") {
        if ($resId) {
            foreach ($this->resources[$resId] as $k => $v) {
                if (!empty($shortcut = $this::createShortcut($k))) {
                    $this->resources[$resId]->$shortcut = $v;
                    unset($this->resources[$resId]->$k);
                }
            }
        } else {
            foreach ($this->resources as $k => $v) {

                if (!empty($shortcut = $this::createShortcut($k))) {
                    $this->resources[$shortcut] = $v;
                    unset($this->resources[$k]);
                }
            }
        }
    }

    public function extractInverseDataFromCoreApiWithId(object $obj, string $id) {
        $root = [];
        $relArr = [];

        while ($triple = $obj->fetchObject()) {

            if ($triple->value === $id) {
                if ($triple->type === 'REL') {

                    $tobj = new \stdClass();
                    $tobj->id = $triple->id;
                    $tobj->type = $triple->type;
                    $tobj->value = $triple->id;
                    $tobj->lang = 'en';

                    $data = \acdhOeaw\arche\lib\TripleValue::fromDbRow($tobj);

                    echo "________DATA _____________ ";
                    echo "<pre>";
                    var_dump($data);
                    echo "</pre>";

                    echo "______DATA END ______________";
                }
                $root[$triple->id][] = $triple;
            }

            if ($triple->property === 'search://count') {
                $root['count'] = $triple->value;
            }
        }

        echo "ROOT";

        echo "<pre>";
        var_dump($root);
        echo "</pre>";

        die();
        foreach ($root as $rpk => $rpv) {
            foreach ($rpv as $rk => $rv) {
                if (array_key_exists($rk, $relArr)) {
                    $root[$rpk][$rk]->values = $relArr[$rk];
                }
            }
        }


        return $root;
    }
}
