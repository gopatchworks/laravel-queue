<?php
namespace Enqueue\LaravelQueue;

use Illuminate\Support\Facades\Config;
use Enqueue\Consumption\ChainExtension;
use Enqueue\Consumption\Context\MessageReceived;
use Enqueue\Consumption\Context\MessageResult;
use Enqueue\Consumption\Context\PostMessageReceived;
use Enqueue\Consumption\Context\PreConsume;
use Enqueue\Consumption\Context\Start;
use Enqueue\Consumption\Extension\LimitConsumedMessagesExtension;
use Enqueue\Consumption\MessageReceivedExtensionInterface;
use Enqueue\Consumption\MessageResultExtensionInterface;
use Enqueue\Consumption\PostMessageReceivedExtensionInterface;
use Enqueue\Consumption\PreConsumeExtensionInterface;
use Enqueue\Consumption\QueueConsumer;
use Enqueue\Consumption\Result;
use Enqueue\Consumption\StartExtensionInterface;
use Illuminate\Queue\WorkerOptions;
use Throwable;

class Worker extends \Illuminate\Queue\Worker implements
    StartExtensionInterface,
    PreConsumeExtensionInterface,
    MessageReceivedExtensionInterface,
    PostMessageReceivedExtensionInterface,
    MessageResultExtensionInterface
{
    protected $connectionName;

    protected $queueNames;

    protected $queue;

    protected $options;

    protected $lastRestart;

    protected $interop = false;

    protected $stopped = false;

    protected $job;

    public function daemon($connectionName, $queueNames, WorkerOptions $options)
    {
        $this->connectionName = $connectionName;
        $this->queueNames = $queueNames;
        $this->options = $options;

        /** @var Queue $queue */
        $this->queue = $this->getManager()->connection($connectionName);
        $this->interop = $this->queue instanceof Queue;

        if (false == $this->interop) {
            parent::daemon($connectionName, $this->queueNames, $options);
            return;
        }

        $context = $this->queue->getQueueInteropContext();
        $queueConsumer = new QueueConsumer($context, new ChainExtension([$this]));
        foreach (explode(',', $queueNames) as $queueName) {
            $queueConsumer->bindCallback($queueName, function() {
                $this->runJob($this->job, $this->connectionName, $this->options);

                return Result::ALREADY_ACKNOWLEDGED;
            });
        }

        $queueConsumer->consume();
    }

    public function runNextJob($connectionName, $queueNames, WorkerOptions $options)
    {
        $this->connectionName = $connectionName;
        $this->queueNames = $queueNames;
        $this->options = $options;

        /** @var Queue $queue */
        $this->queue = $this->getManager()->connection($connectionName);
        $this->interop = $this->queue instanceof Queue;

        if (false == $this->interop) {
            parent::runNextJob($connectionName, $this->queueNames, $options);
            return;
        }

        $context = $this->queue->getQueueInteropContext();

        $queueConsumer = new QueueConsumer($context, new ChainExtension([
            $this,
            new LimitConsumedMessagesExtension(1),
        ]));

        foreach (explode(',', $queueNames) as $queueName) {
            $queueConsumer->bindCallback($queueName, function() {
                $this->runJob($this->job, $this->connectionName, $this->options);

                return Result::ALREADY_ACKNOWLEDGED;
            });
        }

        $queueConsumer->consume();
    }

    public function onStart(Start $context): void
    {
        if ($this->supportsAsyncSignals()) {
            $this->listenForSignals();
        }

        $this->lastRestart = $this->getTimestampOfLastQueueRestart();

        if ($this->stopped) {
            $context->interruptExecution();
        }
    }

    public function onPreConsume(PreConsume $context): void
    {
        if (! $this->daemonShouldRun($this->options, $this->connectionName, $this->queueNames)) {
            $this->pauseWorker($this->options, $this->lastRestart);
        }

        if ($this->stopped) {
            $context->interruptExecution();
        }
    }

    public function onMessageReceived(MessageReceived $context): void
    {
        if (Config::has('queue.connections.interop.acknowledge_on_receive')
            && Config::get('queue.connections.interop.acknowledge_on_receive') === true) {
            ($context->getConsumer())->acknowledge($context->getMessage());
        }

        $this->job = $this->queue->convertMessageToJob(
            $context->getMessage(),
            $context->getConsumer()
        );

        if ($this->supportsAsyncSignals()) {
            $this->registerTimeoutHandler($this->job, $this->options);
        }
    }

    public function onPostMessageReceived(PostMessageReceived $context): void
    {
        $this->stopIfNecessary($this->options, $this->lastRestart, $this->job);

        if ($this->stopped) {
            $context->interruptExecution();
        }
    }

    public function onResult(MessageResult $context): void
    {
        if ($this->supportsAsyncSignals()) {
            $this->resetTimeoutHandler();
        }
    }

    public function stop($status = 0)
    {
        if ($this->interop) {
            $this->stopped = true;

            return;
        }

        parent::stop($status);
    }

    /**
     * Handle an exception that occurred while the job was running.
     *
     * @param  string  $connectionName
     * @param  \Illuminate\Contracts\Queue\Job  $job
     * @param  \Illuminate\Queue\WorkerOptions  $options
     * @param  \Throwable  $e
     * @return void
     *
     * @throws \Throwable
     */
    protected function handleJobException($connectionName, $job, WorkerOptions $options, Throwable $e)
    {
        // if we do not fail job it will be re-queued potentially inifinately
        if (Config::get('queue.connections.interop.fail_job_on_exception') === true) {
            $job->fail($e);
        }

        parent::handleJobException($connectionName, $job, $options, $e);
    }
}

