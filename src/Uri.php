<?php

/**
 * League.Uri (https://uri.thephpleague.com)
 *
 * (c) Ignace Nyamagana Butera <nyamsprod@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace League\Uri;

use finfo;
use League\Uri\Contracts\UriInterface;
use League\Uri\Exceptions\SyntaxError;
use Psr\Http\Message\UriInterface as Psr7UriInterface;
use TypeError;
use function array_filter;
use function array_map;
use function base64_decode;
use function base64_encode;
use function count;
use function explode;
use function file_get_contents;
use function filter_var;
use function implode;
use function in_array;
use function inet_pton;
use function is_scalar;
use function mb_detect_encoding;
use function method_exists;
use function preg_match;
use function preg_replace;
use function preg_replace_callback;
use function rawurlencode;
use function sprintf;
use function str_replace;
use function strlen;
use function strpos;
use function strtolower;
use function substr;
use const FILEINFO_MIME;
use const FILTER_FLAG_IPV4;
use const FILTER_FLAG_IPV6;
use const FILTER_NULL_ON_FAILURE;
use const FILTER_VALIDATE_BOOLEAN;
use const FILTER_VALIDATE_IP;

/**
 * Class Uri.
 *
 * @package League\Uri
 */
final class Uri implements UriInterface
{
    /**
     * RFC3986 Sub delimiter characters regular expression pattern.
     *
     * @see https://tools.ietf.org/html/rfc3986#section-2.2
     *
     * @var string
     */
    private const REGEXP_CHARS_SUBDELIM = "\!\$&'\(\)\*\+,;\=%";

    /**
     * RFC3986 unreserved characters regular expression pattern.
     *
     * @see https://tools.ietf.org/html/rfc3986#section-2.3
     *
     * @var string
     */
    private const REGEXP_CHARS_UNRESERVED = 'A-Za-z0-9_\-\.~';

    /**
     * RFC3986 schema regular expression pattern.
     *
     * @see https://tools.ietf.org/html/rfc3986#section-3.1
     *
     * @var string
     */
    private const REGEXP_SCHEME = ',^[a-z]([-a-z0-9+.]+)?$,i';

    /**
     * Regular expression pattern to for file URI.
     *
     * @var string
     */
    private const REGEXP_FILE_PATH = ',^(?<delim>/)?(?<root>[a-zA-Z][:|\|])(?<rest>.*)?,';

    /**
     * Mimetype retular expression pattern.
     *
     * @see https://tools.ietf.org/html/rfc2397
     *
     * @var string
     */
    private const REGEXP_MIMETYPE = ',^\w+/[-.\w]+(?:\+[-.\w]+)?$,';

    /**
     * Base64 content regular expression pattern.
     *
     * @see https://tools.ietf.org/html/rfc2397
     *
     * @var string
     */
    private const REGEXP_BINARY = ',(;|^)base64$,';

    /**
     * Windows path string regular expression pattern.
     */
    private const REGEXP_WINDOW_PATH = ',^(?<root>[a-zA-Z][:|\|]),';

    /**
     * Supported schemes and corresponding default port.
     *
     * @var array
     */
    private const SCHEME_DEFAULT_PORT = [
        'data' => null,
        'file' => null,
        'ftp' => 21,
        'gopher' => 70,
        'http' => 80,
        'https' => 443,
        'ws' => 80,
        'wss' => 443,
    ];

    /**
     * URI validation methods per scheme.
     *
     * @var array
     */
    private const SCHEME_VALIDATION_METHOD = [
        'data' => 'isUriWithSchemeAndPathOnly',
        'file' => 'isUriWithSchemeHostAndPathOnly',
        'ftp' => 'isNonEmptyHostUriWithoutFragmentAndQuery',
        'gopher' => 'isNonEmptyHostUriWithoutFragmentAndQuery',
        'http' => 'isNonEmptyHostUri',
        'https' => 'isNonEmptyHostUri',
        'ws' => 'isNonEmptyHostUriWithoutFragment',
        'wss' => 'isNonEmptyHostUriWithoutFragment',
    ];

    /**
     * URI scheme component.
     *
     * @var string|null
     */
    private $scheme;

