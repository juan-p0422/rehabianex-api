<?php

namespace App\Console\Commands;

use App\Services\FirebaseService;
use App\Support\AdminPasswordPolicy;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Exception\Auth\UserNotFound;
use Throwable;

class CreateAdmin extends Command
{
    protected $signature = 'rehabianex:create-admin
                            {email : Correo del administrador}
                            {full_name : Nombre visible del administrador}
                            {--link-existing : Autoriza vincular una cuenta Firebase existente sin rol}';

    protected $description = 'Crea o vincula de forma segura el primer administrador.';

    public function handle(FirebaseService $firebase): int
    {
        $email = strtolower(trim((string) $this->argument('email')));
        $fullName = trim((string) $this->argument('full_name'));

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('El correo del administrador no es válido.');

            return self::FAILURE;
        }

        if ($fullName === '') {
            $this->error('El nombre del administrador es obligatorio.');

            return self::FAILURE;
        }

        $createdUid = null;
        $linkedUid = null;
        $previousClaims = [];

        try {
            $auth = $firebase->auth();
            $db = $firebase->db();

            try {
                $user = $auth->getUserByEmail($email);
            } catch (UserNotFound) {
                $user = null;
            }

            if ($user !== null) {
                $uid = $user->uid;
                $role = $user->customClaims['role'] ?? null;

                if ($role === 'admin') {
                    $admin = $db->collection('admins')->document($uid)->snapshot();

                    if ($admin->exists()) {
                        $this->info('El administrador ya existe; no se modificó.');

                        return self::SUCCESS;
                    }

                    if (! $this->option('link-existing')) {
                        $this->error(
                            'La identidad admin ya existe sin perfil. Repite con --link-existing para repararla explícitamente.'
                        );

                        return self::FAILURE;
                    }
                } elseif ($role !== null) {
                    $this->error("La cuenta ya pertenece al rol {$role}; no se modificó.");

                    return self::FAILURE;
                } elseif (! $this->option('link-existing')) {
                    $this->error(
                        'La cuenta Firebase ya existe sin rol. Repite con --link-existing para vincularla explícitamente.'
                    );

                    return self::FAILURE;
                }

                if ($this->hasNonAdminProfile($db, $uid)) {
                    $this->error('La cuenta ya tiene un perfil de paciente o supervisor; no se modificó.');

                    return self::FAILURE;
                }

                $previousClaims = $user->customClaims;
                $linkedUid = $uid;
            } else {
                $password = $this->securePassword();

                if ($password === null) {
                    return self::FAILURE;
                }

                $user = $auth->createUser([
                    'email' => $email,
                    'password' => $password,
                    'displayName' => $fullName,
                    'disabled' => false,
                    'emailVerified' => false,
                ]);
                $createdUid = $user->uid;
                $uid = $createdUid;

                unset($password);
            }

            $auth->setCustomUserClaims($uid, array_replace(
                $user->customClaims,
                ['role' => 'admin']
            ));

            $now = Carbon::now()->toIso8601String();
            $profile = [
                'uid' => $uid,
                'role' => 'admin',
                'full_name' => $fullName,
                'email' => $email,
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ];

            $db->collection('admins')->document($uid)->set($profile);

            $this->info('Administrador creado o vinculado correctamente.');
            $this->warn('Inicia sesión nuevamente para obtener un token con el rol admin.');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->rollback($auth ?? null, $db ?? null, $createdUid, $linkedUid, $previousClaims);
            Log::error('Admin bootstrap failed.', [
                'exception' => get_class($exception),
                'operation' => 'create_or_link_admin',
            ]);
            $this->error('No se pudo crear o vincular el administrador. Revisa la configuración y los logs sanitizados.');

            return self::FAILURE;
        }
    }

    private function securePassword(): ?string
    {
        $password = config('services.admin_bootstrap.password');

        if (! is_string($password) || $password === '') {
            if (! $this->input->isInteractive()) {
                $this->error(
                    'Configura REHABIANEX_ADMIN_PASSWORD para ejecutar el comando sin interacción.'
                );

                return null;
            }

            $password = (string) $this->secret('Contraseña inicial (mínimo 12 caracteres)');
            $confirmation = (string) $this->secret('Confirma la contraseña inicial');

            if (! hash_equals($password, $confirmation)) {
                $this->error('Las contraseñas no coinciden.');

                return null;
            }
        }

        if (! AdminPasswordPolicy::passes($password)) {
            $this->error(AdminPasswordPolicy::requirementMessage());

            return null;
        }

        return $password;
    }

    private function hasNonAdminProfile($db, string $uid): bool
    {
        foreach (['patients', 'supervisors'] as $collection) {
            if ($db->collection($collection)->document($uid)->snapshot()->exists()) {
                return true;
            }
        }

        return false;
    }

    private function rollback($auth, $db, ?string $createdUid, ?string $linkedUid, array $claims): void
    {
        try {
            if ($createdUid !== null) {
                $db?->collection('admins')->document($createdUid)->delete();
                $auth?->deleteUser($createdUid);
            } elseif ($linkedUid !== null) {
                $db?->collection('admins')->document($linkedUid)->delete();
                $auth?->setCustomUserClaims($linkedUid, $claims);
            }
        } catch (Throwable) {
            $this->warn('No fue posible revertir automáticamente todos los cambios parciales.');
        }
    }
}
