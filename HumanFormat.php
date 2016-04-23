<?php

/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

/**
 * Description of HumanFormat
 *
 * @author SCHRED
 */
class HumanFormat {

    /**
     * Creates a human readable string
     * @param PodioItem $item
     * @return string
     */
    public static function toHumanReadableString($item) {
        $itemFile = '--- ' . $item->title . ' ---' . "\n";
        $itemFile .= 'Item ID: ' . $item->item_id . "\n";
        if ($item instanceof PodioItem) {
            foreach ($item->fields as $field) {
                if ($field instanceof PodioItemField) {
                    $itemFile .= $field->label . ': ' . HumanFormat::getFieldValue($field) . "\n";
                } else {
                    echo "WARN non PodioItemField:";
                    var_dump($item);
                }
            }
        } else {
            echo "WARN non PodioItem:";
            var_dump($item);
            foreach ($item->fields as $field) {
                $itemFile .= $field->label . ': ' . HumanFormat::getFieldValue($field) . "\n";
            }
        }
        $itemFile .= "\n";
        return $itemFile;
    }

    /**
     * given a podio field object -> returns a human readable format of the field value (or app reference as array (app_id, item_id)
     * @param type $field should be of type PodioItemField
     * @return string
     */
    public static function getFieldValue($field) {
        $value = "";
        if ($field->type == "text" || $field->type == "number" || $field->type == "progress" || $field->type == "duration" || $field->type == "state") {
            if (is_string($field->values)) {
                // TODO refactor
                $value = $field->values;
                $value = str_ireplace('</p>', '</p><br>', $value);
                $value = preg_replace('/\<br(\s*)?\/?\>/i', "\n", $value);
                $value = strip_tags(br2nl($value));
                $value = str_replace('&nbsp;', ' ', $value);
                $value = str_replace('&amp;', '&', $value);
            } else if (is_numeric($field->values)) {
                $value = $field->values;
            } else {
                echo "WARN expected string or number, but found: ";
                var_dump($field->values);
            }
            return $value;
        }
        if ($field->type == "category") {
            $selected_categories = array();
            foreach ($field->values as $category) {
                array_push($selected_categories, $category['text']);
            }
            return HumanFormat::implodeStrings(", ", $selected_categories);
        }

        if ($field->type == "date") {
            return HumanFormat::parseDate($field->values['start']) . " - " . HumanFormat::parseDate($field->values['end']);
        }

        if ($field->type == "money") {
            return "" . $field->values['value'] . " " . $field->values['currency'];
        }

        if ($field->type == "contact") {
            if (is_array($field->values) || $field->values instanceof Traversable) {
                foreach ($field->values as $contact) {
                    $value .= "\nUserid: $contact->user_id, name: $contact->name, email: " . HumanFormat::implodeStrings(', ', $contact->mail) . ", phone: " . HumanFormat::implodeStrings(", ", $contact->phone);
                }
            } else {
                echo "WARN unexpected contact type:";
                var_dump($field);
            }
            return $value;
        }

        if ($field->type == "embed") {
            if (is_array($field->values) || $field->values instanceof Traversable) {
                foreach ($field->values as $embed) {
                    //TODO implode (general function..)
                    $value .= "\nurl: " . $embed->original_url;
                }
            } else {
                echo "WARN unexpected embed type:";
                var_dump($field);
            }
            return $value;
        }
        if ($field->type == "app") {
            if (is_array($field->values) || $field->values instanceof Traversable) {
                foreach ($field->values as $app) {
                    //TODO implode (general function..)
                    $value .= "\napp: " . $app->app->name . ", item_name: " . $app->item_name . ", title: " . $app->title; //TODO more info?!
                }
            } else {
                echo "WARN unexpected app type:";
                var_dump($field);
            }
            return $value;
        }

        if ($field->type == "image") {
            $images = array();
            foreach ($field->values as $image) {
                array_push($images, "fileid: " . $image->file_id . ", name: " . $image->name);
            }
            return HumanFormat::implodeStrings(" | ", $images);
        }

        if ($field->type == "location") {
            $locations = array();
            foreach ($field->values as $location) {
                array_push($locations, $location);
            }
            return HumanFormat::implodeStrings(" | ", $locations);
        }

	if ($field->type == "calculation" && isset($field->values)) {
	    if(is_string($field->values)) {
	        return $field->values;
	    }
	    if(is_array($field->values)) {
		//Calculation field is a date
		$calculations = $field->values;
		return $calculations[0][start];
	    }
	}

        echo "WARN unexpected type: $field->type\n";
	print_r($field);
        return $field->values;
    }

    /**
     * takes care of NULL etc..
     * @param type $date
     */
    public static function parseDate($date) {
        if (is_null($date))
            return '';
        if (is_string($date))
            return $date;
        if ($date instanceof DateTime)
            return date_format($date, 'Y-m-d H:i:s');
        echo "WARNING unexpected date: ";
        var_dump($date);
        return $date;
    }

    /**
     * 
     * @param type $glue
     * @param type $pieces can be NULL
     * @return string
     */
    public static function implodeStrings($glue, $pieces) {
        if (is_null($pieces))
            return '';
        return implode($glue, $pieces);
    }

}