    /**
     * URI user info part.
     *
     * @var string|null
     */
    private $user_info;

    /**
     * URI host component.
     *
     * @var string|null
     */
    private $host;

    /**
     * URI port component.
     *
     * @var int|null
     */
    private $port;

    /**
     * URI authority string representation.
     *
     * @var string|null
     */
    private $authority;

    /**
     * URI path component.
     *
     * @var string
     */
    private $path = '';

    /**
     * URI query component.
     *
     * @var string|null
     */
    private $query;

    /**
     * URI fragment component.
     *
     * @var string|null
     */
    private $fragment;

    /**
     * URI string representation.
     *
     * @var string|null
     */
    private $uri;

    /**
     * Create a new instance.
     *
     * @param ?string $scheme
     * @param ?string $user
     * @param ?string $pass
     * @param ?string $host
     * @param ?int    $port
     * @param ?string $query
     * @param ?string $fragment
     */
    private function __construct(?string $scheme, ?string $user, ?string $pass, ?string $host, ?int $port, string $path, ?string $query, ?string $fragment)
    {
        $this->scheme = $this->formatScheme($scheme);
        $this->user_info = $this->formatUserInfo($user, $pass);
        $this->host = $this->formatHost($host);
        $this->port = $this->formatPort($port);
        $this->authority = $this->setAuthority();
        $this->path = $this->formatPath($path);
        $this->query = $this->formatQueryAndFragment($query);
        $this->fragment = $this->formatQueryAndFragment($fragment);
        $this->assertValidState();
    }

    /**
     * Format the Scheme and Host component.
     *
     * @param ?string $scheme
     *
     * @throws SyntaxError if the scheme is invalid
     */
    private function formatScheme(?string $scheme): ?string
    {
        if ('' === $scheme || null === $scheme) {
            return $scheme;
        }

        $formatted_scheme = strtolower($scheme);

        if (1 === preg_match(self::REGEXP_SCHEME, $formatted_scheme)) {
            return $formatted_scheme;
        }

        throw new SyntaxError(sprintf('The scheme `%s` is invalid', $scheme));
    }

    /**
     * Set the UserInfo component.
     *
     * @param ?string $user
     * @param ?string $password
     */
    private function formatUserInfo(?string $user, ?string $password): ?string
    {
        if (null === $user) {
            return $user;
        }

        static $user_pattern = '/(?:[^%'.self::REGEXP_CHARS_UNRESERVED.self::REGEXP_CHARS_SUBDELIM.']++|%(?![A-Fa-f0-9]{2}))/';
        $user = preg_replace_callback($user_pattern, [Uri::class, 'urlEncodeMatch'], $user);

        if (null === $password) {
            return $user;
        }

        static $password_pattern = '/(?:[^%:'.self::REGEXP_CHARS_UNRESERVED.self::REGEXP_CHARS_SUBDELIM.']++|%(?![A-Fa-f0-9]{2}))/';

        return $user.':'.preg_replace_callback($password_pattern, [Uri::class, 'urlEncodeMatch'], $password);
    }

    /**
     * Returns the RFC3986 encoded string matched.
     */
    private static function urlEncodeMatch(array $matches): string
    {
        return rawurlencode($matches[0]);
    }

    /**
     * Validate and Format the Host component.
     *
     * @param ?string $host
     */
    private function formatHost(?string $host): ?string
    {
        if (null === $host || '' === $host) {
            return $host;
        }

        if ('[' !== $host[0]) {
            return Common::filterRegisteredName($host);
        }

        return $this->formatIp($host);
    }

