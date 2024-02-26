<?php

namespace MediaWiki\Extension\NetworkSession;

use CachedBagOStuff;
use ErrorPageError;
use InvalidArgumentException;
use MediaWiki\Config\HashConfig;
use MediaWiki\Config\MultiConfig;
use MediaWiki\HookContainer\HookContainer;
use MediaWiki\MainConfigNames;
use MediaWiki\Output\OutputPage;
use MediaWiki\Request\FauxRequest;
use MediaWiki\Session\SessionBackend;
use MediaWiki\Session\SessionId;
use MediaWiki\Session\SessionInfo;
use MediaWiki\Session\SessionManager;
use MediaWiki\Session\TestBagOStuff;
use MediaWiki\Tests\Session\SessionProviderTestTrait;
use MediaWikiIntegrationTestCase;
use Psr\Log\NullLogger;
use RequestContext;

/**
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @group NetworkSession
 * @group Database
 * @covers \MediaWiki\Extension\NetworkSession\NetworkSessionProvider
 */
class NetworkSessionProviderTest extends MediaWikiIntegrationTestCase {
	use SessionProviderTestTrait;

	public const USER = [
		'username' => 'Bot',
		'ip_ranges' => [ '127.0.0.1' ],
		'token' => 'mediawiki',
	];

	private function getProvider( array $testConfig = [], bool $isApiRequest = true ): NetworkSessionProvider {
		$params = [
			'priority' => 100,
			'isApiRequest' => $isApiRequest,
		];

		$unifiedConfig = $testConfig + [
			MainConfigNames::SessionProviders => [
				NetworkSessionProvider::class => [
					'class' => NetworkSessionProvider::class,
					'args' => [ $params ],
				]
			],
			NetworkSessionProvider::USERS_CONFIG_KEY => [ self::USER ],
			NetworkSessionProvider::USERS_RIGHTS_CONFIG_KEY => null,
			NetworkSessionProvider::CAN_ALWAYS_AUTOCREATE_CONFIG_KEY => false,
		];
		// Clear hooks so invoking the hooks doesn't need mocks
		$this->getServiceContainer()->getHookContainer()->clear( 'ApiBeforeMain' );
		$this->getServiceContainer()->getHookContainer()->clear( 'BeforeInitialize' );

		$this->config = new HashConfig( $unifiedConfig );
		$mainConfig = $this->getServiceContainer()->getMainConfig();

		$manager = new SessionManager( [
			'config' => new MultiConfig( [ $this->config, $mainConfig ] ),
			'logger' => new NullLogger,
			'store' => new TestBagOStuff,
		] );

		return $manager->getProvider( NetworkSessionProvider::class );
	}

