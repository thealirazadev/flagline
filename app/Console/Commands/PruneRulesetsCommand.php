<?php

namespace App\Console\Commands;

use App\Models\Environment;
use App\Models\Ruleset;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class PruneRulesetsCommand extends Command
{
    protected $signature = 'app:prune-rulesets';

    protected $description = 'Delete old ruleset versions, keeping the newest FLAGLINE_RULESET_KEEP per environment';

    public function handle(): int
    {
        $keep = max(1, (int) config('flagline.ruleset_keep'));
        $total = 0;

        foreach (Environment::orderBy('id')->get() as $environment) {
            $cutoff = Ruleset::latestFor($environment)->skip($keep - 1)->value('version');
            if ($cutoff === null) {
                continue;
            }

            // Only the latest row is ever served, so older rows are safe to drop.
            $deleted = Ruleset::where('environment_id', $environment->id)->where('version', '<', $cutoff)->delete();
            $total += $deleted;

            if ($deleted > 0) {
                Log::info('ruleset.pruned', ['environment' => $environment->name, 'deleted' => $deleted, 'kept_from' => $cutoff]);
            }
        }

        $this->info("Pruned {$total} ruleset version(s); kept the newest {$keep} per environment.");

        return self::SUCCESS;
    }
}
