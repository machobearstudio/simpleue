<?php
/**
 * User: Javier Bravo
 * Date: 10/05/15
 */

namespace Simpleue\Queue;

use Aws\Sqs\Exception\SqsException;
use Aws\Sqs\SqsClient;
use Simpleue\Exception\InvalidParameterException;
use Simpleue\Exception\MaxJobBatchSizeException;
use Simpleue\Locker\BaseLocker;

/*
 * AWS API 3.x doc : http://docs.aws.amazon.com/aws-sdk-php/v3/api/
 */

class SqsQueue implements Queue
{

    private $sqsClient;
    private $sourceQueueUrl;
    private $failedQueueUrl;
    private $errorQueueUrl;
    private $maxWaitingSeconds;
    private $visibilityTimeout;
    private $maxBatchSize = 10;
    /**
     * @var BaseLocker
     */
    private $locker;

    public function __construct(SqsClient $sqsClient, $queueName, $maxWaitingSeconds = 20, $visibilityTimeout = 30)
    {
        $this->sqsClient = $sqsClient;
        $this->maxWaitingSeconds = $maxWaitingSeconds;
        $this->visibilityTimeout = $visibilityTimeout;
        $this->setQueues($queueName);
    }

    public function setVisibilityTimeout($visibilityTimeout)
    {
        $this->visibilityTimeout = $visibilityTimeout;
        return $this;
    }

    public function setMaxWaitingSeconds($maxWaitingSeconds)
    {
        $this->maxWaitingSeconds = $maxWaitingSeconds;
        return $this;
    }

    public function setSourceQueueUrl($queueUrl)
    {
        $this->sourceQueueUrl = $queueUrl;
        return $this;
    }

    public function setFailedQueueUrl($queueUrl)
    {
        $this->failedQueueUrl = $queueUrl;
        return $this;
    }

    public function setErrorQueueUrl($queueUrl)
    {
        $this->errorQueueUrl = $queueUrl;
        return $this;
    }

    protected function setQueues($queueName)
    {
        $this->sourceQueueUrl = $this->getQueueUrl($queueName);
        $this->failedQueueUrl = $this->getQueueUrl($queueName . '-failed');
        $this->errorQueueUrl = $this->getQueueUrl($queueName . '-error');
    }

    protected function getQueueUrl($queueName)
    {
        try {
            $queueData = $this->sqsClient->getQueueUrl(['QueueName' => $queueName]);
        } catch (SqsException $ex) {
            throw $ex;
        }
        return $queueData->get('QueueUrl');
    }

    public function setSqsClient(SqsClient $sqsClient)
    {
        $this->sqsClient = $sqsClient;
        return $this;
    }

    /**
     * @param BaseLocker $locker
     */
    public function setLocker($locker)
    {
        $this->locker = $locker;
    }

    public function getNext()
    {
        $queueItem = $this->sqsClient->receiveMessage([
            'QueueUrl' => $this->sourceQueueUrl,
            'MaxNumberOfMessages' => 1,
            'WaitTimeSeconds' => $this->maxWaitingSeconds,
            'VisibilityTimeout' => $this->visibilityTimeout
        ]);
        if ($queueItem->hasKey('Messages')) {
            $msg = $queueItem->get('Messages')[0];
            if ($this->locker && $this->locker->lock($this->getMessageBody($msg), $this->visibilityTimeout) === false) {
                $this->error($msg);
                throw new \RuntimeException(
                    'Sqs msg lock cannot acquired!'
                    . ' LockId: ' . $this->locker->getJobUniqId($this->getMessageBody($msg))
                    . ' LockerInfo: ' . $this->locker->getLockerInfo()
                );
            }
            return $msg;
        }
        return false;
    }

    public function successful($job)
    {
        $this->deleteMessage($this->sourceQueueUrl, $job['ReceiptHandle']);
    }
    
    public function changeMessageVisibility($job, $visibilityTimeout)
    {
        $this->sqsClient->changeMessageVisibility([
            'QueueUrl' => $this->sourceQueueUrl,
            'ReceiptHandle' => $job['ReceiptHandle'],
            'VisibilityTimeout' => $visibilityTimeout
        ]);
        return;
    }

    protected function deleteMessage($queueUrl, $messageReceiptHandle)
    {
        $this->sqsClient->deleteMessage([
            'QueueUrl' => $queueUrl,
            'ReceiptHandle' => $messageReceiptHandle
        ]);
    }

    public function failed($job)
    {
        $this->sendMessage($this->failedQueueUrl, $job['Body']);
        $this->deleteMessage($this->sourceQueueUrl, $job['ReceiptHandle']);
        return;
    }

    private function sendMessage($queueUrl, $messageBody)
    {
        $this->sqsClient->sendMessage([
            'QueueUrl' => $queueUrl,
            'MessageBody' => $messageBody
        ]);
    }

    public function error($job)
    {
        $this->sendMessage($this->errorQueueUrl, $job['Body']);
        $this->deleteMessage($this->sourceQueueUrl, $job['ReceiptHandle']);
        return;
    }

    public function nothingToDo()
    {
        return;
    }

    public function resend($job)
    {
        return;
    }

    public function stopped($job)
    {
        $this->deleteMessage($this->sourceQueueUrl, $job['ReceiptHandle']);
        return;
    }

    public function getMessageBody($job)
    {
        return $job['Body'];
    }

    public function toString($job)
    {
        return json_encode($job);
    }

    public function sendJob($job)
    {
        $this->sendMessage($this->sourceQueueUrl, $job);
    }

    /**
     * Jobs must be a string array. jobs array indexes are used as sqs batch id
     * The number of jobs must be 10 or less
     *
     * @param string[] $jobs
     *
     * @throws InvalidParameterException
     * @throws MaxJobBatchSizeException
     */
    public function sendJobBatch($jobs)
    {
        if (!is_array($jobs)) {
            throw new InvalidParameterException(
                "Jobs param is not array"
            );
        }

        if (count($jobs) > $this->maxBatchSize) {
            throw new MaxJobBatchSizeException(
                "Jobs param has jobs more than supported batch size (" . $this->maxBatchSize . ")"
            );
        }

        $batchJob = [];
        foreach ($jobs as $key => $job) {
            $batchJob[] = ["Id" => $key, "MessageBody" => $job];
        }

        $this->sendMessageBatch($batchJob);
    }


    /**
     * @param array $batchJob
     */
    private function sendMessageBatch($batchJob)
    {
        $this->sqsClient->sendMessageBatch([
            'QueueUrl' => $this->sourceQueueUrl,
            'Entries' => $batchJob
        ]);
    }
}