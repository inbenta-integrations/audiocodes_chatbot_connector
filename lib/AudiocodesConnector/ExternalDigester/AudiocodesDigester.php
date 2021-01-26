<?php

namespace Inbenta\AudiocodesConnector\ExternalDigester;

use \Exception;
use Inbenta\ChatbotConnector\ExternalDigester\Channels\DigesterInterface;
use Ramsey\Uuid\Uuid;

class AudiocodesDigester extends DigesterInterface
{
    protected $conf;
    protected $channel;
    protected $langManager;
    protected $externalMessageTypes = array(
        'text',
        'event'
    );

    public function __construct($langManager, $conf, $bot)
    {
        $this->langManager = $langManager;
        $this->channel = 'PhoneCall';
        $this->conf = $conf;
        $this->bot = $bot;
    }

    /**
     *	Returns the name of the channel
     */
    public function getChannel()
    {
        return $this->channel;
    }

    /**
     *	Checks if a request belongs to the digester channel
     */
    public static function checkRequest($request)
    {
        $request = json_decode($request);

        $isMessaging = isset($request->activities) && isset($request->activities[0]);
        if ($isMessaging && count($request->activities)) {
            return true;
        }
        return false;
    }

    /**
     *	Formats a channel request into an Inbenta Chatbot API request
     */
    public function digestToApi($request)
    {
        $request = json_decode($request);
        if (is_null($request) || !isset($request->activities) || !isset($request->activities[0])) {
            return [];
        }

        $messages = $request->activities;
        $output = [];

        foreach ($messages as $msg) {
            $msgType = $this->checkExternalMessageType($msg);
            $digester = 'digestFromAudiocodes' . ucfirst($msgType);
            $output[] = $this->$digester($msg);
        }
        return $output;
    }

    /**
     *	Formats an Inbenta Chatbot API response into a channel request
     */
    public function digestFromApi($request, $lastUserQuestion = '')
    {
        $messages = [];
        //Parse request messages
        if (isset($request->answers) && is_array($request->answers)) {
            $messages = $request->answers;
        } elseif ($this->checkApiMessageType($request) !== null) {
            $messages = array('answers' => $request);
        } elseif (isset($request->messages) && count($request->messages) > 0 && $this->hasTextMessage($messages[0])) {
            // If the first message contains text although it's an unknown message type, send the text to the user
            $output = [];
            $output[] = $this->digestFromApiAnswer($messages[0]);
            return $output;
        } else {
            throw new Exception("Unknown ChatbotAPI response: " . json_encode($request, true));
        }

        $output = [];
        foreach ($messages as $msg) {
            $msgType = $this->checkApiMessageType($msg);
            $digester = 'digestFromApi' . ucfirst($msgType);
            $digestedMessage = $this->$digester($msg);

            if (isset($digestedMessage["type"]) && $digestedMessage["type"] === "empty") {
                continue;
            }

            //Check if there are more than one responses from one incoming message
            if (isset($digestedMessage['multiple_output'])) {
                foreach ($digestedMessage['multiple_output'] as $message) {
                    $output[] = $message;
                }
            } else {
                $output[] = $digestedMessage;
            }
        }

        return $output;
    }

    /**
     *	Classifies the external message into one of the defined $externalMessageTypes
     */
    protected function checkExternalMessageType($message)
    {
        foreach ($this->externalMessageTypes as $type) {
            $checker = 'isAudiocodes' . ucfirst($type);

            if ($this->$checker($message)) {
                return $type;
            }
        }
    }

    /**
     *	Classifies the API message into one of the defined $apiMessageTypes
     */
    protected function checkApiMessageType($message)
    {
        foreach ($this->apiMessageTypes as $type) {
            $checker = 'isApi' . ucfirst($type);

            if ($this->$checker($message)) {
                return $type;
            }
        }
        return null;
    }

    /********************** EXTERNAL MESSAGE TYPE CHECKERS **********************/

    protected function isAudiocodesText($message)
    {
        return $message->type == 'message';
    }

    protected function isAudiocodesEvent($message)
    {
        return $message->type == 'event';
    }

    /********************** API MESSAGE TYPE CHECKERS **********************/

    protected function isApiAnswer($message)
    {
        return $message->type == 'answer';
    }

    protected function isApiPolarQuestion($message)
    {
        return $message->type == "polarQuestion";
    }

    protected function isApiMultipleChoiceQuestion($message)
    {
        return $message->type == "multipleChoiceQuestion";
    }

    protected function isApiExtendedContentsAnswer($message)
    {
        return $message->type == "extendedContentsAnswer";
    }

    protected function hasTextMessage($message)
    {
        return isset($message->text) && isset($message->text->body) && is_string($message->text->body);
    }


    /********************** AUDIOCODES MESSAGE DIGESTERS **********************/

    protected function digestFromAudiocodesText($message)
    {
        return ['message' => $message->text];
    }

