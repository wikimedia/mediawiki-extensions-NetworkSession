NetworkSession is a SessionProvider for api requests based on configured ip
address and a secret token. It is intended for use cases such as having a
system user in a wiki farm for a supporting application.

## Installation

Enable the extension by adding `wfLoadExtension( 'NetworkSession' );` along with
the required config variables to `LocalSettings.php`.

## Configuration

Extension configuration variables are sets of key=value pairs. The following
config options are available for this extension:

```php
// Configures the set of users that will by provided, and the requirements
// the request must meet. This defaults to the empty list, if not configured
// the extension has no effect. All three values are required for each user.
// The top-level array keys are ignored, this can be a list or an assoc
// array depending on what is convenient to configure.
// Configured users must uniquely match a request. If a request matches multiple
// defined users the request will fail, not knowing which one to select.
$wgNetworkSessionProviderUsers = [
	[
		// The name of the account that will be used. If the account does
		// not exist it will be created. If it cannot be created the user
		// will not be logged in.
		'username' => 'Example bot',
		// The secret token that must be provided in the `NetworkSessionToken`
		// HTTP header.
		'token' => '@ryoEdR7p^lG1E&mMsO0tZn3Q6I&r03s'
		// The set of valid ip addresses or ip address ranges that the
		// request must come from. Supports IPv4 and IPv6. May include
		// single ip addresses, ip address ranges, and CIDR blocks. At
		// least one value must be provided, an empty list will not
		// match any requests.
		'ip_ranges' => [
			'127.0.0.1'
			'10.0.0.0-10.255.255.255',
			'192.168.0.0/28',
		]
	]
];

// Configures the limits to the set of user rights that will be available
// when logged in through this provider. This does not grant any rights the
// account does not already have, it limits the rights they have to only this
// list. By default no limits are applied.
$wgNetworkSessionProviderAllowedUserRights = [ 'read' ];

// When false account auto creation will be limited by anonymous user rights.
// If an anonymous user cannot create an account, then neither can an account
// here. When true the account will be created regardless of any other rights
// declarations. By default this is false and account auto creation limits
// are not overridden.
$wgNetworkSessionProviderCanAlwaysAutocreate  = true;
```

## Usage

Requests must specify the `NetworkSession` auth-scheme with the correct token
as the authorization-parameters in the `Authorization` HTTP header and come
from a matching ip address. Requests must use https to protect the secret
token. Non-https requests will be rejected.

The following curl works with the example configuration above.

```shell
curl -H 'Authorization: NetworkSession @ryoEdR7p^lG1E&mMsO0tZn3Q6I&r03s' \
	https://localhost/w/api.php?action=query&meta=userinfo&format=json'
```

### Rotating secrets

A common need is to replace the secret token without interrupting ongoing
operations. This is accomplished by adding a second user with the same username
and a new token. Once the related service has transitioned to the new token the
old user definition should be removed.

```php
$wgNetworkSessionProviderUsers = [
	[
		'username' => 'Example bot',
		'token' => '@ryoEdR7p^lG1E&mMsO0tZn3Q6I&r03s'
		'ip_ranges' => [ '127.0.0.1' ],
	],
	[
		'username' => 'Example bot',
		'token' => 'Ih4#JyFQfyTe1iNn7eWtTry%Ye!caySS',
		'ip_ranges' => [ '127.0.0.1' ],
	],
];
```

### Development

During development it's common to not have https setup. The https status can be faked
with the addition of an X-Forwarded-Proto header. The following works with the example
configuration.

```shell
curl -H 'Authorization: NetworkSession @ryoEdR7p^lG1E&mMsO0tZn3Q6I&r03s' \
	-H 'X-Forwarded-Proto: https' \
	http://localhost/w/api.php?action=query&meta=userinfo&format=json'
```
