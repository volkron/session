<?php
declare(strict_types=1);
/**
 * Kooser Session - Securely manage and preserve session data.
 * 
 * @package Kooser\Session.
 */

namespace Kooser\Session;

use SessionHandlerInterface;

/**
 * A null session handler for testing.
 */
class NullSessionHandler implements SessionHandlerInterface
{

    /**
     * {@inheritdoc}
     */
    public function open($savePath, $sessionName)
    {
        return \true;
    }

    /**
     * {@inheritdoc}
     */
    public function close()
    {
        return \true;
    }

    /**
     * {@inheritdoc}
     */
    public function read($sessionId)
    {
        return '';
    }

    /**
     * {@inheritdoc}
     */
    public function write($sessionId, $data)
    {
        return \true;
    }

    /**
     * {@inheritdoc}
     */
    public function destroy($sessionId)
    {
        return \true;
    }

    /**
     * {@inheritdoc}
     */
    public function gc($lifetime)
    {
        return \true;
    }
}