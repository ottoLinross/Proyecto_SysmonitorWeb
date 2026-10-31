<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Throwable;

class CreateInitialAdmin extends Command
{
    protected $signature = 'sysmonitor:create-admin';

    protected $description = 'Inicializa la primera cuenta Administrador de SysMonitor Web';

    public function handle(): int
    {
        $name = $this->ask('Nombre del Administrador');
        $email = $this->ask('Correo electrónico');
        $password = $this->secret('Contraseña (mínimo 12 caracteres)');
        $confirmation = $this->secret('Confirma la contraseña');

        $validator = Validator::make([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'password_confirmation' => $confirmation,
        ], [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:254', 'unique:users,email'],
            'password' => ['required', 'string', 'min:12', 'confirmed'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        try {
            DB::transaction(function () use ($name, $email, $password): void {
                if (User::query()->where('role', User::ROLE_ADMIN)->exists()) {
                    throw new InitialAdminAlreadyExists;
                }

                $user = new User;
                $user->name = $name;
                $user->email = $email;
                $user->password = $password;
                $user->role = User::ROLE_ADMIN;
                $user->save();
            });
        } catch (InitialAdminAlreadyExists) {
            $this->error('Ya existe una cuenta Administrador; no se realizaron cambios.');

            return self::FAILURE;
        } catch (Throwable) {
            $this->error('No fue posible inicializar la cuenta Administrador. No se realizaron más intentos.');

            return self::FAILURE;
        }

        $this->info('Usuario Administrador inicializado correctamente.');

        return self::SUCCESS;
    }
}

class InitialAdminAlreadyExists extends \RuntimeException {}
