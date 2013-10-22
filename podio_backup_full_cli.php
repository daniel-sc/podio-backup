<?php

/* =====================================================================
 * podio_backup.php
 * This script backs up your entire Podio account.
 * (c) 2013 Globi Web Solutions
 * v1.3 2013-10-03 - Andreas Huttenrauch
 * v1.4 2013-10-18 - Daniel Schreiber
 *
 * TODOS:
 * a) incremental backup - especially for files (done)
 * b) non item/comment scoped files e.g. app/space..
 * c) optimize fetching comments
 *
 *  Please post something nice on your website or blog, and link back to www.podiomail.com if you find this script useful.
 * ===================================================================== */

#require_once('podio-php-master/PodioAPI.php'); // include the php Podio Master Class
require_once('../../libs/podio-php-master/PodioAPI.php'); // include the php Podio Master Class

$start = time();

global $config;
$config_command_line = getopt("fvs:l:", array("backupTo:", "podioClientId:", "podioClientSecret:", "podioUser:", "podioPassword:", "help"));

//var_dump($config_command_line);
$usage = "\nUsage:\n\n" .
        "php podio-backup-php [-f] [-v] [-s PARAMETER_FILE] --backupTo BACKUP_FOLDER" .
        " --podioClientId PODIO_CLIENT_ID --podioClientSecret PODIO_CLIENT_SECRET " .
        "--podioUser PODIO_USERNAME --podioPassword PODIO_PASSWORD\n\n" .
        "php podio-backup-php [-f] [-v] -l PARAMETER_FILE [--backupTo BACKUP_FOLDER]" .
        " [--podioClientId PODIO_CLIENT_ID] [--podioClientSecret PODIO_CLIENT_SECRET] " .
        "[--podioUser PODIO_USERNAME] [--podioPassword PODIO_PASSWORD]\n\n" .
        "php podio-backup-php --help" .
        "\n\nArguments:\n" .
        "   -f\tdownload files from podio (rate limit of 250/h applies!)\n" .
        "   -v\tverbose\n" .
        "   -s\tstore parameters in PARAMETER_FILE\n" .
        "   -l\tload parameters from PARAMETER_FILE (parameters can be overwritten by command line parameters)\n" .
        " \n" .
        "BACKUP_FOLDER represents a (incremental) backup storage. " .
        "I.e. consecutive backups only downloads new files.\n";

if (array_key_exists("help", $config_command_line)) {
    echo $usage;
    return;
}

if (array_key_exists("l", $config_command_line)) {
    read_config($config_command_line['l']);
    $config = array_merge($config, $config_command_line);
} else {
    $config = $config_command_line;
}


if (array_key_exists("s", $config_command_line)) {
    write_config($config['s']);
}

$downloadFiles = array_key_exists("f", $config);

global $verbose;
$verbose = array_key_exists("v", $config);

check_backup_folder();

if (check_config()) {
    do_backup($downloadFiles);
} else {
    echo $usage;

    return -1;
}
$total_time = (time() - $start)/60;
echo "Duration: $total_time minutes.\n";

function check_backup_folder() {
    global $config;
    $folder = $config['backupTo'];
    if (!is_dir($folder)) {
        show_error("ERROR: create a backup folder called '" . $folder . "'");
        exit();
    }
    if (!is_writeable($folder)) {
        show_error("ERROR: please make sure that the " . $folder . " directory is writeable and has 777 permissions set");
        exit();
    }
    if (!file_exists($folder . "/.htaccess")) {
        file_put_contents($folder . "/.htaccess", "deny from all\n");
    }
}

// END check_backup_folder

function check_config() {
    global $config;
    Podio::$debug = true;
    try {
        Podio::setup($config['podioClientId'], $config['podioClientSecret']);
    } catch (PodioError $e) {
        show_error("ERROR: Podio Authentication Failed. Please check the API key and user details.");
        return false;
    }
    try {
        Podio::authenticate('password', array('username' => $config['podioUser'], 'password' => $config['podioPassword']));
    } catch (PodioError $e) {
        show_error("ERROR: Podio Authentication Failed. Please check the API key and user details.");
        return false;
    }
    if (!Podio::is_authenticated()) {
        show_error("ERROR: Podio Authentication Failed. Please check the API key and user details.");
        return false;
    }
    return true;
}