    /**
     * Validate and Format the IPv6/IPvfuture host.
     *
     * @throws SyntaxError if the submitted host is not a valid IP host
     */
    private function formatIp(string $host): string
    {
        $ip = substr($host, 1, -1);

        if (false !== filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return $host;
        }

        if (1 === preg_match(Common::REGEXP_HOST_IPFUTURE, $ip, $matches) && !in_array($matches['version'], ['4', '6'], true)) {
            return $host;
        }

        $pos = strpos($ip, '%');

        if (false === $pos) {
            throw new SyntaxError(sprintf('The host `%s` is invalid : the IP host is malformed', $host));
        }

        if (1 === preg_match(Common::REGEXP_INVALID_HOST_CHARS, rawurldecode(substr($ip, $pos)))) {
            throw new SyntaxError(sprintf('The host `%s` is invalid : the IP host is malformed', $host));
        }

        $ip = substr($ip, 0, $pos);

        if (false === filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            throw new SyntaxError(sprintf('The host `%s` is invalid : the IP host is malformed', $host));
        }

        //Only the address block fe80::/10 can have a Zone ID attach to
        //let's detect the link local significant 10 bits
        if (0 === strpos((string) inet_pton($ip), Common::ZONE_ID_ADDRESS_BLOCK)) {
            return $host;
        }

        throw new SyntaxError(sprintf('The host `%s` is invalid : the IP host is malformed', $host));
    }

    /**
     * Format the Port component.
     *
     * @param null|mixed $port
     *
     * @throws SyntaxError
     */
    private function formatPort($port = null): ?int
    {
        if (null === $port || '' === $port) {
            return null;
        }

        if (!is_int($port) && !(is_string($port) && 1 === preg_match('/^\d*$/', $port))) {
            throw new SyntaxError(sprintf('The port `%s` is invalid', $port));
        }

        $port = (int) $port;

        if (0 > $port) {
            throw new SyntaxError(sprintf('The port `%s` is invalid', $port));
        }

        $defaultPort = self::SCHEME_DEFAULT_PORT[$this->scheme] ?? null;

        if ($defaultPort === $port) {
            return null;
        }

        return $port;
    }

    /**
     * {@inheritdoc}
     */
    public static function __set_state(array $components): self
    {
        $components['user'] = null;
        $components['pass'] = null;

        if (null !== $components['user_info']) {
            [$components['user'], $components['pass']] = explode(':', $components['user_info'], 2) + [1 => null];
        }

        return new self(
            $components['scheme'],
            $components['user'],
            $components['pass'],
            $components['host'],
            $components['port'],
            $components['path'],
            $components['query'],
            $components['fragment']
        );
    }

    /**
     * Create a new instance from a URI and a Base URI.
     *
     * The returned URI must be absolute.
     *
     * @param null|mixed $base_uri
     */
    public static function createFromBaseUri($uri, $base_uri = null): UriInterface
    {
        if (!$uri instanceof UriInterface) {
            $uri = self::createFromString($uri);
        }

        if (null === $base_uri) {
            if (null === $uri->getScheme()) {
                throw new SyntaxError(sprintf('the URI `%s` must be absolute', (string) $uri));
            }

            if (null === $uri->getAuthority()) {
                return $uri;
            }

            /** @var UriInterface $uri */
            $uri = UriResolver::resolve($uri, $uri->withFragment(null)->withQuery(null)->withPath(''));

            return $uri;
        }

        if (!$base_uri instanceof UriInterface) {
            $base_uri = self::createFromString($base_uri);
        }

        if (null === $base_uri->getScheme()) {
            throw new SyntaxError(sprintf('the base URI `%s` must be absolute', (string) $base_uri));
        }

        /** @var UriInterface $uri */
        $uri = UriResolver::resolve($uri, $base_uri);

        return $uri;
    }

    /**
     * Create a new instance from a string.
     *
     * @param string|mixed $uri
     */
    public static function createFromString($uri = ''): self
    {
        $components = UriString::parse($uri);

        return new self(
            $components['scheme'],
            $components['user'],
            $components['pass'],
            $components['host'],
            $components['port'],
            $components['path'],
            $components['query'],
            $components['fragment']
        );
    }

    /**
     * Create a new instance from a hash of parse_url parts.
     *
     * Create an new instance from a hash representation of the URI similar
     * to PHP parse_url function result
     */
    public static function createFromComponents(array $components = []): self
    {
        $components += [
            'scheme' => null, 'user' => null, 'pass' => null, 'host' => null,
            'port' => null, 'path' => '', 'query' => null, 'fragment' => null,
        ];

        return new self(
            $components['scheme'],
            $components['user'],
            $components['pass'],
            $components['host'],
            $components['port'],
            $components['path'],
            $components['query'],
            $components['fragment']
        );
    }