	public function testPriorityIsRequired(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'priority must be specified' );
		$provider = new NetworkSessionProvider( [] );
	}

	public function testPriorityMinValueIsRespected(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Invalid priority' );
		$provider = new NetworkSessionProvider( [
			'priority' => SessionInfo::MIN_PRIORITY - 1,
		] );
	}

	public function testPriorityMaxValueIsRespected(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Invalid priority' );
		$provider = new NetworkSessionProvider( [
			'priority' => SessionInfo::MAX_PRIORITY + 1,
		] );
	}

	public function testBasics(): void {
		$provider = $this->getProvider();

		$this->assertFalse( $provider->persistsSessionId() );
		$this->assertFalse( $provider->canChangeUser() );
		$this->assertNull( $provider->newSessionInfo() );
		$this->assertNull( $provider->newSessionInfo( 'aaaaaaaaaaaaaa' ) );
		$this->assertFalse( $provider->canAlwaysAutocreate() );
	}

	public function testHappyPath(): void {
		$provider = $this->getProvider();

		$request = $this->newHttpsRequest();
		$request->setIP( self::USER['ip_ranges'][0] );
		$this->setAuth( $request, self::USER['token'] );
		$info = $this->runProvideSessionInfo( $provider, $request );
		$this->assertInstanceOf( SessionInfo::class, $info );
		$this->assertEquals( self::USER['username'], $info->getUserInfo()->getName() );
		$this->assertTrue( $info->forceUse() );
		$this->assertFalse( $info->wasPersisted() );
		$this->assertTrue( $info->isIdSafe() );
	}

	public function testCanExemptFromAutocreatePermissions(): void {
		$provider = $this->getProvider( [
			NetworkSessionProvider::CAN_ALWAYS_AUTOCREATE_CONFIG_KEY => false,
		] );
		$this->assertFalse( $provider->canAlwaysAutocreate() );

		$provider = $this->getProvider( [
			NetworkSessionProvider::CAN_ALWAYS_AUTOCREATE_CONFIG_KEY => true,
		] );
		$this->assertTrue( $provider->canAlwaysAutocreate() );
	}

	public function testRejectsNonApiRequests(): void {
		$provider = $this->getProvider( [], false );
		$request = $this->newHttpsRequest();
		$request->setIP( self::USER['ip_ranges'][0] );
		$this->setAuth( $request, self::USER['token'] );

		$this->expectException( ErrorPageError::class );
		$this->runProvideSessionInfo( $provider, $request );
	}

	public function testRejectsNonHttpsRequests() {
		$provider = $this->getProvider();
		$request = new FauxRequest( [], false, null, 'http' );
		$request->setIP( self::USER['ip_ranges'][0] );
		$this->setAuth( $request, self::USER['token'] );

		// Note that here (and in the rest of the test) the actual error
		// should be an ApiUsageException, but SessionProvider::makeException
		// isn't throwing those because MW_API is undefined.
		$this->expectException( ErrorPageError::class );
		$this->runProvideSessionInfo( $provider, $request );
	}

	public function testRightsCanBeNull(): void {
		$provider = $this->getProvider();
		$request = $this->newHttpsRequest();
		$request->setIP( self::USER['ip_ranges'][0] );
		$this->setAuth( $request, self::USER['token'] );
		$info = $this->runProvideSessionInfo( $provider, $request );
		$this->assertInstanceOf( SessionInfo::class, $info );
		$backend = $this->sessionBackend( $info );
		$this->assertNull( $provider->getAllowedUserRights( $backend ) );
	}

	public function testRightsCanBeLimited(): void {
		$provider = $this->getProvider( [
			NetworkSessionProvider::USERS_RIGHTS_CONFIG_KEY => [ 'read' ],
		] );
		$request = $this->newHttpsRequest();
		$request->setIP( self::USER['ip_ranges'][0] );
		$this->setAuth( $request, self::USER['token'] );
		$info = $this->runProvideSessionInfo( $provider, $request );
		$this->assertInstanceOf( SessionInfo::class, $info );
		$backend = $this->sessionBackend( $info );
		$this->assertEquals( [ 'read' ], $provider->getAllowedUserRights( $backend ) );
	}

	public function testIpRangeWithMultipleRanges(): void {
		$provider = $this->getProvider( [
			NetworkSessionProvider::USERS_CONFIG_KEY => [
				[
					'ip_ranges' => [
						'1.2.3.4',
						'127.0.0.0-127.255.255.255',
					],
				] + self::USER,
			]
		] );
		$request = $this->newHttpsRequest();
		$this->setAuth( $request, self::USER['token'] );

		// In first range
		$request->setIP( '1.2.3.4' );
		$info = $this->runProvideSessionInfo( $provider, $request );
		$this->assertEquals( self::USER['username'], $info->getUserInfo()->getName() );

		// In second range
		$request->setIP( '127.0.0.1' );
		$info = $this->runProvideSessionInfo( $provider, $request );
		$this->assertEquals( self::USER['username'], $info->getUserInfo()->getName() );

		// Unrelated ip address providing correct token
		$request->setIP( '10.0.0.1' );
		$this->expectException( ErrorPageError::class );
		$this->runProvideSessionInfo( $provider, $request );
	}

	public function testNoTokenProvided(): void {
		$provider = $this->getProvider();
		$request = $this->newHttpsRequest();
		$request->setIP( self::USER['ip_ranges'][0] );
		// No auth, pass on to other provider
		$this->assertNull( $this->runProvideSessionInfo( $provider, $request ) );
	}

	public function testOtherAuthHeader(): void {
		$provider = $this->getProvider();
		$request = $this->newHttpsRequest();
		$request->setIP( self::USER['ip_ranges'][0] );
		$request->setHeader( NetworkSessionProvider::AUTH_HEADER, 'Basic 12345' );
		// Unrelated auth, pass on to other provider
		$this->assertNull( $this->runProvideSessionInfo( $provider, $request ) );
	}

	public function testAuthSchemeIsCaseInsensitive(): void {
		$provider = $this->getProvider();
		$request = $this->newHttpsRequest();
		$request->setIP( self::USER['ip_ranges'][0] );

		$request->setHeader( NetworkSessionProvider::AUTH_HEADER,
			strtoupper( NetworkSessionProvider::AUTH_SCHEME ) . ' ' . self::USER['token'] );
		$info = $this->runProvideSessionInfo( $provider, $request );
		$this->assertInstanceOf( SessionInfo::class, $info );
		$this->assertEquals( self::USER['username'], $info->getUserInfo()->getName() );

		$request->setHeader( NetworkSessionProvider::AUTH_HEADER,
			strtolower( NetworkSessionProvider::AUTH_SCHEME ) . ' ' . self::USER['token'] );
		$info = $this->runProvideSessionInfo( $provider, $request );
		$this->assertInstanceOf( SessionInfo::class, $info );
		$this->assertEquals( self::USER['username'], $info->getUserInfo()->getName() );
	}

	public function testTokenRejection(): void {
		$provider = $this->getProvider();
		$request = $this->newHttpsRequest();
		$request->setIP( self::USER['ip_ranges'][0] );
		$this->setAuth( $request, 'incorrect' );

		// Incorrect token provided, fail with auth error
		$this->expectException( ErrorPageError::class );
		$info = $this->runProvideSessionInfo( $provider, $request );
	}

	public function testCanProvideMultipleUsers(): void {
		$provider = $this->getProvider( [
			NetworkSessionProvider::USERS_CONFIG_KEY => [
				self::USER,
				[
					'username' => 'Phpunit',
					'ip_ranges' => [ '10.0.0.1' ],
					'token' => 'example',
				]
			],
		] );
		$request = $this->newHttpsRequest();
		$request->setIP( self::USER['ip_ranges'][0] );

		// The first user is valid
		$this->setAuth( $request, self::USER['token'] );
		$info = $this->runProvideSessionInfo( $provider, $request );
		$this->assertInstanceOf( SessionInfo::class, $info );
		$this->assertEquals( self::USER['username'], $info->getUserInfo()->getName() );

		// The second user is valid
		$request->setIP( '10.0.0.1' );
		$this->setAuth( $request, 'example' );
		$info = $this->runProvideSessionInfo( $provider, $request );
		$this->assertInstanceOf( SessionInfo::class, $info );
		$this->assertEquals( 'Phpunit', $info->getUserInfo()->getName() );
	}

	public function testEmptyIpRangeFailsAuth(): void {
		$provider = $this->getProvider( [
			NetworkSessionProvider::USERS_CONFIG_KEY => [
				[
					'ip_ranges' => [],
				] + self::USER
			]
		] );

		$request = $this->newHttpsRequest();

		// With no auth pass on to other providers
		$this->assertNull( $this->runProvideSessionInfo( $provider, $request ) );

		// With auth fail due to no ip address match
		$this->setAuth( $request, self::USER['token'] );
		$this->expectException( ErrorPageError::class );
		$this->runProvideSessionInfo( $provider, $request );
	}

	public function testFailsWithMissingUsernameConfiguration(): void {
		$provider = $this->getProvider( [
			NetworkSessionProvider::USERS_CONFIG_KEY => [
				[
					'ip_ranges' => [ '127.0.0.1' ],
					'token' => 'example',
				]
			],
		] );
		$request = $this->newHttpsRequest();

		// With no auth pass on to other providers
		$this->assertNull( $this->runProvideSessionInfo( $provider, $request ) );

		// With auth fail for bad configuration
		$this->setAuth( $request, 'example' );
		$this->expectException( ErrorPageError::class );
		$this->runProvideSessionInfo( $provider, $request );
	}

	public function testFailsWithMissingIpRangesConfiguration(): void {
		$provider = $this->getProvider( [
			NetworkSessionProvider::USERS_CONFIG_KEY => [
				[
					'username' => 'Phpunit',
					'token' => 'example',
				]
			],
		] );
		$request = $this->newHttpsRequest();

		// With no auth pass on to other providers
		$this->assertNull( $this->runProvideSessionInfo( $provider, $request ) );

		// With auth fail for bad configuration
		$this->setAuth( $request, 'example' );
		$this->expectException( ErrorPageError::class );
		$this->runProvideSessionInfo( $provider, $request );
	}

	public function testFailsWithMissingTokenConfiguration(): void {
		$provider = $this->getProvider( [
			NetworkSessionProvider::USERS_CONFIG_KEY => [
				[
					'username' => 'Phpunit',
					'ip_ranges' => [ '127.0.0.1' ],
				]
			],
		] );
		$request = $this->newHttpsRequest();

		// With no auth pass on to other providers
		$this->assertNull( $this->runProvideSessionInfo( $provider, $request ) );

		// With auth fail for bad configuration
		$this->setAuth( $request, 'example' );
		$this->expectException( ErrorPageError::class );
		$this->runProvideSessionInfo( $provider, $request );
	}

	public function testFailsWithMultipleMatchingUsers(): void {
		$provider = $this->getProvider( [
			NetworkSessionProvider::USERS_CONFIG_KEY => [
				self::USER,
				self::USER
			],
		] );
		$request = $this->newHttpsRequest();
		$request->setIP( self::USER['ip_ranges'][0] );

		// With no auth pass on to other providers
		$this->assertNull( $this->runProvideSessionInfo( $provider, $request ) );

		// With auth fail for bad configuration
		$this->setAuth( $request, self::USER['token'] );
		$this->expectException( ErrorPageError::class );
		$this->runProvideSessionInfo( $provider, $request );
	}

	private function newHttpsRequest() {
		return new FauxRequest( [], false, null, 'https' );
	}

	private function sessionBackend( SessionInfo $info ): SessionBackend {
		return new SessionBackend(
			new SessionId( $info->getId() ),
			$info,
			$this->createMock( CachedBagOStuff::class ),
			new NullLogger,
			$this->createMock( HookContainer::class ),
			600
		);
	}

	private function setAuth( FauxRequest $request, string $token ): void {
		$request->setHeader( NetworkSessionProvider::AUTH_HEADER,
			NetworkSessionProvider::AUTH_SCHEME . ' ' . $token );
	}

	/**
	 * We can't throw exceptions in the typical way from the session provider.
	 * This invokes provideSessionInfo in such a way that the exceptions
	 * get thrown.
	 */
	private function runProvideSessionInfo( NetworkSessionProvider $provider, FauxRequest $request ): ?SessionInfo {
		$info = $provider->provideSessionInfo( $request );
		$hooks = $this->getServiceContainer()->getHookContainer();
		$output = new OutputPage( RequestContext::getMain() );
		try {
			$hooks->run( 'BeforeInitialize', [ null, null, $output, null, null, null ] );
		} catch ( ErrorPageError $e ) {
			$hooks->clear( 'BeforeInitialize' );
			$this->assertTrue( $info->getUserInfo()->isAnon() );
			throw $e;
		}

		if ( $info !== null ) {
			$this->assertSame( $provider, $info->getProvider() );
			$this->assertFalse( $info->getUserInfo()->isAnon() );
		}
		return $info;
	}
}
