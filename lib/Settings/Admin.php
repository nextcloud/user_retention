<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2019 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\UserRetention\Settings;

use OCP\App\IAppManager;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\Settings\ISettings;

class Admin implements ISettings {
	public function __construct(
		protected IConfig $config,
		protected IInitialState $initialStateService,
		protected IGroupManager $groupManager,
		protected IAppManager $appManager,
	) {
	}

	public function getForm(): TemplateResponse {
		$keepUsersWithoutLogin = $this->config->getAppValue('user_retention', 'keep_users_without_login', 'yes') === 'yes';
		$this->initialStateService->provideInitialState('keep_users_without_login', $keepUsersWithoutLogin);
		$userDaysDisable = (int) $this->config->getAppValue('user_retention', 'user_days_disable', '0');
		$this->initialStateService->provideInitialState('user_days_disable', $userDaysDisable);
		$userDays = (int) $this->config->getAppValue('user_retention', 'user_days', '0');
		$this->initialStateService->provideInitialState('user_days', $userDays);
		$guestDaysDisable = (int) $this->config->getAppValue('user_retention', 'guest_days_disable', '0');
		$this->initialStateService->provideInitialState('guest_days_disable', $guestDaysDisable);
		$guestDays = (int) $this->config->getAppValue('user_retention', 'guest_days', '0');
		$this->initialStateService->provideInitialState('guest_days', $guestDays);

		$this->initialStateService->provideInitialState('guests_app_installed', $this->appManager->isInstalled('guests'));
		$this->initialStateService->provideInitialState('ldap_backend_enabled', $this->appManager->isEnabledForUser('user_ldap'));

		$excludedGroups = $this->config->getAppValue('user_retention', 'excluded_groups', '["admin"]');
		$excludedGroups = json_decode($excludedGroups, true);
		$excludedGroups = \is_array($excludedGroups) ? $excludedGroups : [];
		$groups = $this->getGroupDetailsArray($excludedGroups, 'excluded_groups');
		$this->initialStateService->provideInitialState('excluded_groups', $groups);

		return new TemplateResponse('user_retention', 'settings/admin');
	}

	public function getSection(): string {
		return 'server';
	}

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
			$this->config->setAppValue('user_retention', $configKey, json_encode($gids));
		}

		return $groups;
	}
}
