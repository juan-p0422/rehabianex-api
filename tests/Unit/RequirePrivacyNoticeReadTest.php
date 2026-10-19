<?php

namespace Tests\Unit;

use App\Http\Middleware\RequirePrivacyNoticeRead;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class RequirePrivacyNoticeReadTest extends TestCase
{
    public function test_missing_or_old_confirmation_is_blocked(): void
    {
        $request = Request::create('/api/ping');
        $request->attributes->set('firebase_profile', [
            'privacy_notice_read' => [
                'status' => 'confirmed',
                'privacy_notice_version' => '2026-08-01',
            ],
        ]);

        $response = (new RequirePrivacyNoticeRead)->handle(
            $request,
            fn (): Response => response()->json(['ok' => true]),
        );

        $this->assertSame(428, $response->getStatusCode());
        $this->assertSame('privacy_notice_read_required', $response->getData(true)['code']);
    }

    public function test_current_confirmation_allows_request(): void
    {
        $request = Request::create('/api/ping');
        $request->attributes->set('firebase_profile', [
            'privacy_notice_read' => [
                'status' => 'confirmed',
                'privacy_notice_version' => '2026-10-18',
            ],
        ]);

        $response = (new RequirePrivacyNoticeRead)->handle(
            $request,
            fn (): Response => response()->json(['ok' => true]),
        );

        $this->assertSame(200, $response->getStatusCode());
    }
}
