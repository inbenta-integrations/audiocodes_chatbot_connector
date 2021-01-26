<?php

namespace Inbenta\AudiocodesConnector\APIClientCustom;

use Inbenta\ChatbotConnector\ChatbotAPI\ChatbotAPIClient;
use \Exception;

class ChatbotAPIClientCustom extends ChatbotAPIClient
{

    public function sendMessage($message)
    {
        // Update access token if needed
        $this->updateAccessToken();
        //Update sessionToken if needed
        $this->updateSessionToken();

        // Prepare the message
        $string = json_encode($message);
        $params = array("payload" => $string);

        // Headers
        $headers = array(
            "x-inbenta-key:" . $this->key,
            "Authorization: Bearer " . $this->accessToken,
            "x-inbenta-session: Bearer " . $this->sessionToken,
            "Content-Type: application/json,charset=UTF-8",
            "Content-Length: " . strlen($string)
        );

        /* The webhooks that reuses same session_id causes session concurreny, which then causes a timeout
         * Forcing session to close and open again seems to avoid this issue
         */
        $sessionId = session_id();
        session_write_close();

        $response = $this->call("/v1/conversation/message", "POST", $headers, $params);

        session_id($sessionId);
        session_start();

        if (isset($response->errors)) {
            throw new Exception($response->errors[0]->message, $response->errors[0]->code);
        } else {
            return $response;
        }
    }

    /**
     * Records data in userInfo
     */
    public function setUserInfo($data)
    {
        // Update access token if needed
        $this->updateAccessToken();
        //Update sessionToken if needed
        $this->updateSessionToken();

        // Headers
        $headers  = array(
            "x-inbenta-key:" . $this->key,
            "Authorization: Bearer " . $this->accessToken,
            "x-inbenta-session: Bearer " . $this->sessionToken,
            "Content-Type: application/json,charset=UTF-8",
        );

        $params = json_encode(['data' => $data]);

        $response = $this->call("/v1/tracking/session/user", "POST", $headers, ['payload' => $params]);

        if (isset($response->errors)) {
            throw new Exception($response->errors[0]->message, $response->errors[0]->code);
        } else {
            return $response;
        }
    }
}
