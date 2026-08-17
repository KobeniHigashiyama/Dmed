<?php

use Illuminate\Support\Facades\Schedule;

// Catches blobs whose delayed cleanup job never made it through the queue.
Schedule::command('images:prune')->hourly()->withoutOverlapping();
