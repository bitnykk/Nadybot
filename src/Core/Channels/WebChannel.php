<?php declare(strict_types=1);

namespace Nadybot\Core\Channels;

use Nadybot\Core\AOChatEvent;
use Nadybot\Core\EventManager;
use Nadybot\Core\MessageHub;
use Nadybot\Core\MessageReceiver;
use Nadybot\Core\Nadybot;
use Nadybot\Core\Routing\RoutableEvent;
use Nadybot\Core\Routing\Source;
use Nadybot\Modules\WEBSERVER_MODULE\WebChatConverter;
use Nadybot\Modules\WEBSOCKET_MODULE\WebsocketController;

class WebChannel implements MessageReceiver {
	/** @Inject */
	public Nadybot $chatBot;

	/** @Inject */
	public WebChatConverter $webChatConverter;

	/** @Inject */
	public EventManager $eventManager;

	/** @Inject */
	public WebsocketController $websocketController;

	/** @Inject */
	public MessageHub $messageHub;

	public function getChannelName(): string {
		return Source::WEB;
	}

	public function receive(RoutableEvent $event, string $destination): bool {
		if ($event->getType() !== $event::TYPE_MESSAGE) {
			return false;
		}
		$webEvent = new AOChatEvent();
		$webEvent->path = $this->webChatConverter->convertPath($event->getPath());
		$webEvent->color = $this->messageHub->getTextColor($event, $this->getChannelName());
		if (preg_match("/#([A-Fa-f0-9]{6})/", $webEvent->color, $matches)) {
			$webEvent->color = $matches[1];
		}
		$webEvent->channel = "web";
		$webEvent->sender = $event->getCharacter()->name;
		$webEvent->message = $this->webChatConverter->convertMessage($event->getData());
		$webEvent->type = "chat(web)";

		$this->eventManager->fireEvent($webEvent);

		return true;
	}
}
