<?php

namespace App\Console\Commands;

use App\Services\FirebaseService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Throwable;

class AuthorizeSupervisor extends Command
{
    protected $signature = 'rehabianex:authorize-supervisor {uid}';

    protected $description = 'Autoriza de forma administrativa un perfil supervisor de RehabiAnex.';

    public function handle(FirebaseService $firebase): int
    {
        $uid = (string) $this->argument('uid');

        try {
            $auth = $firebase->auth();
            $db = $firebase->db();
            $user = $auth->getUser($uid);

            if (($user->customClaims['role'] ?? null) !== 'supervisor') {
                $this->error('La cuenta no tiene el rol supervisor.');

                return self::FAILURE;
            }

            $profile = $db->collection('supervisors')->document($uid)->snapshot();

            if (! $profile->exists()) {
                $this->error('No existe el perfil del supervisor en Firestore.');

                return self::FAILURE;
            }

            $data = $profile->data();
            $data['authorized'] = true;
            $data['verified'] = true;
            $data['status'] = 'active';
            $data['authorized_at'] = Carbon::now()->toIso8601String();
            $data['verified_at'] = Carbon::now()->toIso8601String();
            $data['supervisor_code'] = $data['supervisor_code']
                ?? 'RA-'.strtoupper(substr(sha1($uid), 0, 8));
            $data['updated_at'] = Carbon::now()->toIso8601String();

            $db->collection('supervisors')->document($uid)->set($data);
            $auth->setCustomUserClaims($uid, array_replace($user->customClaims, [
                'role' => 'supervisor',
                'supervisor_authorized' => true,
            ]));

            $this->info("Supervisor autorizado: {$uid}");
            $this->warn('El supervisor debe iniciar sesion nuevamente para renovar sus claims.');

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('No se pudo autorizar al supervisor: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
