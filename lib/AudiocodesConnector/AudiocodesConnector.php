<?php

namespace Inbenta\AudiocodesConnector;

use \Exception;
use Inbenta\ChatbotConnector\ChatbotConnector;
use Inbenta\ChatbotConnector\Utils\SessionManager;
use Inbenta\AudiocodesConnector\ExternalAPI\AudiocodesAPIClient;
use Inbenta\AudiocodesConnector\ExternalDigester\AudiocodesDigester;

## Customized Chatbot API
use Inbenta\AudiocodesConnector\APIClientCustom\ChatbotAPIClientCustom as ChatbotAPIClient;

class AudiocodesConnector extends ChatbotConnector
{
    private $messages = [];

    public function __construct($appPath)
    {
        // Initialize and configure specific components for Audiocodes
        try {

            parent::__construct($appPath);

            $this->securityCheck();

            // Initialize base components
            $request = file_get_contents('php://input');
            $externalId = $this->getExternalIdFromRequest();

            //
            $conversationConf = array(
                'configuration' => $this->conf->get('conversation.default'),
                'userType'      => $this->conf->get('conversation.user_type'),
                'environment'   => $this->environment,
                'source'        => $this->conf->get('conversation.source')
            );

            $this->session = new SessionManager($externalId);

            //
            $this->botClient = new ChatbotAPIClient(
                $this->conf->get('api.key'),
                $this->conf->get('api.secret'),
                $this->session,
                $conversationConf
            );

            //
            $externalClient = new AudiocodesAPIClient(
                $this->conf->get('audiocodes.token'),
                $request
            ); // Instance Audiocodes client

            // Instance Audiocodes digester
            $externalDigester = new AudiocodesDigester(
                $this->lang,
                $this->conf->get('conversation.digester'),
                $this->botClient
            );

            $this->initComponents($externalClient, null, $externalDigester);
        } catch (Exception $e) {
            echo json_encode(["error" => $e->getMessage()]);
            die();
        }
    }

    /**
     * Get the external id from request
     *
     * @return String 
     */
    protected function getExternalIdFromRequest()
    {
        // Try to get user_id from a Audiocodes message request
        $externalId = AudiocodesAPIClient::buildExternalIdFromRequest();
        if (is_null($externalId)) {
            session_write_close();
            throw new Exception('Invalid request!');
        }
        return $externalId;
    }

    /**
     * Create a response with the endpoints Audiocodes needs
     *
     * @return String - json encoded 
     * @throws Exception
     */
    public function createConversation()
    {
        try {
            $data = json_decode(file_get_contents('php://input'));

            if (!$data) {
                $data = (object) $_GET;
            }
            if (!$data) {
                throw new Exception('Invalid request!');
            }

            $conversationId = $data->conversation;

            return [
                'activitiesURL'  => "conversation/{$conversationId}/activities",
                'refreshURL'     => "conversation/{$conversationId}/refresh",
                'disconnectURL'  => "conversation/{$conversationId}/disconnect",
                'expiresSeconds' => 120
            ];
        } catch (Exception $e) {
            return ["error" => $e->getMessage()];
        }
    }

    /**
     * Refresh message - to keep the conversation alive
     *
     * @return void
     */
    public function refresh()
    {
        return [
            'expiresSeconds' => 120
        ];
    }

    /**
     * Disconnect message - calls the 'sys-goodbye' message in Backstage
     *
     * @return void
     * @throws Exception
     */
    public function disconnect()
    {
        try {
            $data = json_decode(file_get_contents('php://input'));

            if (!$data) {
                throw new Exception('Invalid request!');
            }

            if ($data->reason) {
                $this->botClient->setUserInfo(['disconnect_reason' => $data->reason]);
            }

            return [];
        } catch (Exception $e) {
            return ["error" => $e->getMessage()];
        }
    }

