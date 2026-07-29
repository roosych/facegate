<?php

namespace App\Services\RusGuard;

use Illuminate\Support\Facades\Log;
use SoapClient;
use SoapHeader;
use SoapVar;

abstract class RusGuardService
{
    protected SoapClient $client;

    public function __construct()
    {
        $url = config('rusguard.wsdl');
        $username = config('rusguard.login');
        $password = config('rusguard.password');

        $options = [
            'trace' => true,
            'exceptions' => true,
            'cache_wsdl' => WSDL_CACHE_NONE,
            'stream_context' => stream_context_create([
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                ],
            ]),
        ];

        $this->client = new SoapClient($url, $options);
        $this->setWsSecurityHeader($username, $password);
    }

    protected function setWsSecurityHeader(string $username, string $password): void
    {
        $wsse = '
            <wsse:Security xmlns:wsse="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd">
                <wsse:UsernameToken>
                    <wsse:Username>' . $username . '</wsse:Username>
                    <wsse:Password>' . $password . '</wsse:Password>
                </wsse:UsernameToken>
            </wsse:Security>
        ';

        $header = new SoapHeader(
            'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd',
            'Security',
            new SoapVar($wsse, XSD_ANYXML),
            true
        );

        $this->client->__setSoapHeaders([$header]);
    }

    protected function logError(): void
    {
        $errorMsg = "Request:\n" . $this->client->__getLastRequest() .
            "\n" . $this->client->__getLastRequestHeaders() .
            "\n\nResponse:\n" . $this->client->__getLastResponse() .
            "\n" . $this->client->__getLastResponseHeaders();

        Log::error("[RusGuard SOAP Error]\n" . $errorMsg);
    }
}
