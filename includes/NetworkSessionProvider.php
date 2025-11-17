<?php
/**
 * SessionProvider based on configured ip address and secret token
 *
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
 * @file
 */

namespace MediaWiki\Extension\NetworkSession;

use InvalidArgumentException;
use MediaWiki\Request\WebRequest;
use MediaWiki\Session\SessionBackend;
use MediaWiki\Session\SessionInfo;
use MediaWiki\Session\SessionProvider;
use MediaWiki\Session\UserInfo;
use MediaWiki\WikiMap\WikiMap;
use Wikimedia\IPUtils;

/**
 * This provider allows requests from specific ip addresses or ip address
 * ranges, when they contain a pre-shared secret, to perform api requests.
 * The set of user rights available to logins through this provider can
 * be limited, for example to only grant 'read'.
 *
 * This is intended to work with applications supporting a wiki farm
 * allowing them to perform api requests without managing logins for
 * wikis that don't default to world readable.
 */
class NetworkSessionProvider extends SessionProvider {
	public const AUTH_HEADER = 'Authorization';
	public const AUTH_SCHEME = 'NetworkSession';
	public const CAN_ALWAYS_AUTOCREATE_CONFIG_KEY = 'NetworkSessionProviderCanAlwaysAutocreate';
	public const USERS_CONFIG_KEY = 'NetworkSessionProviderUsers';
	public const USERS_RIGHTS_CONFIG_KEY = 'NetworkSessionProviderAllowedUserRights';

	/** @var bool Whether the current request is an API request. */
	private bool $isApiRequest;

	/**
	 * @param array $params Keys include:
	 *  - priority: (required) Set the priority
	 *  - isApiRequest: Whether the current request is an API request. Should be only set in tests.
	 */
	public function __construct( array $params ) {
		if ( !isset( $params['priority'] ) ) {
			throw new InvalidArgumentException( __METHOD__ . ': priority must be specified' );
		}
		if ( $params['priority'] < SessionInfo::MIN_PRIORITY ||
			$params['priority'] > SessionInfo::MAX_PRIORITY
		) {
			throw new InvalidArgumentException( __METHOD__ . ': Invalid priority' );
		}
		$this->priority = $params['priority'];

		$this->isApiRequest = $params['isApiRequest']
			?? ( defined( 'MW_API' ) || defined( 'MW_REST_API' ) );
	}

	/**
	 * Provide session info for a request
	 *
	 * @param WebRequest $request
	 * @return SessionInfo|null
	 */
	public function provideSessionInfo( WebRequest $request ) {
		$providedToken = $this->parseAuthorization( $request->getHeader( self::AUTH_HEADER ) );
		if ( $providedToken === null ) {
			return null;
		}

		if ( !$this->isApiRequest ) {
			return $this->makeException( 'networksession-only-api-request' );
		}

		if ( $request->getProtocol() !== 'https' ) {
			return $this->makeException( 'networksession-only-https' );
		}

		$ip = $request->getIP();
		$users = $this->config->get( self::USERS_CONFIG_KEY );
		$matchedUser = null;
		foreach ( $users as $config ) {
			if ( !is_array( $config['ip_ranges'] ?? null ) ) {
				return $this->makeException( 'networksession-invalid-config-ip-ranges' );
			}
			if ( !is_string( $config['token'] ?? null ) ) {
				return $this->makeException( 'networksession-invalid-config-token' );
			}
			if ( !is_string( $config['username'] ?? null ) ) {
				return $this->makeException( 'networksession-invalid-config-username' );
			}

			if ( !hash_equals( $config['token'], $providedToken ) ) {
				continue;
			}
			if ( !IPUtils::isInRanges( $ip, $config['ip_ranges'] ) ) {
				continue;
			}
			if ( $matchedUser !== null ) {
				return $this->makeException( 'networksession-invalid-config-multiple-matches' );
			}
			$matchedUser = $config;
		}

		if ( $matchedUser ) {
			return $this->newSessionInfoForUser( $matchedUser );
		} else {
			return $this->makeException( 'networksession-no-token-match' );
		}
	}

	private function parseAuthorization( ?string $authorization ): ?string {
		if ( $authorization === null ) {
			return null;
		}
		$parts = explode( ' ', $authorization, 2 );
		if ( count( $parts ) === 2 && strcasecmp( self::AUTH_SCHEME, $parts[0] ) === 0 ) {
			return $parts[1];
		}
		return null;
	}

	private function newSessionInfoForUser( array $user ): SessionInfo {
		$id = $this->hashToSessionId( implode( '\n', [
			WikiMap::getCurrentWikiId(),
			$user['username'],
			$user['token'],
		] ) );
		return new SessionInfo( $this->priority, [
			'provider' => $this,
			'id' => $id,
			'idIsSafe' => true,
			'userInfo' => UserInfo::newFromName( $user['username'], true ),
			'persisted' => false,
			'forceUse' => true,
		] );
	}

	/**
	 * Fetch the rights allowed to the user when the specified session is active.
	 *
	 * @param SessionBackend $backend
	 * @return null|string[] Allowed user rights, or null to allow all.
	 */
	public function getAllowedUserRights( SessionBackend $backend ) {
		if ( $backend->getProvider() !== $this ) {
			throw new InvalidArgumentException( 'Backend\'s provider isn\'t $this' );
		}
		return $this->config->get( self::USERS_RIGHTS_CONFIG_KEY );
	}

	/**
	 * Declares if this provider is providing system users or regular users
	 *
	 * @return bool
	 */
	public function canAlwaysAutocreate(): bool {
		return $this->config->get( self::CAN_ALWAYS_AUTOCREATE_CONFIG_KEY );
	}

	/**
	 * Indicate whether self::persistSession() can save arbitrary session IDs
	 *
	 * @return bool
	 */
	public function persistsSessionId() {
		// session id is calculated, not persisted
		return false;
	}

	/**
	 * Indicate whether the user associated with the request can be changed
	 *
	 * @return bool
	 */
	public function canChangeUser() {
		return false;
	}

	/** @inheritDoc */
	public function preventSessionsForUser( $username ) {
		// must be implemented since ::canChangeUser() returns false but
		// no need to do anything since we only support predefined users
	}

	/**
	 * Persist a session into a request/response
	 *
	 * @param SessionBackend $session Session to persist
	 * @param WebRequest $request Request into which to persist the session
	 */
	public function persistSession( SessionBackend $session, WebRequest $request ) {
		// Nothing to persist
	}

	/**
	 * Remove any persisted session from a request/response
	 *
	 * @param WebRequest $request Request from which to remove any session data
	 */
	public function unpersistSession( WebRequest $request ) {
		// Nothing to unpersist
	}
}
