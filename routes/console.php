<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('dvr:cleanup')->everyFiveMinutes()->withoutOverlapping();