// END check_config

function read_config($filename) {
    global $config;
    $config['podioClientId'] = '';
    $config['podioClientSecret'] = '';
    $config['podioUser'] = '';
    $config['podioPassword'] = '';
    if (!file_exists($filename)) {
        write_config($filename);
    }
    #echo "filename: $filename\n";
    $data = file_get_contents($filename);
    #$config = unserialize($data);
    $config = array_merge($config, unserialize($data));
}

function write_config($filename) {
    global $config;
    $data = serialize($config);
    file_put_contents($filename, $data);
}

function show_error($error) {
    echo "ERROR: " . $error . "\n";
}

function show_success($message) {
    echo "Message: " . $message . "\n";
}

function do_backup($downloadFiles) {
    global $config, $verbose;
    if ($verbose)
        echo "Warning: This script may run for a LONG time\n";

    ///stores downloaded files ids to assure no file is downloaded twice.
    ///(Which is suspect since files are fetched for app AND item AND comment.)
    global $downloadedFilesIds;
    $downloadedFilesIds = array();

    $currentdir = getcwd();
    $timeStamp = date('YmdHi');
    $backupTo = $config['backupTo'];

    $path_base = $backupTo . '/' . $timeStamp;

    mkdir($path_base);

    $podioOrgs = PodioOrganization::get_all();

    foreach ($podioOrgs as $org) { //org_id
        if ($verbose)
            echo "Org: " . $org->name . "\n";
        $path_org = $path_base . '/' . fixDirName($org->name);
        mkdir($path_org);

        $contactsFile = '';
        $limit = 200;
        $iteration = 0;
        $completed = false;
        try {
            while (!$completed) {
                $filter = array("limit" => $limit, 'offset' => $limit * $iteration);
                $contacts = PodioContact::get_for_org($org->org_id, $filter);
//                echo "B\n";
                RateLimitChecker::preventTimeOut();
                $contactsFile .= contacts2text($contacts);
                $iteration++;
                if (sizeof($contacts) < $limit) {
                    $completed = true;
                }
            }
        } catch (PodioError $e) {
            $contactsFile .= "\n\nPodio Error:\n" . $e;
        }
        file_put_contents($path_org . '/' . 'podio_organization_contacts.txt', $contactsFile);

        foreach ($org->spaces as $space) { // space_id
            if ($verbose)
                echo "Space: " . $space->name . "\n";
            $path_space = $path_org . '/' . fixDirName($space->name);
            mkdir($path_space);

            $contactsFile = '';
            $limit = 200;
            $iteration = 0;
            $completed = false;
            try {
                while (!$completed) {
                    if ($space->name == "Employee Network")
                        $filter = array("limit" => $limit, 'offset' => $limit * $iteration, 'contact_type' => 'user');
                    else
                        $filter = array("limit" => $limit, 'offset' => $limit * $iteration, 'contact_type' => 'space');
                    $contacts = PodioContact::get_for_space($space->space_id, $filter);
//                    echo "C\n";
                    RateLimitChecker::preventTimeOut();
                    $contactsFile .= contacts2text($contacts);
                    $iteration++;
                    if (sizeof($contacts) < $limit) {
                        $completed = true;
                    }
                }
            } catch (PodioError $e) {
                $contactsFile .= "\n\nPodio Error:\n" . $e;
            }
            file_put_contents($path_space . '/' . 'podio_space_contacts.txt', $contactsFile);

            $spaceApps = array();
            try {
                $spaceApps = PodioApp::get_for_space($space->space_id);
            } catch (PodioError $e) {
                error_log($e);
            }

            foreach ($spaceApps as $app) {

                $path_app = $path_space . '/' . fixDirName($app->config['name']);

                if ($verbose)
                    echo "App: " . $app->config['name'] . "\n";

                mkdir($path_app);

                //$itemFile = PodioItem::xlsx( $app->app_id , array("limit" => 1000) );
                //file_put_contents($backupTo.'/'.$timeStamp.'/'.$org->name.'/'.$space->name.'/'.$app->config['name'].'.xlsx', $itemFile);

                $appFile = "";
                $limit = 200;
                $iteration = 0;
                $completed = false;

                $appFiles = array();

                $files_in_app_html = "<html><head><title>Files in app: " . $app->config['name'] . "</title></head><body>" .
                        "<table border=1><tr><th>name</th><th>link</th><th>context</th></tr>";
                try {
                    #TODO: why this filter here? what about other files?
                    $appFiles = PodioFile::get_for_app($app->app_id, array('attached_to' => 'item'));
//                    echo "D\n";
                    RateLimitChecker::preventTimeOut();
                } catch (PodioError $e) {
                    error_log($e);
                }

                try {
                    while (!$completed) {
                        $filter = array("limit" => $limit, 'offset' => $limit * $iteration);
                        $items = PodioItem::filter($app->app_id, $filter);
//                        echo "E\n";
                        RateLimitChecker::preventTimeOut();
                        if (is_array($items) && isset($items['items']) && is_array($items['items'])) {
                            foreach ($items['items'] as $item) {
                                if ($verbose)
                                    echo " - " . $item->title . "\n";

                                $itemFile = '--- ' . $item->title . ' ---' . "\n";
                                $itemFile .= 'Item ID: ' . $item->item_id . "\n";
                                foreach ($item->fields as $field) {
                                    $itemFile .= $field->label . ': ' . getFieldValue($field) . "\n";
                                }
                                $itemFile .= "\n";
                                $folder_item = fixDirName($item->item_id . '_' . $item->title);
                                $path_item = $path_app . '/' . $folder_item;
                                mkdir($path_item);

                                if ($downloadFiles) {
                                    foreach ($appFiles as $file) {
                                        if ($file->context['type'] == 'item' && $file->context['id'] == $item->item_id) {
                                            $link = downloadFileIfHostedAtPodio($path_item, $file);
                                            # $link is relative to $path_item (if downloaded):
                                            if (!(stripos($link, "http") == 0)) {
                                                $link = $folder_item . '/' . $link;//TODO
                                            }
                                            $files_in_app_html .= "<tr><td>" . $file->name . "</td><td><a href=\"" . $link . "\">" . $link . "</a></td><td>" . $file->context['title'] . "</td></tr>";
                                        }
                                    }
                                    #TODO FIXME: the following is probably not working:
                                    //(does it differntiant between podio and non-podio files??)
                                    // furthermore: this should only be duplicates to the above.
                                    if (isset($item->files) && sizeof($item->files) > 0) {
                                        foreach ($item->files as $file) {
                                            $link = downloadFileIfHostedAtPodio($path_item, $file);
                                            # $link is relative to $path_item (if downloaded):
                                            if (!(stripos($link, "http") == 0)) {
                                                $link = $folder_item . '/' . $link;
                                            }
                                            $files_in_app_html .= "<tr><td>" . $file->name . "</td><td><a href=\"" . $link . "\">" . $link . "</a></td><td>" . $file->context['title'] . "</td></tr>";
                                        }
                                    }
                                }



                                //TODO refactor to use less api calls:
                                $comments = PodioComment::get_for('item', $item->item_id);
//                                echo "F\n";
                                RateLimitChecker::preventTimeOut();
                                $commentsFile = "\n\nComments\n--------\n\n";
                                foreach ($comments as $comment) {
                                    $commentsFile .= 'by ' . $comment->created_by->name . ' on ' . $comment->created_on->format('Y-m-d at H:i:s') . "\n----------------------------------------\n" . $comment->value . "\n\n\n";
                                    if ($downloadFiles && isset($comment->files) && sizeof($comment->files) > 0) {
                                        foreach ($comment->files as $file) {
                                            $link = downloadFileIfHostedAtPodio($path_item, $file);
                                            # $link is relative to $path_item (if downloaded):
                                            if (!(stripos($link, "http") == 0)) {
                                                $link = $folder_item . '/' . $link;
                                            }
                                            $files_in_app_html .= "<tr><td>" . $file->name . "</td><td><a href=\"" . $link . "\">" . $link . "</a></td><td>" . $file->context['title'] . "</td></tr>";
                                        }
                                    }
                                }
                                file_put_contents($path_item . '/' . fixDirName($item->item_id . '-' . $item->title) . '.txt', $itemFile . $commentsFile);
                                $appFile .= $itemFile . "\n\n";
                            }
                            $iteration++;
                            if (sizeof($items['items']) < $limit) {
                                $completed = true;
                            }
                        } else {
                            $completed = true;
                        }
                    }
                } catch (PodioError $e) {
                    $appFile .= "\n\nPodio Error:\n" . $e;
                }
                file_put_contents($path_app . '/all_items_summary.txt', $appFile);
                $files_in_app_html .= "</table></body></html>";
                file_put_contents($path_app . "/files_in_app.html", $files_in_app_html);
            }
        }
    }

    if ($verbose)
        show_success("Backup Completed successfully to " . $currentdir . "/" . $backupTo . "/" . $timeStamp);
}

