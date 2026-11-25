<?php

namespace App\Console\Commands;

use App\Models\Environment;
use App\Models\Flag;
use App\Models\FlagEnvironment;
use App\Support\KeyGenerator;
use App\Support\RulesetPublisher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Throwable;

class CreateEnvironmentCommand extends Command
{
    protected $signature = 'app:create-environment {name}';

    protected $description = 'Create an environment and print its SDK key and signing secret once';

    public function handle(RulesetPublisher $publisher): int
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
        $flagCount = 0;

        try {
            $environment = DB::transaction(function () use ($validator, $sdkKey, $signingSecret, $publisher, &$flagCount) {
                $environment = Environment::create([
                    'name' => $validator->validated()['name'],
                    'sdk_key' => $sdkKey,
                    'signing_secret' => $signingSecret,
                ]);

                // Every flag keeps one state row per environment. Without these rows the
                // existing flags could not be edited here and would be missing from this
                // environment's document, so they start off and are published as version 1.
                $flags = Flag::orderBy('id')->get();
                $flagCount = $flags->count();

                if ($flagCount > 0) {
                    $publisher->publish($environment, function () use ($flags, $environment) {
                        foreach ($flags as $flag) {
                            FlagEnvironment::createDefault($flag, $environment);
                        }
                    });
                }

                return $environment;
            });
        } catch (Throwable $e) {
            $this->error('Could not create the environment. Check the logs for details.');
            report($e);

            return self::FAILURE;
        }

        $this->info("Environment {$environment->name} created.");

        if ($flagCount > 0) {
            $this->line("{$flagCount} existing flag(s) start off in {$environment->name}.");
        }

        $this->newLine();
        $this->line('These are shown once. Store them in the consuming application now.');
        $this->line("  SDK key:        {$sdkKey}");
        $this->line("  Signing secret: {$signingSecret}");

        return self::SUCCESS;
    }
}
