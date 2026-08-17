<?php

use Illuminate\Support\Facades\Schedule;

// Catches blobs whose delayed cleanup job never made it through the queue.
Schedule::command('images:prune')->hourly()->withoutOverlapping();

// Files nothing points at: a request that died mid-upload, or a compressor
// that finished writing after the pruner removed the row.
Schedule::command('images:sweep')->weeklyOn(0, '4:00')->withoutOverlapping();

// Tokens stop being accepted once they expire; this drops the dead rows.
Schedule::command('sanctum:prune-expired --hours=24')->daily();
