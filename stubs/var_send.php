<?php

if (!function_exists('var_send')) {
    /**
     * Send variables to debug server for inspection
     * 
     * @param mixed ...$variables One or more variables to send to the debug server
     * @return bool Returns true on success, false on failure
     */
    function var_send(...$variables): bool
    {
        return false;
    }
}