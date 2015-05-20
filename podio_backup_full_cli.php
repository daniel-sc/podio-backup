<?php

/* =====================================================================
 * podio_backup.php
 * This script backs up your entire Podio account.
 * [(c) 2013 Globi Web Solutions]
 * v1.3 2013-10-03 - Andreas Huttenrauch
 * v1.4 2013-10-18 - Daniel Schreiber
 * v2.0 2013-10-31 - Daniel Schreiber
 * 
 *
 *  https://github.com/daniel-sc/podio-backup 
 * 
 *  Please post something nice on your website or blog, and link back to www.podiomail.com if you find this script useful.
 * ===================================================================== */

require_once 'podio-php/PodioAPI.php'; // include the php Podio Master Class

require_once 'RelativePaths.php';
require_once 'RateLimitChecker.php';
require_once 'HumanFormat.php';
require_once 'PodioFetchAll.php';

define('FILE_GET_FOR_APP_LIMIT', 100);
define('ITEM_FILTER_LIMIT', 500);
define('ITEM_XLSX_LIMIT', 500);

Podio::$debug = true;

gc_enable();
ini_set("memory_limit", "200M");

global $start;
$start = time();

global $config;
$config_command_line = getopt("fvs:l:h", array("backupTo:", "podioClientId:", "podioClientSecret:", "podioUser:", "podioPassword:", "help"));

$usage = "\nUsage:\n\n" .
        "php podio_backup_full_cli [-f] [-v] [-s PARAMETER_FILE] --backupTo BACKUP_FOLDER" .
        " --podioClientId PODIO_CLIENT_ID --podioClientSecret PODIO_CLIENT_SECRET " .
        "--podioUser PODIO_USERNAME --podioPassword PODIO_PASSWORD\n\n" .
        "php podio_backup_full_cli [-f] [-v] -l PARAMETER_FILE [--backupTo BACKUP_FOLDER]" .
        " [--podioClientId PODIO_CLIENT_ID] [--podioClientSecret PODIO_CLIENT_SECRET] " .
        "[--podioUser PODIO_USERNAME] [--podioPassword PODIO_PASSWORD]\n\n" .
        "php podio_backup_full_cli --help" .
        "\n\nArguments:\n" .
        "   -f\tdownload files from podio (rate limit of 250/h applies!)\n" .
        "   -v\tverbose\n" .
        "   -s\tstore parameters in PARAMETER_FILE\n" .
        "   -l\tload parameters from PARAMETER_FILE (parameters can be overwritten by command line parameters)\n" .
        "   -h\tshow this message" .
        " \n" .
        "BACKUP_FOLDER represents a (incremental) backup storage. " .
        "I.e. consecutive backups only downloads new files.\n";