// given a podio field object -> returns a human readable format of the field value (or app reference as array (app_id, item_id)
function getFieldValue($field) {
    //$value = $field->values;
    $value = "";
    if ($field->type == "text" || $field->type == "number" || $field->type == "progress" || $field->type == "state") {
        if (is_array($field->values) && sizeof($field->values) > 0) {
            $value = $field->values[0]['value'];
            $value = str_ireplace('</p>', '</p><br>', $value);
            $value = preg_replace('/\<br(\s*)?\/?\>/i', "\n", $value);
            $value = strip_tags(br2nl($value));
            $value = str_replace('&nbsp;', ' ', $value);
            $value = str_replace('&amp;', '&', $value);
        }
    }
    if ($field->type == "category") {
        $value = fieldFindAll($field->values, 'text', '');
    }
    if ($field->type == "contact") {
        $value = fieldFindAll($field->values, 'name', '') . ' (email: ' . fieldFindAll($field->values, 'mail', '') . ')';
        $phone = fieldFindAll($field->values, 'phone', '');
        if ($phone != "")
            $value .= ' [phone: ' . $phone . ']';
    }
    if ($field->type == "embed") {
        $value = fieldFindAll($field->values, 'original_url', '');
    }
    if ($field->type == "app") {
        //$value = array('app_id' => fieldFindAll($field->values, 'app_id', ''), 'itemid' => fieldFindAll($field->values, 'item_id', ''));
        $value = 'App: ' . fieldFindAll($field->values, 'app_id', '') . ', Item: ' . fieldFindAll($field->values, 'item_id', '');
        //$value = $field->values;
    }
    return $value;
}

