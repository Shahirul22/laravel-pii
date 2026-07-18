<?php

namespace Shahirul22\LaravelPiiSanitizer\Commands;

use Illuminate\Console\Command;

class SanitizeCommand extends Command
{
    protected $signature = 'pii:sanitize';

    protected $description = 'Sanitize PII columns in the database (scaffold — not yet implemented).';

    public function handle(): int
    {
        $this->components->info('pii:sanitize is not yet implemented — this is a scaffold command.');

        return self::SUCCESS;
    }
}
