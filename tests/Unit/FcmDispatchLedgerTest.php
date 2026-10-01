<?php

namespace Tests\Unit;

use App\Services\FcmDispatchLedger;
use App\Services\FirebaseService;
use Tests\TestCase;

class FcmDispatchLedgerTest extends TestCase
{
    public function test_ledger_hashes_private_key_claims_once_and_records_sent_state(): void
    {
        $db = new LedgerFakeDatabase;
        $ledger = $this->ledger($db);
        $privateKey = 'risk_alert:private-note:private-supervisor';
        $dedupeId = $ledger->dedupeId($privateKey);

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $dedupeId);
        $this->assertStringNotContainsString('private', $dedupeId);
        $this->assertTrue($ledger->claim($dedupeId, 'risk_alert', 'rn_safe'));
        $this->assertFalse($ledger->claim($dedupeId, 'risk_alert', 'rn_safe'));

        $stored = $db->documents['notification_dispatches'][$dedupeId];
        $this->assertSame('claimed', $stored['status']);
        $this->assertSame(1, $stored['attempts']);
        $this->assertStringNotContainsString('private-note', json_encode($stored, JSON_THROW_ON_ERROR));
        $this->assertArrayNotHasKey('dedupe_key', $stored);

        $ledger->markSent($dedupeId);

        $this->assertSame('sent', $db->documents['notification_dispatches'][$dedupeId]['status']);
        $this->assertNotNull($db->documents['notification_dispatches'][$dedupeId]['sent_at']);
        $this->assertFalse($ledger->claim($dedupeId, 'risk_alert', 'rn_safe'));
    }

    public function test_failed_claim_can_retry_and_distinct_events_do_not_block_each_other(): void
    {
        $db = new LedgerFakeDatabase;
        $ledger = $this->ledger($db);
        $first = $ledger->dedupeId('relapse_alert:note-1:supervisor');
        $second = $ledger->dedupeId('relapse_alert:note-2:supervisor');

        $this->assertTrue($ledger->claim($first, 'relapse_alert', 'rn_first'));
        $ledger->markFailed($first);
        $this->assertTrue($ledger->claim($first, 'relapse_alert', 'rn_first'));
        $this->assertSame(2, $db->documents['notification_dispatches'][$first]['attempts']);

        $this->assertTrue($ledger->claim($second, 'relapse_alert', 'rn_second'));
        $this->assertNotSame($first, $second);
    }

    private function ledger(LedgerFakeDatabase $db): FcmDispatchLedger
    {
        $firebase = $this->createMock(FirebaseService::class);
        $firebase->method('db')->willReturn($db);

        return new FcmDispatchLedger($firebase);
    }
}

class LedgerFakeDatabase
{
    public array $documents = [];

    public function collection(string $name): LedgerFakeCollection
    {
        return new LedgerFakeCollection($this, $name);
    }

    public function runTransaction(callable $callback): mixed
    {
        return $callback(new LedgerFakeTransaction);
    }
}

class LedgerFakeCollection
{
    public function __construct(
        private LedgerFakeDatabase $db,
        private string $name,
    ) {}

    public function document(string $id): LedgerFakeDocumentReference
    {
        return new LedgerFakeDocumentReference($this->db, $this->name, $id);
    }
}

class LedgerFakeDocumentReference
{
    public function __construct(
        private LedgerFakeDatabase $db,
        private string $collection,
        private string $id,
    ) {}

    public function snapshot(): LedgerFakeSnapshot
    {
        return new LedgerFakeSnapshot($this->db->documents[$this->collection][$this->id] ?? null);
    }

    public function set(array $data, array $options = []): void
    {
        $current = $this->db->documents[$this->collection][$this->id] ?? [];
        $this->db->documents[$this->collection][$this->id] = ($options['merge'] ?? false)
            ? array_replace($current, $data)
            : $data;
    }
}

class LedgerFakeTransaction
{
    public function snapshot(LedgerFakeDocumentReference $reference): LedgerFakeSnapshot
    {
        return $reference->snapshot();
    }

    public function set(LedgerFakeDocumentReference $reference, array $data): void
    {
        $reference->set($data);
    }
}

class LedgerFakeSnapshot
{
    public function __construct(private ?array $data) {}

    public function exists(): bool
    {
        return $this->data !== null;
    }

    public function data(): array
    {
        return $this->data ?? [];
    }
}
