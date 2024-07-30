<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2019 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\UserRetention\AppInfo;

use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Util;

class Application extends App implements IBootstrap {

	public function __construct(array $urlParams = []) {
		parent::__construct('user_retention', $urlParams);
	}

	public function register(IRegistrationContext $context): void {
	}

	public function boot(IBootContext $context): void {
		Util::connectHook('OC_User', 'post_createUser', self::class, 'userCreated');
	}

	public static function userCreated($parameters): void {
		if (!isset($parameters['uid'])) {
			return;
		}

		\OC::$server->getConfig()->setUserValue(
			$parameters['uid'],
			'user_retention',
			'user_created_at',
			(string)time()
		);
	}
}
