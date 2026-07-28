<?php

namespace App\Console\Commands;

use App\Models\Environment;
use App\Support\KeyGenerator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Throwable;

class CreateEnvironmentCommand extends Command
{
    protected $signature = 'app:create-environment {name}';

    protected $description = 'Create an environment and print its SDK key and signing secret once';

    public function handle(): int
    {
        $validator = Validator::make(
            ['name' => $this->argument('name')],
            ['name' => ['required', 'string', 'max:50', 'regex:/^[a-z0-9][a-z0-9_-]*$/', 'unique:environments,name']]
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $sdkKey = KeyGenerator::sdkKey();
        $signingSecret = KeyGenerator::signingSecret();

        try {
            $environment = Environment::create([
                'name' => $validator->validated()['name'],
                'sdk_key' => $sdkKey,
                'signing_secret' => $signingSecret,
            ]);
        } catch (Throwable $e) {
            $this->error('Could not create the environment. Check the logs for details.');
            report($e);

            return self::FAILURE;
        }

        $this->info("Environment {$environment->name} created.");
        $this->newLine();
        $this->line('These are shown once. Store them in the consuming application now.');
        $this->line("  SDK key:        {$sdkKey}");
        $this->line("  Signing secret: {$signingSecret}");

        return self::SUCCESS;
    }
}
