<?php

namespace Inbenta\AudiocodesConnector\ExternalAPI;

use Ramsey\Uuid\Uuid;

class AudiocodesAPIClient
{
    /**
     * Create the external id
     */
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
