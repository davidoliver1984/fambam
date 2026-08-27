<?php

namespace App\FaceAnalysis;

interface FaceAnalysisResultQueue
{
    /** @return list<ReceivedFaceAnalysisMessage> */
    public function receive(string $queue): array;

    public function delete(string $queue, string $receiptHandle): void;
}
