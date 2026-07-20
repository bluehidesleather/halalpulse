<?php

declare(strict_types=1);

namespace HalalPulse\Alerts;

use HalalPulse\Support\JsonLogger;
use Throwable;

final readonly class AlertDispatcher
{
    public function __construct(
        private AlertRepository $repository,
        private AlertMessageBuilder $messageBuilder,
        private TelegramBotClient $client,
        private JsonLogger $logger,
    ) {
    }

    /** @return list<array{score_id:int,delivery_id:int,status:string,provider_status?:string}> */
    public function dispatch(AlertRecipient $recipient, int $limit): array
    {
        $results = [];
        foreach ($this->repository->eligibleCandidates($recipient->channel, $recipient->recipientHash, $limit) as $candidate) {
            $body = $this->messageBuilder->build($candidate);
            $reservation = $this->repository->reserve(
                (int) $candidate['score_id'],
                $recipient->id,
                $recipient->channel,
                $recipient->recipientHash,
                hash('sha256', $body),
            );
            if ($reservation === null) {
                continue;
            }
            $results[] = $this->sendReserved($candidate, $body, $reservation, $recipient);
        }

        return $results;
    }

    /** @param array<string,mixed> $candidate @return array{score_id:int,delivery_id:int,status:string,provider_status?:string} */
    public function sendReserved(array $candidate, string $body, AlertReservation $reservation, AlertRecipient $recipient): array
    {
        $scoreId = (int) $candidate['score_id'];
        try {
            $providerResult = $this->client->send($recipient->address, $body);
            $this->repository->markAccepted($reservation, $providerResult);
            $this->logger->info('Telegram alert accepted by provider.', [
                'score_id' => $scoreId,
                'delivery_id' => $reservation->deliveryId,
                'recipient_id' => $recipient->id,
                'provider_status' => $providerResult->status,
            ]);

            return [
                'score_id' => $scoreId,
                'delivery_id' => $reservation->deliveryId,
                'status' => 'accepted',
                'provider_status' => $providerResult->status,
            ];
        } catch (TelegramApiException $exception) {
            $unknown = !$exception->outcomeKnown;
            $safeMessage = self::safeError($exception->getMessage(), [$recipient->address]);
            $this->repository->markFailure($reservation, $unknown, $exception->providerCode, $safeMessage);
            $status = $unknown ? 'unknown' : 'failed';
            $this->logger->error('Telegram alert provider request failed.', [
                'score_id' => $scoreId,
                'delivery_id' => $reservation->deliveryId,
                'recipient_id' => $recipient->id,
                'status' => $status,
                'http_status' => $exception->httpStatus,
                'provider_code' => $exception->providerCode,
            ]);

            return ['score_id' => $scoreId, 'delivery_id' => $reservation->deliveryId, 'status' => $status];
        } catch (Throwable $exception) {
            $this->repository->markFailure($reservation, true, null, 'Unexpected delivery failure; provider acceptance is unknown.');
            $this->logger->error('Telegram alert delivery failed unexpectedly.', [
                'score_id' => $scoreId,
                'delivery_id' => $reservation->deliveryId,
                'recipient_id' => $recipient->id,
                'exception' => $exception::class,
            ]);

            return ['score_id' => $scoreId, 'delivery_id' => $reservation->deliveryId, 'status' => 'unknown'];
        }
    }

    /** @param list<string> $sensitiveValues */
    public static function safeError(string $message, array $sensitiveValues = []): string
    {
        foreach ($sensitiveValues as $sensitiveValue) {
            if ($sensitiveValue !== '') {
                $message = str_replace($sensitiveValue, '[redacted-recipient]', $message);
            }
        }
        $message = (string) preg_replace('/[1-9][0-9]{5,15}:[A-Za-z0-9_-]{20,100}/', '[redacted-token]', $message);
        $message = (string) preg_replace('/(?<![0-9])-?[1-9][0-9]{7,18}(?![0-9])/', '[redacted-recipient]', $message);

        return mb_substr(trim($message), 0, 1000);
    }
}
