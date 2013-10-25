<?php

/**
 * Description of RelativePaths
 *
 * @author SCHRED
 */
class RelativePaths {

    /**
     * taken from http://stackoverflow.com/questions/2637945/getting-relative-path-from-absolute-path-in-php
     * 
     * @param String $from folder (absolute or relative)
     * @param String $to file (absolute or relative)
     * @return String relative path
     */
    static function getRelativePath($from, $to) {
        echo "\nDEBUG: from=$from\nto=$to\n";
        
        $from = self::realPathFix($from);
        $to = self::realPathFix($to);

        echo "\nDEBUG: from=$from\nto=$to\n";

        //==========original:
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
        $result = implode('/', $relPath);
        echo "result=$result\n";
        return $result;
    }

    /**
     * This is an extended realpath, that can handle paths to files that 
     * include intermediate '..' - e.g.: 'folder/../anotherfolder/file.ext'.
     * 
     * This should work on any os, but is only tested on windows.
     * 
     * @param String $path
     * @return string realpath (absolute)
     */
    private static function realPathFix($path) {
        $result = realpath($path);
        if ($result == "") {
            $fileseperator = "\\";
            if (strrpos($path, "/") > strrpos($path, "\\"))
                $fileseperator = "/";
            $indexOfFileName = strrpos($path, $fileseperator);
            $fileNameLength = strlen($path) - $indexOfFileName;
            echo "fs=$fileseperator, len=$fileNameLength, ind=$indexOfFileName\n";
            $folder_path = substr($path, 0, $indexOfFileName);
            $filename = substr($path, $indexOfFileName + 1);
            echo "folderpath=$folder_path, filename=$filename<<\n";
            $result = realpath($folder_path)
                    . $fileseperator . $filename;
        }
//            //remove ../ and ./
//            $fs = '[/\\]';
//            $to = preg_replace('/\.'.$fs.'/', '', $to);
//            $to = preg_replace('%('.$fs.')[^/\\]+'.$fs.'\.\.'.$fs.'%', '$1', $to);

        return $result;
    }

}
