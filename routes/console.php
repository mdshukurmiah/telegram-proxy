<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('model:prune')->daily();
Schedule::command('queue:prune-failed --hours=168')->daily();
