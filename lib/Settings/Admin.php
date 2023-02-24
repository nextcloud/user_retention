<?php
declare(strict_types=1);
/**
 * @copyright Copyright (c) 2019 Joas Schilling <coding@schilljs.com>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
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

	/** @var IConfig */
	private $config;
	/** @var IInitialState */
	private $initialStateService;
	/** @var IGroupManager */
	private $groupManager;
	/** @var IAppManager */
	private $appManager;

	public function __construct(IConfig $config,
								IInitialState $initialStateService,
								IGroupManager $groupManager,
								IAppManager $appManager) {
		$this->config = $config;
		$this->initialStateService = $initialStateService;
		$this->groupManager = $groupManager;
		$this->appManager = $appManager;
	}

	public function getForm(): TemplateResponse {
		$keepUsersWithoutLogin = $this->config->getAppValue('user_retention', 'keep_users_without_login', 'yes') === 'yes';
		$this->initialStateService->provideInitialState('keep_users_without_login', $keepUsersWithoutLogin);
		$userDaysDisable = (int) $this->config->getAppValue('user_retention', 'user_days_disable', 0);
		$this->initialStateService->provideInitialState('user_days_disable', $userDaysDisable);
		$userDays = (int) $this->config->getAppValue('user_retention', 'user_days', 0);
		$this->initialStateService->provideInitialState('user_days', $userDays);
		$guestDaysDisable = (int) $this->config->getAppValue('user_retention', 'guest_days_disable', 0);
		$this->initialStateService->provideInitialState('guest_days_disable', $guestDaysDisable);
		$guestDays = (int) $this->config->getAppValue('user_retention', 'guest_days', 0);
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
