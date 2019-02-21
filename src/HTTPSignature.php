<?php declare(strict_types=1);

namespace LTO\HTTPSignature;

use function array_search;
use Improved as i;
use Carbon\CarbonImmutable;
use Improved\IteratorPipeline\Pipeline;
use Psr\Http\Message\RequestInterface as Request;
use const Improved\FUNCTION_ARGUMENT_PLACEHOLDER as __;

/**
 * Create and verify HTTP Signatures.
 * Only support signatures using the ED25519 algorithm.
 */
class HTTPSignature
{
    /**
     * @var int
     */
    protected $clockSkew = 300;

    /**
     * Headers required / used in message per request method.
     * @var array
     */
    protected $requiredHeaders = [
        'default' => ['(request-target)', 'date'],
    ];

    /**
     * Supported algorithms
     * @var string[]
     */
    protected $supportedAlgorithms;

    /**
     * Function to sign a request.
     * @var callable
     */
    protected $sign;

    /**
     * Function to verify a signed request.
     * @var callable
     */
    protected $verify;


    /**
     * Class construction.
     * 
     * @param string[] $algorithms  Supported algorithms.
     * @param callable $sign        Function to sign a request.
     * @param callable $verify      Function to verify a signed request.
     */
    public function __construct(array $algorithms, callable $sign, callable $verify)
    {
        $this->supportedAlgorithms = $algorithms;
        $this->sign = $sign;
        $this->verify = $verify;
    }

    /**
     * Get supported cryptography algorithms.
     *
     * @return string[]
     */
    public function getSupportedAlgorithms(): array
    {
        return $this->supportedAlgorithms;
    }

    /**
     * Get service with modified max clock offset.
     * 
     * @param int $clockSkew
     * @return static
     */
    public function withClockSkew(int $clockSkew = 300)
    {
        if ($this->clockSkew === $clockSkew) {
            return $this;
        }

        $clone = clone $this;
        $clone->clockSkew = $clockSkew;
        
        return $clone;
    }
    
    /**
     * Get the max clock offset.
     * 
     * @return int
     */
    public function getClockSkew(): int
    {
        return $this->clockSkew;
    }

    /**
     * Set the required headers for the signature message.
     *
     * @param string $method   HTTP Request method or 'default'
     * @param array  $headers
     * @return static
     */
    public function withRequiredHeaders(string $method, array $headers)
    {
        $method = strtolower($method);

        $headers = Pipeline::with($headers)
            ->map(i\function_partial('strtolower', __))
            ->toArray();

        if (isset($this->requiredHeaders[$method]) && $this->requiredHeaders[$method] === $headers) {
            return $this;
        }

        $clone = clone $this;
        $clone->requiredHeaders[$method] = $headers;

        return $clone;
    }

    /**
     * Get the required headers for the signature message.
     *
     * @param string $method
     * @return string[]
     */
    public function getRequiredHeaders(string $method): array
    {
        $method = strtolower($method);

        return $this->requiredHeaders[$method] ?? $this->requiredHeaders['default'];
    }


    /**
     * Verify the signature
     *
     * @param Request $request
     * @throws HTTPSignatureException
     */
    public function verify(Request $request): void
    {
        $params = $this->getParams($request);
        $this->assertParams($params);

        $method = $request->getMethod();
        $headers = isset($params['headers']) ? explode(' ', $params['headers']) : [];
        $this->assertRequiredHeaders($method, $headers);

        $this->assertSignatureAge($request);

        $message = $this->getMessage($request, $headers);
        $keyId = $params['keyId'] ?? '';
        $signature = base64_decode($params['signature'] ?? '');

        $verified = ($this->verify)($message, $signature, $keyId, $params['algorithm'] ?? 'unknown');

        if (!$verified) {
            throw new HTTPSignatureException("invalid signature");
        }
    }

    /**
     * Sign a request.
     *
     * @param Request  $request
     * @param string   $algorithm  Signing algorithm
     * @param string   $keyId      Public key or key reference
     * @return Request
     */
    public function sign(Request $request, string $algorithm, string $keyId): Request
    {
        $method = $request->getMethod();

        $params = [
            'keyId' => $keyId,
            'algorithm' => $algorithm,
            'headers' => join(' ', $this->getRequiredHeaders($method))
        ];

        if (!$request->hasHeader('Date')) {
            $date = CarbonImmutable::now()->format(DATE_RFC1123);
            $request = $request->withHeader('Date', $date);
        }

        $rawSignature = i\type_check(($this->sign)($this->getMessage(), $keyId, $algorithm), 'string');
        $signature = base64_encode($rawSignature);

        $args = [$params['keyId'], $params['algorithm'], $params['headers'], $signature];
        $header = sprintf('Signature keyId="%s",algorithm="%s",headers="%s",signature="%s"', ...$args);

        return $request->withHeader('authorization', $header);
    }


