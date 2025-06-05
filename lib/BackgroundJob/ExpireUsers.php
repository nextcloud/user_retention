<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2022 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\UserRetention\BackgroundJob;

use OCA\UserRetention\Service\RetentionService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

class ExpireUsers extends TimedJob {

	public function __construct(
		ITimeFactory $time,
		protected RetentionService $service,
	) {
		parent::__construct($time);

		// Every day
		$this->setInterval(60 * 60 * 24);
	}

	#[\Override]
	protected function run($argument): void {
		$this->service->runCron();
	}
}
