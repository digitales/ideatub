<?php

namespace App\Console\Commands;

use App\Models\Thought;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillThoughtContentSha256Command extends Command
{
    protected $signature = 'thoughts:backfill-content-sha256 {--chunk=500 : Rows per chunk}';

    protected $description = 'Populate thoughts.content_sha256 for rows missing a hash.';

    public function handle(): int
    {
        $chunk = (int) $this->option('chunk');
        if ($chunk < 1) {
            $this->error('--chunk must be >= 1');

            return self::FAILURE;
        }

        $total = 0;

        do {
            // Select id + content in the chunk query (not just id); avoids N+1 by not re-fetching each row.
            $rows = DB::table('thoughts')
                ->select('id', 'content')
                ->whereNull('content_sha256')
                ->orderBy('id')
                ->limit($chunk)
                ->get();

            if ($rows->isEmpty()) {
                break;
            }

            foreach ($rows as $row) {
                $decoded = Thought::decodeContentEntities((string) $row->content);
                DB::table('thoughts')
                    ->where('id', $row->id)
                    ->update(['content_sha256' => hash('sha256', $decoded)]);
                $total++;
            }
        } while ($rows->count() === $chunk);

        $this->info("Backfilled {$total} thoughts.");

        return self::SUCCESS;
    }
}
