<?php

namespace Inbenta\AudiocodesConnector\HyperChatAPI;

use Inbenta\ChatbotConnector\HyperChatAPI\HyperChatClient;
use Inbenta\AudiocodesConnector\ExternalAPI\AudiocodesAPIClient;

class AudiocodesHyperChatClient extends HyperChatClient
{

    private $session;

    public function __construct($config, $lang, $session, $appConf, $externalClient)
    {
        // parent::__construct($config, $lang, $session, $appConf, $externalClient);
        $this->session = $session;
    }

    //Instances an external client
    protected function instanceExternalClient($externalId, $appConf)
    {
        return null;
    }

    public static function buildExternalIdFromRequest($config)
    {
        $request = json_decode(file_get_contents('php://input'), true);

        $externalId = null;
        if (isset($request['trigger'])) {
            //Obtain user external id from the chat event
            $externalId = self::getExternalIdFromEvent($config, $request);
        }
        return $externalId;
    }
}
