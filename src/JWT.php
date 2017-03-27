<?php

namespace Lindelius\JWT;

use ArrayAccess;
use DomainException;
use InvalidArgumentException;
use Iterator;
use Lindelius\JWT\Exception\BeforeValidException;
use Lindelius\JWT\Exception\ExpiredException;
use Lindelius\JWT\Exception\InvalidException;
use Lindelius\JWT\Exception\InvalidSignatureException;
use Lindelius\JWT\Exception\JsonException;
use Lindelius\JWT\Exception\RuntimeException;

/**
 * Class JWT
 *
 * @author  Tom Lindelius <tom.lindelius@gmail.com>
 * @version 2017-02-25
 */
class JWT implements Iterator
{
    /**
     * The hashing algorithm to use when encoding the JWT.
     *
     * @var string
     */
    private $algorithm;

    /**
     * The allowed hashing algorithms. If empty, all supported algorithms are
     * considered allowed.
     *
     * @var array
     */
    protected static $allowedAlgorithms = [];

    /**
     * The default hashing algorithm.
     *
     * @var string
     */
    protected static $defaultAlgorithm = 'HS256';

    /**
     * The JWT hash.
     *
     * @var string|null
     */
    private $hash = null;

    /**
     * The JWT header.
     *
     * @var array
     */
    private $header = [];

    /**
     * The secret key used when signing the JWT.
     *
     * @var string|resource
     */
    private $key;

    /**
     * Leeway time (in seconds) to account for clock skew.
     *
     * @var int
     */
    protected static $leeway = 0;

    /**
     * The JWT payload.
     *
     * @var array
     */
    private $payload = [];

    /**
     * Supported hashing algorithms.
     *
     * @var array
     */
    protected static $supportedAlgorithms = [
        'HS256' => ['hash_hmac', 'SHA256'],
        'HS512' => ['hash_hmac', 'SHA512'],
        'HS384' => ['hash_hmac', 'SHA384'],
        'RS256' => ['openssl', 'SHA256']
    ];

    /**
     * Constructor for JWT objects.
     *
     * @param string|resource $key
     * @param string|null     $algorithm
     * @param array           $header
     * @throws DomainException
     * @throws InvalidArgumentException
     */
    public function __construct($key, $algorithm = null, array $header = [])
    {
        if (empty($key) || (!is_string($key) && !is_resource($key))) {
            throw new InvalidArgumentException('Invalid key.');
        }

        $this->key = $key;

        if ($algorithm !== null && !is_string($algorithm)) {
            throw new InvalidArgumentException('Invalid hashing algorithm.');
        }

        if (empty($algorithm)) {
            $algorithm = static::$defaultAlgorithm;
        }

        if (empty(static::$supportedAlgorithms[$algorithm]) || !in_array($algorithm, static::getAllowedAlgorithms())) {
            throw new DomainException('Unsupported hashing algorithm.');
        }

        $this->algorithm = $algorithm;

        unset($header['alg']);
        $this->header = array_merge([
            'typ' => 'JWT',
            'alg' => $algorithm
        ], $header);
    }

    /**
     * Gets the current value for a given claim.
     *
     * @param  string $claimName
     * @return mixed
     * @see    http://php.net/manual/en/language.oop5.overloading.php#object.get
     */
    public function __get($claimName)
    {
        return $this->getClaim($claimName);
    }

    /**
     * Sets a new value for a given claim.
     *
     * @param string $claimName
     * @param mixed  $newValue
     * @see   http://php.net/manual/en/language.oop5.overloading.php#object.set
     */
    public function __set($claimName, $newValue)
    {
        $this->payload[$claimName] = $newValue;

        /**
         * If the JWT has been previously encoded, clear the hash since it is
         * no longer valid.
         */
        $this->hash = null;
    }

    /**
     * Convert the JWT to its string representation.
     *
     * @return string
     */
    public function __toString()
    {
        return (string) $this->getHash();
    }

