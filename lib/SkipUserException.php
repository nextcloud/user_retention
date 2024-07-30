<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2022 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\UserRetention;

class SkipUserException extends \RuntimeException {
	public function __construct(
		string $logMessage = '',
		protected array $logParameters = [],
	) {
		parent::__construct($logMessage);
	}

	public function getLogParameters(): array {
		return $this->logParameters;
	}
}
