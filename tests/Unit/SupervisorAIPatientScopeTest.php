<?php

namespace Tests\Unit;

use App\Http\Controllers\SupervisorAIController;
use App\Services\AIService;
use App\Services\FirebaseService;
use App\Services\FirestoreAccessService;
use Illuminate\Http\Request;
use Mockery;
use ReflectionClass;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class SupervisorAIPatientScopeTest extends TestCase
{
    public function test_ai_context_only_selects_patients_with_ai_chat_summary_scope(): void
    {
        $withoutAiScope = $this->snapshot('patient-notes-only', [
            'uid' => 'patient-notes-only',
            'supervisor_uid' => 'supervisor-1',
            'wants_supervision' => true,
        ]);
        $withAiScope = $this->snapshot('patient-ai', [
            'uid' => 'patient-ai',
            'supervisor_uid' => 'supervisor-1',
            'wants_supervision' => true,
        ]);
        $query = Mockery::mock();
        $query->shouldReceive('where')->twice()->andReturnSelf();
        $query->shouldReceive('documents')->once()->andReturn([$withoutAiScope, $withAiScope]);
        $database = Mockery::mock();
        $database->shouldReceive('collection')->with('patients')->once()->andReturn($query);
        $firebase = Mockery::mock(FirebaseService::class);
        $firebase->shouldReceive('db')->once()->andReturn($database);
        $access = Mockery::mock(FirestoreAccessService::class);
        $access->shouldReceive('supervisorCanAccessPatient')
            ->once()
            ->with('supervisor-1', 'patient-notes-only', ['ai_chat_summary'])
            ->andReturnFalse();
        $access->shouldReceive('supervisorCanAccessPatient')
            ->once()
            ->with('supervisor-1', 'patient-ai', ['ai_chat_summary'])
            ->andReturnTrue();

        $controller = new SupervisorAIController(
            $firebase,
            Mockery::mock(AIService::class),
            $access
        );
        $patients = (new ReflectionClass($controller))
            ->getMethod('getAuthorizedPatients')
            ->invoke($controller, 'supervisor-1');

        $this->assertCount(1, $patients);
        $this->assertSame('patient-ai', $patients[0]['uid']);
    }

    public function test_unauthorized_supervisor_is_rejected_before_patient_data_is_loaded(): void
    {
        $firebase = Mockery::mock(FirebaseService::class);
        $firebase->shouldReceive('db')->once()->andReturn(Mockery::mock());
        $access = Mockery::mock(FirestoreAccessService::class);
        $access->shouldReceive('role')->once()->andReturn('supervisor');
        $access->shouldReceive('uid')->once()->andReturn('supervisor-pending');
        $access->shouldReceive('assertAuthorizedSupervisor')
            ->once()
            ->with('supervisor-pending')
            ->andThrow(new HttpException(403, 'Supervisor no autorizado.'));
        $controller = new SupervisorAIController($firebase, Mockery::mock(AIService::class), $access);
        $request = Request::create('/api/ai/supervisor-chat', 'POST', [
            'supervisor_uid' => 'supervisor-pending',
            'question' => 'Resumen',
        ]);

        $this->expectException(HttpException::class);
        $this->expectExceptionCode(0);
        $controller->chat($request);
    }

    public function test_eligibility_is_true_with_ai_scope_even_when_no_notes_exist(): void
    {
        $patient = $this->snapshot('patient-ai', [
            'supervisor_uid' => 'supervisor-1',
            'wants_supervision' => true,
        ]);
        $query = Mockery::mock();
        $query->shouldReceive('where')->twice()->andReturnSelf();
        $query->shouldReceive('documents')->once()->andReturn([$patient]);
        $database = Mockery::mock();
        $database->shouldReceive('collection')->once()->with('patients')->andReturn($query);
        $firebase = Mockery::mock(FirebaseService::class);
        $firebase->shouldReceive('db')->once()->andReturn($database);
        $access = Mockery::mock(FirestoreAccessService::class);
        $access->shouldReceive('role')->once()->andReturn('supervisor');
        $access->shouldReceive('uid')->once()->andReturn('supervisor-1');
        $access->shouldReceive('assertAuthorizedSupervisor')->once()->with('supervisor-1');
        $access->shouldReceive('supervisorCanAccessPatient')
            ->once()
            ->with('supervisor-1', 'patient-ai', ['ai_chat_summary'])
            ->andReturnTrue();
        $controller = new SupervisorAIController(
            $firebase,
            Mockery::mock(AIService::class),
            $access
        );
        $request = Request::create('/api/ai/supervisor-chat/eligibility', 'GET');

        $response = $controller->eligibility($request);
        $payload = $response->getData(true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($payload['eligible']);
        $this->assertSame(1, $payload['eligible_patients_count']);
        $this->assertSame('ai_chat_summary', $payload['required_scope']);
    }

    public function test_eligibility_is_false_when_ai_scope_is_missing(): void
    {
        $patient = $this->snapshot('patient-notes-only', [
            'uid' => 'patient-notes-only',
            'supervisor_uid' => 'supervisor-1',
            'wants_supervision' => true,
        ]);
        $query = Mockery::mock();
        $query->shouldReceive('where')->twice()->andReturnSelf();
        $query->shouldReceive('documents')->once()->andReturn([$patient]);
        $database = Mockery::mock();
        $database->shouldReceive('collection')->once()->with('patients')->andReturn($query);
        $firebase = Mockery::mock(FirebaseService::class);
        $firebase->shouldReceive('db')->once()->andReturn($database);
        $access = Mockery::mock(FirestoreAccessService::class);
        $access->shouldReceive('role')->once()->andReturn('supervisor');
        $access->shouldReceive('uid')->once()->andReturn('supervisor-1');
        $access->shouldReceive('assertAuthorizedSupervisor')->once()->with('supervisor-1');
        $access->shouldReceive('supervisorCanAccessPatient')
            ->once()
            ->with('supervisor-1', 'patient-notes-only', ['ai_chat_summary'])
            ->andReturnFalse();
        $controller = new SupervisorAIController(
            $firebase,
            Mockery::mock(AIService::class),
            $access
        );
        $request = Request::create('/api/ai/supervisor-chat/eligibility', 'GET');

        $payload = $controller->eligibility($request)->getData(true);

        $this->assertFalse($payload['eligible']);
        $this->assertSame(0, $payload['eligible_patients_count']);
    }

    public function test_provider_failure_returns_stable_fallback_local_with_http_200(): void
    {
        $patients = Mockery::mock();
        $patients->shouldReceive('where')->twice()->andReturnSelf();
        $patients->shouldReceive('documents')->once()->andReturn([]);
        $database = Mockery::mock();
        $database->shouldReceive('collection')->with('patients')->once()->andReturn($patients);
        $firebase = Mockery::mock(FirebaseService::class);
        $firebase->shouldReceive('db')->once()->andReturn($database);
        $access = Mockery::mock(FirestoreAccessService::class);
        $access->shouldReceive('role')->once()->andReturn('supervisor');
        $access->shouldReceive('uid')->once()->andReturn('supervisor-1');
        $access->shouldReceive('assertAuthorizedSupervisor')->once()->with('supervisor-1');
        $ai = Mockery::mock(AIService::class);
        $ai->shouldReceive('askSupervisorAssistant')->once()->andReturn([
            'ok' => false,
            'answer' => null,
            'error_code' => 'provider_timeout',
            'retryable' => true,
        ]);
        $controller = new SupervisorAIController($firebase, $ai, $access);
        $request = Request::create('/api/ai/supervisor-chat', 'POST', [
            'supervisor_uid' => 'supervisor-1',
            'question' => 'Resumen',
            'mode' => 'ai',
        ]);

        $response = $controller->chat($request);
        $payload = $response->getData(true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($payload['ok']);
        $this->assertSame('fallback_local', $payload['mode']);
        $this->assertSame('provider_timeout', $payload['provider_error']['code']);
        $this->assertArrayHasKey('answer', $payload);
        $this->assertArrayHasKey('context', $payload);
        $this->assertFalse($payload['stored']);
        $this->assertNull($payload['session_id']);
        $this->assertSame('disabled', $payload['history_persistence']);
    }

    public function test_local_mode_does_not_persist_question_or_answer(): void
    {
        [$controller, $ai] = $this->controllerWithNoAuthorizedPatients();
        $ai->shouldNotReceive('askSupervisorAssistant');

        $response = $controller->chat(Request::create('/api/ai/supervisor-chat', 'POST', [
            'supervisor_uid' => 'supervisor-1',
            'question' => 'Resumen local',
            'mode' => 'local',
        ]));
        $payload = $response->getData(true);

        $this->assertSame('local', $payload['mode']);
        $this->assertFalse($payload['stored']);
        $this->assertNull($payload['session_id']);
        $this->assertSame('disabled', $payload['history_persistence']);
    }

    public function test_ai_mode_does_not_persist_question_or_answer(): void
    {
        [$controller, $ai] = $this->controllerWithNoAuthorizedPatients();
        $ai->shouldReceive('askSupervisorAssistant')->once()->andReturn([
            'ok' => true,
            'answer' => 'Respuesta segura',
            'model' => 'test-model',
            'usage' => null,
        ]);

        $response = $controller->chat(Request::create('/api/ai/supervisor-chat', 'POST', [
            'supervisor_uid' => 'supervisor-1',
            'question' => 'Resumen IA',
            'mode' => 'ai',
        ]));
        $payload = $response->getData(true);

        $this->assertSame('ai', $payload['mode']);
        $this->assertSame('Respuesta segura', $payload['answer']);
        $this->assertFalse($payload['stored']);
        $this->assertNull($payload['session_id']);
        $this->assertSame('disabled', $payload['history_persistence']);
    }

    private function controllerWithNoAuthorizedPatients(): array
    {
        $patients = Mockery::mock();
        $patients->shouldReceive('where')->twice()->andReturnSelf();
        $patients->shouldReceive('documents')->once()->andReturn([]);
        $database = Mockery::mock();
        $database->shouldReceive('collection')->once()->with('patients')->andReturn($patients);
        $firebase = Mockery::mock(FirebaseService::class);
        $firebase->shouldReceive('db')->once()->andReturn($database);
        $access = Mockery::mock(FirestoreAccessService::class);
        $access->shouldReceive('role')->once()->andReturn('supervisor');
        $access->shouldReceive('uid')->once()->andReturn('supervisor-1');
        $access->shouldReceive('assertAuthorizedSupervisor')->once()->with('supervisor-1');
        $ai = Mockery::mock(AIService::class);

        return [new SupervisorAIController($firebase, $ai, $access), $ai];
    }

    private function snapshot(string $id, array $data): object
    {
        $snapshot = Mockery::mock();
        $snapshot->shouldReceive('exists')->andReturnTrue();
        $snapshot->shouldReceive('id')->andReturn($id);
        $snapshot->shouldReceive('data')->andReturn($data);

        return $snapshot;
    }
}