// used by getFieldValue - recurses array
function fieldFindAll($arr, $type, $val) {
    foreach ($arr as $k => $v) {
        if ($k === $type) {
            if ($type == "mail") {
                $v = implode(',', $v);
                if ($val == "")
                    $val = $v;
                else
                    $val .= ',' . $v;
            } elseif ($type == "phone") {
                $v = implode(',', $v);
                if ($val == "")
                    $val = $v;
                else
                    $val .= ',' . $v;
            } else {
                if ($val == "")
                    $val = $v;
                else
                    $val .= ',' . $v;
            }
        } elseif (is_array($v)) {
            $more = fieldFindAll($v, $type, '');
            if ($more != "") {
                if ($val == "")
                    $val = $more;
                else
                    $val .= ',' . $more;
            }
        }
    }
    return $val;
}

function contacts2text($contacts) {
    $contactsFile = "";
    foreach ($contacts as $contact) {
        $contactsFile .= '--- ' . $contact->name . ' ---' . "\n";
        if (isset($contact->profile_id))
            $contactsFile .= 'Profile ID: ' . $contact->profile_id . "\n";
        if (isset($contact->user_id))
            $contactsFile .= 'User ID: ' . $contact->user_id . "\n";
        if (isset($contact->name))
            $contactsFile .= 'Name: ' . $contact->name . "\n";
        if (isset($contact->location) && is_array($contact->location))
            $contactsFile .= 'Location: ' . implode(', ', $contact->location) . "\n";
        //if (isset($contact->about)) $contactsFile .= 'About: '.$contact->about."\n";
        if (isset($contact->mail) && is_array($contact->mail))
            $contactsFile .= 'Email Address: ' . implode(', ', $contact->mail) . "\n";
        if (isset($contact->phone) && is_array($contact->phone))
            $contactsFile .= 'Phone Number: ' . implode(', ', $contact->phone) . "\n";
        if (isset($contact->url) && is_array($contact->url))
            $contactsFile .= 'Website: ' . implode(', ', $contact->url) . "\n";
        if (isset($contact->title) && is_array($contact->title))
            $contactsFile .= 'Title: ' . implode(', ', $contact->title) . "\n";
        if (isset($contact->organization))
            $contactsFile .= 'Organization: ' . $contact->organization . "\n";
        if (isset($contact->address) && is_array($contact->address))
            $contactsFile .= 'Address: ' . implode(', ', $contact->address) . "\n";
        if (isset($contact->city))
            $contactsFile .= 'City: ' . $contact->city . "\n";
        if (isset($contact->state))
            $contactsFile .= 'State: ' . $contact->state . "\n";
        if (isset($contact->zip))
            $contactsFile .= 'Zip: ' . $contact->zip . "\n";
        if (isset($contact->country))
            $contactsFile .= 'Country: ' . $contact->country . "\n";
        if (isset($contact->birthdate))
            $contactsFile .= 'Birth Date: ' . $contact->birthdate->format('Y-m-d') . "\n";
        if (isset($contact->twitter))
            $contactsFile .= 'Twitter: ' . $contact->twitter . "\n";
        if (isset($contact->linkedin))
            $contactsFile .= 'LinkedIn: ' . $contact->linkedin . "\n";
        $contactsFile .= "\n\n";
    }
    return $contactsFile;
}

