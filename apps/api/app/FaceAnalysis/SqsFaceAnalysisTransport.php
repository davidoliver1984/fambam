<?php

namespace App\FaceAnalysis;

use Aws\Sqs\SqsClient;
use JsonException;

class SqsFaceAnalysisTransport implements FaceAnalysisRequestPublisher, FaceAnalysisResultQueue
{
    private SqsClient $client;

    public function __construct()
    {
        $configuration = [
            'version' => 'latest',
            'region' => (string) config('queue.connections.sqs.region'),
        ];
        $key = config('queue.connections.sqs.key');
        if (is_string($key) && $key !== '') {
            $configuration['credentials'] = [
                'key' => $key,
                'secret' => (string) config('queue.connections.sqs.secret'),
            ];
        }
        $endpoint = config('queue.connections.sqs.endpoint');
        if (is_string($endpoint) && $endpoint !== '') {
            $configuration['endpoint'] = $endpoint;
        }
        $this->client = new SqsClient($configuration);
    }

    /** @param array<string, mixed> $message */
    public function publish(array $message): void
    {
        try {
            $body = json_encode($message, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw new \RuntimeException('Face-analysis request could not be encoded.', previous: $exception);
        }
        $this->client->sendMessage([
            'QueueUrl' => $this->queueUrl('requested'),
            'MessageBody' => $body,
        ]);
    }

    public function receive(string $queue): array
    {
        $result = $this->client->receiveMessage([
            'QueueUrl' => $this->queueUrl($queue),
            'MaxNumberOfMessages' => 10,
            'WaitTimeSeconds' => (int) config('image-analysis.queues.wait_time_seconds'),
            'VisibilityTimeout' => (int) config('image-analysis.queues.visibility_timeout_seconds'),
        ]);
        $messages = [];
        foreach ($result['Messages'] ?? [] as $message) {
            if (is_string($message['Body'] ?? null) && is_string($message['ReceiptHandle'] ?? null)) {
                $messages[] = new ReceivedFaceAnalysisMessage($message['Body'], $message['ReceiptHandle']);
            }
        }

        return $messages;
    }

    public function delete(string $queue, string $receiptHandle): void
    {
        $this->client->deleteMessage([
            'QueueUrl' => $this->queueUrl($queue),
            'ReceiptHandle' => $receiptHandle,
        ]);
    }

    private function queueUrl(string $queue): string
    {
        $name = config("image-analysis.queues.{$queue}");
        if (! is_string($name) || $name === '') {
            throw new \InvalidArgumentException('Unknown face-analysis queue.');
        }

        return rtrim((string) config('queue.connections.sqs.prefix'), '/').'/'.$name;
    }
}
