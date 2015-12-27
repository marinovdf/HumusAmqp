<?php
/*
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
 * "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
 * LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR
 * A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT
 * OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL,
 * SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT
 * LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE,
 * DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY
 * THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
 * OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 * This software consists of voluntary contributions made by many individuals
 * and is licensed under the MIT license.
 */

namespace Humus\Amqp;

use AMQPEnvelope;
use AMQPQueue;
use Assert\Assertion;
use InfiniteIterator;

/**
 * Class AbstractMultiQueueConsumer
 * @package Humus\Amqp
 */
abstract class AbstractMultiQueueConsumer implements Consumer
{
    /**
     * @var InfiniteIterator
     */
    protected $queues;

    /**
     * Number of consumed messages
     *
     * @var int
     */
    protected $countMessagesConsumed = 0;

    /**
     * Number of unacked messaged
     *
     * @var int
     */
    protected $countMessagesUnacked = 0;

    /**
     * Last delivery tag seen
     *
     * @var string
     */
    protected $lastDeliveryTag;

    /**
     * @var
     */
    protected $keepAlive = true;

    /**
     * Idle timeout in seconds
     *
     * @var float
     */
    protected $idleTimeout;

    /**
     * Wait timeout in microseconds
     *
     * @var int
     */
    protected $waitTimeout;

    /**
     * The blocksize (see prefetch_count)
     *
     * @var int
     */
    protected $blockSize;

    /**
     * @var float
     */
    protected $timestampLastAck;

    /**
     * @var float
     */
    protected $timestampLastMessage;

    /**
     * How many messages we want to consume
     *
     * @var int
     */
    protected $target;

    /**
     * @var callable
     */
    protected $deliveryCallback;

    /**
     * @var callable
     */
    protected $flushCallback;

    /**
     * @var callable
     */
    protected $errorCallback;

    /**
     * @var bool
     */
    protected $usePcntlSignalDispatch = false;

    /**
     * Start consumer
     *
     * @param int $msgAmount
     */
    public function consume($msgAmount = 0)
    {
        Assertion::min($msgAmount, 0);

        $this->target = $msgAmount;

        foreach ($this->queues as $index => $queue) {
            if (!$this->timestampLastAck) {
                $this->timestampLastAck = microtime(true);
            }

            $message = $queue->get();

            if ($message instanceof AMQPEnvelope) {
                try {
                    $processFlag = $this->handleDelivery($message, $queue);
                } catch (\Exception $e) {
                    $this->handleException($e);
                    $processFlag = false;
                }
                $this->handleProcessFlag($message, $processFlag);
            } elseif (0 == $index) { // all queues checked, no messages found
                usleep($this->waitTimeout);
            }

            $now = microtime(true);

            if ($this->countMessagesUnacked > 0
                && ($this->countMessagesUnacked === $this->blockSize
                    || ($now - $this->timestampLastAck) > $this->idleTimeout
                )) {
                $this->ackOrNackBlock();
            }

            if ($this->usePcntlSignalDispatch) {
                // Check for signals
                pcntl_signal_dispatch();
            }

            if (!$this->keepAlive || (0 != $this->target && $this->countMessagesConsumed >= $this->target)) {
                break;
            }
        }
    }

    /**
     * @param AMQPEnvelope $message
     * @param AMQPQueue $queue
     * @return bool|null
     */
    /**
     * @param AMQPEnvelope $message
     * @param AMQPQueue $queue
     * @return bool|null
     */
    protected function handleDelivery(AMQPEnvelope $message, AMQPQueue $queue)
    {
        $callback = $this->deliveryCallback;

        return $callback($message, $queue, $this);
    }

    /**
     * Shutdown consumer
     *
     * @return void
     */
    public function shutdown()
    {
        $this->keepAlive = false;
    }

    /**
     * Handle exception
     *
     * @param \Exception $e
     * @return void
     */
    protected function handleException(\Exception $e)
    {
        $callback = $this->errorCallback;

        $callback($e, $this);
    }

    /**
     * Process buffered (unacked) messages
     *
     * Messages are deferred until the block size (see prefetch_count) or the timeout is reached
     * The unacked messages will also be flushed immediately when the handleDelivery method returns true
     *
     * @return bool
     */
    protected function flushDeferred()
    {
        $callback = $this->flushCallback;

        if (null === $callback) {
            return true;
        }

        try {
            $result = $callback($this);
        } catch (\Exception $e) {
            $result = false;
            $this->handleException($e);
        }

        return $result;
    }

    /**
     * Handle process flag
     *
     * @param AMQPEnvelope $message
     * @param $flag
     * @return void
     */
    protected function handleProcessFlag(AMQPEnvelope $message, $flag)
    {
        if ($flag === self::MSG_REJECT || false === $flag) {
            $this->ackOrNackBlock();
            $this->getQueue()->reject($message->getDeliveryTag(), AMQP_NOPARAM);
        } elseif ($flag === self::MSG_REJECT_REQUEUE) {
            $this->ackOrNackBlock();
            $this->getQueue()->reject($message->getDeliveryTag(), AMQP_REQUEUE);
        } elseif ($flag === self::MSG_ACK || true === $flag) {
            $this->countMessagesConsumed++;
            $this->countMessagesUnacked++;
            $this->lastDeliveryTag = $message->getDeliveryTag();
            $this->timestampLastMessage = microtime(true);
            $this->ack();
        } else { // $flag === self::MSG_DEFER || null === $flag
            $this->countMessagesConsumed++;
            $this->countMessagesUnacked++;
            $this->lastDeliveryTag = $message->getDeliveryTag();
            $this->timestampLastMessage = microtime(true);
        }
    }

    /**
     * Get the current queue
     *
     * @return AMQPQueue
     */
    protected function getQueue()
    {
        return $this->queues->current();
    }

    /**
     * Ack all deferred messages
     *
     * This will be called every time the block size (see prefetch_count) or timeout is reached
     *
     * @return void
     */
    protected function ack()
    {
        $this->getQueue()->ack($this->lastDeliveryTag, AMQP_MULTIPLE);
        $this->lastDeliveryTag = null;
        $this->timestampLastAck = microtime(true);
        $this->countMessagesUnacked = 0;
    }

    /**
     * Send nack for all deferred messages
     *
     * @param bool $requeue
     * @return void
     */
    protected function nackAll($requeue = false)
    {
        $flags = AMQP_MULTIPLE;
        if ($requeue) {
            $flags |= AMQP_REQUEUE;
        }
        $this->getQueue()->nack($this->lastDeliveryTag, $flags);
    }

    /**
     * Handle deferred acks
     *
     * @return void
     */
    protected function ackOrNackBlock()
    {
        if (! $this->lastDeliveryTag) {
            return;
        }

        try {
            $deferredFlushResult = $this->flushDeferred();
        } catch (\Exception $e) {
            $deferredFlushResult = false;
        }

        if (true === $deferredFlushResult) {
            $this->ack();
        } else {
            $this->nackAll();
            $this->lastDeliveryTag = null;
        }
        $this->countMessagesUnacked = 0;
    }
}