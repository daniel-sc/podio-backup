<?php

#require_once '../podio-php/PodioAPI.php'; // include the php Podio Master Class

class RateLimitChecker {

    /**
     *
     * @var int estimate for remaining rate limited calls.
     */
    private static $rate_limit_lower = 250;
    private static $no_of_estimations = 0;

    /**
     * Go into wait state when remaining rate limit calls are less than $stop_limit.
     * This should _not_ be 1, as due to estimations the remaining might be incorrect.
     * 
     * @var int 
     */
    public static $stop_limit = 20;

    /**
     *
     * @var int remaining non-rate limited calls. 
     */
    public static $rate_limit = 5000;

    /**
     * This is the time of the first call for the current rate limit count.
     * @var type 
     */
    public static $start;

    /**
     * If neccessary performes an dummy call and calls self::prefentTimeOut() (recursively).
     * Assumes self::$no_of_estimations to be set correctly.
     * 
     * @param int $remaining
     * @return boolean true, if was considered neccessary and was performed.
     */
    private static function dummyCallNeccessary($remaining) {
        global $verbose;
        //the worst case scenario is: for every estimate, 2 calls were conducted (authentication..)
        // https://help.podio.com/entries/22140932-Confused-by-rate-limit
        if ($remaining - self::$no_of_estimations < self::$stop_limit) {
            if (isset($verbose) && $verbose)
                echo "performing dummy request for retriving current rate limit..\n";
            PodioSearchResult::search($attributes = array('query' => 'dummy_query_for_getting_remaining_rate_limit_after_file_get_raw', 'limit' => 1));
            self::preventTimeOut();
            return true;
        }
        return false;
    }

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
        if (!isset(self::$start)) {
            echo "init RateLimitChecker..\n";
            self::$start = time();
        }

        //Note: after PodioFile->get_raw() Podio::rate_limit_remaining() leads to errors, since the header is not set.
        if (array_key_exists('x-rate-limit-remaining', Podio::$last_response->headers)) {
            $remaining = Podio::$last_response->headers['x-rate-limit-remaining'];
            $limit = Podio::$last_response->headers['x-rate-limit-limit'];
            if ($limit == 250) {
                self::$no_of_estimations = 0;
            }
        } else {
            $remaining = --self::$rate_limit_lower;
            self::$no_of_estimations++;
            $limit = 250;
        }

        if ($limit == 250) {
            self::$rate_limit_lower = $remaining;
        } else {
            self::$rate_limit = $remaining;
        }

        if (isset($verbose) && $verbose) {
            echo "DEBUG: rate_limit=$limit remaining=$remaining "
            . (array_key_exists('x-rate-limit-remaining', Podio::$last_response->headers) ? "" : "(estimate)") . "\n";
        }

        if ($remaining < self::$stop_limit) {
            echo "running since " . date('H:i', self::$start) . "\n";
            $minutes_till_reset = 60 - ((time() - self::$start) / 60) + 1;
            echo "sleeping $minutes_till_reset minutes to prevent rate limit violation.\n";
            sleep(60 * $minutes_till_reset);
            self::$rate_limit_lower = 250;
            self::$rate_limit = 5000;
            self::$start = time();
        } else {
            if (self::dummyCallNeccessary($remaining)) {
                return;
            }
        }
    }

}
