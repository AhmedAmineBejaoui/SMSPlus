use Illuminate\Console\Scheduling\Schedule;

protected function schedule(Schedule $schedule): void
{
    $schedule->command('cdr:run')
        ->everyTenMinutes()
        ->withoutOverlapping()       // évite 2 exécutions en même temps
        ->runInBackground()          // optionnel
        ->appendOutputTo(storage_path('logs/cdr-schedule.log'));
}