    protected function digestFromAudiocodesEvent($message)
    {
        switch ($message->name) {
            case 'start':
                $response = ['message' => '', 'directCall' => 'sys-welcome'];
                break;
            case 'hangup':
                $response = ['message' => '', 'directCall' => 'sys-goodbye'];
                break;
            default:
                $response = ['message' => $message->name];
                break;
        }
        return $response;
    }

    /********************** CHATBOT API MESSAGE DIGESTERS **********************/

    protected function digestFromApiAnswer($message)
    {
        $messageResponse = [
            'type' => 'message',
            'text' => $this->cleanMessage($message->message)
        ];

        $exit = false;
        if (isset($message->attributes) && isset($message->attributes->DIRECT_CALL) && $message->attributes->DIRECT_CALL == "sys-goodbye") {
            $messageResponse = ['multiple_output' => [
                $messageResponse, // message
                $this->buildHangoutMessage() // disconnect event
            ]];
            $exit = true;
        } else if (trim($message->message) === "") {
            //Prevent emtpy messages
            $messageResponse = [
                'type' => 'empty'
            ];
        }

        if (isset($message->attributes->SIDEBUBBLE_TEXT) && trim($message->attributes->SIDEBUBBLE_TEXT) !== "" && !$exit) {
            $sidebubble = [
                'type' => 'message',
                'text' => $this->cleanMessage($message->attributes->SIDEBUBBLE_TEXT)
            ];
            if ($sidebubble['text'] !== '') {
                $messageResponse = ['multiple_output' => [
                    $messageResponse, // message
                    $sidebubble
                ]];
            }
        }

        if (isset($message->actionField) && !empty($message->actionField) && $message->actionField->fieldType !== 'default' && !$exit) {
            $actionField = $this->handleMessageWithActionField($message);
            if (count($actionField) > 0) {
                if (!isset($messageResponse['multiple_output'])) {
                    $messageResponse = ['multiple_output' => [$messageResponse]];
                }
                foreach($actionField as $element) {
                    $messageResponse['multiple_output'][] = $element;
                }
            }
        }

        return $messageResponse;
    }

    protected function digestFromApiMultipleChoiceQuestion($message)
    {
        return ['multiple_output' => array_map(function ($message) {
            return [
                'type' => 'message',
                'text' => $this->cleanMessage($message->message ?? $message->label ?? $message)
            ];
        }, array_merge([$message->message], $message->options))];
    }

    protected function digestFromApiPolarQuestion($message)
    {
        return [
            'type' => 'message',
            'text' => $this->cleanMessage($message->message)
        ];
    }

    protected function digestFromApiExtendedContentsAnswer($message)
    {
        return [
            'type' => 'message',
            'text' => $this->cleanMessage($message->message)
        ];
    }

    /********************** MISC **********************/
    public function buildEscalationMessage()
    {
        return [
            'type' => 'message',
            'text' => $this->langManager->translate('ask-to-escalate'),
            'id' => Uuid::uuid4()->toString(),
            'timestamp' => date('Y-m-d\TH:i:s\0\Z')
        ];
    }

    public function buildEscalatedMessage()
    {
        return [
            'type' => 'message',
            'text' => $this->langManager->translate('creating_chat'),
            'id' => Uuid::uuid4()->toString(),
            'timestamp' => date('Y-m-d\TH:i:s\0\Z')
        ];
    }

    public function buildInformationMessage()
    {
        return [
            'type' => 'message',
            'text' => $this->langManager->translate('ask-information')
        ];
    }

    public function buildHangoutMessage()
    {
        return [
            'type' => 'event',
            'name' => 'hangup'
        ];
    }

    public function buildContentRatingsMessage($ratingOptions, $rateCode)
    {
        return [];
    }
    public function buildUrlButtonMessage($message, $urlButton)
    {
        return [];
    }
    public function handleMessageWithImages($messages)
    {
        return [];
    }

    /**
     * Clean the message from html and other characters
     * @param string $message
     */
    public function cleanMessage(string $message)
    {
        $message = strip_tags($message);
        $message = str_replace("\t", " ", $message);
        $message = str_replace("\n", " ", $message);
        return trim($message);
    }

    /**
     * Validate if the message has action fields
     * @param object $message
     * @param array $output
     * @return array $output
     */
    protected function handleMessageWithActionField(object $message)
    {
        $output = [];
        if (isset($message->actionField) && !empty($message->actionField)) {
            if ($message->actionField->fieldType === 'list') {
                $output = $this->handleMessageWithListValues($message->actionField->listValues);
            }
        }
        return $output;
    }

    /**
     * Set the options for message with list values
     * @param object $listValues
     * @return array $output
     */
    protected function handleMessageWithListValues(object $listValues)
    {
        $output = [];
        foreach ($listValues->values as $index => $option) {
            $output[] = [
                'type' => 'message',
                'text' => $option->label[0]
            ];
            if ($index == 5) break;
        }
        return $output;
    }
}
