<?php

require_once 'RateLimitChecker.php';

/**
 * Description of PodioFetchAll
 *
 * @author SCHRED
 */
class PodioFetchAll {

    /**
     * Wrapper to fetch all elements besides some from podio imposed maxresult.
     * 
     * Examples:
     * 
     * $result = PodioFetchAll::iterateApiCall('PodioFile::get_for_app', "YOUR_APP_ID", array('attached_to' => 'item'));
     * $result = PodioFetchAll::iterateApiCall('PodioItem::filter', "YOUR_APP_ID", array(), "items");
     * 
     * @param type $function e.g. 'PodioFile::get_for_app'
     * @param type $id first parameter of function
     * @param type $params
     * @param int $limit no of elements fetched on each call
     * @param String $resulttype if set, the result of the call is suspected to be in $result[$resulttype]
     * @return type array of all fetched elements
     */
    static function iterateApiCall($function, $id, $params = array(), $limit = 100, $resulttype = null) {
        echo "iterateApiCall-start: MEMORY: " . memory_get_usage(true) . " | " . memory_get_usage(false) . "\n";
        $completed = false;
        $iteration = 0;
        $result = array();
        while (!$completed) {
            #$tmp_result = $function($id, array_merge($params, array("limit" => $limit, 'offset' => $limit * $iteration)));
            $tmp_result = call_user_func($function, $id, array_merge($params, array('limit' => $limit, 'offset' => $limit * $iteration)));
            #var_dump($tmp_result);
            RateLimitChecker::preventTimeOut();
            #echo "done iteration $iteration\n";
            $iteration++;
            if (isset($resulttype)) {
                if (is_array($tmp_result) && isset($tmp_result[$resulttype]) && is_array($tmp_result[$resulttype])) {
                    $result = array_merge($result, $tmp_result[$resulttype]);
                    if (sizeof($tmp_result[$resulttype]) < $limit) {
                        $completed = true;
                    }
                } else {
                    $completed = true;
                }
            } else {
                if (is_array($tmp_result)) {
                    $result = array_merge($result, $tmp_result);
                    if (sizeof($tmp_result) < $limit) {
                        $completed = true;
                    }
                } else {
                    $completed = true;
                }
            }
            unset($tmp_result);
        }
        echo "iterateApiCall-end  : MEMORY: " . memory_get_usage(true) . " | " . memory_get_usage(false) . "\n";
        return $result;
    }

    public static function podioElements(array $elements) {
        return array('__attributes' => $elements, '__properties' => $elements);
    }

    /**
     * Removes all elements/properties of $object, that are not defined in $elements.
     * This can be used to save memory when handling large lists of items.
     * 
     * Be aware that podio objects make use of the __get(..) and __set(..) funktions,
     * hence a direct removal of the attributes is not possible - on might use PodioFetchAll::podioElemnts(..)
     * 
     * Example usage:
     * 
     * PodioFetchAll::flattenObjectsArray($appFiles, array('__attributes' =>
     *       array('file_id' => null, 'name' => null, 'link' => null, 'hosted_by' => null,
     *           'context' => array('id' => NULL, 'type' => null, 'title' => null)),
     *       '__properties' => array('file_id' => null, 'name' => null, 'link' => null, 'hosted_by' => null,
     *           'context' => NULL)));
     * 
     * analogous:
     * 
     * PodioFetchAll::flattenObjectsArray($appFiles, 
     *           PodioFetchAll::podioElemnts(array('file_id' => null, 'name' => null, 'link' => null, 'hosted_by' => null,
     *                       'context' => array('id' => NULL, 'type' => null, 'title' => null)));
     * 
     * 
     * @param type $object can be class or array
     * @param array $elements
     */
    public static function flattenObject(&$object, array $elements) {
        //unset all propterties/elements of $object:
        if (is_array($object)) {
            foreach (array_keys($object) as $key) {

                if (array_key_exists($key, $elements)) {
                    #echo "found array-key: $key -> $elements[$key]\n";
                    if (is_array($elements[$key])) {
                        #echo "flattening key. for elements $elements[$key]\n";
                        self::flattenObject($object[$key], $elements[$key]);
                    } #else: do nothing
                } else {
                    #echo "unsetting $key\n";
                    unset($object[$key]);
                }
            }
        } else {
            foreach (array_keys(get_object_vars($object)) as $key) {
                if (array_key_exists($key, $elements)) {
                    #echo "found object-key: $key -> $elements[$key]\n";
                    #var_dump($elements[$key]);
                    if (is_array($elements[$key])) {
                        #echo "flattening key. for elements $elements[$key]\n";
                        self::flattenObject($object->$key, $elements[$key]);
                    } #else: do nothing
                } else {
                    #echo "unsetting $key\n";
                    unset($object->$key);
                }
            }
        }
    }

    /**
     * @see PodioFetchAll::flattenObject(..)
     * @param array $objects
     * @param array $elements
     * @return array
     */
    public static function flattenObjectsArray(array &$objects, array $elements) {
        $start = time();
        foreach ($objects as $object) {
            self::flattenObject($object, $elements);
        }
        echo "flattening took " . (time() - $start) . " seconds.\n";
    }

}
