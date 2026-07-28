<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Throwable;

class CreateOperatorCommand extends Command
{
    protected $signature = 'app:create-user {email} {--name=Operator}';

    protected $description = 'Create an operator account for the dashboard';

    public function handle(): int
    {
        $password = $this->secret('Password');
        $confirmation = $this->secret('Confirm password');

        $validator = Validator::make([
            'email' => $this->argument('email'),
            'name' => $this->option('name'),
            'password' => $password,
            'password_confirmation' => $confirmation,
        ], [
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'confirmed', Password::min(12)],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        try {
            $user = User::create($validator->validated());
        } catch (Throwable $e) {
            $this->error('Could not create the operator. Check the logs for details.');
            report($e);

            return self::FAILURE;
        }

        $this->info("Operator {$user->email} created.");

        return self::SUCCESS;
    }
}
