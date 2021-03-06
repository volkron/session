<?php declare(strict_types=1);
/**
 * Session - Securely manage and preserve session data.
 *
 * @license MIT License. (https://github.com/Commander-Ant-Screwbin-Games/session/blob/master/license)
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 * 
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 * https://github.com/Commander-Ant-Screwbin-Games/firecms/tree/master/src/Core
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 *
 * @package Commander-Ant-Screwbin-Games/session.
 */

namespace Session;

use ParagonIE\Halite\KeyFactory;
use Symfony\Component\OptionsResolver\OptionsResolver;
use SessionHandlerInterface;

/**
 * Secure session management.
 *
 * You can start a session using the static method `SessionManager::start(...)` which
 * is compatible to PHP's built-in `session_start()` function.
 *
 * @class SessionManager.
 */
final class SessionManager implements SessionManagerInterface
{

    /** @var array $options The session manager options. */
    private $options;

    /** @var bool $exceptions Should we utilize exceptions. */
    private $exceptions;

    /**
     * {@inheritdoc}
     */
    public function __construct(array $options = [], bool $exceptions = \true)
    {
        $this->setExceptions($exceptions);
        $this->setOptions($options);
        $this->setSaveHandler();
    }

    /**
     * {@inheritdoc}
     */
    public function setOptions(array $options = []): SessionManagerInterface
    {
        $resolver = new OptionsResolver();
        $this->configureOptions($resolver);
        $this->options = $resolver->resolve($options);
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function setExceptions(bool $exceptions = \true): SessionManagerInterface
    {
        $this->exceptions = $exceptions;
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function setSaveHandler(SessionHandlerInterface $sessionHandler = \null): void
    {
        if (\is_null($sessionHandler)) {
            $sessionHandler = new NativeSessionHandler();
        }
        /** @psalm-suppress PossiblyUndefinedMethod **/
        /** @psalm-suppress UndefinedInterfaceMethod **/
        $sessionHandler->setStore(new EncrypterStore($this->options['session_encrypt_key'], $this->options['session_encrypt']));
        \session_set_save_handler($sessionHandler, \true);
    }

    /**
     * {@inheritdoc}
     */
    public function start(): bool
    {
        $result = @\session_start($this->options['session_config']);
        if ($this->options['session_fingerprint']) {
            // @codeCoverageIgnoreStart
            if ($fingerprint = $this->get('kooser.session.fingerprint')) {
                if (!\hash_equals($fingerprint, $this->getFingerprint())) {
                    $this->stop();
                    if ($this->exceptions) {
                        throw new Exception\InvalidFingerprintException('The fingerprint supplied is invalid.');
                    }
                    \trigger_error('The fingerprint supplied is invalid.', \E_USER_ERROR);
                }
            } else {
                $this->put('kooser.session.fingerprint', $this->getFingerprint());
            }
            // @codeCoverageIgnoreEnd
        }
        return (bool) $result;
    }

    /**
     * {@inheritdoc}
     */
    public function stop(): bool
    {
        $_SESSION = array();
        if ($this->options['session_config']["use_cookies"]) {
            $params = \session_get_cookie_params();
            \setcookie(
                \session_name(),
                '',
                \time() - 42000,
                $params["path"],
                $params["domain"],
                $params["secure"],
                $params["httponly"]
            );
        }
        return (bool) @\session_destroy();
    }

    /**
     * {@inheritdoc}
     *
     * @codeCoverageIgnore
     */
    public static function exists(): bool
    {
        if (\php_sapi_name() !== 'cli') {
            return (bool) (\session_status() === \PHP_SESSION_ACTIVE) ? \true : \false;
        }
        return (bool) \false;
    }

    /**
     * {@inheritdoc}
     */
    public static function regenerate(bool $deleteOldSession = \true): bool
    {
        return (bool) \session_regenerate_id($deleteOldSession);
    }

    /**
     * {@inheritdoc}
     */
    public function has(string $key): bool
    {
        return isset($_SESSION[$key]);
    }

    /**
     * {@inheritdoc}
     */
    public static function get(string $key, $defaultValue = \null)
    {
        if (isset($_SESSION[$key])) {
            return $_SESSION[$key];
        }
        return $defaultValue;
    }

    /**
     * {@inheritdoc}
     */
    public static function flash(string $key, $defaultValue = \null)
    {
        if (isset($_SESSION[$key])) {
            $value = $_SESSION[$key];
            unset($_SESSION[$key]);
            return $value;
        }
        return $defaultValue;
    }

    /**
     * {@inheritdoc}
     */
    public static function put(string $key, $value): void
    {
        $_SESSION[$key] = $value;
    }

    /**
     * {@inheritdoc}
     */
    public static function delete(string $key): void
    {
        unset($_SESSION[$key]);
    }

    /**
     * Get the fingerprint from the current accessing user.
     *
     * @throws Exception\IPAddressNotFoundException If the ip address could not be retrieved.
     * @throws Exception\UserAgentNotFoundException If the user agent could not be retrieved.
     *
     * @return string Returns the unique fingerprint.
     */
    private function getFingerprint(): string
    {
        $ip = 'null';
        if ($this->options['session_lock_to_ip_address']) {
            $remoteIp = isset($_SERVER['REMOTE_ADDR'])
                ? $_SERVER['REMOTE_ADDR']
                : 'null';
            $ip = $this->options['session_pass_ip_address'] == ''
                ? $remoteIp
                : $this->options['session_pass_ip_address'];
            if ($ip == 'null' && $this->exceptions) {
                // @codeCoverageIgnoreStart
                throw new Exception\IPAddressNotFoundException('The ip address could not be retrieved.');
                // @codeCoverageIgnorEnd
            }
        }
        $ua = 'null';
        if ($this->options['session_lock_to_user_agent']) {
            $ua = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : 'null';
            if ($ua == 'null' && $this->exceptions) {
                // @codeCoverageIgnoreStart
                throw new Exception\UserAgentNotFoundException('The user agent could not be retrieved.');
                // @codeCoverageIgnoreEnd
            }
        }
        $raw_fingerprint = \sprintf(
            '%s|%s',
            $ip,
            $ua
        );
        return (string) \hash_hmac($this->options['session_fingerprint_hash'], $raw_fingerprint, $this->options['session_security_code']);
    }

    /**
     * Configure the hasher options.
     *
     * @param OptionsResolver The symfony options resolver.
     *
     * @return void Returns nothing.
     */
    private function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'session_encrypt'            => \false,
            'session_encrypt_key'        => KeyFactory::generateEncryptionKey(),
            'session_fingerprint'        => \true,
            'session_fingerprint_hash'   => 'sha512',
            'session_lock_to_ip_address' => \true,
            'session_lock_to_user_agent' => \true,
            'session_pass_ip_address'    => '',
            'session_config' => [
                'use_cookies'      => \true,
                'use_only_cookies' => \true,
                'cookie_httponly'  => \true,
                'cookie_samesite'  => 'Lax',
                'use_strict_mode'  => \true,
            ],
        ]);
        $resolver->setRequired('session_security_code');
        $resolver->setAllowedTypes('session_encrypt', 'bool');
        $resolver->setAllowedTypes('session_fingerprint', 'bool');
        $resolver->setAllowedTypes('session_fingerprint_hash', 'string');
        $resolver->setAllowedTypes('session_lock_to_ip_address', 'bool');
        $resolver->setAllowedTypes('session_lock_to_user_agent', 'bool');
        $resolver->setAllowedTypes('session_pass_ip_address', 'string');
        $resolver->setAllowedTypes('session_config', 'array');
        $resolver->setAllowedTypes('session_security_code', 'string');
    }
}
