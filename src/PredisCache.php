<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace MCP\Cache;

use MCP\Cache\Utility\KeySaltingTrait;
use Predis\Client;

/**
 * @internal
 */
class PredisCache implements CacheInterface
{
    use KeySaltingTrait;

    /**
     * Properties read and used by the salting trait.
     *
     * @var string
     */
    const PREFIX = 'mcp-cache';
    const DELIMITER = ':';

    /**
     * @var client
     */
    private $predis;

    /**
     * @var string|null
     */
    private $suffix;

    /**
     * @var int
     */
    private $maximumTtl;

    /**
     * An optional suffix can be provided which will be appended to the key
     * used to store and retrieve data. This can be used to throw away cached
     * data with a code push or other configuration change.
     *
     * @param Client $client
     * @param string|null $suffix
     */
    public function __construct(Client $client, $suffix = null)
    {
        $this->predis = $client;
        $this->suffix = $suffix;

        $this->maximumTtl = 0;
    }

    /**
     * This cacher performs serialization and unserialization. All string responses from redis will be unserialized.
     *
     * For this reason, only mcp cachers should attempt to retrieve data cached by mcp cachers.
     * {@inheritdoc}
     */
    public function get($key)
    {
        $key = $this->salted($key, $this->suffix);

        $raw = $this->predis->get($key);

        // every response should be a php serialized string.
        // values not matching this pattern will explode.
        // missing data should return null, which is not unserialized.
        $value = (is_string($raw)) ? unserialize($raw) : $raw;

        return $value;
    }

    /**
     * {@inheritdoc}
     */
    public function set($key, $value, $ttl = 0)
    {
        $key = $this->salted($key, $this->suffix);

        // handle deletions
        if ($value === null) {
            $this->predis->del($key);
            return true;
        }

        $value = serialize($value);

        // Resolve the TTL to use
        $ttl = $this->determineTtl($ttl);

        // set with expiration
        if ($ttl > 0) {
            $this->predis->setex($key, $ttl, $value);
            return true;
        }

        // set with no expiration
        $this->predis->set($key, $value);
        return true;
    }

    /**
     * Set the maximum TTL in seconds.
     *
     * @param int $ttl
     * @return null
     */
    public function setMaximumTtl($ttl)
    {
        $this->maximumTtl = (int) $ttl;
    }

    /**
     * @param int $ttl
     * @return int
     */
    private function determineTtl($ttl)
    {
        // if no max is set, use the user provided value
        if (!$this->maximumTtl) {
            return $ttl;
        }

        // if the provided ttl is over the maximum ttl, use the max.
        if ($this->maximumTtl < $ttl) {
            return $this->maximumTtl;
        }

        // If no ttl is set, use the max
        if ($ttl == 0) {
            return $this->maximumTtl;
        }

        return $ttl;
    }
}