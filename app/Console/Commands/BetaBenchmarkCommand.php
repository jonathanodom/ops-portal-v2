<?php

namespace App\Console\Commands;

use App\Http\Controllers\Field\TodayController;
use App\Http\Controllers\Office\CloseoutReviewController;
use App\Http\Controllers\Office\DispatchController;
use App\Http\Controllers\Office\OfficeDashboardController;
use App\Http\Controllers\Office\ServiceTicketController;
use App\Models\Closeout;
use App\Models\OrganizationMembership;
use App\Models\ServiceTicket;
use App\Models\User;
use App\Models\VisitMedia;
use App\Support\OfficeDashboardSnapshot;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ViewErrorBag;

class BetaBenchmarkCommand extends Command
{
    protected $signature = 'beta:benchmark {--runs=20} {--fail-on-budget}';

    protected $description = 'Record warm beta queue and detail response budgets';

    public function handle(): int
    {
        if (! app()->environment('beta')) {
            $this->error('Run with --env=beta.');

            return self::FAILURE;
        }
        $membership = OrganizationMembership::query()->with('user')->whereHas('roles', fn ($query) => $query->where('key', 'super_admin'))->firstOrFail();
        Auth::setUser($membership->user);
        View::share('activeOrganization', $membership->organization);
        View::share('activeMembership', $membership);
        View::share('errors', new ViewErrorBag);
        $ticket = ServiceTicket::query()->where('organization_id', $membership->organization_id)->firstOrFail();
        $closeout = Closeout::query()->where('organization_id', $membership->organization_id)->where('status', 'submitted')->firstOrFail();
        $currentQueries = 0;
        $counting = false;
        DB::listen(function () use (&$currentQueries, &$counting): void {
            if ($counting) {
                $currentQueries++;
            }
        });

        $cases = [
            'office_dashboard' => [500, 25, fn () => app(OfficeDashboardController::class)->index(
                $this->request('/office', $membership),
                app(OfficeDashboardSnapshot::class),
            )],
            'today' => [500, 20, fn () => app(TodayController::class)->index($this->request('/field', $membership))],
            'dispatch' => [500, 25, fn () => app(DispatchController::class)->index($this->request('/office/dispatch', $membership))],
            'ticket_detail' => [750, 32, fn () => app()->call([app(ServiceTicketController::class), 'show'], [
                'request' => $this->request('/office/service-tickets/'.$ticket->id, $membership),
                'serviceTicket' => (string) $ticket->id,
            ])],
            'review_detail' => [750, 35, fn () => app(CloseoutReviewController::class)->show($this->request('/office/closeout-reviews/'.$closeout->id, $membership), (string) $closeout->id)],
        ];
        $failed = false;
        foreach ($cases as $name => [$budget, $queryBudget, $callback]) {
            $durations = [];
            $maxQueries = 0;
            for ($run = 0; $run < (int) $this->option('runs'); $run++) {
                $currentQueries = 0;
                $counting = true;
                $start = hrtime(true);
                $callback()->render();
                $durations[] = (hrtime(true) - $start) / 1_000_000;
                $counting = false;
                $maxQueries = max($maxQueries, $currentQueries);
            }
            sort($durations);
            $p95 = $durations[(int) floor((count($durations) - 1) * .95)];
            $this->line(sprintf('%s: p95 %.1f ms; max queries %d', $name, $p95, $maxQueries));
            $failed = $failed || $p95 > $budget || $maxQueries > $queryBudget;
        }

        $media = VisitMedia::query()->where('organization_id', $membership->organization_id)->oldest()->firstOrFail();
        $mediaDurations = [];
        for ($run = 0; $run < (int) $this->option('runs'); $run++) {
            $start = hrtime(true);
            $stream = Storage::disk($media->storage_disk)->readStream($media->storage_key);
            fread($stream, 1);
            fclose($stream);
            $mediaDurations[] = (hrtime(true) - $start) / 1_000_000;
        }
        sort($mediaDurations);
        $mediaP95 = $mediaDurations[(int) floor((count($mediaDurations) - 1) * .95)];
        $this->line(sprintf('media_first_byte: p95 %.1f ms', $mediaP95));
        $failed = $failed || $mediaP95 > 1000;

        return $this->option('fail-on-budget') && $failed ? self::FAILURE : self::SUCCESS;
    }

    private function request(string $uri, OrganizationMembership $membership): Request
    {
        $request = Request::create($uri);
        $request->setUserResolver(fn (): User => $membership->user);
        $request->attributes->set('organization', $membership->organization);
        $request->attributes->set('membership', $membership);

        return $request;
    }
}
