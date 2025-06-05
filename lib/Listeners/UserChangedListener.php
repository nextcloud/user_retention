<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\UserRetention\Listeners;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IConfig;
use OCP\User\Events\UserChangedEvent;

/**
 * @template-implements IEventListener<Event&UserChangedEvent>
 */
class UserChangedListener implements IEventListener {

	public function __construct(
		protected IConfig $config,
		protected ITimeFactory $timeFactory,
	) {
	}

	#[\Override]
	public function handle(Event $event): void {
		if (!($event instanceof UserChangedEvent)) {
			return;
		}

		if ($event->getFeature() === 'enabled') {
			$this->handleEnabledChange($event);
		}
	}

	private function handleEnabledChange(UserChangedEvent $event): void {
		$oldValue = $event->getOldValue();
		$newValue = $event->getValue();
		if ($oldValue === false && $newValue === true) {
			$this->config->setUserValue(
				$event->getUser()->getUID(),
				'user_retention',
				'user_reenabled_at',
				(string)$this->timeFactory->getTime()
			);
		}
	}
}
