<?php
namespace RKA\Middleware;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use \Exception;

class IpAddress implements MiddlewareInterface
{
    /**
     * Enable checking of proxy headers (X-Forwarded-For to determined client IP.
     *
     * Defaults to false as only $_SERVER['REMOTE_ADDR'] is a trustworthy source
     * of IP address.
     *
     * @var bool
     */
    protected $checkProxyHeaders;

    /**
     * List of trusted proxy IP addresses
     *
     * If not empty, then one of these IP addresses must be in $_SERVER['REMOTE_ADDR']
     * in order for the proxy headers to be looked at.
     *
     * @var array
     */
    protected $trustedProxies;

    /**
     * Name of the attribute added to the ServerRequest object
     *
     * @var string
     */
    protected $attributeName = 'ip_address';

    /**
     * List of proxy headers inspected for the client IP address
     *
     * @var array
     */
    protected $headersToInspect = [
        'Forwarded',
        'X-Forwarded-For',
        'X-Forwarded',
        'X-Cluster-Client-Ip',
        'Client-Ip',
    ];

    /**
     * Constructor
     *
     * @param bool $checkProxyHeaders Whether to use proxy headers to determine client IP
     * @param array $trustedProxies   List of IP addresses of trusted proxies
     * @param string $attributeName   Name of attribute added to ServerRequest object
     * @param array $headersToInspect List of headers to inspect
     */
    public function __construct(
        $checkProxyHeaders = false,
        array $trustedProxies = null,
        $attributeName = null,
        array $headersToInspect = []
    ) {
        if ($checkProxyHeaders && $trustedProxies === null) {
            throw new \InvalidArgumentException('Use of the forward headers requires an array for trusted proxies.');
        }

        $this->checkProxyHeaders = $checkProxyHeaders;
        $this->trustedProxies = $trustedProxies;

        if ($attributeName) {
            $this->attributeName = $attributeName;
        }
        if (!empty($headersToInspect)) {
            $this->headersToInspect = $headersToInspect;
        }
    }

    /**
     * {@inheritDoc}
     *
     * Set the "$attributeName" attribute to the client's IP address as determined from
     * the proxy header (X-Forwarded-For or from $_SERVER['REMOTE_ADDR']
     *
     * @throws Exception
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $ipAddress = $this->determineClientIpAddress($request);
        $request = $request->withAttribute($this->attributeName, $ipAddress);

        return $handler->handle($request);
    }

    /**
     * Set the "$attributeName" attribute to the client's IP address as determined from
     * the proxy header (X-Forwarded-For or from $_SERVER['REMOTE_ADDR']
     *
     * @param ServerRequestInterface $request PSR7 request
     * @param ResponseInterface $response     PSR7 response
     * @param callable $next                  Next middleware
     *
     * @return ResponseInterface
     * @throws Exception
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, $next)
    {
        if (!$next) {
            return $response;
        }

        $ipAddress = $this->determineClientIpAddress($request);
        $request = $request->withAttribute($this->attributeName, $ipAddress);

        return $response = $next($request, $response);
    }

    /**
     * Find out the client's IP address from the headers available to us
     *
     * @param  ServerRequestInterface $request PSR-7 Request
     * @return string
     * @throws Exception
     */
    protected function determineClientIpAddress($request)
    {
        $ipAddress = null;

        $serverParams = $request->getServerParams();
        if (isset($serverParams['REMOTE_ADDR'])) {
            $remoteAddr = $this->extractIpAddress($serverParams['REMOTE_ADDR']);
            if ($this->isValidIpAddress($remoteAddr)) {
                $ipAddress = $remoteAddr;
            }
        }

        if ($this->checkProxyHeaders && $this->checkTrustedProxies($ipAddress)) {
            foreach ($this->headersToInspect as $header) {
                if ($request->hasHeader($header)) {
                    $ip = $this->getFirstIpAddressFromHeader($request, $header);
                    if ($this->isValidIpAddress($ip)) {
                        $ipAddress = $ip;
                        break;
                    }
                }
            }
        }

        return $ipAddress;
    }

    /**
     * See if given ipAddress is trusted. Supporting CIDR now.
     * If no trustedProxies are given, return true.
     *
     * @param string $ipAddress
     * @return bool
     * @throws Exception
     */
    private function checkTrustedProxies(string $ipAddress):bool
    {
        if (empty($this->trustedProxies)) return true;
        foreach($this->trustedProxies as $i => $proxy) {
            $parts = explode('/', $proxy);
            switch (count($parts)) {
                case 1:
                    if ($proxy === $ipAddress) return true;
                    break;
                case 2:
                    if ($this->addressInNetwork($ipAddress, $parts[0], $parts[1])) return true;
                    break;
                default:
                    throw new Exception('Wrong ip address format in trustedProxies on index ' . $i);
            }
        }
        return false;
    }

    /**
     * @param string $ipAddress
     * @param string $network
     * @param int $width
     * @return bool
     */
    private function addressInNetwork(string $ipAddress, string $network, int $width):bool
    {
        // Not really needed, but more efficient...
        if ($width === 32) {
            return $network === $ipAddress;
        }
        // Do the math for all other cases
        $mask = ~ ((1 << (32 - $width)) - 1);
        return (ip2long($ipAddress) & $mask) == (ip2long($network) & $mask);
    }

    /**
     * Remove port from IPV4 address if it exists
     *
     * Note: leaves IPV6 addresses alone
     *
     * @param  string $ipAddress
     * @return string
     */
    protected function extractIpAddress($ipAddress)
    {
        $parts = explode(':', $ipAddress);
        if (count($parts) == 2) {
            if (filter_var($parts[0], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                return $parts[0];
            }
        }

        return $ipAddress;
    }

    /**
     * Check that a given string is a valid IP address
     *
     * @param  string  $ip
     * @return boolean
     */
    protected function isValidIpAddress($ip)
    {
        $flags = FILTER_FLAG_IPV4 | FILTER_FLAG_IPV6;
        if (filter_var($ip, FILTER_VALIDATE_IP, $flags) === false) {
            return false;
        }
        return true;
    }

    /**
     * Find out the client's IP address from the headers available to us
     *
     * @param  ServerRequestInterface $request PSR-7 Request
     * @param  string $header Header name
     * @return string
     */
    private function getFirstIpAddressFromHeader($request, $header)
    {
        $items = explode(',', $request->getHeaderLine($header));
        $headerValue = trim(reset($items));

        if (ucfirst($header) == 'Forwarded') {
            foreach (explode(';', $headerValue) as $headerPart) {
                if (strtolower(substr($headerPart, 0, 4)) == 'for=') {
                    $for = explode(']', $headerPart);
                    $headerValue = trim(substr(reset($for), 4), " \t\n\r\0\x0B" . "\"[]");
                    break;
                }
            }
        }

        return $this->extractIpAddress($headerValue);
    }
}
