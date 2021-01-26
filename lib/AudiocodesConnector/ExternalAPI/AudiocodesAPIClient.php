<?php

namespace Inbenta\AudiocodesConnector\ExternalAPI;

use Symfony\Component\HttpFoundation\Request;
use Ramsey\Uuid\Uuid;

class AudiocodesAPIClient
{
    /**
     * Get the incoming messages.
     *
     * @return array
     */
    public function messages(Request $request = null)
    {
        $request = $request ?: Request::createFromGlobals();
        $request = json_decode($request->getContent(), true);

        if ($request) {
            return $request['messages'];
        }
        return [];
    }

    /**
     *   Generates the external id used by HyperChat to identify one user as external.
     *   This external id will be used by HyperChat adapter to instance this client class from the external id
     */
    public function getExternalId()
    {
        return ""; //'ac-' . $this->getSender();
    }

    /**
     *   Retrieves the user id from the external ID generated by the getExternalId method    
     */
    public static function getIdFromExternalId($externalId)
    {
        $info = explode('-', $externalId);
        if (array_shift($info) == 'ac') {
            return end($info);
        }
        return null;
    }

    public static function buildExternalIdFromRequest()
    {
        $request = json_decode(file_get_contents('php://input'));

        if (!$request) {
            $request = (object)$_GET;
        }
        return isset($request->conversation) ? 'ac-' . $request->conversation : null;
    }

    /**
     * Overwritten, not necessary with Audiocodes
     */
    public function showBotTyping($show = true)
    {
        return true;
    }

    /**
     * Makes a message formatted with the Audiocodes notation
     */
    public function sendMessage($messages)
    {
        $messages = array_map(function ($message) {
            return array_merge($message, [
                'id' => Uuid::uuid4()->toString(),
                'timestamp' => date('Y-m-d\TH:i:s\0\Z')
            ]);
        }, $messages);

        return $messages;
    }

    /**
     * Establishes the Audiocodes sender (user) directly with the provided ID
     */
    public function setSenderFromId($senderID)
    {
        $this->sender = $senderID;
    }

    /**
     * Create the array needed for escalation
     * @param string $address
     * @return array
     */
    public function escalate(string $address)
    {
        return [
            'type' => 'event',
            'name' => 'handover',
            'id' => Uuid::uuid4()->toString(),
            'timestamp' => date('Y-m-d\TH:i:s\0\Z'),
            'activityParams' => [
                'handoverReason' => 'userRequest',
                'transferTarget' => $address
            ]
        ];
    }
}