    /**
     * Check if the request matches the security needs
     *
     * @return boolean
     * @throws Exception
     */
    protected function securityCheck()
    {
        $headers = getallheaders();

        if (!isset($headers['Authorization'])) {
            throw new Exception('Invalid request!');
        }

        $auth = explode(' ', $headers['Authorization']);

        $conf = $this->conf->get('audiocodes');

        // Check if both type and token matches
        if ($auth[0] !== $conf['type'] || $auth[1] !== $conf['token']) {
            throw new Exception('Invalid request!');
        }

        return true;
    }

    public function handleRequest()
    {
        try {
            $request = file_get_contents('php://input');
            // Translate the request into a ChatbotAPI request
            $externalRequest = $this->digester->digestToApi($request);
            // Check if it's needed to perform any action other than a standard user-bot interaction
            $nonBotResponse = $this->handleNonBotActions($externalRequest);
            if (!is_null($nonBotResponse)) {
                return $nonBotResponse;
            }
            // Handle standard bot actions
            $this->handleBotActions($externalRequest);
            // Send all messages
            return $this->sendMessages();
        } catch (Exception $e) {
            return ["error" => $e->getMessage()];
        }
    }

    protected function handleNonBotActions($digestedRequest)
    {
        // If user answered to an ask-to-escalate question, handle it
        if ($this->session->get('askingForEscalation', false)) {
            return $this->handleEscalation($digestedRequest);
        }
        return null;
    }

    /**
     * Print the message that Audiocodes can process
     */
    public function sendMessages()
    {
        return ['activities' => $this->messages];
    }

    protected function sendMessagesToExternal($messages)
    {
        // Digest the bot response into the external service format
        $digestedBotResponse = $this->digester->digestFromApi($messages, $this->session->get('lastUserQuestion'));
        $response = $this->externalClient->sendMessage($digestedBotResponse);
        $this->messages = array_merge($this->messages, $response);
    }

    /**
     * Overwritten
     */
    protected function handleEscalation($userAnswer = null)
    {
        if (!$this->session->get('askingForEscalation', false)) {
            if ($this->session->get('escalationType') == static::ESCALATION_DIRECT) {
                return $this->escalateToAgent();
            } else {
                // Ask the user if wants to escalate
                $this->session->set('askingForEscalation', true);
                $this->messages[] = $this->digester->buildEscalationMessage();
                return $this->sendMessages();
            }
        } else {
            // Handle user response to an escalation question
            $this->session->set('askingForEscalation', false);
            // Reset escalation counters
            $this->session->set('noResultsCount', 0);
            $this->session->set('negativeRatingCount', 0);

            $yesResponses = [
                strtolower($this->lang->translate('yes')),
                strtolower($this->lang->translate('yes1')),
                strtolower($this->lang->translate('yes2')),
                strtolower($this->lang->translate('yes3')),
                strtolower($this->lang->translate('yes4')),
                strtolower($this->lang->translate('yes5'))
            ];
            $match = implode("|", $yesResponses);

            //Confirm the escalation
            if (count($userAnswer) && isset($userAnswer[0]['message']) && preg_match('/' . $match . '/', strtolower($userAnswer[0]['message']))) {
                return $this->escalateToAgent();
            } else {
                //Any other response that is no "yes" (or similar) it's going to be considered as "no"
                $message = ["option" => strtolower($this->lang->translate('no'))];
                $botResponse = $this->sendMessageToBot($message);
                $this->sendMessagesToExternal($botResponse);
                return $this->sendMessages();
            }
        }
    }

    /**
     * Overwritten
     * Make the structure for Audiocodes transfer
     */
    protected function escalateToAgent()
    {
        $this->trackContactEvent("CHAT_ATTENDED");
        $this->session->delete('escalationType');
        $this->session->delete('escalationV2');

        $this->messages[] = $this->digester->buildEscalatedMessage();
        $this->messages[] = $this->externalClient->escalate($this->conf->get('chat.chat.address'));
        return $this->sendMessages();
    }
}