/**
 * TODO the current approach is just fast forward - could be made more sophisticated - e.g. \F6->oe..
 *
 * @param String $name
 * @return String valid dir/file name
 */
function fixDirName($name) {
    $name = preg_replace("/[^.a-zA-Z0-9_-]/", '', $name);

    $name = substr($name, 0, 20);
    return $name;
}

class RateLimitChecker {

    /**
     *
     * @var int estimate for remaining rate limited calls. 
     */
    private static $rate_limit_estimate = 250;

    /**
     * If getting close to hitting the rate limit, this method blocks until the
     * rate-limit count is resetted.
     * 
     * One might want to call this method before/after every call to the podio api.
     * 
     * This is a devensive approach as it supspects a rate limited call every time
     * the header "x-rate-limit-remaining" is not set. E.g. after PodioFile->get_raw().
     * 
     * @global boolean $verbose
     */
    public static function preventTimeOut() {
        global $verbose;

        //Note: after PodioFile->get_raw() Podio::rate_limit_remaining() leads to errors, since the header is not set.
        if (array_key_exists('x-rate-limit-remaining', Podio::$last_response->headers))
            $remaining = Podio::$last_response->headers['x-rate-limit-remaining'];
        else {
            if ($verbose)
                echo "DEBUG: estimating remaining..\n";
            $remaining = --self::$rate_limit_estimate;
        }


        if (array_key_exists('x-rate-limit-limit', Podio::$last_response->headers))
            $limit = Podio::$last_response->headers['x-rate-limit-limit'];
        else
            $limit = 250;

        if ($limit == 250)
            self::$rate_limit_estimate = $remaining;

        if ($verbose)
            echo "DEBUG: rate_limit=" . $limit . " remaining=" . $remaining . "\n";

        if ($remaining != 0 && $remaining < 10) { //could technically be '< 1' ..
            $minutes_till_reset = 60 - date('i');
            echo "sleeping $minutes_till_reset minutes to prevent rate limit violation.\n";
            sleep(60 * $minutes_till_reset);
            self::$rate_limit_estimate = 250;
        }
    }

}

