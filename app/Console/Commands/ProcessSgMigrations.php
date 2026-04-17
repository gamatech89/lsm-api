<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Models\Todo;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ProcessSgMigrations extends Command
{
    protected $signature = 'migrations:sg-to-hostinger {--dry-run : Preview all changes without saving}';

    protected $description = 'Update project URLs from SG to Hostinger, complete migration todos, and add monitoring todos for malware sites';

    // SG source host => Hostinger subdomain (felixw43 blocked, felixw223 patient zero — both excluded)
    private const URL_MAP = [
        'felixw14.sg-host.com'  => 'moccasin-scorpion-696081.hostingersite.com',
        'felixw41.sg-host.com'  => 'skyblue-quail-277940.hostingersite.com',
        'felixw94.sg-host.com'  => 'darkorange-spoonbill-931211.hostingersite.com',
        'felixw95.sg-host.com'  => 'lightgreen-wolverine-721187.hostingersite.com',
        'felixw126.sg-host.com' => 'chocolate-grasshopper-987781.hostingersite.com',
        'felixw129.sg-host.com' => 'lemonchiffon-duck-354155.hostingersite.com',
        'felixw173.sg-host.com' => 'darkorchid-bear-100125.hostingersite.com',
        'felixw185.sg-host.com' => 'palevioletred-dugong-293853.hostingersite.com',
        'felixw188.sg-host.com' => 'lightskyblue-dogfish-289068.hostingersite.com',
        'felixw193.sg-host.com' => 'darkslategray-quetzal-607083.hostingersite.com',
        'felixw194.sg-host.com' => 'darkslateblue-kangaroo-356250.hostingersite.com',
        'felixw196.sg-host.com' => 'bisque-sparrow-899804.hostingersite.com',
        'felixw198.sg-host.com' => 'darkgray-rook-268109.hostingersite.com',
        'felixw200.sg-host.com' => 'sandybrown-wombat-810719.hostingersite.com',
        'felixw215.sg-host.com' => 'snow-goshawk-790274.hostingersite.com',
        'felixw218.sg-host.com' => 'floralwhite-goshawk-345896.hostingersite.com',
        'felixw220.sg-host.com' => 'whitesmoke-stingray-212688.hostingersite.com',
        'felixw221.sg-host.com' => 'lime-pelican-905987.hostingersite.com',
        'felixw224.sg-host.com' => 'midnightblue-duck-937654.hostingersite.com',
        'felixw227.sg-host.com' => 'white-porpoise-804698.hostingersite.com',
        'felixw228.sg-host.com' => 'greenyellow-nightingale-757148.hostingersite.com',
        'felixw229.sg-host.com' => 'green-rabbit-750602.hostingersite.com',
        'felixw230.sg-host.com' => 'lightyellow-dog-435557.hostingersite.com',
        'felixw231.sg-host.com' => 'skyblue-dolphin-946979.hostingersite.com',
        'felixw232.sg-host.com' => 'whitesmoke-oryx-782412.hostingersite.com',
        'felixw233.sg-host.com' => 'palegoldenrod-mantis-983316.hostingersite.com',
        'felixw234.sg-host.com' => 'darkgrey-capybara-689907.hostingersite.com',
        'felixw235.sg-host.com' => 'royalblue-louse-267193.hostingersite.com',
        'felixw236.sg-host.com' => 'yellowgreen-emu-121156.hostingersite.com',
        'felixw237.sg-host.com' => 'green-llama-624024.hostingersite.com',
        'felixw239.sg-host.com' => 'darkcyan-swallow-164189.hostingersite.com',
        'felixw240.sg-host.com' => 'lime-shark-565767.hostingersite.com',
        'felixw241.sg-host.com' => 'honeydew-wolverine-776932.hostingersite.com',
        'felixw243.sg-host.com' => 'floralwhite-chicken-903476.hostingersite.com',
        'felixw245.sg-host.com' => 'darkgoldenrod-skunk-202997.hostingersite.com',
        'felixw247.sg-host.com' => 'honeydew-wolverine-651959.hostingersite.com',
        'felixw248.sg-host.com' => 'palegreen-chough-209625.hostingersite.com',
        'felixw251.sg-host.com' => 'yellowgreen-eland-396489.hostingersite.com',
        'felixw253.sg-host.com' => 'palevioletred-curlew-248313.hostingersite.com',
        'felixw257.sg-host.com' => 'plum-guanaco-980376.hostingersite.com',
        'felixw258.sg-host.com' => 'orange-ibex-758918.hostingersite.com',
        'felixw259.sg-host.com' => 'lightsteelblue-oryx-434416.hostingersite.com',
    ];

    // Subset of the above that had malware cleanup — get monitoring todo + security_status = monitoring
    private const MALWARE_HOSTS = [
        'felixw95.sg-host.com',   // Bauer Schmidt Weilerbach       — Type B
        'felixw129.sg-host.com',  // Wössner Technology              — Type A
        'felixw194.sg-host.com',  // Flötenbau Bernhard Hammig       — Type B
        'felixw196.sg-host.com',  // JP Abdichtungstechnik           — Type B
        'felixw218.sg-host.com',  // Baufinanz Düren                 — Type B
        'felixw221.sg-host.com',  // Immospar Genie I                — Type B
        'felixw227.sg-host.com',  // Propfin / Wenger Trading        — Type B
        'felixw231.sg-host.com',  // de la Motte Rohr- u. Kanaltechnik — Type B
        'felixw232.sg-host.com',  // Spirit in Move — Fiona Sebald   — Type B
        'felixw235.sg-host.com',  // tradAc / European Trading Academy — Type B
        'felixw236.sg-host.com',  // Immospar Genie II               — Type B
        'felixw237.sg-host.com',  // E. Kranz Inh. Mike Keßler       — Type B
        'felixw239.sg-host.com',  // EnterPrint GmbH                 — Type B
        'felixw247.sg-host.com',  // Wissmach                        — Type B
        'felixw253.sg-host.com',  // Meister Baukonzepte GmbH        — Type B
    ];

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->warn('━━━ DRY RUN — no changes will be written ━━━');
        }

        $bojan = User::where('email', 'bojanmark89@gmail.com')->first();
        if (!$bojan) {
            $this->error('User bojanmark89@gmail.com not found — cannot assign monitoring todos.');
            return 1;
        }

        $stats = [
            'found'            => 0,
            'not_found'        => 0,
            'urls_updated'     => 0,
            'todos_completed'  => 0,
            'monitoring_added' => 0,
            'security_updated' => 0,
        ];

        $notFound = [];

        DB::beginTransaction();

        try {
            foreach (self::URL_MAP as $sgHost => $hostingerHost) {
                $project = Project::whereRaw('url LIKE ?', ["%{$sgHost}%"])->first();

                if (!$project) {
                    $notFound[] = $sgHost;
                    $stats['not_found']++;
                    continue;
                }

                $stats['found']++;
                $newUrl = "https://{$hostingerHost}";

                $this->line(sprintf(
                    '  <info>%-45s</info> %s → %s',
                    $project->name,
                    $project->url,
                    $newUrl
                ));

                if (!$dryRun) {
                    $project->url          = $newUrl;
                    $project->hosting_provider = 'Hostinger';
                    $project->hosting_url  = $newUrl;
                    $project->save();
                }
                $stats['urls_updated']++;

                // Complete all open todos on this project
                $openCount = Todo::where('project_id', $project->id)
                    ->whereIn('status', ['pending', 'in_progress'])
                    ->count();

                if ($openCount > 0) {
                    $this->line("    → completing {$openCount} open todo(s)");
                    if (!$dryRun) {
                        Todo::where('project_id', $project->id)
                            ->whereIn('status', ['pending', 'in_progress'])
                            ->update(['status' => 'completed']);
                    }
                    $stats['todos_completed'] += $openCount;
                }

                // Malware sites: update security status + create monitoring todo
                if (in_array($sgHost, self::MALWARE_HOSTS, true)) {
                    $this->line("    → security_status: {$project->security_status} → monitoring");
                    if (!$dryRun) {
                        $project->security_status = 'monitoring';
                        $project->save();
                    }
                    $stats['security_updated']++;

                    $this->line("    → creating monitoring todo for Bojan (due +7 days)");
                    if (!$dryRun) {
                        Todo::create([
                            'project_id'  => $project->id,
                            'assignee_id' => $bojan->id,
                            'title'       => 'Post-migration malware monitoring',
                            'description' => 'Monitor for re-infection after malware cleanup and Hostinger migration. Check for new rogue admin accounts, suspicious plugins, injected files, and abnormal temp file counts.',
                            'priority'    => 'high',
                            'status'      => 'pending',
                            'due_date'    => now()->addDays(7),
                        ]);
                    }
                    $stats['monitoring_added']++;
                }
            }

            if ($dryRun) {
                DB::rollBack();
                $this->newLine();
                $this->warn('Dry run complete — all changes rolled back.');
            } else {
                DB::commit();
                $this->newLine();
                $this->info('All changes committed successfully.');
            }
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error("Unexpected error — rolled back: {$e->getMessage()}");
            return 1;
        }

        if (!empty($notFound)) {
            $this->newLine();
            $this->warn('Projects NOT found in DB (URL not matched):');
            foreach ($notFound as $host) {
                $this->line("  - {$host}");
            }
        }

        $this->newLine();
        $this->table(
            ['Action', 'Count'],
            [
                ['Projects found',           $stats['found']],
                ['Projects not found',       $stats['not_found']],
                ['URLs updated',             $stats['urls_updated']],
                ['Todos completed',          $stats['todos_completed']],
                ['Security status → monitoring', $stats['security_updated']],
                ['Monitoring todos created', $stats['monitoring_added']],
            ]
        );

        return 0;
    }
}
