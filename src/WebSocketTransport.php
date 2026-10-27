<?php

namespace Python_In_PHP;

use WebSocket\Client;

/**
 * Thin wrapper over phrity/websocket that hides the API split between the legacy
 * 1.x line (textalk heritage: options array, send(string), receive(): ?string) and
 * 3.x (fluent setters, text(), receive(): Message, exceptions on close), so the
 * library can accept whichever major a host project already resolves. 2.x is
 * excluded: its Client::setLogger() signature is incompatible with psr/log ≥ 2.
 */
class WebSocketTransport
{
    private Client $client;
    private bool $legacy;
    private ?int $closeStatus = null;

    public function __construct(string $uri, int $timeout, int $fragmentSize)
    {
        $this->legacy = !method_exists(Client::class, 'setFrameSize');

        if ($this->legacy) {
            $this->client = new Client($uri, ['timeout' => $timeout, 'fragment_size' => $fragmentSize]);
            return;
        }

        $this->client = new Client($uri);
        $this->client->setTimeout($timeout)->setFrameSize($fragmentSize);
        // Answer pings and acknowledge close frames like the legacy client did on its own
        $this->client->addMiddleware(new \WebSocket\Middleware\CloseHandler());
        $this->client->addMiddleware(new \WebSocket\Middleware\PingResponder());
        $this->client->connect();
    }

    public function send(string $payload): void
    {
        if ($this->legacy) {
            $this->client->send($payload);
        } else {
            $this->client->text($payload);
        }
    }

    /** Next text message, or null once the peer has closed the connection */
    public function receive(): ?string
    {
        if ($this->legacy) {
            $message = $this->client->receive();
            if ($message === null) {
                $this->closeStatus = $this->client->getCloseStatus();
            }
            return $message;
        }

        try {
            $message = $this->client->receive();
        } catch (\WebSocket\Exception\ConnectionClosedException) {
            return null;
        }
        if ($message === null || $message instanceof \WebSocket\Message\Close) {
            $this->closeStatus = $message?->getCloseStatus();
            return null;
        }
        return $message->getContent();
    }

    /** Close code reported by the peer, when receive() returned null because of a close frame */
    public function getCloseStatus(): ?int
    {
        return $this->closeStatus;
    }

    public function close(): void
    {
        $this->client->close();
        if (!$this->legacy) {
            $this->client->disconnect();
        }
    }
}
