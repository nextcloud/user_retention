<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\UserRetention\Tests;

use OCA\UserRetention\Listeners\UserChangedListener;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IUser;
use OCP\User\Events\UserChangedEvent;
use OCP\User\Events\UserCreatedEvent;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Test\TestCase;

class UserChangedListenerTest extends TestCase {
	private MockObject&LoggerInterface $logger;
	private MockObject&IConfig $config;
	private MockObject&ITimeFactory $timeFactory;

	protected function setUp(): void {
		parent::setUp();

		$this->logger = $this->createMock(LoggerInterface::class);
		$this->config = $this->createMock(IConfig::class);
		$this->timeFactory = $this->createMock(ITimeFactory::class);
	}

	public function testUserEnabledShouldTriggerUserReenabledAtUpdate() {
		$time = time();
		$uid = '100';
		$user = $this->createMock(IUser::class);
		$user->expects($this->once())->method('getUID')->willReturn($uid);
		$this->timeFactory->expects($this->once())->method('getTime')->willReturn($time);
		$this->config->expects($this->once())->method('setUserValue')->with($uid, 'user_retention', 'user_reenabled_at', $time);

		$event = $this->createMock(UserChangedEvent::class);
		$event->expects($this->once())->method('getUser')->willReturn($user);
		$event->expects($this->once())->method('getFeature')->willReturn('enabled');
		$event->expects($this->once())->method('getOldValue')->willReturn(false);
		$event->expects($this->once())->method('getValue')->willReturn(true);

		$listener = new UserChangedListener($this->logger, $this->config, $this->timeFactory);
		$listener->handle($event);
	}

	public function testHandleShouldNotHandleOtherEvents() {
		$event = $this->createMock(UserCreatedEvent::class);
		$this->config->expects($this->never())->method('setUserValue');

		$listener = new UserChangedListener($this->logger, $this->config, $this->timeFactory);
		$listener->handle($event);
	}

	public function testHandleShouldOnlyHandleEnabledFeature() {
		$event = $this->createMock(UserChangedEvent::class);
		$event->expects($this->once())->method('getFeature')->willReturn('otherFeature');
		$this->config->expects($this->never())->method('setUserValue');

		$listener = new UserChangedListener($this->logger, $this->config, $this->timeFactory);
		$listener->handle($event);
	}

	public function testDisabledUserShouldNotTriggerUserReenabledAtUpdate() {
		$this->config->expects($this->never())->method('setUserValue');

		$event = $this->createMock(UserChangedEvent::class);
		$event->expects($this->once())->method('getFeature')->willReturn('enabled');
		$event->expects($this->once())->method('getOldValue')->willReturn(true);
		$event->expects($this->once())->method('getValue')->willReturn(false);

		$listener = new UserChangedListener($this->logger, $this->config, $this->timeFactory);
		$listener->handle($event);
	}

}
