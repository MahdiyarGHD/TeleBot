<?php

namespace TeleBot;

/**
 * @method setWebhook(array $parameters)
 * @method getMe()
 * @method sendMessage(array $parameters)
 * @method forwardMessage(array $parameters)
 * @method sendPhoto(array $parameters)
 * @method sendAudio(array $parameters)
 * @method sendDocument(array $parameters)
 * @method sendVideo(array $parameters)
 * @method sendAnimation(array $parameters)
 * @method sendVoice(array $parameters)
 * @method sendLocation(array $parameters)
 * @method sendContact(array $parameters)
 * @method sendPoll(array $parameters)
 * @method sendChatAction(array $parameters)
 * @method getUserProfilePhotos(array $parameters)
 * @method getFile(array $parameters)
 * @method kickChatMember(array $parameters)
 * @method unbanChatMember(array $parameters)
 * @method restrictChatMember(array $parameters)
 * @method promoteChatMember(array $parameters)
 * @method exportChatInviteLink(array $parameters)
 * @method setChatPhoto(array $parameters)
 * @method deleteChatPhoto(array $parameters)
 * @method setChatTitle(array $parameters)
 * @method pinChatMessage(array $parameters)
 * @method unpinChatMessage(array $parameters)
 * @method leaveChat(array $parameters)
 * @method getChat(array $parameters)
 * @method getChatAdministrators(array $parameters)
 * @method getChatMembersCount(array $parameters)
 * @method getChatMember(array $parameters)
 * @method answerCallbackQuery(array $parameters)
 * @method editMessageText(array $parameters)
 * @method editMessageCaption(array $parameters)
 * @method editMessageMedia(array $parameters)
 * @method deleteMessage(array $parameters)
 * @method answerInlineQuery(array $parameters)
 * @property message
 * @property chat
 * @property user
 */

use TeleBot\Util\Http;
use TeleBot\Exceptions\TeleBotException;
use TeleBot\Traits\Extendable;

class TeleBot
{
    use Extendable;

    private $token;

    public $update;

    private array $defaultParameters = [];

    /**
     * Create a new TeleBot instance.
     * 
     * @param string $token The generated token by [@BotFather](https://t.me/BotFather), looks something like `123456:ABC-DEF1234ghIkl-zyx57W2v1u123ew11`.
     * @return void
     */
    public function __construct($token)
    {
        $this->token = $token;
        $this->update = $this->getUpdate();
    }

    /**
     * Get the update object from the incoming request.
     * 
     * @return object
     */
    public function getUpdate()
    {
        return json_decode(file_get_contents('php://input'));
    }

    /**
     * @param string $command
     * @param callable $closure
     * @param bool $thenDie Do you want to terminate the script after executing the command?
     */
    public function listen($command, $closure, $thenDie = true)
    {
        $text = $this->hasCallbackQuery() ?
            $this->update->callback_query->data :
            $this->update->message->text;

        if (is_null($text)) {
            return;
        }

        if ($text == $command) {
            call_user_func($closure);
            return $this->dieIf($thenDie);
        }
        
        if ($this->isMatch($text, $command)) {
            preg_match($this->createRegexPattern($command), $text, $params);
            $params = array_slice($params, 1);
            call_user_func_array($closure, $params);
            return $this->dieIf($thenDie);
        }
    }

    /**
     * Terminate the script if `$condition` is true.
     */
    private function dieIf(bool $condition)
    {
        if ($condition) {
            die();
        }
    }

    /**
     * Check if the update object has a `callback_query` field.
     * 
     * @return bool
     */
    public function hasCallbackQuery(): bool
    {
        return isset($this->update->callback_query);
    }

    /**
     * Check if `$text` matches the `$command` pattern.
     * 
     * @return bool
     */
    private function isMatch(string $text, string $command): bool
    {
        $pattern = $this->createRegexPattern($command);

        return preg_match($pattern, $text) === 1;
    }

    /**
     * Translate our specifiers to regex format.
     * 
     * @return string
     */
    private function createRegexPattern($command): string
    {
        $map = ['%d' => '(\d+)', '%s' => '(\S+)', '%c' => '(\S)', '%p' => '(.*)'];
        $pattern = '/^' . str_replace(array_keys($map), array_values($map), str_replace('/', '\/', $command)) . '$/';

        return $pattern;
    }

    /**
     * Set default parameters for telegram methods.
     * 
     * @param string|string[] $method Method name, an array of method names or '*'.
     * @param array $params
     */
    public function setDefaults(string|array $method, array $params): void
    {
        if (is_string($method)) {
            $this->defaultParameters[$method] = $params;
        }

        if (is_array($method)) {
            foreach ($method as $singleMethod) {
                $this->setDefaults($singleMethod, $params);
            }
        }
    }

    /**
     * Get default parameters for a method.
     * 
     * @param string $method
     * @return array
     */
    private function getDefaults(string $method): array
    {
        $defaultParameters = $this->defaultParameters[$method] ?? [];

        if (isset($this->defaultParameters['*'])) {
            $defaultParameters += $this->defaultParameters['*'];
        }

        return $defaultParameters;
    }

    /**
     * Dynamically handle calls into the TeleBot instance.
     * 
     * @param string $name
     * @param array $params
     * @return mixed
     * 
     * @throws TeleBotException
     */
    public function __call($name, $params)
    {
        if (static::hasExtension($name)) {
            $extension = static::$extensions[$name];
            $extension = $extension->bindTo($this, static::class);

            return $extension(...$params);
        }

        $params = is_array($params)
            ? $params[0] + $this->getDefaults($name)
            : $this->getDefaults($name);

        $httpResponse = Http::post("https://api.telegram.org/bot{$this->token}/{$name}", $params);

        if (!$httpResponse->ok) {
            throw new TeleBotException($httpResponse->description);
        }

        return $httpResponse->result;
    }

    /**
     * Dynamically access the update object fields.
     * 
     * @param string $name
     * @return mixed
     * 
     * @throws TeleBotException
     */
    public function __get($name)
    {
        $message = $this->hasCallbackQuery() ? $this->update->callback_query->message : $this->update->message;

        if ($name === 'message') {
            return $message;
        }

        if ($name === 'chat') {
            return $message->chat;
        }

        if ($name === 'user') {
            return $this->hasCallbackQuery() ? $this->update->callback_query->from : $message->from;
        }

        if (property_exists($this->update, $name)) {
            return $this->update->$name;
        }

        throw new TeleBotException("Property $name doesn't exist!");
    }
}