    /**
     * Create a new instance from a data file path.
     *
     * @param null|mixed $context
     *
     * @throws SyntaxError If the file does not exist or is not readable
     */
    public static function createFromDataPath(string $path, $context = null): self
    {
        $file_args = [$path, false];
        $mime_args = [$path, FILEINFO_MIME];

        if (null !== $context) {
            $file_args[] = $context;
            $mime_args[] = $context;
        }

        $raw = @file_get_contents(...$file_args);

        if (false === $raw) {
            throw new SyntaxError(sprintf('The file `%s` does not exist or is not readable', $path));
        }

        $mimetype = (string) (new finfo(FILEINFO_MIME))->file(...$mime_args);

        return Uri::createFromComponents([
            'scheme' => 'data',
            'path' => str_replace(' ', '', $mimetype.';base64,'.base64_encode($raw)),
        ]);
    }

    /**
     * Create a new instance from a Unix path string.
     */
    public static function createFromUnixPath(string $uri = ''): self
    {
        $uri = implode('/', array_map('rawurlencode', explode('/', $uri)));
        if ('/' !== ($uri[0] ?? '')) {
            return Uri::createFromComponents(['path' => $uri]);
        }

        return Uri::createFromComponents(['path' => $uri, 'scheme' => 'file', 'host' => '']);
    }

    /**
     * Create a new instance from a local Windows path string.
     */
    public static function createFromWindowsPath(string $uri = ''): self
    {
        $root = '';

        if (1 === preg_match(self::REGEXP_WINDOW_PATH, $uri, $matches)) {
            $root = substr($matches['root'], 0, -1).':';
            $uri = substr($uri, strlen($root));
        }
        $uri = str_replace('\\', '/', $uri);
        $uri = implode('/', array_map('rawurlencode', explode('/', $uri)));

        //Local Windows absolute path
        if ('' !== $root) {
            return Uri::createFromComponents(['path' => '/'.$root.$uri, 'scheme' => 'file', 'host' => '']);
        }

        //UNC Windows Path
        if ('//' !== substr($uri, 0, 2)) {
            return Uri::createFromComponents(['path' => $uri]);
        }

        $parts = explode('/', substr($uri, 2), 2) + [1 => null];

        return Uri::createFromComponents(['host' => $parts[0], 'path' => '/'.$parts[1], 'scheme' => 'file']);
    }

    /**
     * Create a new instance from a URI object.
     *
     * @param Psr7UriInterface|UriInterface $uri the input URI to create
     */
    public static function createFromUri($uri): self
    {
        if ($uri instanceof UriInterface) {
            $user_info = $uri->getUserInfo();
            $user = null;
            $pass = null;

            if (null !== $user_info) {
                [$user, $pass] = explode(':', $user_info, 2) + [1 => null];
            }

            return new self(
                $uri->getScheme(),
                $user,
                $pass,
                $uri->getHost(),
                $uri->getPort(),
                $uri->getPath(),
                $uri->getQuery(),
                $uri->getFragment()
            );
        }

        if (!$uri instanceof Psr7UriInterface) {
            throw new TypeError(sprintf('The object must implement the `%s` or the `%s`', Psr7UriInterface::class, UriInterface::class));
        }

        $scheme = $uri->getScheme();

        if ('' === $scheme) {
            $scheme = null;
        }

        $fragment = $uri->getFragment();

        if ('' === $fragment) {
            $fragment = null;
        }

        $query = $uri->getQuery();

        if ('' === $query) {
            $query = null;
        }

        $host = $uri->getHost();

        if ('' === $host) {
            $host = null;
        }

        $user_info = $uri->getUserInfo();
        $user = null;
        $pass = null;

        if ('' !== $user_info) {
            [$user, $pass] = explode(':', $user_info, 2) + [1 => null];
        }

        return new self(
            $scheme,
            $user,
            $pass,
            $host,
            $uri->getPort(),
            $uri->getPath(),
            $query,
            $fragment
        );
    }