    /**
     * Creates a new instance of the model.
     *
     * @param string|resource $key
     * @param array           $header
     * @param array           $payload
     * @return static
     * @throws DomainException
     * @throws InvalidException
     */
    public static function create($key, array $header = [], array $payload = [])
    {
        $algorithm = isset($header['alg']) ?: null;
        $jwt       = new static($key, $algorithm, $header);

        foreach ($payload as $claimName => $claimValue) {
            $jwt->{$claimName} = $claimValue;
        }

        return $jwt;
    }

    /**
     * Gets the value of the "current" claim in the payload array.
     *
     * @return mixed
     */
    public function current()
    {
        return current($this->payload);
    }

    /**
     * Decodes a JWT hash and returns the resulting object.
     *
     * @param string          $jwt
     * @param string|resource $key
     * @param bool            $verify
     * @return static
     * @throws DomainException
     * @throws InvalidArgumentException
     * @throws InvalidException
     */
    public static function decode($jwt, $key, $verify = true)
    {
        if (empty($jwt) || !is_string($jwt)) {
            throw new InvalidArgumentException('Invalid JWT.');
        }

        $segments = explode('.', $jwt);

        if (count($segments) !== 3) {
            throw new InvalidException('Unexpected number of JWT segments.');
        }

        $header  = static::jsonDecode(url_safe_base64_decode($segments[0]));
        $payload = static::jsonDecode(url_safe_base64_decode($segments[1]));

        if (empty($header)) {
            throw new InvalidException('Invalid JWT header.');
        }

        if (empty($payload)) {
            throw new InvalidException('Invalid JWT payload.');
        }

        if (empty($key) || (!is_string($key) && !is_resource($key))) {
            throw new InvalidArgumentException('Invalid key.');
        }

        if (is_array($key) || $key instanceof ArrayAccess) {
            if (isset($header['kid']) && isset($key[$header['kid']])) {
                $key = $key[$header['kid']];
            } else {
                throw new InvalidException('Invalid "kid" value. Unable to lookup secret key.');
            }
        }

        $jwt = static::create($key, $header, $payload);

        if ($verify) {
            $jwt->verify($segments[2]);
        }

        return $jwt;
    }

    /**
     * Encodes the JWT object and returns the resulting hash.
     *
     * @return string
     * @throws JsonException
     * @throws RuntimeException
     */
    public function encode()
    {
        $segments   = [];
        $segments[] = url_safe_base64_encode(static::jsonEncode($this->header));
        $segments[] = url_safe_base64_encode(static::jsonEncode($this->payload));

        /**
         * Sign the JWT.
         */
        $dataToSign        = implode('.', $segments);
        $functionName      = static::$supportedAlgorithms[$this->algorithm][0];
        $functionAlgorithm = static::$supportedAlgorithms[$this->algorithm][1];
        $signature         = null;

        if ($functionName === 'hash_hmac') {
            $signature = hash_hmac($functionAlgorithm, $dataToSign, $this->key, true);
        } elseif ($functionName === 'openssl') {
            openssl_sign($dataToSign, $signature, $this->key, $functionAlgorithm);
        }

        if (empty($signature)) {
            throw new RuntimeException('Unable to sign the JWT.');
        }

        $segments[] = url_safe_base64_encode($signature);

        $this->hash = implode('.', $segments);

        return $this->hash;
    }

    /**
     * Gets the allowed hashing algorithms.
     *
     * @return array
     */
    public static function getAllowedAlgorithms()
    {
        if (empty(static::$allowedAlgorithms)) {
            return array_keys(static::$supportedAlgorithms);
        }

        return static::$allowedAlgorithms;
    }

    /**
     * Gets the current value of a given claim.
     *
     * @param string $name
     * @return mixed|null
     */
    public function getClaim($name)
    {
        if (isset($this->payload[$name])) {
            return $this->payload[$name];
        }

        return null;
    }