    /**
     * Extract the authorization Signature parameters
     *
     * @param Request $request
     * @return string[]
     * @throws HTTPSignatureException
     */
    protected function getParams(Request $request): array
    {
        if (!$request->hasHeader('authorization')) {
            throw new HTTPSignatureException('missing "Authorization" header');
        }
        
        $auth = $request->getHeaderLine('authorization');
        
        list($method, $paramString) = explode(' ', $auth, 2) + [null, null];
        
        if (strtolower($method) !== 'signature') {
            throw new HTTPSignatureException(sprintf('authorization scheme should be "Signature" not "%s"', $method));
        }
        
        if (!preg_match_all('/(\w+)\s*=\s*"([^"]++)"\s*(,|$)/', $paramString, $matches, PREG_PATTERN_ORDER)) {
            throw new HTTPSignatureException('corrupt "Authorization" header');
        }
        
        return array_combine($matches[1], $matches[2]);
    }

    /**
     * Assert that required headers are present
     *
     * @param string   $method
     * @param string[] $headers
     * @throws HTTPSignatureException
     */
    protected function assertRequiredHeaders(string $method, array $headers): void
    {
        if (in_array('x-date', $headers)) {
            $key = array_search('x-date', $headers, true);
            $headers[$key] = 'date';
        }

        $missing = array_diff($this->getRequiredHeaders($method), $headers);

        if (!empty($missing)) {
            $err = sprintf("%s %s not part of signature", join(', ', $missing), count($missing) === 1 ? 'is' : 'are');
            throw new HTTPSignatureException($err);
        }
    }

    /**
     * Get message that should be signed.
     *
     * @param Request  $request
     * @param string[] $headers
     * @return string
     */
    protected function getMessage(Request $request, array $headers): string
    {
        $headers = Pipeline::with($headers)
            ->map(i\function_partial('strtolower', __))
            ->toArray();

        $message = [];
        
        foreach ($headers as $header) {
            $message[] = $header === '(request-target)'
                ? sprintf("%s: %s", '(request-target)', $this->getRequestTarget($request))
                : sprintf("%s: %s", $header, $request->getHeaderLine($header));
        }
        
        return join("\n", $message);
    }

    /**
     * Build a request line.
     *
     * @param Request $request
     * @return string
     */
    protected function getRequestTarget(Request $request): string
    {
        $method = strtolower($request->getMethod());
        $uri = (string)$request->getUri()->withScheme('')->withHost('')->withPort(null)->withUserInfo('');

        return $method . ' ' . $uri;
    }

    /**
     * Assert all required parameters are available.
     *
     * @param string[] $params
     * @throws HTTPSignatureException
     */
    protected function assertParams(array $params): void
    {
        $required = ['keyId', 'algorithm', 'headers', 'signature'];

        foreach ($required as $param) {
            if (!array_key_exists($param, $params)) {
                throw new HTTPSignatureException("{$param} not specified in Authorization header");
            }
        }

        if (!in_array($params['algorithm'], $this->supportedAlgorithms)) {
            throw new HTTPSignatureException(sprintf(
                'signed with unsupported algorithm: %s',
                $params['algorithm']
            ));
        }
    }

    /**
     * Asset that the signature is not to old
     *
     * @param Request $request
     * @throws HTTPSignatureException
     */
    protected function assertSignatureAge(Request $request): void
    {
        $dateString =
            ($request->hasHeader('x-date') ? $request->getHeaderLine('x-date') : null) ??
            ($request->hasHeader('date') ? $request->getHeaderLine('date') : null);

        if ($dateString === null) {
            return; // Normally 'Date' should be a required header, so we shouldn't event get to this point.
        }

        $date = CarbonImmutable::createFromTimeString($dateString);

        if (abs(CarbonImmutable::now()->diffInSeconds($date)) > $this->clockSkew) {
            throw new HTTPSignatureException("signature to old or system clocks out of sync");
        }
    }
}
