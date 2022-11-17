<?php
/**
 * @copyright Copyright (c) 2022 Joas Schilling <coding@schilljs.com>
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
namespace OCA\UserRetention\Tests;

use OCA\UserRetention\BackgroundJob\ExpireUsers;
use OCA\UserRetention\Service\RetentionService;
use OCA\UserRetention\SkipUserException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IServerContainer;
use OCP\IUser;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use OCP\Mail\IMailer;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Test\TestCase;

class RetentionServiceTest extends TestCase {
	/** @var MockObject|IConfig */
	protected $config;
	/** @var MockObject|IUserManager */
	protected $userManager;
	/** @var MockObject|IGroupManager */
	protected $groupManager;
	/** @var MockObject|ITimeFactory */
	protected $timeFactory;
	/** @var MockObject|IServerContainer */
	protected $container;
	/** @var MockObject|IMailer */
	protected $mailer;
	/** @var MockObject|IFactory */
	protected $l10nFactory;
	/** @var MockObject|LoggerInterface */
	protected $logger;

	protected function setUp(): void {
		parent::setUp();

		$this->config = $this->createMock(IConfig::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->timeFactory = $this->createMock(ITimeFactory::class);
		$this->container = $this->createMock(IServerContainer::class);
		$this->mailer = $this->createMock(IMailer::class);
		$this->l10nFactory = $this->createMock(IFactory::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}

	/**
	 * @param string[] $methods
	 * @return RetentionService|MockObject
	 */
	protected function createService(array $methods = []) {
		if (empty($methods)) {
			return new RetentionService(
				$this->config,
				$this->userManager,
				$this->groupManager,
				$this->timeFactory,
				$this->container,
				$this->mailer,
				$this->l10nFactory,
				$this->logger
			);
		}

		$mock = $this->getMockBuilder(RetentionService::class);
		$mock->setConstructorArgs([
			$this->config,
			$this->userManager,
			$this->groupManager,
			$this->timeFactory,
			$this->container,
			$this->mailer,
			$this->l10nFactory,
			$this->logger,
		]);
		$mock->onlyMethods($methods);
		return $mock->getMock();
	}

	public function dataShouldPerformActionOnUser(): array {
		return [
			// No action at all
			[true, 0, 0, 0, null, true],
			[false, 0, 0, 0, null, false, 0],

			// Deletion part without skip older
			// Everything is old without max age
			[true, 9, 9, 9, null, false, 9],
			[true, 99_999, 9, 9, null, false, 99_999],
			[true, 9, 99_999, 9, null, false, 99_999],
			[true, 9, 9, 99_999, null, false, 99_999],

			// One is new enough
			[true, 100_001, 9, 9, null, true],
			[true, 100_001, 99_999, 99_999, null, true],
			[true, 9, 100_001, 9, null, true],
			[true, 99_999, 100_001, 99_999, null, true],
			[true, 9, 9, 100_001, null, true],
			[true, 99_999, 99_999, 100_001, null, true],

			// Reminder part with skip older
			// Everything is old but one is newer than skip
			[true, 9, 9, 9, 100, true],
			[true, 300, 9, 9, 100, false, 300],
			[true, 9, 300, 9, 100, false, 300],
			[true, 9, 9, 300, 100, false, 300],

			// One is new enough
			[true, 100_001, 9, 9, 100, true],
			[true, 100_001, 99_999, 99_999, 100, true],
			[true, 9, 100_001, 9, 100, true],
			[true, 99_999, 100_001, 99_999, 100, true],
			[true, 9, 9, 100_001, 100, true],
			[true, 99_999, 99_999, 100_001, 100, true],

			// Don't break with null on client response
			[true, 100_001, 9, null, null, true],
			[true, 9, 9, null, null, false, 9],
		];
	}

	/**
	 * @dataProvider dataShouldPerformActionOnUser
	 * @param bool $skipWithoutLogin
	 * @param int $discoveryTimestamp
	 * @param int $lastLogin
	 * @param int $authTokenLastActivity
	 * @param bool $expectsThrow
	 */
	public function testShouldPerformActionOnUser(bool $skipWithoutLogin, int $discoveryTimestamp, int $lastLogin, ?int $authTokenLastActivity, ?int $skipOlderThan, bool $expectsThrow, ?int $expectedReturn = null): void {
		/** @var MockObject|RetentionService $service */
		$service = $this->createService(['skipUserBasedOnDiscovery', 'getAuthTokensLastActivity']);
		self::invokePrivate($service, 'keepUsersWithoutLogin', [$skipWithoutLogin]);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')
			->willReturn('uid');
		$user->method('getLastLogin')
			->willReturn($lastLogin);

		$service->method('skipUserBasedOnDiscovery')
			->with($user)
			->willReturn($discoveryTimestamp);

		$service->method('getAuthTokensLastActivity')
			->with($user)
			->willReturn($authTokenLastActivity);

		if ($expectsThrow) {
			 $this->expectException(SkipUserException::class);
			self::invokePrivate($service, 'shouldPerformActionOnUser', [$user, 100_000, $skipOlderThan]);
		} else {
			$this->assertSame($expectedReturn, self::invokePrivate($service, 'shouldPerformActionOnUser', [$user, 100_000, $skipOlderThan]));
		}
	}

	public function dataSkipUserBasedOnDiscovery(): array {
		return [
			[0, 100_001, true],
			[99_999, null, false],
		];
	}

	/**
	 * @dataProvider dataSkipUserBasedOnDiscovery
	 * @param int $discoveryTimestamp
	 * @param int|null $newDiscoveryTimestamp
	 * @param bool $expectsThrow
	 */
	public function testSkipUserBasedOnDiscovery(int $discoveryTimestamp, ?int $newDiscoveryTimestamp, bool $expectsThrow): void {
		$service = $this->createService();

		$user = $this->createMock(IUser::class);
		$user->method('getUID')
			->willReturn('uid');

		$this->config
			->expects($this->once())
			->method('getUserValue')
			->with('uid', 'user_retention', 'user_created_at', '0')
			->willReturn($discoveryTimestamp);

		if ($newDiscoveryTimestamp !== null) {
			$this->timeFactory->method('getTime')
				->willReturn($newDiscoveryTimestamp);

			$this->config
				->expects($this->once())
				->method('setUserValue')
				->with('uid', 'user_retention', 'user_created_at', $newDiscoveryTimestamp);
		}

		if ($expectsThrow) {
			 $this->expectException(SkipUserException::class);
		}
		self::invokePrivate($service, 'skipUserBasedOnDiscovery', [$user]);
	}

	public function dataSkipUserBasedOnProtectedGroupMembership(): array {
		return [
			[[], [], false],
			[[], ['foobar'], false],
			[[], ['foo', 'admin', 'bar'], false],
			[['admin'], [], false],
			[['admin'], ['foobar'], false],
			[['admin'], ['admin'], true],
			[['admin'], ['foo', 'admin', 'bar'], true],
		];
	}

	/**
	 * @dataProvider dataSkipUserBasedOnProtectedGroupMembership
	 * @param string[] $excludedGroups
	 * @param string[] $groupMemberships
	 * @param bool $expectsThrow
	 */
	public function testSkipUserBasedOnProtectedGroupMembership(array $excludedGroups, array $groupMemberships, bool $expectsThrow): void {
		$service = $this->createService();
		self::invokePrivate($service, 'excludedGroups', [$excludedGroups]);

		$user = $this->createMock(IUser::class);

		$this->groupManager->method('getUserGroupIds')
			->with($user)
			->willReturn($groupMemberships);

		if ($expectsThrow) {
			$this->expectException(SkipUserException::class);
		}
		self::invokePrivate($service, 'skipUserBasedOnProtectedGroupMembership', [$user]);
		if (!$expectsThrow) {
			$this->assertTrue(true);
		}
	}
}