    /**
     * Gets the set of claims included in the JWT.
     *
     * @return array
     */
    public function getClaims()
    {
        return $this->getPayload();
    }

    /**
     * Gets the JWT hash, if the JWT has been encoded.
     *
     * @return string|null
     */
    public function getHash()
    {
        return $this->hash;
    }

    /**
     * Gets the JWT header.
     *
     * @return array
     */
    public function getHeader()
    {
        return $this->header;
    }

    /**
     * Gets the current value for a given header field.
     *
     * @param string $name
     * @return mixed|null
     */
    public function getHeaderField($name)
    {
        if (isset($this->header[$name])) {
            return $this->header[$name];
        }

        return null;
    }

    /**
     * Gets the JWT payload.
     *
     * @return array
     */
    public function getPayload()
    {
        return $this->payload;
    }

    /**
     * Encodes the given data to a JSON string.
     *
     * @param mixed $data
     * @return string
     * @throws JsonException
     */
    protected function jsonEncode($data)
    {
        $json = json_encode($data);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new JsonException(sprintf(
                'Unable to encode the given data (%s).',
                json_last_error()
            ));
        }

        return $json;
    }

    /**
     * Decodes a given JSON string.
     *
     * @param string $json
     * @return mixed
     * @throws JsonException
     */
    protected function jsonDecode($json)
    {
        $data = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new JsonException(sprintf(
                'Unable to decode the given JSON string (%s).',
                json_last_error()
            ));
        }

        return $data;
    }

    /**
     * Gets the name of the "current" claim in the payload array.
     *
     * @return string
     */
    public function key()
    {
        return key($this->payload);
    }

    /**
     * Advances the iterator to the "next" claim in the payload array.
     */
    public function next()
    {
        next($this->payload);
    }

    /**
     * Rewinds the payload iterator.
     */
    public function rewind()
    {
        reset($this->payload);
    }

    /**
     * Checks whether the current position in the payload array is valid.
     *
     * @return bool
     */
    public function valid()
    {
        $key = key($this->payload);

        return $key !== null && $key !== false;
    }

    /**
     * Verifies that the JWT is correctly formatted and that a given signature
     * is valid.
     *
     * @param string $rawSignature
     * @return bool
     * @throws BeforeValidException
     * @throws ExpiredException
     * @throws InvalidSignatureException
     */
    public function verify($rawSignature)
    {
        $dataToSign        = sprintf('%s.%s', $this->getHeader(), $this->getPayload());
        $functionName      = static::$supportedAlgorithms[$this->algorithm][0];
        $functionAlgorithm = static::$supportedAlgorithms[$this->algorithm][1];
        $signature         = url_safe_base64_decode($rawSignature);
        $verified          = false;

        if ($functionName === 'hash_hmac') {
            $hash     = hash_hmac($functionAlgorithm, $dataToSign, $this->key, true);
            $verified = hash_equals($signature, $hash);
        } elseif ($functionName === 'openssl') {
            $success = openssl_verify($dataToSign, $signature, $this->key, $functionAlgorithm);

            if ($success === 1) {
                $verified = true;
            }
        }

        if (!$verified) {
            throw new InvalidSignatureException('Invalid JWT signature.');
        }

        /**
         * Verify any time restriction that may have been set for the JWT.
         */
        $timestamp = time();

        if (isset($this->nbf) && is_numeric($this->nbf) && (float) $this->nbf > ($timestamp + static::$leeway)) {
            throw new BeforeValidException('The JWT is not yet valid.');
        }

        if (isset($this->iat) && is_numeric($this->iat) && (float) $this->iat > ($timestamp + static::$leeway)) {
            throw new BeforeValidException('The JWT is not yet valid.');
        }

        if (isset($this->exp) && is_numeric($this->exp) && (float) $this->exp <= ($timestamp - static::$leeway)) {
            throw new ExpiredException('The JWT has expired.');
        }

        return true;
    }
}
