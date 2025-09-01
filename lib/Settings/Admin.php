<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2019 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\UserRetention\Settings;

use OCP\App\IAppManager;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IAppConfig;
use OCP\AppFramework\Services\IInitialState;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\Settings\ISettings;

class Admin implements ISettings {
	public function __construct(
		protected IAppConfig $appConfig,
		protected IInitialState $initialStateService,
		protected IGroupManager $groupManager,
		protected IAppManager $appManager,
	) {
	}

	#[\Override]
	public function getForm(): TemplateResponse {
		$keepUsersWithoutLogin = $this->appConfig->getAppValueBool('keep_users_without_login', true);
		$this->initialStateService->provideInitialState('keep_users_without_login', $keepUsersWithoutLogin);
		$userDaysDisable = $this->appConfig->getAppValueInt('user_days_disable');
		$this->initialStateService->provideInitialState('user_days_disable', max(0, $userDaysDisable));
		$userDays = $this->appConfig->getAppValueInt('user_days');
		$this->initialStateService->provideInitialState('user_days', max(0, $userDays));
		$guestDaysDisable = $this->appConfig->getAppValueInt('guest_days_disable');
		$this->initialStateService->provideInitialState('guest_days_disable', max(0, $guestDaysDisable));
		$guestDays = $this->appConfig->getAppValueInt('guest_days');
		$this->initialStateService->provideInitialState('guest_days', max(0, $guestDays));

		$this->initialStateService->provideInitialState('guests_app_installed', $this->appManager->isInstalled('guests'));
		$this->initialStateService->provideInitialState('ldap_backend_enabled', $this->appManager->isEnabledForUser('user_ldap'));

		$excludedGroups = $this->appConfig->getAppValueArray('excluded_groups', ['admin']);
		$groups = $this->getGroupDetailsArray($excludedGroups, 'excluded_groups');
		$this->initialStateService->provideInitialState('excluded_groups', $groups);

		return new TemplateResponse('user_retention', 'settings/admin');
	}

	#[\Override]
	public function getSection(): string {
		return 'server';
	}

	#[\Override]
	public function getPriority(): int {
		return 50;
	}

	protected function getGroupDetailsArray(array $gids, string $configKey): array {
		$groups = [];
		foreach ($gids as $gid) {
			$group = $this->groupManager->get($gid);
			if ($group instanceof IGroup) {
				$groups[] = [
					'id' => $group->getGID(),
					'displayname' => $group->getDisplayName(),
				];
			}
		}

		if (count($gids) !== count($groups)) {
			$gids = array_map(static function (array $group) {
				return $group['id'];
			}, $groups);
			$this->appConfig->setAppValueArray($configKey, $gids);
		}

		return $groups;
	}
}
