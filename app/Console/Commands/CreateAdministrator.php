<?php

namespace App\Console\Commands;

use App\Models\CareGroup;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

class CreateAdministrator extends Command
{
    protected $signature = 'careslot:admin {email} {--name=Administrator} {--group= : ID der verwalteten Gruppe; ohne Angabe Gesamtzugriff}';

    protected $description = 'Erstellt ein Administratorkonto mit verdeckter Passwortabfrage';

    public function handle(): int
    {
        $groupId = $this->option('group');
        if ($groupId !== null && (! ctype_digit((string) $groupId) || ! CareGroup::whereKey($groupId)->exists())) {
            $this->error('Die angegebene Gruppe existiert nicht.');

            return self::FAILURE;
        }
        $password = $this->secret('Passwort (mindestens 12 Zeichen)');
        $data = ['email' => $this->argument('email'), 'name' => $this->option('name'), 'password' => $password];
        $validator = Validator::make($data, ['email' => ['required', 'email', 'unique:users,email'], 'name' => ['required', 'string', 'max:255'], 'password' => ['required', 'string', 'min:12']]);
        if ($validator->fails()) {
            $this->error($validator->errors()->first());

            return self::FAILURE;
        }
        $user = new User($data);
        $user->is_admin = true;
        $user->group_id = $groupId !== null ? (int) $groupId : null;
        $user->save();
        $this->info('Administratorkonto erstellt.');

        return self::SUCCESS;
    }
}
