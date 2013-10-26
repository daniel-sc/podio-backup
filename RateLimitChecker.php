<?php

/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

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

