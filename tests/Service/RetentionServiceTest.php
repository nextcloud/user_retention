<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2022 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\UserRetention\Tests;

use OC\Authentication\Token\Manager;
use OC\Authentication\Token\PublicKeyToken;
use OCA\UserRetention\Service\RetentionService;
use OCA\UserRetention\SkipUserException;
use OCP\AppFramework\Services\IAppConfig;
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
	protected IConfig&MockObject $config;
	protected IAppConfig&MockObject $appConfig;
	protected IUserManager&MockObject $userManager;
	protected IGroupManager&MockObject $groupManager;
	protected ITimeFactory&MockObject $timeFactory;
	protected IServerContainer&MockObject $container;
	protected IMailer&MockObject $mailer;
	protected IFactory&MockObject $l10nFactory;
	protected LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		parent::setUp();

		$this->config = $this->createMock(IConfig::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
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
				$this->appConfig,
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
			$this->appConfig,
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

	public static function dataShouldPerformActionOnUser(): array {
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

	public static function dataSkipUserBasedOnDiscovery(): array {
		return [
			[0, 100_001, true],
			[99_999, null, false],
		];
	}

	/**
	 * @dataProvider dataSkipUserBasedOnDiscovery
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

	public static function dataSkipUserBasedOnProtectedGroupMembership(): array {
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

	public static function dataGetAuthTokensLastActivity(): array {
		return [
			[[], null],
			[[1], 1],
			[[1, 2], 2],
			[[4, 2], 4],
		];
	}

	/**
	 * @dataProvider dataGetAuthTokensLastActivity
	 * @param int[] $tokenActivities
	 */
	public function testGetAuthTokensLastActivity(array $tokenActivities, ?int $expected): void {
		$service = $this->createService();

		$tokens = [];
		foreach ($tokenActivities as $lastActivity) {
			$token = PublicKeyToken::fromParams([
				'lastActivity' => $lastActivity,
			]);

			$tokens[] = $token;
		}

		$user = $this->createMock(IUser::class);
		$user->method('getUID')
			->willReturn('uid');

		$manager = $this->createMock(Manager::class);
		$manager->method('getTokenByUser')
			->with('uid')
			->willReturn($tokens);

		$this->container->method('get')
			->with(Manager::class)
			->willReturn($manager);

		$actual = self::invokePrivate($service, 'getAuthTokensLastActivity', [$user]);
		$this->assertSame($expected, $actual);
	}
}
