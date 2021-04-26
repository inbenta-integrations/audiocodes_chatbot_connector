<?php

namespace Inbenta\AudiocodesConnector\ExternalDigester;

use \Exception;
use Inbenta\ChatbotConnector\ExternalDigester\Channels\DigesterInterface;
use Inbenta\AudiocodesConnector\Helpers\Helper;
use Ramsey\Uuid\Uuid;

class AudiocodesDigester extends DigesterInterface
{
    protected $conf;
    protected $channel;
    protected $langManager;
    protected $session;
    protected $externalMessageTypes = array(
        'text',
        'event'
    );

    public function __construct($langManager, $conf, $session)
    {
        $this->langManager = $langManager;
        $this->channel = 'PhoneCall';
        $this->conf = $conf;
        $this->session = $session;
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

        $isMessaging = isset($request->activities[0]);
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
        if (is_null($request) || !isset($request->activities[0])) {
            return [];
        }

        $messages = $request->activities;
        $output = [];

        if (isset($messages[0]->type) && isset($messages[0]->name) && $messages[0]->type === 'event' && $messages[0]->name !== '') {
            $output = $this->checkOptions($messages[0]->name);
        }
        if (count($output) === 0) {
            foreach ($messages as $msg) {
                $msgType = $this->checkExternalMessageType($msg);
                $digester = 'digestFromAudiocodes' . ucfirst($msgType);
                $output[] = $this->$digester($msg);
            }
        }
        return $output;
    }

    /**
     * Check if the response has options
     * @param string $userMessage
     * @return array $output
     */
    protected function checkOptions(string $userMessage)
    {
        $output = [];
        if ($this->session->has('options')) {

            $lastUserQuestion = $this->session->get('lastUserQuestion');
            $options = $this->session->get('options');
            $this->session->delete('options');
            $this->session->delete('lastUserQuestion');

            $selectedOption = false;
            $selectedOptionText = "";
            $isListValues = false;
            $isPolar = false;
            $optionSelected = false;
            foreach ($options as $option) {
                if (isset($option->list_values)) {
                    $isListValues = true;
                } else if (isset($option->is_polar)) {
                    $isPolar = true;
                }
                if (Helper::removeAccentsToLower($userMessage) === Helper::removeAccentsToLower($this->langManager->translate($option->label))) {
                    if ($isListValues || (isset($option->attributes) && isset($option->attributes->DYNAMIC_REDIRECT) && $option->attributes->DYNAMIC_REDIRECT == 'escalationStart')) {
                        $selectedOptionText = $option->label;
                    } else {
                        $selectedOption = $option;
                        $lastUserQuestion = isset($option->title) && !$isPolar ? $option->title : $lastUserQuestion;
                    }
                    $optionSelected = true;
                    break;
                }
            }

            if (!$optionSelected) {
                if ($isListValues) { //Set again options for variable
                    if ($this->session->get('optionListValues', 0) < 1) { //Make sure only enters here just once
                        $this->session->set('options', $options);
                        $this->session->set('lastUserQuestion', $lastUserQuestion);
                        $this->session->set('optionListValues', 1);
                    } else {
                        $this->session->delete('options');
                        $this->session->delete('lastUserQuestion');
                        $this->session->delete('optionListValues');
                    }
                } else if ($isPolar) { //For polar, on wrong answer, goes for NO
                    $output[]['message'] = $this->langManager->translate('no');
                }
            }

            if ($selectedOption) {
                $output[]['option'] = $selectedOption->value;
            } else if ($selectedOptionText !== "") {
                $output[]['message'] = $selectedOptionText;
            }
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
            $output[] = $this->digestFromApiAnswer($messages[0], $lastUserQuestion);
            return $output;
        } else {
            throw new Exception("Unknown ChatbotAPI response: " . json_encode($request, true));
        }

        $output = [];
        foreach ($messages as $msg) {
            $msgType = $this->checkApiMessageType($msg);
            $digester = 'digestFromApi' . ucfirst($msgType);
            $digestedMessage = $this->$digester($msg, $lastUserQuestion);

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
        return isset($message->text->body) && is_string($message->text->body);
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

    protected function digestFromApiAnswer($message, $lastUserQuestion)
    {
        $messageResponse = [
            'type' => 'message',
            'text' => $this->cleanMessage($message->message)
        ];

        $exit = false;
        if (isset($message->attributes->DIRECT_CALL) && $message->attributes->DIRECT_CALL == "sys-goodbye") {
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
            $actionField = $this->handleMessageWithActionField($message, $lastUserQuestion);
            if (count($actionField) > 0) {
                if (!isset($messageResponse['multiple_output'])) {
                    $messageResponse = ['multiple_output' => [$messageResponse]];
                }
                foreach ($actionField as $element) {
                    $messageResponse['multiple_output'][] = $element;
                }
            }
        }

        return $messageResponse;
    }

    /**
     * Validate if the message has action fields
     * @param object $message
     * @param array $output
     * @return array $output
     */
    protected function handleMessageWithActionField(object $message, $lastUserQuestion)
    {
        $output = [];
        if (isset($message->actionField) && !empty($message->actionField)) {
            if ($message->actionField->fieldType === 'list') {
                $output = $this->handleMessageWithListValues($message->actionField->listValues, $lastUserQuestion);
            }
        }
        return $output;
    }

    /**
     * Set the options for message with list values
     * @param object $listValues
     * @return array $output
     */
    protected function handleMessageWithListValues(object $listValues, $lastUserQuestion)
    {
        $output = [];
        $output['multiple_output'] = [];
        $options = $listValues->values;
        foreach ($options as $index => &$option) {
            $option->list_values = true;
            $option->label = $option->option;
            $output['multiple_output'][] = [
                'type' => 'message',
                'text' => $option->label
            ];
            if ($index == 5) break;
        }

        if (count($output['multiple_output']) > 0) {
            $this->session->set('options', $options);
            $this->session->set('lastUserQuestion', $lastUserQuestion);
        }
        return $output;
    }

    protected function digestFromApiMultipleChoiceQuestion($message, $lastUserQuestion, $isPolar = false)
    {
        $output = [];
        $output['multiple_output'] = [[
            'type' => 'message',
            'text' => $this->cleanMessage($message->message)
        ]];

        $options = $message->options;
        foreach ($options as &$option) {
            if (isset($option->attributes->title) && !$isPolar) {
                $option->title = $option->attributes->title;
            } elseif ($isPolar) {
                $option->is_polar = true;
            }
            $output['multiple_output'][] = [
                'type' => 'message',
                'text' => $this->cleanMessage($option->label)
            ];
        }
        $this->session->set('options', $options);
        $this->session->set('lastUserQuestion', $lastUserQuestion);
        return $output;
    }

    protected function digestFromApiPolarQuestion($message, $lastUserQuestion)
    {
        return $this->digestFromApiMultipleChoiceQuestion($message, $lastUserQuestion, true);
    }

    protected function digestFromApiExtendedContentsAnswer($message, $lastUserQuestion)
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
        $message = str_replace("&nbsp;", " ", $message);
        $message = str_replace("\t", " ", $message);
        $message = str_replace("\n", " ", $message);
        return trim($message);
    }
}
