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

namespace OCA\UserRetention\AppInfo;


use OCP\AppFramework\App;
use OCP\Util;

class Application extends App {

	public function __construct(array $urlParams = []) {
		parent::__construct('user_retention', $urlParams);
	}

	public function register(): void {
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
			time()
		);
	}
}