function br2nl($string) {
    $s2 = preg_replace('/\<br(\s*)?\/?\>/i', "\n", $string);
    $s3 = preg_replace('/<p>/i', "\n", $s2);
    return $s3;
}

/**
 * If $file represents a file hosted by podio, it is downloaded to $folder.
 * In any case a link to the file is returned (relative to $folder).
 * $folder is assumed to be without trailing '/'.
 * (The problem with files not hosted by podio is that mostly you need a login,
 * i.e. you wont get the file but an html login page.)
 *
 * Uses the file $config['backupTo'].'/filestore.php' to assure no file is downloaded twice.
 * (over incremental backups)
 *
 * @param type $folder
 * @param type $file
 * @return String link relative to $folder or weblink
 */
function downloadFileIfHostedAtPodio($folder, $file) {
    global $config;

    $link = $file->link;
    if ($file->hosted_by == "podio") {
        //$filestore: Stores fileid->path_to_file_relative to backupTo-folder.
        //this is loaded on every call to assure files from interrupted runs are preserved.
        $filestore = array();
        $filenameFilestore = $config['backupTo'] . '/filestore.php';

        if (file_exists($filenameFilestore)) {
            $filestore = unserialize(file_get_contents($filenameFilestore));
        }
        if (array_key_exists($file->file_id, $filestore)) {

            echo "DEBUG: Detected duplicate download for file: $file->file_id\n";
            $existing_file = realpath($config['backupTo'] . '/' . $filestore[$file->file_id]);
            $link = getRelativePath(realpath($folder . '/'), $existing_file);
        } else {

            try {
                $filename = fixDirName($file->name);
                file_put_contents($folder . '/' . $filename, $file->get_raw());
//                echo "A\n";
                RateLimitChecker::preventTimeOut();
                $link = $filename;

                $filestore[$file->file_id] = getRelativePath(realpath($config['backupTo'] . '/'), realpath($folder . '/' . $filename));
                file_put_contents($filenameFilestore, serialize($filestore));
            } catch (PodioBadRequestError $e) {
                echo $e->body;   # Parsed JSON response from the API
                echo $e->status; # Status code of the response
                echo $e->url;    # URI of the API request
                // You normally want this one, a human readable error description
                echo $e->body['error_description'];
            }
        }
    } else {
        #echo "Warning: Not downloading file hosted by ".$file->hosted_by."\n";
    }
    return $link;
}

/**
 * taken from http://stackoverflow.com/questions/2637945/getting-relative-path-from-absolute-path-in-php
 */
function getRelativePath($from, $to) {
    // some compatibility fixes for Windows paths
    $from = is_dir($from) ? rtrim($from, '\/') . '/' : $from;
    $to = is_dir($to) ? rtrim($to, '\/') . '/' : $to;
    $from = str_replace('\\', '/', $from);
    $to = str_replace('\\', '/', $to);

    $from = explode('/', $from);
    $to = explode('/', $to);
    $relPath = $to;

    foreach ($from as $depth => $dir) {
        // find first non-matching dir
        if ($dir === $to[$depth]) {
            // ignore this directory
            array_shift($relPath);
        } else {
            // get number of remaining dirs to $from
            $remaining = count($from) - $depth;
            if ($remaining > 1) {
                // add traversals up to first matching dir
                $padLength = (count($relPath) + $remaining - 1) * -1;
                $relPath = array_pad($relPath, $padLength, '..');
                break;
            } else {
                $relPath[0] = './' . $relPath[0];
            }
        }
    }
    return implode('/', $relPath);
}

?>