if (array_key_exists("help", $config_command_line) || array_key_exists("h", $config_command_line)) {
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

$variables = array('backupTo','podioClientId','podioClientSecret','podioUser','podioPassword');
foreach ($variables as $var) {
    if (!isset($config[$var])) {
        show_error("Variable $var needs to be defined");
        return -1;
    }
}

check_backup_folder();

if (check_config()) {
    do_backup($downloadFiles);
} else {
    echo $usage;

    return -1;
}
$total_time = (time() - $start) / 60;
echo "Duration: $total_time minutes.\n";

function check_backup_folder() {
    global $config;
    $folder = $config['backupTo'];
    if (!is_dir($folder)) {
        show_error("create a backup folder called '" . $folder . "'");
        exit();
    }
    if (!is_writeable($folder)) {
        show_error("please make sure that the " . $folder . " directory is writeable and has 777 permissions set");
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
        show_error("Podio Authentication Failed. Please check the API key and user details.");
        return false;
    }
    try {
        Podio::authenticate('password', array('username' => $config['podioUser'], 'password' => $config['podioPassword']));
    } catch (PodioError $e) {
        show_error("Podio Authentication Failed. Please check the API key and user details.");
        return false;
    }
    if (!Podio::is_authenticated()) {
        show_error("Podio Authentication Failed. Please check the API key and user details.");
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

/**
 * Backups $app to a subfolder in $path
 * 
 * @param type $app app to backup
 * @param type $path in this folder a subfolder for the app will be created
 */
function backup_app($app, $path, $downloadFiles) {
    $path_app = $path . '/' . fixDirName($app->config['name']);

    global $verbose;

    if ($verbose) {
        echo "App: " . $app->config['name'] . "\n";
        echo "debug: MEMORY: " . memory_get_usage(true) . " | " . memory_get_usage(false) . "\n";
    }

    mkdir($path_app);

    $appFile = "";

    $appFiles = array();

    $files_in_app_html = "<html><head><title>Files in app: " . $app->config['name'] . "</title></head><body>" .
            "<table border=1><tr><th>name</th><th>link</th><th>context</th></tr>";
    try {
        #$appFiles = PodioFile::get_for_app($app->app_id, array('attached_to' => 'item'));
        $appFiles = PodioFetchAll::iterateApiCall('PodioFile::get_for_app', $app->app_id, array(), FILE_GET_FOR_APP_LIMIT);
        #var_dump($appFiles);
        PodioFetchAll::flattenObjectsArray($appFiles, PodioFetchAll::podioElements(
                        array('file_id' => null, 'name' => null, 'link' => null, 'hosted_by' => null,
                            'context' => array('id' => NULL, 'type' => null, 'title' => null))));
        if ($verbose)
            echo "fetched information for " . sizeof($appFiles) . " files in app.\n";
    } catch (PodioError $e) {
        show_error($e);
    }


    try {
        $allitems = PodioFetchAll::iterateApiCall('PodioItem::filter', $app->app_id, array(), ITEM_FILTER_LIMIT, 'items');

        echo "app contains " . sizeof($allitems) . " items.\n";

        for ($i = 0; $i < sizeof($allitems); $i+=ITEM_XLSX_LIMIT) {
            $itemFile = PodioItem::xlsx($app->app_id, array("limit" => ITEM_XLSX_LIMIT, "offset" => $i));
            RateLimitChecker::preventTimeOut();
            file_put_contents($path_app . '/' . $app->config['name'] . '_' . $i . '.xlsx', $itemFile);
            unset($itemFile);
        }

        $before = time();
        gc_collect_cycles();
        echo "gc took : " . (time() - $before) . " seconds.\n";

        foreach ($allitems as $item) {

            if ($verbose)
                echo " - " . $item->title . "\n";

            $folder_item = fixDirName($item->item_id . '_' . $item->title);
            $path_item = $path_app . '/' . $folder_item;
            mkdir($path_item);

            unset($itemFile);

            $itemFile = HumanFormat::toHumanReadableString($item);

            if ($downloadFiles) {
                foreach ($appFiles as $file) {

                    if ($file->context['type'] == 'item' && $file->context['id'] == $item->item_id) {
                        $link = downloadFileIfHostedAtPodio($path_item, $file);
                        # $link is relative to $path_item (if downloaded):
                        if (!preg_match("/^http/i", $link)) {
                            $link = RelativePaths::getRelativePath($path_app, $path_item . '/' . $link);
                        }
                        $itemFile .= "File: $link\n";
                        $files_in_app_html .= "<tr><td>" . $file->name . "</td><td><a href=\"" . $link . "\">" . $link . "</a></td><td>" . $file->context['title'] . "</td></tr>";
                    }
                }
            }

            //TODO refactor to use less api calls: (not possible??!)
            if ($item->comment_count > 0) {
                #echo "comments.. (".$item->comment_count.")\n";
                $comments = PodioComment::get_for('item', $item->item_id);
                RateLimitChecker::preventTimeOut();

                $commentsFile = "\n\nComments\n--------\n\n";
                foreach ($comments as $comment) {
                    $commentsFile .= 'by ' . $comment->created_by->name . ' on ' . $comment->created_on->format('Y-m-d at H:i:s') . "\n----------------------------------------\n" . $comment->value . "\n\n\n";
                    if ($downloadFiles && isset($comment->files) && sizeof($comment->files) > 0) {
                        foreach ($comment->files as $file) {
                            $link = downloadFileIfHostedAtPodio($path_item, $file);
                            # $link is relative to $path_item (if downloaded):
                            if (!preg_match("/^http/i", $link)) {
                                $link = RelativePaths::getRelativePath($path_app, $path_item . '/' . $link);
                            }
                            $commentsFile .= "File: $link\n";
                            $files_in_app_html .= "<tr><td>" . $file->name . "</td><td><a href=\"" . $link . "\">" . $link . "</a></td><td>" . $file->context['title'] . "</td></tr>";
                        }
                    }
                }
            } else {
                $commentsFile = "\n\n[no comments]\n";
                #echo "no comments.. (".$item->comment_count.")\n";
            }
            file_put_contents($path_item . '/' . fixDirName($item->item_id . '-' . $item->title) . '.txt', $itemFile . $commentsFile);

            $appFile .= $itemFile . "\n\n";
        }

        //store non item/comment files:
        if ($verbose)
            echo "storing non item/comment files..\n";
        $app_files_folder = 'other_files';
        $path_app_files = $path_app . '/' . $app_files_folder;
        mkdir($path_app_files);
        $files_in_app_html .= "<tr><td><b>App Files</b></td><td><a href=$app_files_folder>" . $app_files_folder . "</a></td><td></td></tr>";
        foreach ($appFiles as $file) {
            if ($file->context['type'] != 'item' && $file->context['type'] != 'comment') {
                echo "debug: downloading non item/comment file: $file->name\n";
                $link = downloadFileIfHostedAtPodio($path_app_files, $file);
                # $link is relative to $path_item (if downloaded):
                if (!preg_match("/^http/i", $link)) {
                    $link = RelativePaths::getRelativePath($path_app, $path_item . '/' . $link);
                }
                $files_in_app_html .= "<tr><td>" . $file->name . "</td><td><a href=\"" . $link . "\">" . $link . "</a></td><td>" . $file->context['title'] . "</td></tr>";
            }
        }
    } catch (PodioError $e) {
        show_error($e);
        $appFile .= "\n\nPodio Error:\n" . $e;
    }
    file_put_contents($path_app . '/all_items_summary.txt', $appFile);
    $files_in_app_html .= "</table></body></html>";
    file_put_contents($path_app . "/files_in_app.html", $files_in_app_html);
    unset($appFile);
    unset($files_in_app_html);
}

function do_backup($downloadFiles) {
    global $config, $verbose;
    if ($verbose)
        echo "Warning: This script may run for a LONG time\n";

    $currentdir = getcwd();
    $timeStamp = date('Y-m-d_H-i');
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
        try {
            $contacts = PodioFetchAll::iterateApiCall('PodioContact::get_for_org', $org->org_id);
            $contactsFile .= contacts2text($contacts);
        } catch (PodioError $e) {
            show_error($e);
            $contactsFile .= "\n\nPodio Error:\n" . $e;
        }
        file_put_contents($path_org . '/' . 'podio_organization_contacts.txt', $contactsFile);

        foreach ($org->spaces as $space) { // space_id
            if ($verbose)
                echo "Space: " . $space->name . "\n";
            $path_space = $path_org . '/' . fixDirName($space->name);
            mkdir($path_space);

            $contactsFile = '';
            try {
                if ($space->name == "Employee Network")
                    $filter = array('contact_type' => 'user');
                else
                    $filter = array('contact_type' => 'space');
                $contacts = PodioFetchAll::iterateApiCall('PodioContact::get_for_space', $space->space_id, $filter);
                $contactsFile .= contacts2text($contacts);
            } catch (PodioError $e) {
                show_error($e);
                $contactsFile .= "\n\nPodio Error:\n" . $e;
            }
            file_put_contents($path_space . '/' . 'podio_space_contacts.txt', $contactsFile);

            $spaceApps = array();
            try {
                $spaceApps = PodioApp::get_for_space($space->space_id);
                RateLimitChecker::preventTimeOut();
            } catch (PodioError $e) {
                show_error($e);
            }

            foreach ($spaceApps as $app) {

                backup_app($app, $path_space, $downloadFiles);
            }
        }
    }

    if ($verbose)
        show_success("Backup Completed successfully to " . $currentdir . "/" . $backupTo . "/" . $timeStamp);
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

    $name = substr($name, 0, 25);
    return $name;
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
 * (over incremental backups). Creates a (sym)link to the original file in case.
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
        $filename = fixDirName($file->name);
        while (file_exists($folder . '/' . $filename))
            $filename = 'Z' . $filename;
        if (array_key_exists($file->file_id, $filestore)) {

            echo "DEBUG: Detected duplicate download for file: $file->file_id\n";
            $existing_file = realpath($config['backupTo'] . '/' . $filestore[$file->file_id]);
            $link = RelativePaths::getRelativePath($folder, $existing_file);
            link($existing_file, $folder . '/' . $filename);
            #symlink($existing_file, $folder.'/'.$filename);
        } else {

            try {
                file_put_contents($folder . '/' . $filename, $file->get_raw());
                RateLimitChecker::preventTimeOut();
                $link = $filename;

                $filestore[$file->file_id] = RelativePaths::getRelativePath($config['backupTo'], $folder . '/' . $filename);
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
    unset($filestore);
    return $link;
}

?>