    /**
     * Create a new instance from the environment.
     */
    public static function createFromServer(array $server): self
    {
        [$user, $pass] = self::fetchUserInfo($server);
        [$host, $port] = self::fetchHostname($server);
        [$path, $query] = self::fetchRequestUri($server);

        return Uri::createFromComponents([
            'scheme' => self::fetchScheme($server),
            'user' => $user,
            'pass' => $pass,
            'host' => $host,
            'port' => $port,
            'path' => $path,
            'query' => $query,
        ]);
    }

    /**
     * Returns the environment scheme.
     */
    private static function fetchScheme(array $server): string
    {
        $server += ['HTTPS' => ''];
        $res = filter_var($server['HTTPS'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        return $res !== false ? 'https' : 'http';
    }

    /**
     * Returns the environment user info.
     */
    private static function fetchUserInfo(array $server): array
    {
        $server += ['PHP_AUTH_USER' => null, 'PHP_AUTH_PW' => null, 'HTTP_AUTHORIZATION' => ''];
        $user = $server['PHP_AUTH_USER'];
        $pass = $server['PHP_AUTH_PW'];

        if (0 === strpos(strtolower($server['HTTP_AUTHORIZATION']), 'basic')) {
            $userinfo = base64_decode(substr($server['HTTP_AUTHORIZATION'], 6), true);

            if (false === $userinfo) {
                throw new SyntaxError('The user info could not be detected');
            }
            [$user, $pass] = explode(':', $userinfo, 2) + [1 => null];
        }

        if (null !== $user) {
            $user = rawurlencode($user);
        }

        if (null !== $pass) {
            $pass = rawurlencode($pass);
        }

        return [$user, $pass];
    }

    /**
     * Returns the environment host.
     *
     * @throws SyntaxError If the host can not be detected
     */
    private static function fetchHostname(array $server): array
    {
        $server += ['SERVER_PORT' => null];

        if (null !== $server['SERVER_PORT']) {
            $server['SERVER_PORT'] = (int) $server['SERVER_PORT'];
        }

        if (isset($server['HTTP_HOST'])) {
            preg_match(',^(?<host>(\[.*]|[^:])*)(:(?<port>[^/?#]*))?$,x', $server['HTTP_HOST'], $matches);

            return [
                $matches['host'],
                isset($matches['port']) ? (int) $matches['port'] : $server['SERVER_PORT'],
            ];
        }

        if (!isset($server['SERVER_ADDR'])) {
            throw new SyntaxError('The host could not be detected');
        }

        if (false === filter_var($server['SERVER_ADDR'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $server['SERVER_ADDR'] = '['.$server['SERVER_ADDR'].']';
        }

        return [$server['SERVER_ADDR'], $server['SERVER_PORT']];
    }

    /**
     * Returns the environment path.
     */
    private static function fetchRequestUri(array $server): array
    {
        $server += ['IIS_WasUrlRewritten' => null, 'UNENCODED_URL' => '', 'PHP_SELF' => '', 'QUERY_STRING' => null];

        if ('1' === $server['IIS_WasUrlRewritten'] && '' !== $server['UNENCODED_URL']) {
            return explode('?', $server['UNENCODED_URL'], 2) + [1 => null];
        }

        if (isset($server['REQUEST_URI'])) {
            [$path, ] = explode('?', $server['REQUEST_URI'], 2);
            $query = ('' !== $server['QUERY_STRING']) ? $server['QUERY_STRING'] : null;

            return [$path, $query];
        }

        return [$server['PHP_SELF'], $server['QUERY_STRING']];
    }

    /**
     * Generate the URI authority part.
     */
    private function setAuthority(): ?string
    {
        $authority = null;

        if (null !== $this->user_info) {
            $authority = $this->user_info.'@';
        }

        if (null !== $this->host) {
            $authority .= $this->host;
        }

        if (null !== $this->port) {
            $authority .= ':'.$this->port;
        }

        return $authority;
    }

    /**
     * Format the Path component.
     */
    private function formatPath(string $path): string
    {
        $path = $this->formatDataPath($path);

        static $pattern = '/(?:[^'.self::REGEXP_CHARS_UNRESERVED.self::REGEXP_CHARS_SUBDELIM.'%:@\/}{]++\|%(?![A-Fa-f0-9]{2}))/';

        $path = (string) preg_replace_callback($pattern, [Uri::class, 'urlEncodeMatch'], $path);

        return $this->formatFilePath($path);
    }

    /**
     * Filter the Path component.
     *
     * @see https://tools.ietf.org/html/rfc2397
     *
     * @throws SyntaxError If the path is not compliant with RFC2397
     */
    private function formatDataPath(string $path): string
    {
        if ('data' !== $this->scheme) {
            return $path;
        }

        if ('' == $path) {
            return 'text/plain;charset=us-ascii,';
        }

        if (false === mb_detect_encoding($path, 'US-ASCII', true) || false === strpos($path, ',')) {
            throw new SyntaxError(sprintf('The path `%s` is invalid according to RFC2937', $path));
        }

        $parts = explode(',', $path, 2) + [1 => null];
        $mediatype = explode(';', (string) $parts[0], 2) + [1 => null];
        $data = (string) $parts[1];
        $mimetype = $mediatype[0];

        if (null === $mimetype || '' === $mimetype) {
            $mimetype = 'text/plain';
        }

        $parameters = $mediatype[1];

        if (null === $parameters || '' === $parameters) {
            $parameters = 'charset=us-ascii';
        }

        $this->assertValidPath($mimetype, $parameters, $data);

        return $mimetype.';'.$parameters.','.$data;
    }

    /**
     * Assert the path is a compliant with RFC2397.
     *
     * @see https://tools.ietf.org/html/rfc2397
     *
     * @throws SyntaxError If the mediatype or the data are not compliant with the RFC2397
     */
    private function assertValidPath(string $mimetype, string $parameters, string $data): void
    {
        if (1 !== preg_match(self::REGEXP_MIMETYPE, $mimetype)) {
            throw new SyntaxError(sprintf('The path mimetype `%s` is invalid', $mimetype));
        }

        $is_binary = 1 === preg_match(self::REGEXP_BINARY, $parameters, $matches);

        if ($is_binary) {
            $parameters = substr($parameters, 0, - strlen($matches[0]));
        }

        $res = array_filter(array_filter(explode(';', $parameters), [$this, 'validateParameter']));

        if ([] !== $res) {
            throw new SyntaxError(sprintf('The path paremeters `%s` is invalid', $parameters));
        }

        if (!$is_binary) {
            return;
        }

        $res = base64_decode($data, true);

        if (false === $res || $data !== base64_encode($res)) {
            throw new SyntaxError(sprintf('The path data `%s` is invalid', $data));
        }
    }

    /**
     * Validate mediatype parameter.
     */
    private function validateParameter(string $parameter): bool
    {
        $properties = explode('=', $parameter);

        return 2 != count($properties) || strtolower($properties[0]) === 'base64';
    }

    /**
     * Format path component for file scheme.
     */
    private function formatFilePath(string $path): string
    {
        if ('file' !== $this->scheme) {
            return $path;
        }

        $replace = static function (array $matches): string {
            return $matches['delim'].str_replace('|', ':', $matches['root']).$matches['rest'];
        };

        return (string) preg_replace_callback(self::REGEXP_FILE_PATH, $replace, $path);
    }

    /**
     * Format the Query or the Fragment component.
     *
     * Returns a array containing:
     * <ul>
     * <li> the formatted component (a string or null)</li>
     * <li> a boolean flag telling wether the delimiter is to be added to the component
     * when building the URI string representation</li>
     * </ul>
     *
     * @param ?string $component
     */
    private function formatQueryAndFragment(?string $component): ?string
    {
        if (null === $component || '' === $component) {
            return $component;
        }

        static $pattern = '/(?:[^'.self::REGEXP_CHARS_UNRESERVED.self::REGEXP_CHARS_SUBDELIM.'%:@\/\?]++|%(?![A-Fa-f0-9]{2}))/';
        return preg_replace_callback($pattern, [Uri::class, 'urlEncodeMatch'], $component);
    }

    /**
     * assert the URI internal state is valid.
     *
     * @see https://tools.ietf.org/html/rfc3986#section-3
     * @see https://tools.ietf.org/html/rfc3986#section-3.3
     *
     * @throws SyntaxError if the URI is in an invalid state according to RFC3986
     * @throws SyntaxError if the URI is in an invalid state according to scheme specific rules
     */
    private function assertValidState(): void
    {
        if (null !== $this->authority && ('' !== $this->path && '/' !== $this->path[0])) {
            throw new SyntaxError('If an authority is present the path must be empty or start with a `/`');
        }

        if (null === $this->authority && 0 === strpos($this->path, '//')) {
            throw new SyntaxError(sprintf('If there is no authority the path `%s` can not start with a `//`', $this->path));
        }

        $pos = strpos($this->path, ':');

        if (null === $this->authority
            && null === $this->scheme
            && false !== $pos
            && false === strpos(substr($this->path, 0, $pos), '/')
        ) {
            throw new SyntaxError('In absence of a scheme and an authority the first path segment cannot contain a colon (":") character.');
        }

        $validationMethod = self::SCHEME_VALIDATION_METHOD[$this->scheme] ?? null;

        if (null === $validationMethod || true === $this->$validationMethod()) {
            $this->uri = null;

            return;
        }

        throw new SyntaxError(sprintf('The uri `%s` is invalid for the data scheme', (string) $this));
    }

    /**
     * URI validation for URI schemes which allows only scheme and path components.
     */
    private function isUriWithSchemeAndPathOnly()
    {
        return null === $this->authority
            && null === $this->query
            && null === $this->fragment;
    }

    /**
     * URI validation for URI schemes which allows only scheme, host and path components.
     */
    private function isUriWithSchemeHostAndPathOnly()
    {
        return null === $this->user_info
            && null === $this->port
            && null === $this->query
            && null === $this->fragment
            && !('' != $this->scheme && null === $this->host);
    }

    /**
     * URI validation for URI schemes which disallow the empty '' host.
     */
    private function isNonEmptyHostUri()
    {
        return '' !== $this->host
            && !(null !== $this->scheme && null === $this->host);
    }

    /**
     * URI validation for URIs schemes which disallow the empty '' host
     * and forbids the fragment component.
     */
    private function isNonEmptyHostUriWithoutFragment()
    {
        return $this->isNonEmptyHostUri() && null === $this->fragment;
    }

    /**
     * URI validation for URIs schemes which disallow the empty '' host
     * and forbids fragment and query components.
     */
    private function isNonEmptyHostUriWithoutFragmentAndQuery()
    {
        return $this->isNonEmptyHostUri() && null === $this->fragment && null === $this->query;
    }

    /**
     * Generate the URI string representation from its components.
     *
     * @see https://tools.ietf.org/html/rfc3986#section-5.3
     *
     * @param ?string $scheme
     * @param ?string $authority
     * @param ?string $query
     * @param ?string $fragment
     */
    private function getUriString(
        ?string $scheme,
        ?string $authority,
        string $path,
        ?string $query,
        ?string $fragment
    ): string {
        if (null !== $scheme) {
            $scheme = $scheme.':';
        }

        if (null !== $authority) {
            $authority = '//'.$authority;
        }

        if (null !== $query) {
            $query = '?'.$query;
        }

        if (null !== $fragment) {
            $fragment = '#'.$fragment;
        }

        return $scheme.$authority.$path.$query.$fragment;
    }

    /**
     * {@inheritDoc}
     */
    public function __toString(): string
    {
        $this->uri = $this->uri ?? $this->getUriString(
            $this->scheme,
            $this->authority,
            $this->path,
            $this->query,
            $this->fragment
        );

        return $this->uri;
    }

    /**
     * {@inheritdoc}
     */
    public function jsonSerialize(): string
    {
        return $this->__toString();
    }

    /**
     * {@inheritdoc}
     */
    public function __debugInfo(): array
    {
        return [
            'scheme' => $this->scheme,
            'user_info' => isset($this->user_info) ? preg_replace(',:(.*).?$,', ':***', $this->user_info) : null,
            'host' => $this->host,
            'port' => $this->port,
            'path' => $this->path,
            'query' => $this->query,
            'fragment' => $this->fragment,
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function getScheme(): ?string
    {
        return $this->scheme;
    }

    /**
     * {@inheritDoc}
     */
    public function getAuthority(): ?string
    {
        return $this->authority;
    }

    /**
     * {@inheritDoc}
     */
    public function getUserInfo(): ?string
    {
        return $this->user_info;
    }

    /**
     * {@inheritDoc}
     */
    public function getHost(): ?string
    {
        return $this->host;
    }

    /**
     * {@inheritDoc}
     */
    public function getPort(): ?int
    {
        return $this->port;
    }

    /**
     * {@inheritDoc}
     */
    public function getPath(): string
    {
        return $this->path;
    }

    /**
     * {@inheritDoc}
     */
    public function getQuery(): ?string
    {
        return $this->query;
    }

    /**
     * {@inheritDoc}
     */
    public function getFragment(): ?string
    {
        return $this->fragment;
    }

    /**
     * {@inheritDoc}
     */
    public function withScheme($scheme): UriInterface
    {
        $scheme = $this->formatScheme($this->filterString($scheme));

        if ($scheme === $this->scheme) {
            return $this;
        }

        $clone = clone $this;
        $clone->scheme = $scheme;
        $clone->port = $clone->formatPort($clone->port);
        $clone->authority = $clone->setAuthority();
        $clone->assertValidState();

        return $clone;
    }

    /**
     * Filter a string.
     *
     * @param mixed $str the value to evaluate as a string
     *
     * @throws SyntaxError if the submitted data can not be converted to string
     */
    private function filterString($str): ?string
    {
        if (null === $str) {
            return $str;
        }

        if (!is_scalar($str) && !method_exists($str, '__toString')) {
            throw new TypeError(sprintf('The component must be a string, a scalar or a stringable object %s given', gettype($str)));
        }

        $str = (string) $str;

        if (1 !== preg_match(Common::REGEXP_INVALID_URI_CHARS, $str)) {
            return $str;
        }

        throw new SyntaxError(sprintf('The component `%s` contains invalid characters', $str));
    }

    /**
     * {@inheritDoc}
     */
    public function withUserInfo($user, $password = null): UriInterface
    {
        $user_info = null;
        $user = $this->filterString($user);

        if (null !== $password) {
            $password = $this->filterString($password);
        }

        if ('' !== $user) {
            $user_info = $this->formatUserInfo($user, $password);
        }

        if ($user_info === $this->user_info) {
            return $this;
        }

        $clone = clone $this;
        $clone->user_info = $user_info;
        $clone->authority = $clone->setAuthority();
        $clone->assertValidState();

        return $clone;
    }

    /**
     * {@inheritDoc}
     */
    public function withHost($host): UriInterface
    {
        $host = $this->formatHost($this->filterString($host));

        if ($host === $this->host) {
            return $this;
        }

        $clone = clone $this;
        $clone->host = $host;
        $clone->authority = $clone->setAuthority();
        $clone->assertValidState();

        return $clone;
    }

    /**
     * {@inheritDoc}
     */
    public function withPort($port): UriInterface
    {
        $port = $this->formatPort($port);

        if ($port === $this->port) {
            return $this;
        }

        $clone = clone $this;
        $clone->port = $port;
        $clone->authority = $clone->setAuthority();
        $clone->assertValidState();

        return $clone;
    }

    /**
     * {@inheritDoc}
     */
    public function withPath($path): UriInterface
    {
        $path = $this->filterString($path);

        if (null === $path) {
            throw new TypeError('A path must be a string NULL given');
        }

        $path = $this->formatPath($path);

        if ($path === $this->path) {
            return $this;
        }

        $clone = clone $this;
        $clone->path = $path;
        $clone->assertValidState();

        return $clone;
    }

    /**
     * {@inheritDoc}
     */
    public function withQuery($query): UriInterface
    {
        $query = $this->formatQueryAndFragment($this->filterString($query));

        if ($query === $this->query) {
            return $this;
        }

        $clone = clone $this;
        $clone->query = $query;
        $clone->assertValidState();

        return $clone;
    }

    /**
     * {@inheritDoc}
     */
    public function withFragment($fragment): UriInterface
    {
        $fragment = $this->formatQueryAndFragment($this->filterString($fragment));

        if ($fragment === $this->fragment) {
            return $this;
        }

        $clone = clone $this;
        $clone->fragment = $fragment;
        $clone->assertValidState();

        return $clone;
    }
}
