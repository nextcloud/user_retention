<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2019 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\UserRetention\AppInfo;

use OCA\UserRetention\Listeners\UserChangedListener;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\User\Events\UserChangedEvent;
use OCP\User\Events\UserCreatedEvent;

class Application extends App implements IBootstrap {

	public function __construct(array $urlParams = []) {
		parent::__construct('user_retention', $urlParams);
	}

	#[\Override]
	public function register(IRegistrationContext $context): void {
		$context->registerEventListener(UserCreatedEvent::class, UserChangedListener::class);
		$context->registerEventListener(UserChangedEvent::class, UserChangedListener::class);
	}

	#[\Override]
	public function boot(IBootContext $context): void {
	}
}
