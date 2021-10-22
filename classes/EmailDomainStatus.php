<?php

namespace Vanderbilt\CareerDevLibrary;

require_once(__DIR__ . '/ClassLoader.php');

class EmailDomainStatus
{
    public static function isSecure($domainName)
    {
        # from https://stackoverflow.com/questions/13402866/how-do-i-verify-a-tls-smtp-certificate-is-valid-in-php
        $errno = NULL;
        $errstr = NULL;
        $resource = fsockopen( "tcp://$domainName", 25, $errno, $errstr );

        stream_set_blocking($resource, true);

        stream_context_set_option($resource, 'ssl', 'verify_host', true);
        stream_context_set_option($resource, 'ssl', 'verify_peer', true);
        stream_context_set_option($resource, 'ssl', 'allow_self_signed', false);

        $secure = stream_socket_enable_crypto($resource, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        stream_set_blocking($resource, false);

        if ($errno || $errstr) {
            throw new \Exception("ERROR: $errno $errstr");
        }

        if ($secure) {
            return TRUE;
        } else {
            return FALSE;
        }
    }
}
