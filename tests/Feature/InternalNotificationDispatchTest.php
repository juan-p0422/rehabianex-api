<?php

namespace Tests\Feature;

use App\Services\DueNotificationDispatcher;
use Mockery\MockInterface;
use Tests\TestCase;

class InternalNotificationDispatchTest extends TestCase
{
    private const PATH = '/api/internal/notifications/dispatch-due';

    private const SECRET = 'scheduler-test-secret-with-at-least-32-characters';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'fcm.scheduler.secret' => self::SECRET,
            'fcm.scheduler.max_clock_skew_seconds' => 300,
        ]);
    }

    public function test_endpoint_rejects_requests_without_scheduler_signature(): void
    {
        $this->mock(DueNotificationDispatcher::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('dispatch');
        });

        $this->post(self::PATH)
            ->assertUnauthorized()
            ->assertExactJson([
                'ok' => false,
                'message' => 'Solicitud no autorizada.',
            ]);
    }

    public function test_endpoint_rejects_stale_scheduler_signature(): void
    {
        $this->mock(DueNotificationDispatcher::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('dispatch');
        });
        $timestamp = (string) now()->subMinutes(10)->timestamp;

        $this->withHeaders($this->signedHeaders($timestamp))
            ->post(self::PATH)
            ->assertUnauthorized();
    }

    public function test_endpoint_dispatches_with_valid_signature_and_returns_counts_only(): void
    {
        $this->mock(DueNotificationDispatcher::class, function (MockInterface $mock): void {
            $mock->shouldReceive('dispatch')
                ->once()
                ->andReturn(['agenda' => 1, 'check_in' => 2]);
        });
        $timestamp = (string) now()->timestamp;

        $this->withHeaders($this->signedHeaders($timestamp))
            ->post(self::PATH)
            ->assertOk()
            ->assertExactJson([
                'ok' => true,
                'processed' => [
                    'agenda' => 1,
                    'check_in' => 2,
                    'total' => 3,
                ],
            ]);
    }

    public function test_endpoint_rejects_arbitrary_payload(): void
    {
        $this->mock(DueNotificationDispatcher::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('dispatch');
        });
        $timestamp = (string) now()->timestamp;

        $this->withHeaders($this->signedHeaders($timestamp))
            ->postJson(self::PATH, ['type' => 'appointment_reminder'])
            ->assertUnprocessable()
            ->assertExactJson([
                'ok' => false,
                'message' => 'Este endpoint no acepta payload.',
            ]);
    }

    /**
     * @return array<string, string>
     */
    private function signedHeaders(string $timestamp): array
    {
        $canonical = implode("\n", [$timestamp, 'POST', self::PATH]);

        return [
            'X-Rehabianex-Scheduler-Timestamp' => $timestamp,
            'X-Rehabianex-Scheduler-Signature' => hash_hmac('sha256', $canonical, self::SECRET),
        ];
    }
}
