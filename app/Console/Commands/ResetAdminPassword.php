<?php

namespace App\Console\Commands;

use App\Services\FirebaseService;
use App\Support\AdminPasswordPolicy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Exception\Auth\UserNotFound;
use Throwable;

class ResetAdminPassword extends Command
{
    protected $signature = 'rehabianex:reset-admin-password
                            {email : Correo del administrador}
                            {--confirm : Confirma explícitamente el cambio en modo no interactivo}';

    protected $description = 'Cambia de forma segura la contraseña de un administrador existente.';

    public function handle(FirebaseService $firebase): int
    {
        $email = strtolower(trim((string) $this->argument('email')));

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('El correo del administrador no es válido.');

            return self::FAILURE;
        }

        try {
            $auth = $firebase->auth();
            $db = $firebase->db();

            try {
                $user = $auth->getUserByEmail($email);
            } catch (UserNotFound) {
                $this->error('No existe una cuenta admin con ese correo.');

                return self::FAILURE;
            }

            if (($user->customClaims['role'] ?? null) !== 'admin') {
                $this->error('La cuenta existe, pero no pertenece al rol admin; no se modificó.');

                return self::FAILURE;
            }

            $profile = $db->collection('admins')->document($user->uid)->snapshot();

            if (! $profile->exists()) {
                $this->error('La cuenta no tiene un perfil admin válido; no se modificó.');

                return self::FAILURE;
            }

            if (! $this->confirmed()) {
                $this->warn('Operación cancelada; la contraseña no cambió.');

                return self::FAILURE;
            }

            $password = $this->securePassword();

            if ($password === null) {
                return self::FAILURE;
            }

            $auth->changeUserPassword($user->uid, $password);
            unset($password);

            $this->info('Contraseña del administrador actualizada correctamente.');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            Log::error('Admin password reset failed.', [
                'exception' => get_class($exception),
                'operation' => 'reset_admin_password',
            ]);
            $this->error('No se pudo actualizar la contraseña del administrador. Revisa la configuración y los logs sanitizados.');

            return self::FAILURE;
        }
    }

    private function confirmed(): bool
    {
        if ($this->option('confirm')) {
            return true;
        }

        if (! $this->input->isInteractive()) {
            $this->error('En modo no interactivo debes agregar --confirm.');

            return false;
        }

        return $this->confirm('¿Confirmas el cambio de contraseña para este administrador?', false);
    }

    private function securePassword(): ?string
    {
        $password = config('services.admin_bootstrap.password');

        if (! is_string($password) || $password === '') {
            if (! $this->input->isInteractive()) {
                $this->error('Configura REHABIANEX_ADMIN_PASSWORD para ejecutar el comando sin interacción.');

                return null;
            }

            $password = (string) $this->secret('Nueva contraseña temporal segura');
            $confirmation = (string) $this->secret('Confirma la nueva contraseña');

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
}
